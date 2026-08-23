<?php
header('Content-Type: application/json');
require_once __DIR__ . '/includes/Auth.php';
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Login required']);
    exit;
}
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/InstagramLookup.php';

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

if (empty($d['ig_search'])) {
    echo json_encode(['success' => false, 'message' => 'Instagram Profile Not Set.']);
    exit;
}

$result = (new InstagramLookup())->countInRange($d['ig_search'], $from, $to);

if (!$result['success']) {
    echo json_encode(['success' => false, 'message' => $result['message']]);
    exit;
}

$update = $db->prepare("UPDATE dealerships SET ig_posts_week = :count, ig_engagement_avg = :engagement, ig_posts_checked_at = NOW() WHERE id = :id");
$update->execute(['count' => $result['count'], 'engagement' => $result['avg_engagement'], 'id' => $id]);

echo json_encode(['success' => true, 'id' => $id, 'ig_posts_week' => $result['count'], 'ig_engagement_avg' => $result['avg_engagement']]);
