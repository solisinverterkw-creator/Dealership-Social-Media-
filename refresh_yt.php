<?php
header('Content-Type: application/json');
require_once __DIR__ . '/includes/Auth.php';
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Login required']);
    exit;
}
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/YouTubeLookup.php';

$id = $_GET['id'] ?? null;
if (!$id) {
    echo json_encode(['success' => false, 'message' => 'ID missing']);
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
$stmt = $db->prepare("SELECT yt_search, yt_channel_id, yt_subscribers, yt_videos, yt_views FROM dealerships WHERE id = :id");
$stmt->execute(['id' => $id]);
$d = $stmt->fetch();

if (!$d) {
    echo json_encode(['success' => false, 'message' => 'Dealership Not Found']);
    exit;
}

if (empty($d['yt_search'])) {
    echo json_encode(['success' => true, 'skipped' => true, 'yt_subscribers' => (int)$d['yt_subscribers']]);
    exit;
}

$yt = (new YouTubeLookup())->searchAndGetStats($d['yt_search'], $d['yt_channel_id']);
if (!$yt['success']) {
    echo json_encode(['success' => false, 'message' => $yt['message'], 'yt_subscribers' => (int)$d['yt_subscribers']]);
    exit;
}

$db->prepare("
    UPDATE dealerships SET
        yt_subscribers = :subs, yt_videos = :videos, yt_views = :views,
        yt_channel_id = :channel_id, last_refreshed = NOW()
    WHERE id = :id
")->execute([
    'subs' => $yt['subscribers'],
    'videos' => $yt['total_videos'],
    'views' => $yt['total_views'],
    'channel_id' => $yt['channel_id'],
    'id' => $id,
]);

echo json_encode(['success' => true, 'yt_subscribers' => $yt['subscribers']]);
