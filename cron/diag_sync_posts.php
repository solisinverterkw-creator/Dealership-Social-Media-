<?php
// TEMPORARY diagnostic script — checks each of sync_posts.php's dependencies
// one at a time and reports exactly which step fails, instead of a bare
// HTTP 500. Delete this file once the real problem is found and fixed.

ini_set('display_errors', '1');
error_reporting(E_ALL);

echo "<pre>\n";

echo "Step 1: Loading config.php... ";
try {
    require_once __DIR__ . '/../config.php';
    echo "OK\n";
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    exit;
}

echo "Step 2: Checking ?key= against CRON_SECRET_KEY... ";
$hasValidKey = isset($_GET['key']) && hash_equals(CRON_SECRET_KEY, $_GET['key']);
echo ($hasValidKey ? "OK" : "MISMATCH (continuing anyway for diagnostics)") . "\n";

echo "Step 3: Loading includes/Database.php... ";
try {
    require_once __DIR__ . '/../includes/Database.php';
    echo "OK\n";
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    exit;
}

echo "Step 4: Connecting to the database... ";
try {
    $db = Database::getConnection();
    echo "OK\n";
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    exit;
}

echo "Step 5: Loading includes/BrightDataClient.php... ";
try {
    require_once __DIR__ . '/../includes/BrightDataClient.php';
    echo "OK\n";
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    exit;
}

echo "Step 6: Loading includes/FacebookPostsLookup.php... ";
try {
    require_once __DIR__ . '/../includes/FacebookPostsLookup.php';
    echo "OK\n";
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    exit;
}

echo "Step 7: Instantiating FacebookPostsLookup... ";
try {
    $lookup = new FacebookPostsLookup();
    echo "OK\n";
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    exit;
}

echo "Step 8: Reading source_page_url from app_settings... ";
try {
    $settings = $db->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('source_page_url', 'source_page_id', 'source_page_name')")
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    echo "OK — source_page_url = " . ($settings['source_page_url'] ?? '(not set)') . "\n";
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    exit;
}

echo "Step 9: Checking curl extension is enabled... ";
echo (function_exists('curl_init') ? "OK\n" : "MISSING — curl extension is not enabled on this server.\n");

echo "Step 10: Checking BRIGHTDATA_API_TOKEN is defined... ";
echo (defined('BRIGHTDATA_API_TOKEN') && BRIGHTDATA_API_TOKEN !== '' ? "OK\n" : "MISSING OR EMPTY\n");

echo "\nAll steps completed without a fatal error. If sync_posts.php itself still\n";
echo "500s, the problem is likely inside FacebookPostsLookup::getRecentPosts()\n";
echo "reaching out to Bright Data — check PHP's error log for the real stack trace.\n";
echo "</pre>\n";
