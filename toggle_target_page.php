<?php
header('Content-Type: application/json');
require_once __DIR__ . '/includes/Auth.php';
if (!Auth::isSuperAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only A Super Admin Can Manage Target Pages.']);
    exit;
}
require_once __DIR__ . '/includes/Database.php';

$id = $_POST['id'] ?? null;
if (!$id) {
    echo json_encode(['success' => false, 'message' => 'ID missing']);
    exit;
}

$db = Database::getConnection();
$db->prepare("UPDATE target_pages SET is_active = NOT is_active WHERE id = :id")->execute(['id' => $id]);

$stmt = $db->prepare("SELECT is_active FROM target_pages WHERE id = :id");
$stmt->execute(['id' => $id]);
$row = $stmt->fetch();

echo json_encode(['success' => true, 'is_active' => (bool)($row['is_active'] ?? false)]);
