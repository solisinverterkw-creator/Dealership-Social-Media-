<?php
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/ProductCodeMapper.php';

$db = Database::getConnection();

echo "=== PRODUCTS IN DATABASE ===\n\n";

// Get all distinct product names
$products = $db->query("SELECT DISTINCT product_name FROM stock_records ORDER BY product_name")->fetchAll(PDO::FETCH_COLUMN);

echo "Total distinct products: " . count($products) . "\n\n";

foreach ($products as $product) {
    $code = ProductCodeMapper::getProductCode($product);
    $status = ($code === null) ? "❌ NOT MAPPED" : "✅ → " . $code;
    echo "[$status] " . $product . "\n";
}

echo "\n=== EXPECTED MAPPING KEYS ===\n\n";

// Show what ProductCodeMapper expects
$reflection = new ReflectionClass('ProductCodeMapper');
$property = $reflection->getProperty('productMapping');
$property->setAccessible(true);
$mapping = $property->getValue();

foreach (array_keys($mapping) as $key) {
    echo "- " . $key . "\n";
}

echo "\n=== SUMMARY ===\n";
$mappedCount = count(array_filter(array_map([ProductCodeMapper::class, 'getProductCode'], $products)));
echo "Mapped: $mappedCount / " . count($products) . "\n";
echo "Unmapped: " . (count($products) - $mappedCount) . " / " . count($products) . "\n";
?>
