<?php
// Lightweight polling endpoint: returns the current status, reasons, and checked_at
// for a single submission ID. Called by submit_post_check.php every 5 seconds to
// auto-update pending cards without a full page reload.
header('Content-Type: application/json');
require_once __DIR__ . '/includes/Auth.php';
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Login required']);
    exit;
}
require_once __DIR__ . '/includes/Database.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid ID']);
    exit;
}

$db = Database::getConnection();
$stmt = $db->prepare("SELECT ps.status, ps.reasons, ps.checked_at, ps.dealership_id FROM post_submissions ps WHERE ps.id = :id");
$stmt->execute(['id' => $id]);
$row = $stmt->fetch();

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Not found']);
    exit;
}

// Security: user must have access to this dealership
if (!Auth::canAccessDealership((int)$row['dealership_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$reasons = [];
if (!empty($row['reasons'])) {
    $reasons = explode(' | ', $row['reasons']);
}

echo json_encode([
    'success'    => true,
    'status'     => $row['status'],
    'reasons'    => $reasons,
    'checked_at' => $row['checked_at'],
]);
