<?php
require_once __DIR__ . '/includes/Auth.php';
Auth::requireLogin();
require_once __DIR__ . '/includes/Database.php';

$db = Database::getConnection();
$scopedIds = Auth::dealershipIds();
$isSuperAdmin = Auth::isSuperAdmin();

$graceHours = 20;

$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$type = $_GET['type'] ?? 'summary'; // 'summary' or 'missed'

if ($from === '' || $to === '') {
    http_response_code(400);
    exit('from/to date range required.');
}
$fromDateTime = $from . ' 00:00:00';
$toDateTime = $to . ' 23:59:59';

if (!$isSuperAdmin) {
    if (empty($scopedIds)) {
        $dealerships = [];
    } else {
        $placeholders = implode(',', array_fill(0, count($scopedIds), '?'));
        $stmt = $db->prepare("SELECT id, name FROM dealerships WHERE id IN ($placeholders) ORDER BY name");
        $stmt->execute($scopedIds);
        $dealerships = $stmt->fetchAll();
    }
} else {
    $dealerships = $db->query("SELECT id, name FROM dealerships ORDER BY name")->fetchAll();
}

if ($type === 'missed') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=missed_reshares_' . $from . '_to_' . $to . '.csv');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Dealership', 'Source Post (Snippet)', 'Published At']);

    if ($isSuperAdmin || !empty($scopedIds)) {
        $missingQuery = "
            SELECT rc.dealership_id, d.name AS dealership_name, rc.message_snippet, rc.published_at
            FROM reshare_checks rc
            JOIN dealerships d ON d.id = rc.dealership_id
            WHERE rc.reshared = 0 AND rc.published_at <= NOW() - INTERVAL ? HOUR
              AND rc.published_at BETWEEN ? AND ?"
            . (!$isSuperAdmin ? " AND rc.dealership_id IN (" . implode(',', array_fill(0, count($scopedIds), '?')) . ")" : "") . "
            ORDER BY rc.published_at ASC
        ";
        $missingStmt = $db->prepare($missingQuery);
        $missingParams = [$graceHours, $fromDateTime, $toDateTime];
        if (!$isSuperAdmin) {
            $missingParams = [...$missingParams, ...$scopedIds];
        }
        $missingStmt->execute($missingParams);
        foreach ($missingStmt->fetchAll() as $m) {
            fputcsv($out, [$m['dealership_name'], $m['message_snippet'], $m['published_at']]);
        }
    }

    fclose($out);
    exit;
}

// type === 'summary'
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=reshare_compliance_summary_' . $from . '_to_' . $to . '.csv');
$out = fopen('php://output', 'w');
fputs($out, "\xEF\xBB\xBF");
fputcsv($out, ['Dealership', 'Own Posts (Selected Range)', 'Source Posts Tracked', 'Reshared', 'Missed', 'Last Checked']);

$statsStmt = $db->prepare("
    SELECT
        COUNT(*) AS tracked,
        SUM(reshared = 1) AS reshared_count,
        SUM(reshared = 0 AND published_at <= NOW() - INTERVAL :grace HOUR) AS missed_count,
        MAX(last_checked_at) AS last_checked_at
    FROM reshare_checks
    WHERE dealership_id = :id AND published_at BETWEEN :from AND :to
");
$ownStatsStmt = $db->prepare("
    SELECT own_post_count, reshare_post_count, checked_at
    FROM reshare_own_post_stats
    WHERE dealership_id = :id AND range_from = :from_date AND range_to = :to_date
");

foreach ($dealerships as $d) {
    $statsStmt->execute(['id' => $d['id'], 'grace' => $graceHours, 'from' => $fromDateTime, 'to' => $toDateTime]);
    $stats = $statsStmt->fetch();

    $ownStatsStmt->execute(['id' => $d['id'], 'from_date' => $from, 'to_date' => $to]);
    $ownStats = $ownStatsStmt->fetch();

    fputcsv($out, [
        $d['name'],
        $ownStats ? (int)$ownStats['own_post_count'] : '—',
        (int)($stats['tracked'] ?? 0),
        (int)($stats['reshared_count'] ?? 0),
        (int)($stats['missed_count'] ?? 0),
        $stats['last_checked_at'] ?? 'never',
    ]);
}

fclose($out);
