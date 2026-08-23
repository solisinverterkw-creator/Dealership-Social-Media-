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
set_time_limit(0); // Bright Data can take longer than PHP's default execution limit if it falls back to polling

$id = $_GET['id'] ?? null;
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
if (!$id) {
    echo json_encode(['success' => false, 'message' => 'ID missing']);
    exit;
}
if ($from === '' || $to === '') {
    echo json_encode(['success' => false, 'message' => 'Select A From And To Date First.']);
    exit;
}
if (!Auth::canAccessDealership((int)$id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}
if (!Auth::can('refresh')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You Do Not Have Permission To Refresh Data.']);
    exit;
}

$db = Database::getConnection();
$stmt = $db->prepare("SELECT * FROM dealerships WHERE id = :id");
$stmt->execute(['id' => $id]);
$d = $stmt->fetch();

if (!$d) {
    echo json_encode(['success' => false, 'message' => 'Dealership Not Found']);
    exit;
}

if (empty($d['fb_input'])) {
    echo json_encode(['success' => false, 'message' => 'Facebook Page Not Set.']);
    exit;
}

// Exclude posts we auto-published via Content Syndication so reshared content
// isn't counted as this dealership's own organic posting activity.
$excludeIds = [];
$targetName = $db->prepare("SELECT name FROM target_pages WHERE dealership_id = :id LIMIT 1");
$targetName->execute(['id' => $id]);
$linkedName = $targetName->fetchColumn();
if ($linkedName) {
    $logStmt = $db->prepare("SELECT fb_post_id FROM post_log WHERE dealership_name = :name AND status = 'success' AND fb_post_id IS NOT NULL");
    $logStmt->execute(['name' => $linkedName]);
    $excludeIds = $logStmt->fetchAll(PDO::FETCH_COLUMN);
}

// Also exclude posts matching Suzuki Pakistan's own post history by text, so
// dealerships who reshare source content themselves (outside our own publish
// pipeline, so there's no post_log row) aren't credited with organic activity.
$excludeTextSnippets = $db->query("SELECT message_snippet FROM processed_source_posts WHERE message_snippet IS NOT NULL AND message_snippet != ''")->fetchAll(PDO::FETCH_COLUMN);

$result = (new FacebookPostsLookup())->countInRange($d['fb_input'], $from, $to, $d['fb_page_id'], $excludeIds, 100, $excludeTextSnippets);

if (!$result['success']) {
    echo json_encode(['success' => false, 'message' => $result['message']]);
    exit;
}

$update = $db->prepare("UPDATE dealerships SET fb_posts_week = :count, fb_engagement_avg = :engagement, fb_page_id = :page_id, fb_posts_checked_at = NOW() WHERE id = :id");
$update->execute(['count' => $result['count'], 'engagement' => $result['avg_engagement'], 'page_id' => $result['page_id'], 'id' => $id]);

echo json_encode(['success' => true, 'id' => $id, 'fb_posts_week' => $result['count'], 'fb_engagement_avg' => $result['avg_engagement']]);
