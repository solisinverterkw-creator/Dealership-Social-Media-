<?php
header('Content-Type: application/json');
require_once __DIR__ . '/includes/Auth.php';
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Login required']);
    exit;
}
if (!Auth::canView('manual_publish')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You Do Not Have Access To Trigger The Shared Zapier Automation.']);
    exit;
}
require_once __DIR__ . '/includes/Database.php';

$message = $_POST['message'] ?? '';
$imageUrl = $_POST['image_url'] ?? '';
$videoUrl = $_POST['video_url'] ?? '';
$sourcePostId = $_POST['source_post_id'] ?? null;
$sourceUrl = $_POST['source_url'] ?? '';

$db = Database::getConnection();
$webhookUrl = $db->query("SELECT setting_value FROM app_settings WHERE setting_key = 'zapier_webhook_url'")->fetchColumn();

if (empty($webhookUrl)) {
    echo json_encode(['success' => false, 'message' => 'Zapier Webhook URL Not Set.']);
    exit;
}

$finalMessage = $message;

$payload = [
    'message' => $finalMessage,
    'image_url' => $imageUrl,
    'video_url' => $videoUrl,
    'source_url' => $sourceUrl,
];

$ch = curl_init($webhookUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$success = $httpCode === 200;

$logAttempt = $db->prepare("
    INSERT INTO post_log (source_post_id, source_url, message, dealership_name, target_page_id, fb_post_id, status, error_message)
    VALUES (:source_post_id, :source_url, :message, :dealership_name, :target_page_id, :fb_post_id, :status, :error_message)
");
$logAttempt->execute([
    'source_post_id' => $sourcePostId,
    'source_url' => $sourceUrl,
    'message' => $finalMessage,
    'dealership_name' => 'Zapier (connected pages)',
    'target_page_id' => 'zapier',
    'fb_post_id' => null,
    'status' => $success ? 'success' : 'failed',
    'error_message' => $success ? null : "Webhook HTTP {$httpCode}: {$response}",
]);

echo json_encode(['success' => $success, 'message' => $success ? 'Sent to Zapier.' : "Zapier webhook failed (HTTP {$httpCode})."]);
