<?php
require_once __DIR__ . '/includes/Auth.php';
Auth::requireLogin();
if (!Auth::canView('ageing_report')) {
    http_response_code(403);
    exit('You Do Not Have Access To This Page.');
}
require_once __DIR__ . '/includes/Database.php';

$db = Database::getConnection();

$monthEnd = new DateTime(date('Y-m-t'));

// Same chassis-in-both-imports filter as the on-screen report — see
// ageing_report.php for why.
$rows = $db->query("
    SELECT ar.*, d.name AS dealership_name, d.region FROM ageing_records ar
    JOIN dealerships d ON d.id = ar.dealership_id
    WHERE EXISTS (
        SELECT 1 FROM stock_chassis_records scr
        WHERE UPPER(TRIM(scr.chassis_number)) = UPPER(TRIM(ar.chassis_number))
    )
")->fetchAll();

foreach ($rows as &$r) {
    $deliveryDt = new DateTime($r['delivery_date']);
    $r['days_aged'] = (int)$monthEnd->diff($deliveryDt)->format('%r%a') * -1;
}
unset($r);

// Only vehicles aged 60+ days count as an ageing concern worth reporting.
$rows = array_values(array_filter($rows, fn($r) => $r['days_aged'] >= 60));

if (!Auth::isSuperAdmin()) {
    $scopedIds = Auth::dealershipIds();
    $rows = array_values(array_filter($rows, fn($r) => in_array((int)$r['dealership_id'], $scopedIds, true)));
}

$selectedRegion = trim($_GET['region'] ?? '');
if ($selectedRegion !== '') {
    $rows = array_values(array_filter($rows, fn($r) => $r['region'] === $selectedRegion));
}

// Same grouping/order as the on-screen report — worst dealership first, its
// own oldest vehicle first within that.
usort($rows, fn($a, $b) => $b['days_aged'] <=> $a['days_aged']);
$grouped = [];
foreach ($rows as $r) {
    $grouped[$r['dealership_name']][] = $r;
}
uasort($grouped, fn($a, $b) => $b[0]['days_aged'] <=> $a[0]['days_aged']);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=ageing_report_' . date('Y-m-d') . '.csv');

$out = fopen('php://output', 'w');
fputs($out, "\xEF\xBB\xBF");

fputcsv($out, ['Ageing Report — ' . date('d M, Y')]);
fputcsv($out, []);

fputcsv($out, ['Dealership', 'Vehicle', 'Chassis', 'Delivery Date', 'Days Aged']);

foreach ($grouped as $dealershipName => $vehicles) {
    foreach ($vehicles as $r) {
        fputcsv($out, [
            $dealershipName,
            $r['product_name'],
            $r['chassis_number'],
            $r['delivery_date'],
            $r['days_aged'],
        ]);
    }
}

fclose($out);
