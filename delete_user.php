<?php
header('Content-Type: application/json');
require_once __DIR__ . '/includes/Auth.php';
if (!Auth::isSuperAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only A Super Admin Can Delete Users.']);
    exit;
}
require_once __DIR__ . '/includes/Database.php';

$id = $_POST['id'] ?? null;
if (!$id) {
    echo json_encode(['success' => false, 'message' => 'ID missing']);
    exit;
}

if ((int)$id === (int)$_SESSION['user_id']) {
    echo json_encode(['success' => false, 'message' => 'You Cannot Delete Your Own Account.']);
    exit;
}

$db = Database::getConnection();
$stmt = $db->prepare("DELETE FROM users WHERE id = :id");
$stmt->execute(['id' => $id]);

echo json_encode(['success' => true]);
