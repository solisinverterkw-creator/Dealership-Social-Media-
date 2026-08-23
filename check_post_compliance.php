<?php
header('Content-Type: application/json');
require_once __DIR__ . '/includes/Auth.php';
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Login required']);
    exit;
}
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/GeminiVisionChecker.php';
require_once __DIR__ . '/includes/ImageResizer.php';
set_time_limit(120);

$db = Database::getConnection();

$dealershipId = (int)($_POST['dealership_id'] ?? 0);
$caption = trim($_POST['caption'] ?? '');

if (!Auth::canAccessDealership($dealershipId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You Do Not Have Access To This Dealership.']);
    exit;
}

if (empty($_FILES['post_image']['name']) || $_FILES['post_image']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'A Post Image Is Required.']);
    exit;
}

$ext = strtolower(pathinfo($_FILES['post_image']['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
    echo json_encode(['success' => false, 'message' => 'Only jpg, png, or webp Images Are Allowed.']);
    exit;
}

$filename = 'submission_' . uniqid() . '.' . $ext;
$relativePath = "assets/uploads/submissions/$filename";
move_uploaded_file($_FILES['post_image']['tmp_name'], __DIR__ . '/' . $relativePath);
ImageResizer::resizeInPlace(__DIR__ . '/' . $relativePath);

$insert = $db->prepare("INSERT INTO post_submissions (dealership_id, image_path, caption, status) VALUES (:did, :img, :cap, 'pending')");
$insert->execute(['did' => $dealershipId, 'img' => $relativePath, 'cap' => $caption]);
$submissionId = (int)$db->lastInsertId();

$vehicleModelId = (int)($_POST['vehicle_model_id'] ?? 0);
if ($vehicleModelId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Please select a target Vehicle Model.']);
    exit;
}

$vStmt = $db->prepare("SELECT * FROM vehicle_models WHERE id = :id");
$vStmt->execute(['id' => $vehicleModelId]);
$targetVehicle = $vStmt->fetch();

if (!$targetVehicle) {
    echo json_encode(['success' => false, 'message' => 'Selected Vehicle Model not found in Brand Assets.']);
    exit;
}

$imgStmt = $db->prepare("SELECT image_path FROM vehicle_model_images WHERE vehicle_model_id = :vid ORDER BY id LIMIT 4");
$imgStmt->execute(['vid' => $targetVehicle['id']]);
$targetVehicle['images'] = $imgStmt->fetchAll(PDO::FETCH_COLUMN);

$identity = $db->query("SELECT * FROM brand_identity WHERE id = 1")->fetch();

if (empty($targetVehicle['images'])) {
    echo json_encode(['success' => false, 'message' => 'No reference photos uploaded yet for ' . htmlspecialchars($targetVehicle['name']) . ' in Brand Assets.']);
    exit;
}

$checker = new GeminiVisionChecker();
$result = $checker->checkCompliance(__DIR__ . '/' . $relativePath, $caption, $targetVehicle, $identity);

if (!$result['success']) {
    echo json_encode(['success' => false, 'message' => 'Check Failed: ' . $result['message']]);
    exit;
}

$status = $result['approved'] ? 'approved' : 'rejected';
$displayReasons = $result['reasons'] ?? [];
if (!empty($result['suggestion'])) {
    $displayReasons[] = '💡 Suggested Wording: ' . $result['suggestion'];
}
$reasonsText = implode(' | ', $displayReasons);
$db->prepare("UPDATE post_submissions SET status = :status, reasons = :reasons, checked_at = NOW() WHERE id = :id")
   ->execute(['status' => $status, 'reasons' => $reasonsText, 'id' => $submissionId]);

$nameStmt = $db->prepare("SELECT name FROM dealerships WHERE id = :id");
$nameStmt->execute(['id' => $dealershipId]);

echo json_encode([
    'success'         => true,
    'id'              => $submissionId,
    'status'          => $status,
    'reasons'         => $displayReasons,
    'image_path'      => $relativePath,
    'dealership_name' => $nameStmt->fetchColumn(),
    'caption'         => $caption,
    'submitted_at'    => date('d M, H:i'),
]);
