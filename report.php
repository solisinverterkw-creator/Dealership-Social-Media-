<?php
require_once __DIR__ . '/includes/Auth.php';
Auth::requireLogin();
require_once __DIR__ . '/includes/Database.php';

$db = Database::getConnection();
$scopedIds = Auth::dealershipIds();
$isSuperAdmin = Auth::isSuperAdmin();
$detailId = $_GET['id'] ?? null;
$detail = null;

if ($detailId && Auth::canAccessDealership((int)$detailId)) {
    $stmt = $db->prepare("SELECT * FROM dealerships WHERE id = :id");
    $stmt->execute(['id' => $detailId]);
    $detail = $stmt->fetch();
} elseif ($detailId) {
    http_response_code(403);
    exit('You Do Not Have Access To This Dealership.');
}

if (!$isSuperAdmin) {
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

$totalsQuery = "
    SELECT
        SUM(fb_followers) AS fb_total,
        SUM(ig_followers) AS ig_total,
        SUM(yt_subscribers) AS yt_total,
        SUM(fb_target) AS fb_target_total,
        SUM(ig_target) AS ig_target_total,
        SUM(yt_target) AS yt_target_total,
        SUM(google_review_target) AS gr_target_total,
        AVG(NULLIF(google_rating, 0)) AS avg_rating,
        COUNT(*) AS cnt
    FROM dealerships" . (!$isSuperAdmin ? (empty($scopedIds) ? " WHERE 1=0" : " WHERE id IN (" . implode(',', array_fill(0, count($scopedIds), '?')) . ")") : "");
$totalsStmt = $db->prepare($totalsQuery);
$totalsStmt->execute(!$isSuperAdmin ? $scopedIds : []);
$totals = $totalsStmt->fetch();

/**
 * Percent-achieved badge vs a target. Returns null (no badge) when no target
 * is set, so untargeted dealerships/platforms don't show a misleading 0%.
 */
function targetBadge(int $value, int $target): ?string
{
    if ($target <= 0) {
        return null;
    }
    $percent = (int)round(($value / $target) * 100);
    $bg = $percent >= 100 ? 'rgba(34, 197, 94, 0.18)' : ($percent >= 50 ? 'rgba(245, 158, 11, 0.18)' : 'rgba(239, 68, 68, 0.18)');
    $color = $percent >= 100 ? '#16a34a' : ($percent >= 50 ? '#d97706' : '#dc2626');
    return sprintf('<span style="font-size:11px; padding:2px 7px; border-radius:4px; background:%s; color:%s; font-weight:700; margin-left:6px; display:inline-block;" title="Target: %s">%d%%</span>', $bg, $color, number_format($target), $percent);
}

/**
 * The "Apply To All Dealerships" checkbox on the edit form makes every row's
 * target uniform in the normal case, so the highest set value is a good
 * representative figure to print once under the column header.
 */
function columnTargetLabel(array $dealerships, string $field): string
{
    $max = !empty($dealerships) ? max(array_column($dealerships, $field)) : 0;
    return $max > 0 ? '<div class="th-target">Tgt: ' . number_format($max) . '</div>' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>(function(){var t=localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
<meta charset="UTF-8">
<title>Social Media Report</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#1a1a19">
<link rel="apple-touch-icon" href="assets/icon-192.png">
<script>if('serviceWorker' in navigator){window.addEventListener('load',()=>navigator.serviceWorker.register('sw.js'));}</script>
</head>
<body>
<div class="app-layout">
<?php require __DIR__ . '/includes/Sidebar.php'; ?>
<main class="main-content">
<div class="container">

  <header>
    <div>
      <h1>Dealership Social Media Report</h1>
      <div class="subtitle"><?= $totals['cnt'] ?? 0 ?> Dealerships · Overview And Comparison</div>
    </div>
    <div class="toolbar">
      <a href="export_social_report.php" class="btn primary">Export CSV</a>
    </div>
  </header>

  <?php if ($detail): ?>
  <div class="detail-card" style="padding:24px; margin-bottom:20px; background:var(--panel); border:1px solid var(--border); border-radius:16px;">
    <h2 style="font-size:18px; font-weight:700; margin-top:0; margin-bottom:20px; text-transform:uppercase; color:var(--text);"><?= htmlspecialchars($detail['name']) ?></h2>
    
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap:16px; margin-bottom:24px; padding-bottom:20px; border-bottom:1px solid var(--border);">
      <div><div class="stat-label" style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin-bottom:6px;">FB FOLLOWERS</div><div class="stat-value" style="font-size:18px; font-weight:700; color:var(--fb)"><?= number_format($detail['fb_followers']) ?> <?= targetBadge((int)$detail['fb_followers'], (int)($detail['fb_target'] ?? 0)) ?></div></div>
      <div><div class="stat-label" style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin-bottom:6px;">IG FOLLOWERS</div><div class="stat-value" style="font-size:18px; font-weight:700; color:var(--ig)"><?= number_format($detail['ig_followers']) ?> <?= targetBadge((int)$detail['ig_followers'], (int)($detail['ig_target'] ?? 0)) ?></div></div>
      <div><div class="stat-label" style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin-bottom:6px;">YT SUBSCRIBERS</div><div class="stat-value" style="font-size:18px; font-weight:700; color:var(--yt)"><?= number_format($detail['yt_subscribers']) ?> <?= targetBadge((int)$detail['yt_subscribers'], (int)($detail['yt_target'] ?? 0)) ?></div></div>
      <div><div class="stat-label" style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin-bottom:6px;">YT VIDEOS</div><div class="stat-value" style="font-size:18px; font-weight:700;"><?= number_format($detail['yt_videos']) ?></div></div>
      <div><div class="stat-label" style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin-bottom:6px;">YT TOTAL VIEWS</div><div class="stat-value" style="font-size:18px; font-weight:700;"><?= number_format($detail['yt_views']) ?></div></div>
      <div><div class="stat-label" style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin-bottom:6px;">GOOGLE REVIEWS</div><div class="stat-value" style="font-size:18px; font-weight:700; color:var(--gr)"><?= number_format($detail['google_review_count']) ?> <?= targetBadge((int)$detail['google_review_count'], (int)($detail['google_review_target'] ?? 0)) ?></div></div>
      <div><div class="stat-label" style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin-bottom:6px;">GOOGLE RATING</div><div class="stat-value" style="font-size:18px; font-weight:700;"><?= $detail['google_rating'] ?>★</div></div>
    </div>

    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap:16px;">
      <div><div class="stat-label" style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin-bottom:6px;">FB POSTS (LAST CHECK)</div><div class="stat-value" style="font-size:15px; font-weight:700;"><?= number_format($detail['fb_posts_week']) ?>/week</div></div>
      <div><div class="stat-label" style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin-bottom:6px;">IG POSTS (LAST CHECK)</div><div class="stat-value" style="font-size:15px; font-weight:700;"><?= number_format($detail['ig_posts_week']) ?>/week</div></div>
      <div><div class="stat-label" style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin-bottom:6px;">YT VIDEOS (LAST CHECK)</div><div class="stat-value" style="font-size:15px; font-weight:700;"><?= number_format($detail['yt_videos_month']) ?>/month</div></div>
      <div><div class="stat-label" style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin-bottom:6px;">LAST REFRESHED</div><div class="stat-value" style="font-size:14px; font-weight:600;"><?= $detail['last_refreshed'] ? date('d M, H:i', strtotime($detail['last_refreshed'])) : 'never' ?></div></div>
    </div>
  </div>
  <div class="subtitle" style="margin-bottom:24px;"><a href="report.php" style="color:var(--accent); font-weight:600; text-decoration:none;">← Back To Full Report</a></div>
  <?php else: ?>

  <div class="stat-cards">
    <div class="stat-card"><div class="stat-label">Total FB Followers</div><div class="stat-value" style="color:var(--fb)"><?= number_format($totals['fb_total'] ?? 0) ?> <?= targetBadge((int)($totals['fb_total'] ?? 0), (int)($totals['fb_target_total'] ?? 0)) ?></div></div>
    <div class="stat-card"><div class="stat-label">Total IG Followers</div><div class="stat-value" style="color:var(--ig)"><?= number_format($totals['ig_total'] ?? 0) ?> <?= targetBadge((int)($totals['ig_total'] ?? 0), (int)($totals['ig_target_total'] ?? 0)) ?></div></div>
    <div class="stat-card"><div class="stat-label">Total YT Subscribers</div><div class="stat-value" style="color:var(--yt)"><?= number_format($totals['yt_total'] ?? 0) ?> <?= targetBadge((int)($totals['yt_total'] ?? 0), (int)($totals['yt_target_total'] ?? 0)) ?></div></div>
    <div class="stat-card"><div class="stat-label">Average Google Rating</div><div class="stat-value"><?= $totals['avg_rating'] ? number_format($totals['avg_rating'], 1) : '—' ?>★</div></div>
  </div>

  <div class="table-wrap">
    <?php if (empty($dealerships)): ?>
      <div class="empty-state">No Dealerships Added Yet.</div>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Rank</th>
          <th>Dealership</th>
          <th>Total Reach (FB+IG+YT)</th>
          <th class="th-center">FB<?= columnTargetLabel($dealerships, 'fb_target') ?></th>
          <th class="th-center">IG<?= columnTargetLabel($dealerships, 'ig_target') ?></th>
          <th class="th-center">YT<?= columnTargetLabel($dealerships, 'yt_target') ?></th>
          <th class="th-center">Google<?= columnTargetLabel($dealerships, 'google_review_target') ?></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($dealerships as $i => $d): ?>
        <tr>
          <td><span class="rank-badge">#<?= $i + 1 ?></span></td>
          <td class="name"><?= htmlspecialchars($d['name']) ?></td>
          <td class="metric"><?= number_format($d['total_reach']) ?></td>
          <td class="metric fb-color"><?= number_format($d['fb_followers']) ?> <?= targetBadge((int)$d['fb_followers'], (int)($d['fb_target'] ?? 0)) ?></td>
          <td class="metric ig-color"><?= number_format($d['ig_followers']) ?> <?= targetBadge((int)$d['ig_followers'], (int)($d['ig_target'] ?? 0)) ?></td>
          <td class="metric yt-color"><?= number_format($d['yt_subscribers']) ?> <?= targetBadge((int)$d['yt_subscribers'], (int)($d['yt_target'] ?? 0)) ?></td>
          <td class="metric gr-color"><?= number_format($d['google_review_count']) ?> (<?= $d['google_rating'] ?>★) <?= targetBadge((int)$d['google_review_count'], (int)($d['google_review_target'] ?? 0)) ?></td>
          <td><a class="edit-btn" href="report.php?id=<?= $d['id'] ?>">View Detail</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div>
</main>
</div>
</body>
</html>
