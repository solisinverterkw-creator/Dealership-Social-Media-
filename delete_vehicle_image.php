<?php
header('Content-Type: application/json');
require_once __DIR__ . '/includes/Auth.php';
if (!Auth::isSuperAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only A Super Admin Can Delete Reference Photos.']);
    exit;
}
require_once __DIR__ . '/includes/Database.php';

$id = $_POST['id'] ?? null;
if (!$id) {
    echo json_encode(['success' => false, 'message' => 'ID missing']);
    exit;
}

$db = Database::getConnection();
$stmt = $db->prepare("SELECT vehicle_model_id, image_path FROM vehicle_model_images WHERE id = :id");
$stmt->execute(['id' => $id]);
$row = $stmt->fetch();

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Photo Not Found.']);
    exit;
}

$remaining = (int)$db->query("SELECT COUNT(*) FROM vehicle_model_images WHERE vehicle_model_id = " . (int)$row['vehicle_model_id'])->fetchColumn();
if ($remaining <= 1) {
    echo json_encode(['success' => false, 'message' => 'Cannot Delete The Last Photo — Delete The Whole Model Instead If No Longer Needed.']);
    exit;
}

$db->prepare("DELETE FROM vehicle_model_images WHERE id = :id")->execute(['id' => $id]);

if (!empty($row['image_path']) && file_exists(__DIR__ . '/' . $row['image_path'])) {
    unlink(__DIR__ . '/' . $row['image_path']);
}

echo json_encode(['success' => true]);
