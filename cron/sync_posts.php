<?php
require_once __DIR__ . '/../config.php';
$isCli = php_sapi_name() === 'cli';
$hasValidKey = isset($_GET['key']) && hash_equals(CRON_SECRET_KEY, $_GET['key']);
if (!$isCli && !$hasValidKey) {
    http_response_code(403);
    exit('CLI only, or provide a valid ?key=.');
}
set_time_limit(0); // Bright Data can take longer than PHP's default execution limit if it falls back to polling

// When triggered over HTTP (cron-job.org, a browser, etc.), the web server's
// OWN connection timeout (LiteSpeed here) can kill the request before Bright
// Data responds, regardless of set_time_limit(0) above — that's a hosting-
// level cutoff PHP can't override by itself. So the response is sent back
// immediately and the connection closed; the rest of this script keeps
// running server-side afterward (CLI runs are unaffected either way).
if (!$isCli) {
    ignore_user_abort(true);
    header('Connection: close');
    ob_start();
    echo "Sync started — continuing in the background; check post_log for results.\n";
    $responseSize = ob_get_length();
    header("Content-Length: {$responseSize}");
    ob_end_flush();
    flush();
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    }
}

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/FacebookPostsLookup.php';

$db = Database::getConnection();
$lookup = new FacebookPostsLookup();

$settings = $db->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('source_page_url', 'source_page_id', 'source_page_name')")
    ->fetchAll(PDO::FETCH_KEY_PAIR);
$sourceUrl = $settings['source_page_url'] ?? null;
$sourcePageId = $settings['source_page_id'] ?? null;
$sourceName = trim($settings['source_page_name'] ?? '');

if (empty($sourceUrl)) {
    echo "Source page not set. Set it from the Publish Content page first.\n";
    exit(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Checking source page for new posts...\n";

$result = $lookup->getRecentPosts($sourceUrl, 10, $sourcePageId);
if (!$result['success']) {
    echo "FAILED to fetch source posts: {$result['message']}\n";
    exit(1);
}

$isProcessed = $db->prepare("SELECT 1 FROM processed_source_posts WHERE source_post_id = :id");
$markProcessed = $db->prepare("INSERT IGNORE INTO processed_source_posts (source_post_id, message_snippet) VALUES (:id, :snippet)");
$logAttempt = $db->prepare("
    INSERT INTO post_log (source_post_id, source_url, message, dealership_name, target_page_id, fb_post_id, status, error_message)
    VALUES (:source_post_id, :source_url, :message, :dealership_name, :target_page_id, :fb_post_id, :status, :error_message)
");

$zapierWebhookUrl = $db->query("SELECT setting_value FROM app_settings WHERE setting_key = 'zapier_webhook_url'")->fetchColumn();

$newPostsFound = 0;
$totalPublished = 0;
$totalFailed = 0;

foreach ($result['posts'] as $post) {
    $isProcessed->execute(['id' => $post['id']]);
    if ($isProcessed->fetch()) {
        continue; // already syndicated
    }

    $newPostsFound++;
    $message = $post['message'] ?? '';
    $imageUrl = $post['image_url'] ?? null;
    $videoUrl = $post['video_url'] ?? null;
    $sourceUrl2 = $post['source_url'];

    if (empty($message) && empty($imageUrl) && empty($videoUrl)) {
        echo "  Skipping post {$post['id']} — no message, image, or video.\n";
        $markProcessed->execute(['id' => $post['id'], 'snippet' => '']);
        continue;
    }

    echo "  New post {$post['id']} — sending to Zapier...\n";

    if (!empty($zapierWebhookUrl)) {
        $zapierMessage = $message;

        $zch = curl_init($zapierWebhookUrl);
        curl_setopt($zch, CURLOPT_POST, true);
        curl_setopt($zch, CURLOPT_POSTFIELDS, http_build_query([
            'message' => $zapierMessage,
            'image_url' => $imageUrl,
            'video_url' => $videoUrl,
            'source_url' => $sourceUrl2,
        ]));
        curl_setopt($zch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($zch, CURLOPT_TIMEOUT, 30);
        $zResponse = curl_exec($zch);
        $zHttpCode = curl_getinfo($zch, CURLINFO_HTTP_CODE);
        curl_close($zch);

        $zSuccess = $zHttpCode === 200;
        $logAttempt->execute([
            'source_post_id' => $post['id'],
            'source_url' => $sourceUrl2,
            'message' => $zapierMessage,
            'dealership_name' => 'Zapier (connected pages)',
            'target_page_id' => 'zapier',
            'fb_post_id' => null,
            'status' => $zSuccess ? 'success' : 'failed',
            'error_message' => $zSuccess ? null : "Webhook HTTP {$zHttpCode}: {$zResponse}",
        ]);

        if ($zSuccess) {
            $totalPublished++;
            echo "    OK   -> Zapier (connected pages)\n";
        } else {
            $totalFailed++;
            echo "    FAIL -> Zapier: HTTP {$zHttpCode}\n";
        }
    }

    $markProcessed->execute(['id' => $post['id'], 'snippet' => mb_substr($message, 0, 255)]);
}

echo "[" . date('Y-m-d H:i:s') . "] Done. New posts: {$newPostsFound}, published: {$totalPublished}, failed: {$totalFailed}.\n";
