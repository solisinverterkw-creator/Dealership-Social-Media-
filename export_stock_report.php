<?php
require_once __DIR__ . '/includes/Auth.php';
Auth::requireLogin();
if (!Auth::canView('stock_report')) {
    http_response_code(403);
    exit('You Do Not Have Access To This Page.');
}
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/SpreadsheetImportHelper.php';

// Helper function to extract simplified product variant from full product description
function extractProductVariant($fullProductName) {
    $fullProductName = trim($fullProductName);
    if (empty($fullProductName)) {
        return '';
    }
    
    // Remove leading "SUZUKI" if present
    $productName = preg_replace('/^SUZUKI\s+/i', '', $fullProductName);
    $productName = trim($productName);
    
    // Extract model name and variant using pattern matching
    if (preg_match('/^(ALTO|CULTUS|SWIFT|EVERY|FRONX)\s+([A-Z]+)(?:\s+[A-Z]+)?\s+/i', $productName, $matches)) {
        $model = strtoupper($matches[1]);
        $variant = strtoupper($matches[2]);
        
        // Handle CVT specially (can be two words: "GL CVT")
        if (preg_match('/^(ALTO|CULTUS|SWIFT|EVERY|FRONX)\s+([A-Z]+\s+CVT)/i', $productName, $cvtMatches)) {
            $model = strtoupper($cvtMatches[1]);
            $variant = strtoupper($cvtMatches[2]);
        }
        
        return "{$model} {$variant}";
    }
    
    // Fallback: extract first two words (model and variant)
    $parts = explode(' ', $productName);
    if (count($parts) >= 2) {
        return strtoupper($parts[0] . ' ' . $parts[1]);
    }
    
    return strtoupper($productName);
}

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
    'ALTO VXR', 'ALTO VXR AGS', 'ALTO AGS', 'ALTO VXL AGS',
    'CULTUS VXR', 'CULTUS VXL', 'CULTUS AGS',
    'FRONX GL', 'FRONX GL AT', 'FRONX GLX',
    'SWIFT MT', 'SWIFT GL', 'SWIFT GL CVT', 'SWIFT GLX',
    'EVERY VXR', 'EVERY',
];

// Get all distinct extracted product variants
$productNames = $db->query("SELECT DISTINCT product_name FROM stock_records")->fetchAll(PDO::FETCH_COLUMN);
$extractedVariants = array_unique(array_map('extractProductVariant', $productNames));
$extractedVariants = array_filter($extractedVariants); // Remove empty strings

// Sort by priority
$sortedVariants = [];
foreach ($variantPriority as $priority) {
    if (in_array($priority, $extractedVariants, true)) {
        $sortedVariants[] = $priority;
        unset($extractedVariants[array_search($priority, $extractedVariants, true)]);
    }
}
// Add remaining variants in alphabetical order
sort($extractedVariants);
$sortedVariants = array_merge($sortedVariants, $extractedVariants);

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

// Group by dealership and extracted product variant
$pivot = [];
foreach ($rows as $r) {
    $dealership = $r['dealership_name'];
    $extractedVariant = extractProductVariant($r['product_name']);
    
    if (!isset($pivot[$dealership])) {
        $pivot[$dealership] = [];
    }
    
    $pivot[$dealership]['__security'] = $r['security_amount'];
    
    // Sum quantities if the same variant appears multiple times
    $pivot[$dealership][$extractedVariant] = ($pivot[$dealership][$extractedVariant] ?? 0) + (int)$r['quantity'];
}

$computeRowTotal = fn(array $products) => array_sum(array_diff_key($products, ['__security' => true]));
uasort($pivot, fn($a, $b) => $computeRowTotal($b) <=> $computeRowTotal($a));

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=stock_report_' . date('Y-m-d') . '.csv');

$out = fopen('php://output', 'w');
fputs($out, "\xEF\xBB\xBF");

fputcsv($out, ['Stock Report — ' . date('d M, Y')]);
fputcsv($out, []);

fputcsv($out, array_merge(['Sr#', 'Dealer'], $sortedVariants, ['Total', 'Available Security Amount']));

$sr = 0;
foreach ($pivot as $dealershipName => $products) {
    $sr++;
    $row = [$sr, $dealershipName];
    foreach ($sortedVariants as $variant) {
        $row[] = $products[$variant] ?? 0;
    }
    $row[] = $computeRowTotal($products);
    $row[] = $products['__security'] !== null ? $products['__security'] : '';
    fputcsv($out, $row);
}

fclose($out);
