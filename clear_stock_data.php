<?php
/**
 * CLEAR ALL STOCK DATA - RUN THIS BEFORE RE-IMPORTING
 * 
 * This clears all imported stock records from the database
 * so you can reimport with the updated skip keywords.
 * 
 * After running this:
 * 1. Go to stock_report.php
 * 2. Upload your CSV/Excel file
 * 3. Summary columns will now be skipped (UNPAID, PAID, DIFFERENCE, CUC, etc.)
 * 4. Only actual product variants will be stored (ALTO VXR, CULTUS VXR, SWIFT MT, etc.)
 */

require_once __DIR__ . '/includes/Auth.php';
Auth::requireLogin();

if (!Auth::isSuperAdmin()) {
    die('Only Super Admin Can Clear Stock Data');
}

require_once __DIR__ . '/includes/Database.php';

$db = Database::getConnection();

// Get count before clearing
$countBefore = $db->query("SELECT COUNT(*) as cnt FROM stock_records")->fetch()['cnt'];

// Clear all stock records
$db->exec("DELETE FROM stock_records");

// Get count after clearing  
$countAfter = $db->query("SELECT COUNT(*) as cnt FROM stock_records")->fetch()['cnt'];

echo "<div style='padding: 20px; font-family: Arial; background: #f0f0f0;'>";
echo "<h2>Stock Data Cleared ✅</h2>";
echo "<p><strong>Records deleted:</strong> " . ($countBefore - $countAfter) . "</p>";
echo "<p><strong>Remaining records:</strong> " . $countAfter . "</p>";
echo "<hr>";
echo "<h3>Next Steps:</h3>";
echo "<ol>";
echo "<li>Go to <a href='stock_report.php'><strong>stock_report.php</strong></a></li>";
echo "<li>Upload your CSV or Excel file with dealership data</li>";
echo "<li>The import will now <strong>skip summary columns</strong>:</li>";
echo "<ul>";
echo "<li style='color: red;'>❌ CUC-UNPAID STOCK</li>";
echo "<li style='color: red;'>❌ NORMAL-UNPAID STOCK</li>";
echo "<li style='color: red;'>❌ PAID STOCK</li>";
echo "<li style='color: red;'>❌ DIFFERENCE</li>";
echo "<li style='color: red;'>❌ Any column with: UNPAID, PAID, DIFFERENCE, CUC, PENDING, STATUS, etc.</li>";
echo "</ul>";
echo "<li style='color: green;'>✅ <strong>Only actual product variants will be imported</strong> (ALTO VXR, CULTUS VXR, SWIFT MT, etc.)</li>";
echo "</ol>";
echo "</div>";
?>
