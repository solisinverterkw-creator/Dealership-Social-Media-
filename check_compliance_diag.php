<?php
// PHP self-test diagnostic for post compliance check
header('Content-Type: text/plain');
set_time_limit(0);

echo "=== Gemini Post Compliance Diagnostic ===\n";

require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/GeminiVisionChecker.php';

try {
    echo "1. Connecting to Database... ";
    $db = Database::getConnection();
    echo "OK\n";

    echo "2. Fetching Vehicle Models... ";
    $vehicles = $db->query("SELECT * FROM vehicle_models")->fetchAll();
    foreach ($vehicles as &$v) {
        $imgStmt = $db->prepare("SELECT image_path FROM vehicle_model_images WHERE vehicle_model_id = :vid ORDER BY id");
        $imgStmt->execute(['vid' => $v['id']]);
        $v['images'] = $imgStmt->fetchAll(PDO::FETCH_COLUMN);
    }
    unset($v);
    echo "OK (Found " . count($vehicles) . " models)\n";

    if (empty($vehicles)) {
        die("Error: No vehicle models found in database.\n");
    }

    echo "3. Fetching Brand Identity... ";
    $identity = $db->query("SELECT * FROM brand_identity WHERE id = 1")->fetch();
    echo "OK\n";

    // Select a sample image from the first vehicle model to use as a test input
    $sampleImage = null;
    foreach ($vehicles as $v) {
        if (!empty($v['images'][0])) {
            $sampleImage = $v['images'][0];
            break;
        }
    }

    if (!$sampleImage) {
        die("Error: No reference images found in vehicle models. Please add a reference photo to at least one vehicle model first.\n");
    }

    $sampleFullPath = __DIR__ . '/' . $sampleImage;
    echo "4. Sample Image for Test: $sampleImage (Exists: " . (file_exists($sampleFullPath) ? "Yes" : "No") . ")\n";

    if (!file_exists($sampleFullPath)) {
        die("Error: Sample reference image file does not exist at $sampleFullPath. Did you upload the assets/uploads folder to the live server?\n");
    }

    echo "5. Testing Gemini Vehicle Identification... ";
    $start = microtime(true);
    $checker = new GeminiVisionChecker();
    
    // We access identifyVehicle via reflection since it is private
    $reflector = new ReflectionClass('GeminiVisionChecker');
    $method = $reflector->getMethod('identifyVehicle');
    $method->setAccessible(true);
    
    $identified = $method->invoke($checker, $sampleFullPath, $vehicles);
    $duration = round(microtime(true) - $start, 2);
    echo "OK ({$duration}s)\n";
    if ($identified) {
        echo "   Identified as: " . $identified['name'] . "\n";
    } else {
        echo "   Could not identify vehicle.\n";
    }

    echo "6. Testing Full Compliance Check... ";
    $start = microtime(true);
    $result = $checker->checkCompliance($sampleFullPath, "Test caption", $vehicles, $identity);
    $duration = round(microtime(true) - $start, 2);
    echo "OK ({$duration}s)\n";
    echo "   Result: " . json_encode($result) . "\n";

    echo "\nDiagnostic completed successfully! Everything is working correctly.\n";

} catch (Exception $e) {
    echo "\nDIAGNOSTIC FAILED with Exception:\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}
