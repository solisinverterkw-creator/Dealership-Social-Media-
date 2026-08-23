<?php
header('Content-Type: application/json');
require_once __DIR__ . '/includes/Auth.php';
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Login required']);
    exit;
}
if (!Auth::isSuperAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only A Super Admin Can Undo A Publish.']);
    exit;
}
require_once __DIR__ . '/includes/Database.php';

$sourcePostId = $_POST['source_post_id'] ?? null;

if (!$sourcePostId) {
    echo json_encode(['success' => false, 'message' => 'source_post_id missing']);
    exit;
}

$db = Database::getConnection();
$db->prepare("DELETE FROM processed_source_posts WHERE source_post_id = :id")
   ->execute(['id' => $sourcePostId]);

echo json_encode(['success' => true]);
