<?php
header('Content-Type: application/json');
require_once __DIR__ . '/includes/Auth.php';
if (!Auth::isSuperAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only A Super Admin Can Delete Vehicle References.']);
    exit;
}
require_once __DIR__ . '/includes/Database.php';

$id = $_POST['id'] ?? null;
if (!$id) {
    echo json_encode(['success' => false, 'message' => 'ID missing']);
    exit;
}

$db = Database::getConnection();
$stmt = $db->prepare("SELECT image_path FROM vehicle_model_images WHERE vehicle_model_id = :id");
$stmt->execute(['id' => $id]);
$images = $stmt->fetchAll();

$db->prepare("DELETE FROM vehicle_models WHERE id = :id")->execute(['id' => $id]); // cascades to vehicle_model_images

foreach ($images as $img) {
    if (!empty($img['image_path']) && file_exists(__DIR__ . '/' . $img['image_path'])) {
        unlink(__DIR__ . '/' . $img['image_path']);
    }
}

echo json_encode(['success' => true]);
