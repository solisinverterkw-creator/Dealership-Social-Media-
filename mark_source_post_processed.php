<?php
header('Content-Type: application/json');
require_once __DIR__ . '/includes/Auth.php';
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Login required']);
    exit;
}
require_once __DIR__ . '/includes/Database.php';

$sourcePostId = $_POST['source_post_id'] ?? null;
$snippet = $_POST['message_snippet'] ?? '';

if (!$sourcePostId) {
    echo json_encode(['success' => false, 'message' => 'source_post_id missing']);
    exit;
}

$db = Database::getConnection();
$db->prepare("INSERT IGNORE INTO processed_source_posts (source_post_id, message_snippet) VALUES (:id, :snippet)")
   ->execute(['id' => $sourcePostId, 'snippet' => mb_substr($snippet, 0, 255)]);

echo json_encode(['success' => true]);
