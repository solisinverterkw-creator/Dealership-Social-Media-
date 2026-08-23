<?php
header('Content-Type: application/json');
require_once __DIR__ . '/includes/Auth.php';
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Login required']);
    exit;
}
require_once __DIR__ . '/includes/Database.php';

$db = Database::getConnection();

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

$submissionId = (int)($data['submission_id'] ?? 0);
$approved = !empty($data['approved']);
$reasons = is_array($data['reasons'] ?? null) ? $data['reasons'] : [];
$suggestion = $data['suggestion'] ?? null;

if ($submissionId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid submission ID']);
    exit;
}

$status = $approved ? 'approved' : 'rejected';
$displayReasons = $reasons;
if (!empty($suggestion)) {
    $displayReasons[] = '💡 Suggested Wording: ' . $suggestion;
}
$reasonsText = implode(' | ', $displayReasons);

$db->prepare("UPDATE post_submissions SET status = :status, reasons = :reasons, checked_at = NOW() WHERE id = :id")
   ->execute(['status' => $status, 'reasons' => $reasonsText, 'id' => $submissionId]);

echo json_encode([
    'success' => true,
    'status' => $status,
    'reasons' => $displayReasons
]);
