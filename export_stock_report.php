<?php
require_once __DIR__ . '/includes/Auth.php';
Auth::requireLogin();
if (!Auth::canView('stock_report')) {
    http_response_code(403);
    exit('You Do Not Have Access To This Page.');
}
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/SpreadsheetImportHelper.php';
require_once __DIR__ . '/includes/ProductCodeMapper.php';

$db = Database::getConnection();
$isSuperAdmin = Auth::isSuperAdmin();
$scopedIds = Auth::dealershipIds();

$excludedStockNames = ['suzuki habib motors', 'suzuki habib motors alipur'];
$excludedStockIds = [];
foreach ($db->query("SELECT id, name FROM dealerships")->fetchAll() as $d) {
    if (in_array(SpreadsheetImportHelper::normalizeDealershipName($d['name']), $excludedStockNames, true)) {
        $excludedStockIds[] = (int)$d['id'];
    }
}

// Get all distinct product codes from database and sort by priority
// Only show codes from the priority list that have actual data
$productNames = $db->query("SELECT DISTINCT product_name FROM stock_records")->fetchAll(PDO::FETCH_COLUMN);

// Convert to codes, filtering out null values (unmapped products)
$productCodes = [];
foreach ($productNames as $name) {
    $code = ProductCodeMapper::getProductCode($name);
    if ($code !== null) {
        $productCodes[$code] = true;
    }
}
$productCodes = array_keys($productCodes);

// Sort by priority using the ProductCodeMapper priority list
$sortedCodes = ProductCodeMapper::getSortedProductCodes($productCodes);

$selectedRegion = trim($_GET['region'] ?? '');
$rowsQuery = "
    SELECT sr.*, d.name AS dealership_name, d.security_amount, d.region FROM stock_records sr
    JOIN dealerships d ON d.id = sr.dealership_id
";
$rowsConditions = [];
$rowsParams = [];
if (!empty($excludedStockIds)) {
    $rowsConditions[] = "d.id NOT IN (" . implode(',', $excludedStockIds) . ")";
}
if ($selectedRegion !== '') {
    $rowsConditions[] = "d.region = :region";
    $rowsParams['region'] = $selectedRegion;
}
if (!$isSuperAdmin) {
    $rowsConditions[] = !empty($scopedIds) ? "d.id IN (" . implode(',', array_map('intval', $scopedIds)) . ")" : "1 = 0";
}
if (!empty($rowsConditions)) {
    $rowsQuery .= " WHERE " . implode(' AND ', $rowsConditions);
}
$rowsStmt = $db->prepare($rowsQuery);
$rowsStmt->execute($rowsParams);
$rows = $rowsStmt->fetchAll();

// Group by dealership and product code
$pivot = [];
foreach ($rows as $r) {
    $dealership = $r['dealership_name'];
    $productCode = ProductCodeMapper::getProductCode($r['product_name']);
    
    // Skip if product code not found in mapping
    if ($productCode === null) {
        continue;
    }
    
    if (!isset($pivot[$dealership])) {
        $pivot[$dealership] = [];
    }
    
    $pivot[$dealership]['__security'] = $r['security_amount'];
    
    // Sum quantities if the same code appears multiple times
    $pivot[$dealership][$productCode] = ($pivot[$dealership][$productCode] ?? 0) + (int)$r['quantity'];
}

$computeRowTotal = fn(array $products) => array_sum(array_diff_key($products, ['__security' => true]));
uasort($pivot, fn($a, $b) => $computeRowTotal($b) <=> $computeRowTotal($a));

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=stock_report_' . date('Y-m-d') . '.csv');

$out = fopen('php://output', 'w');
fputs($out, "\xEF\xBB\xBF");

fputcsv($out, ['Stock Report — ' . date('d M, Y')]);
fputcsv($out, []);

fputcsv($out, array_merge(['Sr#', 'Dealer'], $sortedCodes, ['Total', 'Available Security Amount']));

$sr = 0;
foreach ($pivot as $dealershipName => $products) {
    $sr++;
    $row = [$sr, $dealershipName];
    foreach ($sortedCodes as $code) {
        $row[] = $products[$code] ?? 0;
    }
    $row[] = $computeRowTotal($products);
    $row[] = $products['__security'] !== null ? $products['__security'] : '';
    fputcsv($out, $row);
}

fclose($out);
