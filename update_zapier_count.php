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
    echo json_encode(['success' => false, 'message' => 'You Do Not Have Access To Change This Setting.']);
    exit;
}
require_once __DIR__ . '/includes/Database.php';

$count = (int)($_POST['count'] ?? 0);
if ($count < 0) {
    echo json_encode(['success' => false, 'message' => 'Count Cannot Be Negative.']);
    exit;
}

$db = Database::getConnection();
$db->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('zapier_connected_pages_count', :count)
    ON DUPLICATE KEY UPDATE setting_value = :count")->execute(['count' => $count]);

echo json_encode(['success' => true]);
