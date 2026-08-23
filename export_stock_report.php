<?php
require_once __DIR__ . '/includes/Auth.php';
Auth::requireLogin();
if (!Auth::canView('stock_report')) {
    http_response_code(403);
    exit('You Do Not Have Access To This Page.');
}
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/SpreadsheetImportHelper.php';

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

$variantPriority = [
    'Alto VXR', 'Alto VXR AGS', 'Alto AGS', 'Alto VXL AGS',
    'FRONX GL AT', 'FRONX GLX',
    'SWIFT MT', 'Swift GL', 'Swift GL CVT', 'SWIFT GLX',
    'CULTUS VXR', 'CULTUS VXL', 'CULTUS AGS',
    'EVERY',
];
$productNames = $db->query("SELECT DISTINCT product_name FROM stock_records")->fetchAll(PDO::FETCH_COLUMN);
$sortedProductNames = SpreadsheetImportHelper::sortProductColumnsByPriority($productNames, $variantPriority);

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

$pivot = [];
foreach ($rows as $r) {
    $pivot[$r['dealership_name']]['__security'] = $r['security_amount'];
    $pivot[$r['dealership_name']][$r['product_name']] = (int)$r['quantity'];
}

$computeRowTotal = fn(array $products) => array_sum(array_diff_key($products, ['__security' => true]));
uasort($pivot, fn($a, $b) => $computeRowTotal($b) <=> $computeRowTotal($a));

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=stock_report_' . date('Y-m-d') . '.csv');

$out = fopen('php://output', 'w');
fputs($out, "\xEF\xBB\xBF");

fputcsv($out, ['Stock Report — ' . date('d M, Y')]);
fputcsv($out, []);

$shortHeaders = array_map(fn($p) => SpreadsheetImportHelper::shortenProductLabel($p, $variantPriority), $sortedProductNames);
fputcsv($out, array_merge(['Sr#', 'Dealer'], $shortHeaders, ['Total', 'Available Security Amount']));

$sr = 0;
foreach ($pivot as $dealershipName => $products) {
    $sr++;
    $row = [$sr, $dealershipName];
    foreach ($sortedProductNames as $p) {
        $row[] = $products[$p] ?? 0;
    }
    $row[] = $computeRowTotal($products);
    $row[] = $products['__security'] !== null ? $products['__security'] : '';
    fputcsv($out, $row);
}

fclose($out);
