<?php
header('Content-Type: application/json');
require_once __DIR__ . '/includes/Auth.php';
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Login required']);
    exit;
}
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/FacebookPostsLookup.php';
require_once __DIR__ . '/includes/RefreshStatus.php';
set_time_limit(0); // Bright Data can take longer than PHP's default execution limit if it falls back to polling

$db = Database::getConnection();
$settings = $db->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('source_page_url', 'source_page_id')")
    ->fetchAll(PDO::FETCH_KEY_PAIR);

$sourceUrl = $settings['source_page_url'] ?? null;
$sourcePageId = $settings['source_page_id'] ?? null;

if (empty($sourceUrl)) {
    echo json_encode(['success' => false, 'message' => 'Source Page Not Set Yet.']);
    exit;
}

$from = $_GET['from'] ?? null;
$to = $_GET['to'] ?? null;
$jobKey = ($from && $to) ? "{$from}_{$to}" : 'recent';

// Bright Data can take several minutes — well past LiteSpeed's own ~121s
// connection timeout, which otherwise kills the request before the browser
// ever sees a response (same issue as refresh_fb.php/refresh_ig.php).
// Respond immediately and keep working server-side; the frontend polls
// refresh_status.php for the real result.
RefreshStatus::start('source_posts', $jobKey);
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

$lookup = new FacebookPostsLookup();
$result = ($from && $to)
    ? $lookup->getPostsInRange($sourceUrl, $from, $to, $sourcePageId)
    : $lookup->getRecentPosts($sourceUrl, 10, $sourcePageId);

if (!$result['success']) {
    RefreshStatus::fail('source_posts', $jobKey, $result['message']);
    exit;
}

$isProcessed = $db->prepare("SELECT 1 FROM processed_source_posts WHERE source_post_id = :id");

$posts = [];
foreach ($result['posts'] as $post) {
    $isProcessed->execute(['id' => $post['id']]);
    $posts[] = [
        'id' => $post['id'],
        'message' => $post['message'],
        'image_url' => $post['image_url'],
        'video_url' => $post['video_url'],
        'created_time' => $post['created_time'],
        'source_url' => $post['source_url'],
        'is_processed' => (bool)$isProcessed->fetch(),
    ];
}

RefreshStatus::finish('source_posts', $jobKey, ['posts' => $posts, 'source_url' => $sourceUrl]);
