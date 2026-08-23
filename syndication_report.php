<?php
require_once __DIR__ . '/includes/Auth.php';
Auth::requireLogin();
require_once __DIR__ . '/includes/Database.php';

$db = Database::getConnection();
$monthlyThreshold = 12; // posts/month target per dealership
$scopedIds = Auth::dealershipIds();
$isSuperAdmin = Auth::isSuperAdmin();
$scopedNames = [];

if (!$isSuperAdmin && !empty($scopedIds)) {
    $placeholders = implode(',', array_fill(0, count($scopedIds), '?'));
    $stmt = $db->prepare("SELECT name FROM target_pages WHERE dealership_id IN ($placeholders)");
    $stmt->execute($scopedIds);
    $scopedNames = $stmt->fetchAll(PDO::FETCH_COLUMN);
}
$isScopedWithNoAccess = !$isSuperAdmin && empty($scopedNames);

$monthStart = date('Y-m-01 00:00:00');
$namePlaceholders = !empty($scopedNames) ? implode(',', array_fill(0, count($scopedNames), '?')) : '';

$monthlyCounts = [];
if ($isSuperAdmin || !empty($scopedNames)) {
    $monthlyCountsSql = "
        SELECT dealership_name, COUNT(*) AS post_count
        FROM post_log
        WHERE status = 'success' AND posted_at >= ?" . (!$isSuperAdmin ? " AND dealership_name IN ($namePlaceholders)" : "") . "
        GROUP BY dealership_name
        ORDER BY dealership_name
    ";
    $monthlyCountsStmt = $db->prepare($monthlyCountsSql);
    $monthlyCountsStmt->execute(!$isSuperAdmin ? [$monthStart, ...$scopedNames] : [$monthStart]);
    $monthlyCounts = $monthlyCountsStmt->fetchAll();
}

// Include target pages with zero posts this month too.
if (!$isSuperAdmin) {
    $allTargets = array_map(fn($n) => ['name' => $n], $scopedNames);
} else {
    $allTargets = $db->query("SELECT name FROM target_pages ORDER BY name")->fetchAll();
}
$countMap = [];
foreach ($monthlyCounts as $row) {
    $countMap[$row['dealership_name']] = (int)$row['post_count'];
}

$recentLog = [];
if ($isSuperAdmin || !empty($scopedNames)) {
    $recentLogSql = "SELECT * FROM post_log" . (!$isSuperAdmin ? " WHERE dealership_name IN ($namePlaceholders)" : "") . " ORDER BY posted_at DESC LIMIT 50";
    $recentLogStmt = $db->prepare($recentLogSql);
    $recentLogStmt->execute(!$isSuperAdmin ? $scopedNames : []);
    $recentLog = $recentLogStmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>(function(){var t=localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
<meta charset="UTF-8">
<title>Integration Report</title>
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
      <h1>Integration Report</h1>
      <div class="subtitle">Posts Published This Month — Target: <?= $monthlyThreshold ?>/Month Per Dealership</div>
    </div>
  </header>

  <div class="table-wrap" style="margin-bottom:28px;">
    <?php if (empty($allTargets)): ?>
      <div class="empty-state"><?= $isSuperAdmin ? 'No Target Pages Added Yet.' : 'No Target Page Linked To Your Dealership Yet.' ?></div>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Dealership</th>
          <th>Posts This Month</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($allTargets as $i => $t): ?>
        <?php $count = $countMap[$t['name']] ?? 0; ?>
        <tr>
          <td class="row-number"><?= $i + 1 ?></td>
          <td class="name"><?= htmlspecialchars($t['name']) ?></td>
          <td class="metric fb-color"><?= number_format($count) ?></td>
          <td>
            <span class="status-badge <?= $count >= $monthlyThreshold ? 'status-done' : 'status-flag' ?>">
              <?= $count >= $monthlyThreshold ? 'On Target' : 'Below Target' ?>
            </span>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <h2 style="font-size:16px; margin-bottom:14px;">Recent Activity (Last 50)</h2>
  <div class="table-wrap">
    <?php if (empty($recentLog)): ?>
      <div class="empty-state">No Publish Attempts Logged Yet.</div>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Dealership</th>
          <th>Status</th>
          <th>Message</th>
          <th>Error</th>
          <th>Posted At</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($recentLog as $log): ?>
        <tr>
          <td class="name"><?= htmlspecialchars($log['dealership_name']) ?></td>
          <td><span class="status-badge <?= $log['status'] === 'success' ? 'status-done' : 'status-flag' ?>"><?= ucfirst($log['status']) ?></span></td>
          <td class="timestamp" style="max-width:280px; white-space:normal;"><?= htmlspecialchars(mb_substr($log['message'] ?? '', 0, 80)) ?></td>
          <td class="timestamp" style="color:var(--red);"><?= htmlspecialchars($log['error_message'] ?? '') ?></td>
          <td class="timestamp"><?= date('d M, H:i', strtotime($log['posted_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

</div>
</main>
</div>
</body>
</html>
