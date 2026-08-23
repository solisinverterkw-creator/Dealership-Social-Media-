<?php
header('Content-Type: application/json');
require_once __DIR__ . '/includes/Auth.php';
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Login required']);
    exit;
}
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/ImageResizer.php';

$db = Database::getConnection();

$dealershipId = (int)($_POST['dealership_id'] ?? 0);
$caption = trim($_POST['caption'] ?? '');
$vehicleModelId = (int)($_POST['vehicle_model_id'] ?? 0);

if (!Auth::canAccessDealership($dealershipId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You Do Not Have Access To This Dealership.']);
    exit;
}

if ($vehicleModelId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Please select a target Vehicle Model.']);
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
$fullSubPath = __DIR__ . '/' . $relativePath;
move_uploaded_file($_FILES['post_image']['tmp_name'], $fullSubPath);
ImageResizer::resizeInPlace($fullSubPath);

$insert = $db->prepare("INSERT INTO post_submissions (dealership_id, image_path, caption, status) VALUES (:did, :img, :cap, 'pending')");
$insert->execute(['did' => $dealershipId, 'img' => $relativePath, 'cap' => $caption]);
$submissionId = (int)$db->lastInsertId();

$vStmt = $db->prepare("SELECT * FROM vehicle_models WHERE id = :id");
$vStmt->execute(['id' => $vehicleModelId]);
$targetVehicle = $vStmt->fetch();

if (!$targetVehicle) {
    echo json_encode(['success' => false, 'message' => 'Selected Vehicle Model not found in Brand Assets.']);
    exit;
}

$imgStmt = $db->prepare("SELECT image_path FROM vehicle_model_images WHERE vehicle_model_id = :vid ORDER BY id LIMIT 1");
$imgStmt->execute(['vid' => $targetVehicle['id']]);
$targetVehicle['images'] = $imgStmt->fetchAll(PDO::FETCH_COLUMN);

$identity = $db->query("SELECT * FROM brand_identity WHERE id = 1")->fetch() ?: null;

function encodeImageHelper(string $path, int $maxDim = 720): ?array {
    if (!file_exists($path)) return null;
    $data = null;
    $mime = 'image/jpeg';
    if (function_exists('imagecreatefromstring')) {
        $raw = @file_get_contents($path);
        $src = $raw ? @imagecreatefromstring($raw) : null;
        if ($src) {
            $w = imagesx($src);
            $h = imagesy($src);
            if ($w > $maxDim || $h > $maxDim) {
                if ($w >= $h) {
                    $newW = $maxDim;
                    $newH = (int)round(($h / $w) * $maxDim);
                } else {
                    $newH = $maxDim;
                    $newW = (int)round(($w / $h) * $maxDim);
                }
                $dst = imagecreatetruecolor($newW, $newH);
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
                imagedestroy($src);
                ob_start();
                imagejpeg($dst, null, 82);
                $data = ob_get_clean();
                imagedestroy($dst);
            } else {
                $data = $raw;
            }
        }
    }
    if ($data === null) {
        $data = file_get_contents($path);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }
    return [
        'inline_data' => [
            'mime_type' => $mime,
            'data' => base64_encode($data),
        ]
    ];
}

$imageParts = [];
$referenceDescriptions = [];
$refIndex = 1;
$vehicleImageCount = 0;

$images = $targetVehicle['images'] ?? (!empty($targetVehicle['reference_image']) ? [$targetVehicle['reference_image']] : []);
foreach ($images as $imagePath) {
    $encoded = encodeImageHelper(__DIR__ . '/' . $imagePath, 720);
    if ($encoded) {
        $imageParts[] = $encoded;
        $referenceDescriptions[] = "Reference image {$refIndex}: approved target vehicle — {$targetVehicle['name']}, color {$targetVehicle['color']}.";
        $refIndex++;
        $vehicleImageCount++;
    }
}

if ($vehicleImageCount === 0) {
    echo json_encode(['success' => false, 'message' => "No reference photos uploaded yet for {$targetVehicle['name']} in Brand Assets."]);
    exit;
}

if (!empty($identity['logo_light_path'])) {
    $encoded = encodeImageHelper(__DIR__ . '/' . $identity['logo_light_path'], 720);
    if ($encoded) {
        $imageParts[] = $encoded;
        $referenceDescriptions[] = "Reference image {$refIndex}: LIGHT logo variant (for dark/blue backgrounds).";
        $refIndex++;
    }
}
if (!empty($identity['logo_dark_path'])) {
    $encoded = encodeImageHelper(__DIR__ . '/' . $identity['logo_dark_path'], 720);
    if ($encoded) {
        $imageParts[] = $encoded;
        $referenceDescriptions[] = "Reference image {$refIndex}: DARK logo variant (for white/light backgrounds).";
        $refIndex++;
    }
}
if (!empty($identity['logo_white_bg_path'])) {
    $encoded = encodeImageHelper(__DIR__ . '/' . $identity['logo_white_bg_path'], 720);
    if ($encoded) {
        $imageParts[] = $encoded;
        $referenceDescriptions[] = "Reference image {$refIndex}: RED & BLUE logo variant (for white backgrounds).";
        $refIndex++;
    }
}

$submittedEncoded = encodeImageHelper($fullSubPath, 720);
if (!$submittedEncoded) {
    echo json_encode(['success' => false, 'message' => 'Submitted post image could not be read.']);
    exit;
}

$ruleNum = 1;
$promptLines = [
    "You are a strict brand-compliance auditor for a car dealership's social media post.",
    "Check the SUBMITTED POST IMAGE (the last image below) against ALL of these rules with 100% precision, using the earlier reference images as ground truth:",
    "",
];

$targetName = $targetVehicle['name'];
$promptLines[] = ($ruleNum++) . ". MANDATORY VEHICLE MODEL MATCH ({$targetName}):\n" .
    "   Look at the car body shape, front grille, headlights, and ANY text overlay/badge on the submitted graphic (such as 'FRONX', 'ALTO', 'CULTUS', 'EVERY', 'SWIFT').\n" .
    "   The user explicitly selected '{$targetName}' in the form as the target car model for this post.\n" .
    "   IF THE SUBMITTED GRAPHIC SHOWS A DIFFERENT CAR MODEL OR TEXT BADGE (for example: if the graphic features a Fronx SUV, Alto, Cultus, or Every when '{$targetName}' is selected), YOU MUST REJECT IMMEDIATELY with reason 'Vehicle Model Mismatch: Graphic shows a different car model, but {$targetName} was selected'.";

$promptLines[] = ($ruleNum++) . ". DOOR HANDLES STRICT CHROME AUDIT:\n" .
    "   Zoom in on the front and rear door handles of the car in the graphic.\n" .
    "   Standard approved {$targetName} specification requires CHROME / METALLIC SILVER door handles.\n" .
    "   CRITICAL INSTRUCTION: If the door handles on the car are WHITE, PAINTED, BODY-COLOR, or if you cannot see a distinct metallic chrome shine on the handles, YOU MUST REJECT IMMEDIATELY with reason 'Vehicle Spec Violation: Door handles are painted white body-color, whereas approved {$targetName} specification requires Chrome handles'. DO NOT APPROVE WHITE OR BODY-COLOR PAINTED DOOR HANDLES!";

$hasLogo = !empty($identity['logo_light_path']) || !empty($identity['logo_dark_path']) || !empty($identity['logo_white_bg_path']);
if ($hasLogo) {
    $promptLines[] = ($ruleNum++) . ". LOGO VARIANT AUDIT:\n" .
        "   LOGO BACKGROUND CONTRAST: Examine the background patch directly behind the Suzuki logo. If the background is DARK or BLUE, the WHITE/LIGHT logo variant is required (which is valid and correct). If the background is WHITE, the RED & BLUE or DARK logo variant is required. Only reject if wrong variant is used (e.g. white logo on white background, or dark logo on black background).";
}

if (!empty($identity['tagline'])) {
    $promptLines[] = ($ruleNum++) . ". TAGLINE: The post caption should include or closely reflect this tagline: \"{$identity['tagline']}\". Missing it = REJECT.";
}

$promptLines[] = "";
$promptLines[] = "Submitted post caption: \"" . ($caption !== '' ? $caption : '(no caption)') . "\"";
$promptLines[] = "";
$promptLines[] = "Reference images attached below, in order:";
foreach ($referenceDescriptions as $desc) {
    $promptLines[] = $desc;
}
$promptLines[] = "";
$promptLines[] = "Last image attached below is the SUBMITTED POST IMAGE to judge.";
$promptLines[] = "";
$promptLines[] = "COMPREHENSIVE AUDIT INSTRUCTION: Do NOT stop after finding the first violation. You MUST evaluate ALL rules. If multiple violations exist, YOU MUST INCLUDE ALL VIOLATIONS in the 'reasons' array so the user receives a complete report!";
$promptLines[] = 'Respond with ONLY this JSON, no other text: {"approved": true|false, "reasons": ["violation 1", "violation 2", "..."], "suggestion": "better wording example, or null"}';
$promptLines[] = 'If approved with zero issues across all rules, reasons should be an empty array and suggestion null.';

$parts = [];
$parts[] = ['text' => implode("\n", $promptLines)];
foreach ($imageParts as $imgPart) {
    $parts[] = $imgPart;
}
$parts[] = $submittedEncoded;

$nameStmt = $db->prepare("SELECT name FROM dealerships WHERE id = :id");
$nameStmt->execute(['id' => $dealershipId]);

echo json_encode([
    'success'         => true,
    'submission_id'   => $submissionId,
    'dealership_name' => $nameStmt->fetchColumn(),
    'image_path'      => $relativePath,
    'caption'         => $caption,
    'api_key'         => GEMINI_API_KEY,
    'payload'         => [
        'contents' => [['parts' => $parts]],
        'generationConfig' => ['response_mime_type' => 'application/json'],
    ]
]);
