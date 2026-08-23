<?php
header('Content-Type: application/json');
require_once __DIR__ . '/includes/Auth.php';
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Login required']);
    exit;
}
// Shared, global step (affects every dealership's tracked source posts) — only
// a super admin should trigger it, so scoped users clicking "Check Now" can't
// repeatedly burn the shared RapidAPI quota on this step.
if (!Auth::isSuperAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only a super admin can refresh source posts.']);
    exit;
}
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/FacebookPostsLookup.php';
require_once __DIR__ . '/includes/RefreshStatus.php';
set_time_limit(0); // Bright Data can take longer than PHP's default execution limit if it falls back to polling

$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
if ($from === '' || $to === '') {
    echo json_encode(['success' => false, 'message' => 'Select A From And To Date First.']);
    exit;
}

$db = Database::getConnection();
$lookup = new FacebookPostsLookup();

$settings = $db->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('source_page_url', 'source_page_id')")
    ->fetchAll(PDO::FETCH_KEY_PAIR);
$sourceUrl = $settings['source_page_url'] ?? null;
$sourcePageId = $settings['source_page_id'] ?? null;

if (empty($sourceUrl)) {
    echo json_encode(['success' => false, 'message' => 'Source page not set. Set it from the Publish Content page first.']);
    exit;
}

// Bright Data can take several minutes — well past LiteSpeed's own ~121s
// connection timeout, which otherwise kills the request before the browser
// ever sees a response. Respond immediately and keep working server-side;
// the frontend polls refresh_status.php for the real result.
$jobKey = "{$from}_{$to}";
RefreshStatus::start('reshare_source', $jobKey);
session_write_close();

ignore_user_abort(true);
header('Connection: close');
ob_start();
echo json_encode(['status' => 'started']);
$responseSize = ob_get_length();
header("Content-Length: {$responseSize}");
ob_end_flush();
flush();
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} elseif (function_exists('litespeed_finish_request')) {
    litespeed_finish_request();
}

// Fully paginated over the selected range (not just the latest 15) — so older
// source posts within the range are tracked too, not just the most recent ones.
$result = $lookup->getPostsInRange($sourceUrl, $from, $to, $sourcePageId);
if (!$result['success']) {
    RefreshStatus::fail('reshare_source', $jobKey, $result['message']);
    exit;
}

$upsert = $db->prepare("
    INSERT INTO processed_source_posts (source_post_id, message_snippet, published_at)
    VALUES (:id, :snippet, :published_at)
    ON DUPLICATE KEY UPDATE message_snippet = VALUES(message_snippet), published_at = VALUES(published_at)
");
$newCount = 0;
$tracked = 0;
foreach ($result['posts'] as $post) {
    $snippet = mb_substr($post['message'] ?? '', 0, 255);
    if ($snippet === '') {
        continue;
    }
    $tracked++;
    $upsert->execute([
        'id' => $post['id'],
        'snippet' => $snippet,
        'published_at' => $post['created_time'] ? date('Y-m-d H:i:s', strtotime($post['created_time'])) : null,
    ]);
    if ($upsert->rowCount() === 1) {
        $newCount++;
    }
}

RefreshStatus::finish('reshare_source', $jobKey, ['new_posts' => $newCount, 'tracked' => $tracked]);
