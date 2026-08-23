<?php
header('Content-Type: application/json');
require_once __DIR__ . '/includes/Auth.php';
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Login required']);
    exit;
}
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/FacebookPoster.php';

$targetId = $_POST['target_id'] ?? null;
$message = $_POST['message'] ?? '';
$imageUrl = $_POST['image_url'] ?? null;
$videoUrl = $_POST['video_url'] ?? null;
$sourcePostId = $_POST['source_post_id'] ?? null;
$sourceUrl = $_POST['source_url'] ?? null;

if (!$targetId) {
    echo json_encode(['success' => false, 'message' => 'target_id missing']);
    exit;
}
if (empty(trim($message)) && empty($imageUrl) && empty($videoUrl)) {
    echo json_encode(['success' => false, 'message' => 'Message, Image, And Video Are All Empty.']);
    exit;
}

$db = Database::getConnection();
$stmt = $db->prepare("SELECT * FROM target_pages WHERE id = :id");
$stmt->execute(['id' => $targetId]);
$target = $stmt->fetch();

if (!$target) {
    echo json_encode(['success' => false, 'message' => 'Target Page Not Found.']);
    exit;
}
if (!Auth::isSuperAdmin() && !in_array((int)$target['dealership_id'], Auth::dealershipIds(), true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You Do Not Have Access To This Target Page.']);
    exit;
}

$sourceName = trim($db->query("SELECT setting_value FROM app_settings WHERE setting_key = 'source_page_name'")->fetchColumn());
$finalMessage = $sourceName !== '' ? "Check {$sourceName}\n\n{$message}" : $message;

$poster = new FacebookPoster();
$result = $poster->publishToPage($target['page_id'], $target['page_access_token'], $finalMessage, $imageUrl ?: null, $videoUrl ?: null);

$logAttempt = $db->prepare("
    INSERT INTO post_log (source_post_id, source_url, message, dealership_name, target_page_id, fb_post_id, status, error_message)
    VALUES (:source_post_id, :source_url, :message, :dealership_name, :target_page_id, :fb_post_id, :status, :error_message)
");
$logAttempt->execute([
    'source_post_id' => $sourcePostId,
    'source_url' => $sourceUrl,
    'message' => $finalMessage,
    'dealership_name' => $target['name'],
    'target_page_id' => $target['page_id'],
    'fb_post_id' => $result['fb_post_id'] ?? null,
    'status' => $result['success'] ? 'success' : 'failed',
    'error_message' => $result['success'] ? null : $result['message'],
]);

if (!$result['success']) {
    echo json_encode(['success' => false, 'message' => $result['message']]);
    exit;
}

echo json_encode(['success' => true, 'fb_post_id' => $result['fb_post_id']]);
