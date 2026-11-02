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
    'Own Exchange Content',
    'Own Exchange Post Link',
    'Own Certified Used Car Content',
    'Own Used Car Post Link',
    'PSMCL Exchange Tracked',
    'PSMCL Exchange Reshared',
    'PSMCL Exchange Post Link',
    'PSMCL Exchange Status',
    'PSMCL Used Car Tracked',
    'PSMCL Used Car Reshared',
    'PSMCL Used Car Post Link',
    'PSMCL Used Car Status',
    'Compliance Score',
    'Overall Compliance',
    'Last Checked'
]);

foreach ($dealerships as $i => $d) {
    $r = $recordsMap[$d['id']] ?? null;

    $ownExStatus = ($r && $r['own_exchange_status'] === 'uploaded') ? 'Uploaded' : 'Not Uploaded';
    $ownExUrl = ($r && $r['own_exchange_status'] === 'uploaded') ? ($r['own_exchange_url'] ?? '') : '';

    $ownUcStatus = ($r && $r['own_usedcar_status'] === 'uploaded') ? 'Uploaded' : 'Not Uploaded';
    $ownUcUrl = ($r && $r['own_usedcar_status'] === 'uploaded') ? ($r['own_usedcar_url'] ?? '') : '';

    $psmclExTr = $r ? (int)$r['psmcl_exchange_tracked'] : 0;
    $psmclExRe = $r ? (int)$r['psmcl_exchange_reshared'] : 0;
    $psmclExUrl = $r ? ($r['psmcl_exchange_url'] ?? '') : '';
    if (!$r || $r['psmcl_exchange_status'] === 'no_source_post') {
        $psmclExSt = 'No PSMCL Post';
    } elseif ($r['psmcl_exchange_status'] === 'reshared') {
        $psmclExSt = 'Reshared';
    } else {
        $psmclExSt = 'Missed';
    }

    $psmclUcTr = $r ? (int)$r['psmcl_usedcar_tracked'] : 0;
    $psmclUcRe = $r ? (int)$r['psmcl_usedcar_reshared'] : 0;
    $psmclUcUrl = $r ? ($r['psmcl_usedcar_url'] ?? '') : '';
    if (!$r || $r['psmcl_usedcar_status'] === 'no_source_post') {
        $psmclUcSt = 'No PSMCL Post';
    } elseif ($r['psmcl_usedcar_status'] === 'reshared') {
        $psmclUcSt = 'Reshared';
    } else {
        $psmclUcSt = 'Missed';
    }

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
        $ownExStatus,
        $ownExUrl,
        $ownUcStatus,
        $ownUcUrl,
        $psmclExTr,
        $psmclExRe,
        $psmclExUrl,
        $psmclExSt,
        $psmclUcTr,
        $psmclUcRe,
        $psmclUcUrl,
        $psmclUcSt,
        $score . '%',
        $compStatus,
        $lastChecked
    ]);
}

fclose($out);
exit;
