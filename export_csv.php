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

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=dealerships_' . date('Y-m-d') . '.csv');

$out = fopen('php://output', 'w');
fputs($out, "\xEF\xBB\xBF");

fputcsv($out, [
    'Dealership', 'FB Followers', 'IG Followers', 'YT Subscribers',
    'YT Videos', 'YT Views', 'Google Review Count', 'Google Rating', 'Last Refreshed',
]);

foreach ($dealerships as $d) {
    fputcsv($out, [
        $d['name'],
        $d['fb_followers'],
        $d['ig_followers'],
        $d['yt_subscribers'],
        $d['yt_videos'],
        $d['yt_views'],
        $d['google_review_count'],
        $d['google_rating'],
        $d['last_refreshed'] ?? 'never',
    ]);
}

fclose($out);
