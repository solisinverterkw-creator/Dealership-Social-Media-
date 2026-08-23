<?php
require_once __DIR__ . '/../config.php';
$isCli = php_sapi_name() === 'cli';
$hasValidKey = isset($_GET['key']) && hash_equals(CRON_SECRET_KEY, $_GET['key']);
if (!$isCli && !$hasValidKey) {
    http_response_code(403);
    exit('CLI only, or provide a valid ?key=.');
}
set_time_limit(0); // Bright Data can take longer than PHP's default execution limit if it falls back to polling

// See sync_posts.php for why this exists — the web server's own connection
// timeout (not PHP's) can kill a slow HTTP-triggered request before Bright
// Data responds, so the response is sent immediately and the rest of this
// script keeps running server-side after the connection closes.
if (!$isCli) {
    ignore_user_abort(true);
    header('Connection: close');
    ob_start();
    echo "Seed started — continuing in the background.\n";
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

$settings = $db->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('source_page_url', 'source_page_id')")
    ->fetchAll(PDO::FETCH_KEY_PAIR);
$sourceUrl = $settings['source_page_url'] ?? null;
$sourcePageId = $settings['source_page_id'] ?? null;

if (empty($sourceUrl)) {
    echo "Source page not set. Set it from the Publish Content page first.\n";
    exit(1);
}

echo "Seeding processed_source_posts from source page {$sourceUrl} ...\n";

$result = $lookup->getRecentPosts($sourceUrl, 25, $sourcePageId);
if (!$result['success']) {
    echo "FAILED to fetch source posts: {$result['message']}\n";
    exit(1);
}

$insert = $db->prepare("
    INSERT IGNORE INTO processed_source_posts (source_post_id, message_snippet)
    VALUES (:id, :snippet)
");

$count = 0;
foreach ($result['posts'] as $post) {
    $snippet = mb_substr($post['message'] ?? '', 0, 255);
    $insert->execute(['id' => $post['id'], 'snippet' => $snippet]);
    if ($insert->rowCount() > 0) {
        $count++;
    }
}

echo "Done. Marked {$count} existing post(s) as already processed.\n";
echo "It is now safe to enable the 12-hour scheduled task.\n";
