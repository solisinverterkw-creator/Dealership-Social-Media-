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

$selectedRegion = trim($_GET['region'] ?? '');

// Fetch all tracked dealerships
$dealershipsQuery = "SELECT id, name, security_amount, region FROM dealerships";
$dealershipsConditions = [];
$dealershipsParams = [];
if (!empty($excludedStockIds)) {
    $dealershipsConditions[] = "id NOT IN (" . implode(',', $excludedStockIds) . ")";
}
if ($selectedRegion !== '') {
    $dealershipsConditions[] = "region = :region";
    $dealershipsParams['region'] = $selectedRegion;
}
if (!$isSuperAdmin) {
    $dealershipsConditions[] = !empty($scopedIds) ? "id IN (" . implode(',', array_map('intval', $scopedIds)) . ")" : "1 = 0";
}
if (!empty($dealershipsConditions)) {
    $dealershipsQuery .= " WHERE " . implode(' AND ', $dealershipsConditions);
}
$dealershipsQuery .= " ORDER BY id ASC";
$dealershipsStmt = $db->prepare($dealershipsQuery);
$dealershipsStmt->execute($dealershipsParams);
$allDealerships = $dealershipsStmt->fetchAll();

// Fetch stock records
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

// Extract all unique mapped product codes present in the database
$extractedCodesMap = [];
foreach ($rows as $r) {
    $code = ProductCodeMapper::getProductCode($r['product_name']);
    if ($code !== null && $code !== '') {
        $extractedCodesMap[$code] = true;
    }
}

// Sort by priority using ProductCodeMapper
$sortedCodes = ProductCodeMapper::getSortedProductCodes(array_keys($extractedCodesMap));

// Group by dealership and product code — ensuring ALL tracked dealerships appear in the export
$pivot = [];
foreach ($allDealerships as $d) {
    $dealershipName = $d['name'];
    $pivot[$dealershipName] = ['__security' => $d['security_amount']];
    foreach ($sortedCodes as $code) {
        $pivot[$dealershipName][$code] = 0;
    }
}

foreach ($rows as $r) {
    $dealershipName = $r['dealership_name'];
    if (!isset($pivot[$dealershipName])) {
        continue;
    }
    $productCode = ProductCodeMapper::getProductCode($r['product_name']);
    if ($productCode !== null && $productCode !== '') {
        $pivot[$dealershipName][$productCode] = ($pivot[$dealershipName][$productCode] ?? 0) + (int)$r['quantity'];
    }
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
