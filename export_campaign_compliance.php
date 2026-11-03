<?php
require_once __DIR__ . '/includes/Auth.php';
Auth::requireLogin();
if (!Auth::canView('reshare_compliance') && !Auth::canView('campaign_compliance') && !Auth::isSuperAdmin()) {
    http_response_code(403);
    exit('Access Denied');
}
require_once __DIR__ . '/includes/Database.php';

$db = Database::getConnection();
$scopedIds = Auth::dealershipIds();
$isSuperAdmin = Auth::isSuperAdmin();

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');

if (!$isSuperAdmin && !empty($scopedIds)) {
    $sql = "SELECT id, name, fb_input, fb_url FROM dealerships WHERE id <= 21 AND id IN (" . implode(',', array_fill(0, count($scopedIds), '?')) . ") ORDER BY name";
    $stmt = $db->prepare($sql);
    $stmt->execute($scopedIds);
    $dealerships = $stmt->fetchAll();
} else {
    $sql = "SELECT id, name, fb_input, fb_url FROM dealerships WHERE id <= 21 ORDER BY name";
    $stmt = $db->query($sql);
    $dealerships = $stmt->fetchAll();
}

$recordsMap = [];
if (!empty($dealerships)) {
    $dIds = array_column($dealerships, 'id');
    $placeholders = implode(',', array_fill(0, count($dIds), '?'));
    $stmt = $db->prepare("SELECT * FROM campaign_compliance_records WHERE dealership_id IN ($placeholders) AND date_from = ? AND date_to = ?");
    $stmt->execute(array_merge($dIds, [$from, $to]));
    foreach ($stmt->fetchAll() as $row) {
        $recordsMap[$row['dealership_id']] = $row;
    }
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=campaign_compliance_' . $from . '_to_' . $to . '.csv');

$out = fopen('php://output', 'w');
// Write UTF-8 BOM so Microsoft Excel renders Arabic/Urdu and emojis cleanly
fputs($out, "\xEF\xBB\xBF");

fputcsv($out, [
    'Sr #',
    'Dealership Name',
    'Tracking From',
    'Tracking To',
    'Exchange Posts Count',
    'Exchange Status',
    'Certified Used Car Posts Count',
    'Certified Used Car Status',
    'Total Posts',
    'Compliance Score',
    'Overall Compliance',
    'Last Checked'
]);

foreach ($dealerships as $i => $d) {
    $r = $recordsMap[$d['id']] ?? null;

    $exCount = $r ? (int)($r['own_exchange_count'] ?? 0) : 0;
    $exStatus = ($exCount > 0) ? 'Uploaded' : 'Not Uploaded';

    $ucCount = $r ? (int)($r['own_usedcar_count'] ?? 0) : 0;
    $ucStatus = ($ucCount > 0) ? 'Uploaded' : 'Not Uploaded';

    $totPosts = $exCount + $ucCount;

    $score = $r ? (int)$r['compliance_score'] : 0;
    if ($score === 100) {
        $compStatus = '100% Compliant';
    } elseif ($score >= 50) {
        $compStatus = 'Partial';
    } else {
        $compStatus = 'Non-Compliant';
    }

    $lastChecked = ($r && !empty($r['checked_at'])) ? date('Y-m-d H:i', strtotime($r['checked_at'])) : 'Never';

    fputcsv($out, [
        $i + 1,
        $d['name'],
        $from,
        $to,
        $exCount,
        $exStatus,
        $ucCount,
        $ucStatus,
        $totPosts,
        $score . '%',
        $compStatus,
        $lastChecked
    ]);
}

fclose($out);
exit;
