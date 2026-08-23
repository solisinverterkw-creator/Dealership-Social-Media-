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
        $stmt = $db->prepare("SELECT id, name, fb_posts_week FROM dealerships WHERE id IN ($placeholders) ORDER BY name");
        $stmt->execute($scopedIds);
        $dealerships = $stmt->fetchAll();
    }
} else {
    $dealerships = $db->query("SELECT id, name, fb_posts_week FROM dealerships ORDER BY name")->fetchAll();
}

$monthStart = date('Y-m-01 00:00:00');
$targetPagesStmt = $db->query("SELECT id, name, dealership_id, is_active FROM target_pages WHERE dealership_id IS NOT NULL");
$targetByDealership = [];
foreach ($targetPagesStmt->fetchAll() as $tp) {
    $targetByDealership[$tp['dealership_id']] = $tp;
}

$reshareCountStmt = $db->prepare("SELECT COUNT(*) FROM post_log WHERE dealership_name = :name AND status = 'success' AND posted_at >= :month_start");

$rows = [];
foreach ($dealerships as $d) {
    $ownPosts = (int)($d['fb_posts_week'] ?? 0);
    $target = $targetByDealership[$d['id']] ?? null;
    $reshareCount = null;
    $reshareNote = 'Not Linked To Content Syndication';

    if ($target) {
        if ($target['is_active']) {
            $reshareCountStmt->execute(['name' => $target['name'], 'month_start' => $monthStart]);
            $reshareCount = (int)$reshareCountStmt->fetchColumn();
            $reshareNote = 'Direct (Accurate Count)';
        } else {
            $reshareNote = 'Via Zapier (Per-Page Count Not Available)';
        }
    }

    $rows[] = [
        'name' => $d['name'],
        'own_posts' => $ownPosts,
        'reshare_count' => $reshareCount,
        'reshare_note' => $reshareNote,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>(function(){var t=localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
<meta charset="UTF-8">
<title>Post Breakdown Report</title>
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
      <h1>Post Breakdown Report</h1>
      <div class="subtitle">Own (Organic) Posts vs Reshared From Suzuki Pakistan — This Month</div>
    </div>
  </header>

  <div class="table-wrap">
    <?php if (empty($rows)): ?>
      <div class="empty-state">No Dealerships Added Yet.</div>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Dealership</th>
          <th>Own Posts (This Week)</th>
          <th>Reshared This Month</th>
          <th>Notes</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $i => $r): ?>
        <tr>
          <td class="row-number"><?= $i + 1 ?></td>
          <td class="name"><?= htmlspecialchars($r['name']) ?></td>
          <td class="metric fb-color"><?= number_format($r['own_posts']) ?></td>
          <td class="metric" style="color:var(--accent)">
            <?= $r['reshare_count'] !== null ? number_format($r['reshare_count']) : '—' ?>
          </td>
          <td class="timestamp"><?= htmlspecialchars($r['reshare_note']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <div class="subtitle" style="margin-top:16px; line-height:1.6;">
    <strong>Own Posts</strong> = dealership's own organic Facebook posts this week (reshared content already excluded — see Posting Activity Report).<br>
    <strong>Reshared This Month</strong> = posts auto-published from Suzuki Pakistan via Content Syndication.<br>
    <strong>Direct</strong> pages (linked via Target Pages, published straight through our own Graph API) show an exact count.<br>
    <strong>Zapier</strong> pages show "—" because Zapier does not report back which specific page received each post — only that the reshare was sent successfully to all connected pages.
  </div>

</div>
</main>
</div>
</body>
</html>
