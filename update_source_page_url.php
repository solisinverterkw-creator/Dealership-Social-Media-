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
    echo json_encode(['success' => false, 'message' => 'Only A Super Admin Can Change The Source Page.']);
    exit;
}
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/FacebookLookup.php';
set_time_limit(0); // Bright Data can take longer than PHP's default execution limit if it falls back to polling

$url = trim($_POST['url'] ?? '');
if ($url === '') {
    echo json_encode(['success' => false, 'message' => 'Source Page URL Is Empty.']);
    exit;
}

$fb = new FacebookLookup();
$result = $fb->getFollowerCount($url);
if (!$result['success']) {
    echo json_encode(['success' => false, 'message' => $result['message']]);
    exit;
}

$db = Database::getConnection();
$update = $db->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (:key, :value)
    ON DUPLICATE KEY UPDATE setting_value = :value");
$update->execute(['key' => 'source_page_url', 'value' => $url]);
$update->execute(['key' => 'source_page_id', 'value' => $result['page_id']]);
$update->execute(['key' => 'source_page_name', 'value' => $result['name'] ?? '']);

echo json_encode(['success' => true, 'page_id' => $result['page_id']]);
