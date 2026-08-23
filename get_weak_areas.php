<?php
// AJAX endpoint used by visit_report.php — the Gemini call can take a while
// (retries across a model fallback chain), so it's kept out of the main page
// load entirely; the page renders immediately and calls this afterward,
// showing a live "Generating... Xs" counter until it resolves.
header('Content-Type: application/json');
require_once __DIR__ . '/includes/Auth.php';
Auth::requireLogin();
if (!Auth::canView('visit_report')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You Do Not Have Access To This Page.']);
    exit;
}
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/VisitReportAnalyzer.php';
set_time_limit(0); // Gemini's retry loop across fallback models can take a while

$db = Database::getConnection();
$dealershipId = (int)($_GET['dealership_id'] ?? 0);

if (!Auth::canAccessDealership($dealershipId)) {
    echo json_encode(['success' => false, 'message' => 'You Do Not Have Access To This Dealership.']);
    exit;
}

$stmt = $db->prepare("SELECT * FROM dealerships WHERE id = :id");
$stmt->execute(['id' => $dealershipId]);
$dealership = $stmt->fetch();

if (!$dealership) {
    echo json_encode(['success' => false, 'message' => 'Dealership Not Found.']);
    exit;
}

$salesRows = [];
$salesTarget = null;
$salesGrandTotal = null;
$latestPeriod = $db->prepare("SELECT MAX(period_month) FROM sales_records WHERE dealership_id = :id");
$latestPeriod->execute(['id' => $dealershipId]);
$period = $latestPeriod->fetchColumn();
if ($period) {
    $salesStmt = $db->prepare("SELECT * FROM sales_records WHERE dealership_id = :id AND period_month = :period ORDER BY product_name");
    $salesStmt->execute(['id' => $dealershipId, 'period' => $period]);
    $salesRows = $salesStmt->fetchAll();

    $summaryStmt = $db->prepare("SELECT target, grand_total FROM sales_summary WHERE dealership_id = :id AND period_month = :period");
    $summaryStmt->execute(['id' => $dealershipId, 'period' => $period]);
    $summary = $summaryStmt->fetch();
    $salesTarget = $summary['target'] ?? null;
    $salesGrandTotal = $summary['grand_total'] ?? null;
}

$stockStmt = $db->prepare("SELECT * FROM stock_records WHERE dealership_id = :id ORDER BY product_name");
$stockStmt->execute(['id' => $dealershipId]);
$stockRows = $stockStmt->fetchAll();

// Same "days aged against this month's last date" + 60-day cutoff +
// chassis-must-also-be-in-the-current-Stock-import filter as
// ageing_report.php/visit_report.php's own display section.
$monthEnd = new DateTime(date('Y-m-t'));
$ageingRows = [];
$ageingStmt = $db->prepare("
    SELECT ar.* FROM ageing_records ar
    WHERE ar.dealership_id = :id
      AND EXISTS (
          SELECT 1 FROM stock_chassis_records scr
          WHERE UPPER(TRIM(scr.chassis_number)) = UPPER(TRIM(ar.chassis_number))
      )
");
$ageingStmt->execute(['id' => $dealershipId]);
foreach ($ageingStmt->fetchAll() as $ar) {
    $deliveryDt = new DateTime($ar['delivery_date']);
    $ar['days_aged'] = (int)$monthEnd->diff($deliveryDt)->format('%r%a') * -1;
    if ($ar['days_aged'] >= 60) {
        $ageingRows[] = $ar;
    }
}
usort($ageingRows, fn($a, $b) => $b['days_aged'] <=> $a['days_aged']);

$crmParameters = $db->query("SELECT * FROM crm_parameters ORDER BY display_order, id")->fetchAll();
$crmScoreByParam = [];
$latestCrmPeriod = $db->prepare("SELECT MAX(period_month) FROM crm_scores WHERE dealership_id = :id");
$latestCrmPeriod->execute(['id' => $dealershipId]);
$crmPeriod = $latestCrmPeriod->fetchColumn();
if ($crmPeriod) {
    $crmStmt = $db->prepare("SELECT crm_parameter_id, points_obtained FROM crm_scores WHERE dealership_id = :id AND period_month = :period");
    $crmStmt->execute(['id' => $dealershipId, 'period' => $crmPeriod]);
    foreach ($crmStmt->fetchAll() as $cs) {
        $crmScoreByParam[$cs['crm_parameter_id']] = (float)$cs['points_obtained'];
    }
}

$context = [
    'dealership_name' => $dealership['name'],
    'sales' => $salesRows,
    'sales_target' => $salesTarget,
    'sales_grand_total' => $salesGrandTotal,
    'stock' => $stockRows,
    'ageing' => $ageingRows,
    'security_amount' => $dealership['security_amount'],
    'crm' => array_map(fn($p) => [
        'parameter_name' => $p['parameter_name'],
        'calc_key' => $p['calc_key'],
        'max_points' => (float)$p['max_points'],
        'points_obtained' => $crmScoreByParam[$p['id']] ?? null,
    ], $crmParameters),
    'social' => [
        'fb_followers' => $dealership['fb_followers'], 'fb_target' => $dealership['fb_target'],
        'ig_followers' => $dealership['ig_followers'], 'ig_target' => $dealership['ig_target'],
        'yt_subscribers' => $dealership['yt_subscribers'], 'yt_target' => $dealership['yt_target'],
        'fb_posts_week' => $dealership['fb_posts_week'], 'ig_posts_week' => $dealership['ig_posts_week'],
        'google_review_count' => $dealership['google_review_count'], 'google_rating' => $dealership['google_rating'],
        'google_review_target' => $dealership['google_review_target'],
    ],
];

$analyzer = new VisitReportAnalyzer();
$result = $analyzer->analyzeWeakAreas($context);

if (!$result['success']) {
    echo json_encode(['success' => false, 'message' => $result['message']]);
    exit;
}

$weakAreasText = '';
if (!empty($result['summary'])) {
    $weakAreasText .= $result['summary'] . "\n\n";
}
if (!empty($result['weak_areas'])) {
    foreach ($result['weak_areas'] as $w) {
        $weakAreasText .= "- {$w}\n";
    }
} else {
    $weakAreasText .= "No significant weak areas identified.\n";
}

echo json_encode(['success' => true, 'text' => trim($weakAreasText)]);
