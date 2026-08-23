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
$scopedIds = Auth::dealershipIds();

if (Auth::isSuperAdmin()) {
    $targets = $db->query("SELECT id, name FROM target_pages WHERE is_active = 1 ORDER BY name")->fetchAll();
} elseif (empty($scopedIds)) {
    $targets = [];
} else {
    $placeholders = implode(',', array_fill(0, count($scopedIds), '?'));
    $stmt = $db->prepare("SELECT id, name FROM target_pages WHERE is_active = 1 AND dealership_id IN ($placeholders) ORDER BY name");
    $stmt->execute($scopedIds);
    $targets = $stmt->fetchAll();
}

echo json_encode(['success' => true, 'targets' => $targets]);
