<?php
require_once __DIR__ . '/includes/Auth.php';
Auth::requireLogin();
require_once __DIR__ . '/includes/Database.php';

$db = Database::getConnection();
$scopedIds = Auth::dealershipIds();
$isSuperAdmin = Auth::isSuperAdmin();
if (!$isSuperAdmin && empty($scopedIds)) {
    $dealerships = [];
} else {
    $sql = "SELECT id, name, yt_search, yt_channel_id FROM dealerships WHERE yt_search IS NOT NULL AND yt_search != ''"
        . (!$isSuperAdmin ? " AND id IN (" . implode(',', array_fill(0, count($scopedIds), '?')) . ")" : "")
        . " ORDER BY name";
    $stmt = $db->prepare($sql);
    $stmt->execute(!$isSuperAdmin ? $scopedIds : []);
    $dealerships = $stmt->fetchAll();
}

$from = $_GET['from'] ?? date('Y-01-01');
$to = $_GET['to'] ?? date('Y-m-d');

// Month columns for the table
$monthKeys = [];
$cursor = new DateTime(substr($from, 0, 7) . '-01');
$end = new DateTime(substr($to, 0, 7) . '-01');
while ($cursor <= $end) {
    $monthKeys[] = $cursor->format('Y-m');
    $cursor->modify('+1 month');
}

