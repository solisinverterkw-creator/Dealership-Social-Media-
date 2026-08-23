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

$db = Database::getConnection();
$stmt = $db->prepare("SELECT * FROM dealerships WHERE id = :id");
$stmt->execute(['id' => $id]);
$d = $stmt->fetch();

if (!$d) {
    echo json_encode(['success' => false, 'message' => 'Dealership Not Found']);
    exit;
}

if (empty($d['yt_search'])) {
    echo json_encode(['success' => false, 'message' => 'YouTube Channel Not Set.']);
    exit;
}

$result = (new YouTubeLookup())->countThisMonth($d['yt_search'], $d['yt_channel_id']);

if (!$result['success']) {
    echo json_encode(['success' => false, 'message' => $result['message']]);
    exit;
}

$update = $db->prepare("UPDATE dealerships SET yt_videos_month = :count, yt_videos_checked_at = NOW(), yt_channel_id = :channel_id WHERE id = :id");
$update->execute(['count' => $result['count'], 'channel_id' => $result['channel_id'], 'id' => $id]);

echo json_encode(['success' => true, 'id' => $id, 'yt_videos_month' => $result['count']]);
