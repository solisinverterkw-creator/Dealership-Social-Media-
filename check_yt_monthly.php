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
$from = $_GET['from'] ?? null;
$to = $_GET['to'] ?? null;

if (!$id || !$from || !$to) {
    echo json_encode(['success' => false, 'message' => 'id/from/to missing']);
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

if (empty($d['yt_search'])) {
    echo json_encode(['success' => false, 'message' => 'YouTube Channel Not Set.']);
    exit;
}

$result = (new YouTubeLookup())->getMonthlyBreakdown($d['yt_search'], $from, $to, $d['yt_channel_id']);

if (!$result['success']) {
    echo json_encode(['success' => false, 'message' => $result['message']]);
    exit;
}

if (empty($d['yt_channel_id'])) {
    $db->prepare("UPDATE dealerships SET yt_channel_id = :channel_id WHERE id = :id")
       ->execute(['channel_id' => $result['channel_id'], 'id' => $id]);
}

$upsert = $db->prepare("
    INSERT INTO yt_monthly_stats (dealership_id, month, video_count)
    VALUES (:id, :month, :count)
    ON DUPLICATE KEY UPDATE video_count = :count2
");
foreach ($result['breakdown'] as $month => $count) {
    $upsert->execute(['id' => $id, 'month' => $month, 'count' => $count, 'count2' => $count]);
}

echo json_encode(['success' => true, 'id' => $id, 'breakdown' => $result['breakdown']]);
