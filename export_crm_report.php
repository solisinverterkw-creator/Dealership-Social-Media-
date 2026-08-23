<?php
require_once __DIR__ . '/includes/Auth.php';
Auth::requireLogin();
if (!Auth::canView('crm_report')) {
    http_response_code(403);
    exit('You Do Not Have Access To This Page.');
}
require_once __DIR__ . '/includes/Database.php';

$db = Database::getConnection();
$isSuperAdmin = Auth::isSuperAdmin();
$scopedIds = Auth::dealershipIds();

$parameters = $db->query("SELECT * FROM crm_parameters ORDER BY display_order, id")->fetchAll();
$isTemplate = isset($_GET['template']);

if ($isTemplate && !$isSuperAdmin) {
    http_response_code(403);
    exit('You Do Not Have Access To This.');
}

if ($isTemplate) {
    $dealerships = $db->query("SELECT name FROM dealerships ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=crm_report_template.csv');

    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");

    $header = ['Dealer Name'];
    foreach ($parameters as $p) {
        $header[] = $p['parameter_name'] . ' (Max ' . rtrim(rtrim(number_format($p['max_points'], 2), '0'), '.') . ')';
    }
    fputcsv($out, $header);

    foreach ($dealerships as $name) {
        fputcsv($out, array_merge([$name], array_fill(0, count($parameters), '')));
    }

    fclose($out);
    exit;
}

// Same "show the dealership's own target inside Criteria" as the on-screen
// single-dealer scorecard — a calc_key using a dealership-specific target
// (not the monthly raw sheet) gets that number appended so it's clear what's
// being measured against.
$dealerTargetFieldByCalcKey = [
    'digital_enquiry_targets' => 'digital_enquiry_target',
    'stage_won_conversion' => 'digital_enquiry_conversion_target',
];

/** Writes one dealership's vertical scorecard (name, month, Sr#/Parameters/Criteria/Max Pts/Points Obtained, Total) into the open CSV handle. */
function writeDealerScorecard($out, array $dealership, string $selectedPeriod, array $parameters, array $scoreByParam, array $dealerTargetFieldByCalcKey): void
{
    fputcsv($out, [$dealership['name']]);
    fputcsv($out, [date('F Y', strtotime($selectedPeriod . '-01'))]);
    fputcsv($out, []);
    fputcsv($out, ['Sr#', 'Parameters', 'Criteria', 'Max Pts', 'Points Obtained']);

    $totalMaxPoints = array_sum(array_column($parameters, 'max_points'));
    $totalObtained = 0;
    foreach ($parameters as $i => $p) {
        $isDirectResult = (float)$p['max_points'] === 0.0;
        $obtained = $scoreByParam[$p['id']] ?? null;
        if (!$isDirectResult) {
            $totalObtained += $obtained ?? 0;
        }

        $criteriaText = $p['criteria'] ?? '';
        $targetField = $dealerTargetFieldByCalcKey[$p['calc_key']] ?? null;
        if ($targetField !== null && ($dealership[$targetField] ?? null) !== null) {
            $criteriaText = trim($criteriaText . ' (Target: ' . number_format($dealership[$targetField], 0) . ')');
        }

        fputcsv($out, [
            $i + 1,
            $p['parameter_name'],
            $criteriaText,
            $isDirectResult ? '' : $p['max_points'],
            $obtained !== null ? $obtained . ($isDirectResult ? '%' : '') : '',
        ]);
    }
    fputcsv($out, ['', 'TOTAL CRM POINTS', '', $totalMaxPoints, $totalObtained]);
}

$selectedPeriod = $_GET['period'] ?? date('Y-m');
$selectedDealershipId = (int)($_GET['dealership_id'] ?? 0);

if ($selectedDealershipId) {
    $dealershipStmt = $db->prepare("SELECT * FROM dealerships WHERE id = :id");
    $dealershipStmt->execute(['id' => $selectedDealershipId]);
    $dealership = $dealershipStmt->fetch();
    if ($dealership && !$isSuperAdmin && !in_array($selectedDealershipId, $scopedIds, true)) {
        $dealership = null;
    }

    if ($dealership) {
        $oneStmt = $db->prepare("SELECT crm_parameter_id, points_obtained FROM crm_scores WHERE dealership_id = :id AND period_month = :period");
        $oneStmt->execute(['id' => $selectedDealershipId, 'period' => $selectedPeriod]);
        $scoreByParam = [];
        foreach ($oneStmt->fetchAll() as $r) {
            $scoreByParam[$r['crm_parameter_id']] = (float)$r['points_obtained'];
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=crm_report_' . $selectedPeriod . "_dealer{$selectedDealershipId}.csv");

        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF");
        writeDealerScorecard($out, $dealership, $selectedPeriod, $parameters, $scoreByParam, $dealerTargetFieldByCalcKey);
        fclose($out);
        exit;
    }
}

// All dealerships — same vertical scorecard, one block per dealership (in
// name order), separated by a blank line, instead of one wide pivot table.
$scoreScopeSql = (!$isSuperAdmin && !empty($scopedIds)) ? " AND cs.dealership_id IN (" . implode(',', array_map('intval', $scopedIds)) . ")" : '';
$scoreRows = $db->prepare("
    SELECT cs.*, d.name AS dealership_name FROM crm_scores cs
    JOIN dealerships d ON d.id = cs.dealership_id
    WHERE cs.period_month = :period{$scoreScopeSql}
");

$scoresByDealership = [];
if ($isSuperAdmin || !empty($scopedIds)) {
    $scoreRows->execute(['period' => $selectedPeriod]);
    foreach ($scoreRows->fetchAll() as $r) {
        $scoresByDealership[$r['dealership_id']][$r['crm_parameter_id']] = (float)$r['points_obtained'];
    }
}

$dealerships = $db->query("SELECT * FROM dealerships WHERE id IN (" . implode(',', array_map('intval', array_keys($scoresByDealership) ?: [0])) . ") ORDER BY name")->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=crm_report_' . $selectedPeriod . '.csv');

$out = fopen('php://output', 'w');
fputs($out, "\xEF\xBB\xBF");

foreach ($dealerships as $i => $dealership) {
    if ($i > 0) {
        fputcsv($out, []);
        fputcsv($out, []);
    }
    writeDealerScorecard($out, $dealership, $selectedPeriod, $parameters, $scoresByDealership[$dealership['id']] ?? [], $dealerTargetFieldByCalcKey);
}

fclose($out);
