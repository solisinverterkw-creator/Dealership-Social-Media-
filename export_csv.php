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
        $stmt = $db->prepare("SELECT * FROM dealerships WHERE id IN ($placeholders) ORDER BY name");
        $stmt->execute($scopedIds);
        $dealerships = $stmt->fetchAll();
    }
} else {
    $dealerships = $db->query("SELECT * FROM dealerships ORDER BY name")->fetchAll();
}

/** Plain numeric percent-achieved, blank when no target is set. */
function percentAchieved(int $value, int $target)
{
    return $target > 0 ? round(($value / $target) * 100) . '%' : '';
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=dealerships_' . date('Y-m-d') . '.csv');

$out = fopen('php://output', 'w');
fputs($out, "\xEF\xBB\xBF");

fputcsv($out, [
    'Dealership',
    'FB Followers', 'FB Target', 'FB %',
    'IG Followers', 'IG Target', 'IG %',
    'YT Subscribers', 'YT Target', 'YT %',
    'YT Videos', 'YT Views',
    'Google Review Count', 'Google Rating', 'Google Target', 'Google %',
    'Last Refreshed',
]);

foreach ($dealerships as $d) {
    fputcsv($out, [
        $d['name'],
        $d['fb_followers'], $d['fb_target'] ?? 0, percentAchieved((int)$d['fb_followers'], (int)($d['fb_target'] ?? 0)),
        $d['ig_followers'], $d['ig_target'] ?? 0, percentAchieved((int)$d['ig_followers'], (int)($d['ig_target'] ?? 0)),
        $d['yt_subscribers'], $d['yt_target'] ?? 0, percentAchieved((int)$d['yt_subscribers'], (int)($d['yt_target'] ?? 0)),
        $d['yt_videos'],
        $d['yt_views'],
        $d['google_review_count'], $d['google_rating'], $d['google_review_target'] ?? 0, percentAchieved((int)$d['google_review_count'], (int)($d['google_review_target'] ?? 0)),
        $d['last_refreshed'] ?? 'never',
    ]);
}

fclose($out);
