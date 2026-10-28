<?php
require_once __DIR__ . '/includes/Auth.php';
Auth::requireLogin();
if (!Auth::canView('yt_monthly') && !Auth::isSuperAdmin()) {
    http_response_code(403);
    exit('Access Denied');
}
require_once __DIR__ . '/includes/Database.php';

$db = Database::getConnection();
$scopedIds = Auth::dealershipIds();
$isSuperAdmin = Auth::isSuperAdmin();

$selectedMonth = $_GET['month'] ?? date('Y-m');

if (!$isSuperAdmin && empty($scopedIds)) {
    $dealerships = [];
} else {
    $sql = "SELECT id, name, yt_search, yt_channel_id FROM dealerships WHERE 1=1"
        . (!$isSuperAdmin ? " AND id IN (" . implode(',', array_fill(0, count($scopedIds), '?')) . ")" : "")
        . " ORDER BY name";
    $stmt = $db->prepare($sql);
    $stmt->execute(!$isSuperAdmin ? $scopedIds : []);
    $dealerships = $stmt->fetchAll();
}

// Fetch records for selected month
$recordsMap = [];
if (!empty($dealerships)) {
    $dIds = array_column($dealerships, 'id');
    $placeholders = implode(',', array_fill(0, count($dIds), '?'));
    $stmt = $db->prepare("SELECT * FROM yt_testimonial_records WHERE dealership_id IN ($placeholders) AND month = ?");
    $stmt->execute(array_merge($dIds, [$selectedMonth]));
    foreach ($stmt->fetchAll() as $row) {
        $recordsMap[$row['dealership_id']] = $row;
    }
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=youtube_testimonial_compliance_' . $selectedMonth . '.csv');

$out = fopen('php://output', 'w');
// Write UTF-8 BOM so Microsoft Excel opens it correctly
fputs($out, "\xEF\xBB\xBF");

fputcsv($out, [
    'Sr #',
    'Dealership Name',
    'YouTube Channel',
    'Fronx Testimonial',
    'Fronx Video Link',
    'Every Testimonial',
    'Every Video Link',
    'AI Remarks Verification',
    'Compliance Score',
    'Status',
    'Audit Month'
]);

foreach ($dealerships as $i => $d) {
    $r = $recordsMap[$d['id']] ?? null;

    $fronxStatus = ($r && $r['fronx_status'] === 'verified') ? 'Uploaded' : 'Not Uploaded';
    $fronxUrl = $r['fronx_video_url'] ?? '';

    $everyStatus = ($r && $r['every_status'] === 'verified') ? 'Uploaded' : 'Not Uploaded';
    $everyUrl = $r['every_video_url'] ?? '';

    $rems = [];
    if (!empty($r['fronx_reason'])) {
        $rems[] = "Fronx: " . $r['fronx_reason'];
    }
    if (!empty($r['every_reason'])) {
        $rems[] = "Every: " . $r['every_reason'];
    }
    $remarksStr = !empty($rems) ? implode(' | ', $rems) : 'Not Evaluated';

    $score = isset($r['compliance_score']) ? (int)$r['compliance_score'] : 0;
    if ($score === 100) {
        $compStatus = '100% Compliant';
    } elseif ($score === 50) {
        $compStatus = '50% Partial';
    } else {
        $compStatus = 'Non-Compliant';
    }

    fputcsv($out, [
        $i + 1,
        $d['name'],
        $d['yt_search'] ?? '',
        $fronxStatus,
        $fronxUrl,
        $everyStatus,
        $everyUrl,
        $remarksStr,
        $score . '%',
        $compStatus,
        $selectedMonth
    ]);
}

fclose($out);
exit;
