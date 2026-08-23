<?php
header('Content-Type: application/json');
require_once __DIR__ . '/includes/Auth.php';
if (!Auth::isSuperAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only A Super Admin Can Delete Submissions.']);
    exit;
}
require_once __DIR__ . '/includes/Database.php';

$id = $_POST['id'] ?? null;
if (!$id) {
    echo json_encode(['success' => false, 'message' => 'ID missing']);
    exit;
}

$db = Database::getConnection();
$stmt = $db->prepare("SELECT image_path FROM post_submissions WHERE id = :id");
$stmt->execute(['id' => $id]);
$row = $stmt->fetch();

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Submission Not Found.']);
    exit;
}

$db->prepare("DELETE FROM post_submissions WHERE id = :id")->execute(['id' => $id]);

if (!empty($row['image_path']) && file_exists(__DIR__ . '/' . $row['image_path'])) {
    unlink(__DIR__ . '/' . $row['image_path']);
}

echo json_encode(['success' => true]);