// Cached stats for all dealerships in this range
$cachedMap = [];
if (!empty($dealerships) && !empty($monthKeys)) {
    $ids = array_column($dealerships, 'id');
    $idPlaceholders = implode(',', array_fill(0, count($ids), '?'));
    $monthPlaceholders = implode(',', array_fill(0, count($monthKeys), '?'));
    $stmt = $db->prepare("
        SELECT dealership_id, month, video_count FROM yt_monthly_stats
        WHERE dealership_id IN ($idPlaceholders) AND month IN ($monthPlaceholders)
    ");
    $stmt->execute(array_merge($ids, $monthKeys));
    foreach ($stmt->fetchAll() as $row) {
        $cachedMap[$row['dealership_id']][$row['month']] = (int)$row['video_count'];
    }
}

$totalMonths = count($monthKeys);
$rowPercents = [];
foreach ($dealerships as $d) {
    $cachedCount = count($cachedMap[$d['id']] ?? []);
    $rowPercents[$d['id']] = $totalMonths > 0 ? (int)round($cachedCount / $totalMonths * 100) : 0;
}
$overallPercent = !empty($rowPercents) ? (int)round(array_sum($rowPercents) / count($rowPercents)) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>(function(){var t=localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
<meta charset="UTF-8">
<title>YouTube Monthly Videos</title>
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
      <h1>YouTube Monthly Video Uploads</h1>
      <div class="subtitle">How Many Videos Were Uploaded Each Month — Filter By Date Range</div>
    </div>
  </header>

  <?php if (empty($dealerships)): ?>
    <div class="empty-state">No Dealership Has A YouTube Channel Set.</div>
  <?php else: ?>

  <div class="progress-wrap" id="progress-wrap">
    <div class="progress-bar-track"><div class="progress-bar-fill" id="progress-bar-fill" style="width:<?= $overallPercent ?>%"></div></div>
    <div class="progress-text" id="progress-text"><?= $overallPercent ?>% Complete — <?= count($dealerships) ?> Dealerships</div>
  </div>

  <form method="GET" class="search-panel" style="margin-bottom:24px; display:flex; gap:16px; align-items:flex-end; flex-wrap:wrap;">
    <div class="field" style="margin-bottom:0;">
      <label>From</label>
      <input type="date" name="from" value="<?= htmlspecialchars($from) ?>">
    </div>
    <div class="field" style="margin-bottom:0;">
      <label>To</label>
      <input type="date" name="to" value="<?= htmlspecialchars($to) ?>">
    </div>
    <button type="submit" class="submit" style="width:auto; padding:11px 24px;">Load</button>
  </form>

  <header style="margin-bottom:14px;">
    <div>
      <h1 style="font-size:16px;">All Dealerships — <?= date('M Y', strtotime($from)) ?> To <?= date('M Y', strtotime($to)) ?></h1>
      <div class="subtitle">— Means This Dealership's Data For That Month Hasn't Been Checked Yet</div>
    </div>
    <div class="toolbar">
      <?php if (Auth::can('refresh')): ?>
      <button class="btn primary" id="load-all">Load All (Fetch Missing Data)</button>
      <?php endif; ?>
    </div>
  </header>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Dealership</th>
          <?php foreach ($monthKeys as $m): ?>
            <th><?= date('M Y', strtotime($m . '-01')) ?></th>
          <?php endforeach; ?>
          <th>Total</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($dealerships as $i => $d): ?>
        <tr id="yt-row-<?= $d['id'] ?>">
          <td class="row-number"><?= $i + 1 ?></td>
          <td class="name"><?= htmlspecialchars($d['name']) ?></td>
          <?php $rowTotal = 0; ?>
          <?php foreach ($monthKeys as $m): ?>
            <?php $val = $cachedMap[$d['id']][$m] ?? null; $rowTotal += $val ?? 0; ?>
            <td class="metric yt-color" id="ytcell-<?= $d['id'] ?>-<?= $m ?>"><?= $val === null ? '—' : number_format($val) ?></td>
          <?php endforeach; ?>
          <td class="metric" id="yttotal-<?= $d['id'] ?>"><?= number_format($rowTotal) ?></td>
          <td class="actions-cell">
            <?php if (Auth::can('refresh')): ?>
            <button class="refresh-btn" onclick="checkOne(<?= $d['id'] ?>, this)">Check</button>
            <?php endif; ?>
            <?php $p = $rowPercents[$d['id']]; ?>
            <span class="status-badge <?= $p >= 100 ? 'status-done' : ($p > 0 ? 'status-partial' : 'status-pending') ?>" id="status-<?= $d['id'] ?>"><?= $p ?>%</span>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php endif; ?>

</div>
</main>
</div>

<script>
const monthKeys = <?= json_encode($monthKeys) ?>;
const rangeFrom = <?= json_encode($from) ?>;
const rangeTo = <?= json_encode($to) ?>;

function updateOverallProgress() {
  const badges = document.querySelectorAll('.status-badge');
  let sum = 0;
  badges.forEach(b => sum += parseInt(b.textContent, 10) || 0);
  const total = badges.length;
  const percent = total > 0 ? Math.round(sum / total) : 0;
  document.getElementById('progress-bar-fill').style.width = `${percent}%`;
  document.getElementById('progress-text').textContent = `${percent}% Complete — ${total} Dealerships`;
}

async function loadDealership(id) {
  const res = await fetch(`check_yt_monthly.php?id=${id}&from=${rangeFrom}&to=${rangeTo}`);
  const data = await res.json();
  if (!data.success) return false;

  let total = 0;
  for (const m of monthKeys) {
    const count = data.breakdown[m] ?? 0;
    total += count;
    const cell = document.getElementById(`ytcell-${id}-${m}`);
    if (cell) cell.textContent = count.toLocaleString();
  }
  const totalCell = document.getElementById(`yttotal-${id}`);
  if (totalCell) totalCell.textContent = total.toLocaleString();

  const statusEl = document.getElementById(`status-${id}`);
  if (statusEl) {
    statusEl.textContent = '100%';
    statusEl.classList.remove('status-pending', 'status-partial');
    statusEl.classList.add('status-done');
  }
  updateOverallProgress();
  return true;
}

async function checkOne(id, btnEl) {
  if (btnEl) { btnEl.classList.add('loading'); btnEl.textContent = '...'; }
  await loadDealership(id);
  if (btnEl) { btnEl.classList.remove('loading'); btnEl.textContent = 'Check'; }
}

document.getElementById('load-all')?.addEventListener('click', async function () {
  const rows = document.querySelectorAll('tr[id^="yt-row-"]');
  this.disabled = true;

  let done = 0;
  for (const row of rows) {
    const id = row.id.replace('yt-row-', '');
    this.textContent = `Loading ${done + 1}/${rows.length}...`;
    await loadDealership(id);
    done++;
  }

  this.textContent = 'Load All (Fetch Missing Data)';
  this.disabled = false;
});
</script>
</body>
</html>
