<?php
require_once __DIR__ . '/includes/Auth.php';
Auth::requireLogin();

require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/ProductCodeMapper.php';

$db = Database::getConnection();

echo "<h2>Stock Report Diagnostic</h2>";

// Check total records
$totalRecords = $db->query("SELECT COUNT(*) as cnt FROM stock_records")->fetch()['cnt'];
echo "<p><strong>Total stock_records in database:</strong> $totalRecords</p>";

if ($totalRecords == 0) {
    echo "<p style='color:red;'><strong>⚠️ No stock data found! Need to import CSV first.</strong></p>";
    exit;
}

// Check distinct dealerships
$dealerships = $db->query("SELECT COUNT(DISTINCT dealership_id) as cnt FROM stock_records")->fetch()['cnt'];
echo "<p><strong>Dealerships with stock:</strong> $dealerships</p>";

// Check distinct products
$products = $db->query("SELECT COUNT(DISTINCT product_name) as cnt FROM stock_records")->fetch()['cnt'];
echo "<p><strong>Distinct product names:</strong> $products</p>";

echo "<hr><h3>Product Mapping Status</h3>";

$rows = $db->query("SELECT DISTINCT product_name FROM stock_records ORDER BY product_name")->fetchAll(PDO::FETCH_COLUMN);

$mapped = 0;
$unmapped = 0;
$mappedList = [];
$unmappedList = [];

foreach ($rows as $name) {
    $code = ProductCodeMapper::getProductCode($name);
    if ($code !== null) {
        $mapped++;
        $mappedList[] = "$name → <strong>$code</strong>";
    } else {
        $unmapped++;
        $unmappedList[] = $name;
    }
}

echo "<p>✅ <strong>Mapped:</strong> $mapped products</p>";
if (!empty($mappedList)) {
    echo "<ul>";
    foreach ($mappedList as $item) {
        echo "<li>$item</li>";
    }
    echo "</ul>";
}

if ($unmapped > 0) {
    echo "<p style='color:red;'>❌ <strong>Unmapped:</strong> $unmapped products</p>";
    echo "<ul style='color:red;'>";
    foreach ($unmappedList as $item) {
        echo "<li>$item</li>";
    }
    echo "</ul>";
}

echo "<hr><h3>Sample Data</h3>";

$sample = $db->query("
    SELECT sr.*, d.name as dealership_name 
    FROM stock_records sr 
    JOIN dealerships d ON d.id = sr.dealership_id 
    LIMIT 10
")->fetchAll();

if (empty($sample)) {
    echo "<p style='color:red;'>⚠️ No data found</p>";
} else {
    echo "<table border='1' cellpadding='5' style='border-collapse:collapse;'>";
    echo "<tr><th>Dealership</th><th>Product Name</th><th>Quantity</th><th>Mapped Code</th></tr>";
    foreach ($sample as $row) {
        $code = ProductCodeMapper::getProductCode($row['product_name']);
        $codeDisplay = $code ? "<strong style='color:green;'>$code</strong>" : "<span style='color:red;'>NULL</span>";
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['dealership_name']) . "</td>";
        echo "<td>" . htmlspecialchars($row['product_name']) . "</td>";
        echo "<td>" . $row['quantity'] . "</td>";
        echo "<td>$codeDisplay</td>";
        echo "</tr>";
    }
    echo "</table>";
}

?>
