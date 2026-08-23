<?php
require_once __DIR__ . '/includes/Auth.php';
Auth::requireLogin();
require_once __DIR__ . '/includes/Database.php';

$db = Database::getConnection();
$scopedIds = Auth::dealershipIds();

if (!Auth::isSuperAdmin()) {
    if (empty($scopedIds)) {
        $dealerships = [];
    } else {
        $placeholders = implode(',', array_fill(0, count($scopedIds), '?'));
        $stmt = $db->prepare("
            SELECT *, (fb_followers + ig_followers + yt_subscribers) AS total_reach
            FROM dealerships WHERE id IN ($placeholders)
            ORDER BY total_reach DESC
        ");
        $stmt->execute($scopedIds);
        $dealerships = $stmt->fetchAll();
    }
} else {
    $dealerships = $db->query("
        SELECT *, (fb_followers + ig_followers + yt_subscribers) AS total_reach
        FROM dealerships
        ORDER BY total_reach DESC
    ")->fetchAll();
}

/** Plain numeric percent-achieved, blank when no target is set (mirrors report.php's targetBadge). */
function percentAchieved(int $value, int $target)
{
    return $target > 0 ? round(($value / $target) * 100) . '%' : '';
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=social_media_report_' . date('Y-m-d') . '.csv');

$out = fopen('php://output', 'w');
fputs($out, "\xEF\xBB\xBF");

fputcsv($out, [
    'Rank', 'Dealership', 'Total Reach (FB+IG+YT)',
    'FB Followers', 'FB Target', 'FB %',
    'IG Followers', 'IG Target', 'IG %',
    'YT Subscribers', 'YT Target', 'YT %',
    'Google Reviews', 'Google Rating', 'Google Target', 'Google %',
]);

foreach ($dealerships as $i => $d) {
    fputcsv($out, [
        $i + 1,
        $d['name'],
        $d['total_reach'],
        $d['fb_followers'], $d['fb_target'] ?? 0, percentAchieved((int)$d['fb_followers'], (int)($d['fb_target'] ?? 0)),
        $d['ig_followers'], $d['ig_target'] ?? 0, percentAchieved((int)$d['ig_followers'], (int)($d['ig_target'] ?? 0)),
        $d['yt_subscribers'], $d['yt_target'] ?? 0, percentAchieved((int)$d['yt_subscribers'], (int)($d['yt_target'] ?? 0)),
        $d['google_review_count'], $d['google_rating'], $d['google_review_target'] ?? 0, percentAchieved((int)$d['google_review_count'], (int)($d['google_review_target'] ?? 0)),
    ]);
}

fclose($out);
