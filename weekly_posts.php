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

$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$hasRange = $from !== '' && $to !== '';

function rowPercent(array $d): ?int
{
    $applicable = (!empty($d['fb_input']) ? 1 : 0) + (!empty($d['ig_search']) ? 1 : 0);
    if ($applicable === 0) return null;
    $done = (!empty($d['fb_input']) && !empty($d['fb_posts_checked_at']) ? 1 : 0)
          + (!empty($d['ig_search']) && !empty($d['ig_posts_checked_at']) ? 1 : 0);
    return (int)round($done / $applicable * 100);
}

$rowPercents = array_filter(array_map('rowPercent', $dealerships), fn($p) => $p !== null);
$totalCheckable = count($rowPercents);
$overallPercent = $totalCheckable > 0 ? (int)round(array_sum($rowPercents) / $totalCheckable) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>(function(){var t=localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
<meta charset="UTF-8">
<title>Posting Activity Report</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#1a1a19">
<link rel="apple-touch-icon" href="assets/icon-192.png">
<script>if('serviceWorker' in navigator){window.addEventListener('load',()=>navigator.serviceWorker.register('sw.js'));}</script>
<script src="assets/progress-timer.js"></script>
</head>
<body>
<div class="app-layout">
<?php require __DIR__ . '/includes/Sidebar.php'; ?>
<main class="main-content">
<div class="container">

  <header>
    <div>
      <h1>Posting Activity Report</h1>
      <div class="subtitle">Facebook + Instagram Posts — By Date Range</div>
    </div>
    <div class="toolbar">
      <?php if (Auth::can('refresh')): ?>
      <button class="btn primary" id="check-all" <?= $hasRange ? '' : 'disabled title="Select A From And To Date, Then Click Load, First"' ?>>Check All</button>
      <?php endif; ?>
    </div>
  </header>

  <div class="progress-wrap" id="progress-wrap">
    <div class="progress-bar-track"><div class="progress-bar-fill" id="progress-bar-fill" style="width:<?= $overallPercent ?>%"></div></div>
    <div class="progress-text" id="progress-text"><?= $overallPercent ?>% Complete — <?= $totalCheckable ?> Dealerships</div>
  </div>

  <form method="GET" class="search-panel" style="margin-bottom:24px; display:flex; gap:16px; align-items:flex-end; flex-wrap:wrap;">
    <div class="field" style="margin-bottom:0;">
      <label>FB/IG From</label>
      <input type="date" name="from" value="<?= htmlspecialchars($from) ?>">
    </div>
    <div class="field" style="margin-bottom:0;">
      <label>FB/IG To</label>
      <input type="date" name="to" value="<?= htmlspecialchars($to) ?>">
    </div>
    <button type="submit" class="submit" style="width:auto; padding:11px 24px;">Load</button>
  </form>

  <?php if (!$hasRange): ?>
    <div class="empty-state" style="margin-bottom:24px;">Select A From And To Date, Then Click "Load", To Enable Checking.</div>
  <?php endif; ?>

  <div class="table-wrap">
    <?php if (empty($dealerships)): ?>
      <div class="empty-state">No Dealerships Added Yet.</div>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Dealership</th>
          <th>FB Posts</th>
          <th>IG Posts</th>
          <th>Last Checked</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($dealerships as $i => $d): ?>
        <tr id="row-<?= $d['id'] ?>">
          <td class="row-number"><?= $i + 1 ?></td>
          <td class="name"><?= htmlspecialchars($d['name']) ?></td>
          <td class="metric fb-color" id="fbposts-<?= $d['id'] ?>"><?= number_format($d['fb_posts_week']) ?></td>
          <td class="metric ig-color" id="igposts-<?= $d['id'] ?>"><?= number_format($d['ig_posts_week']) ?></td>
          <td class="timestamp" id="ts-<?= $d['id'] ?>">
            <?php
              $dates = array_filter([$d['fb_posts_checked_at'], $d['ig_posts_checked_at']]);
              $latest = !empty($dates) ? max($dates) : null;
              echo $latest ? date('d M, H:i', strtotime($latest)) : 'never';
            ?>
          </td>
          <td class="actions-cell">
            <?php if (Auth::can('refresh')): ?>
            <button class="refresh-btn"
                    data-has-fb="<?= !empty($d['fb_input']) ? '1' : '' ?>"
                    data-has-ig="<?= !empty($d['ig_search']) ? '1' : '' ?>"
                    <?= $hasRange ? '' : 'disabled title="Select A From And To Date, Then Click Load, First"' ?>
                    onclick="checkRow(<?= $d['id'] ?>, this, this.dataset.hasFb === '1', this.dataset.hasIg === '1')">Check</button>
            <?php endif; ?>
            <?php $p = rowPercent($d); ?>
            <?php if ($p !== null): ?>
              <?php $applicableCount = (!empty($d['fb_input']) ? 1 : 0) + (!empty($d['ig_search']) ? 1 : 0); ?>
              <span class="status-badge <?= $p >= 100 ? 'status-done' : ($p > 0 ? 'status-partial' : 'status-pending') ?>"
                    id="status-<?= $d['id'] ?>"
                    data-applicable="<?= $applicableCount ?>"
                    data-done="<?= (int)round($p * $applicableCount / 100) ?>"><?= $p ?>%</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

</div>
</main>
</div>

<script>
const rangeFrom = <?= json_encode($from) ?>;
const rangeTo = <?= json_encode($to) ?>;

function setRowPercent(id, done, applicable) {
  const statusEl = document.getElementById(`status-${id}`);
  if (!statusEl) return;
  const percent = applicable > 0 ? Math.round((done / applicable) * 100) : 0;
  statusEl.textContent = `${percent}%`;
  statusEl.dataset.done = done;
  statusEl.classList.remove('status-done', 'status-partial', 'status-pending');
  statusEl.classList.add(percent >= 100 ? 'status-done' : (percent > 0 ? 'status-partial' : 'status-pending'));
}

function updateOverallProgress() {
  const badges = document.querySelectorAll('.status-badge');
  let sum = 0;
  badges.forEach(b => {
    const applicable = parseInt(b.dataset.applicable, 10) || 0;
    const done = parseInt(b.dataset.done, 10) || 0;
    sum += applicable > 0 ? (done / applicable) * 100 : 0;
  });
  const total = badges.length;
  const percent = total > 0 ? Math.round(sum / total) : 0;
  document.getElementById('progress-bar-fill').style.width = `${percent}%`;
  document.getElementById('progress-text').textContent = `${percent}% Complete — ${total} Dealerships`;
}

async function checkRow(id, btnEl, hasFb, hasIg) {
  if (!rangeFrom || !rangeTo) {
    alert('Select A From And To Date, Then Click Load, Before Checking.');
    return;
  }
  if (btnEl) { btnEl.classList.add('loading'); }
  const errors = [];
  const applicable = (hasFb ? 1 : 0) + (hasIg ? 1 : 0);
  let done = 0;

  try {
    if (hasFb) {
      const timer = btnEl ? startElapsedTimer(btnEl, 'FB') : null;
      const r = await fetch(`check_fb_posts.php?id=${id}&from=${rangeFrom}&to=${rangeTo}`);
      const d = await r.json();
      if (timer) stopElapsedTimer(timer);
      if (d.success) document.getElementById(`fbposts-${id}`).textContent = d.fb_posts_week.toLocaleString();
      else errors.push('FB: ' + d.message);
      done++;
      setRowPercent(id, done, applicable);
      updateOverallProgress();
    }
    if (hasIg) {
      if (btnEl) btnEl.textContent = `${Math.round((done / applicable) * 100)}%`;
      const r = await fetch(`check_ig_posts.php?id=${id}&from=${rangeFrom}&to=${rangeTo}`);
      const d = await r.json();
      if (d.success) document.getElementById(`igposts-${id}`).textContent = d.ig_posts_week.toLocaleString();
      else errors.push('IG: ' + d.message);
      done++;
      setRowPercent(id, done, applicable);
      updateOverallProgress();
    }

    const tsEl = document.getElementById(`ts-${id}`);
    if (errors.length) {
      tsEl.innerHTML = `just now <span class="error-text" title="${errors.join(' | ').replace(/"/g, '&quot;')}">⚠ error</span>`;
    } else {
      tsEl.textContent = 'just now';
    }
  } catch (e) {
    console.error(e);
  } finally {
    if (btnEl) { btnEl.classList.remove('loading'); btnEl.textContent = 'Check'; }
  }
}

document.getElementById('check-all')?.addEventListener('click', async function () {
  const rows = document.querySelectorAll('tr[id^="row-"]');
  this.disabled = true;

  let done = 0;
  for (const row of rows) {
    const id = row.id.replace('row-', '');
    this.textContent = `Checking ${done + 1}/${rows.length}...`;

    const btn = row.querySelector('.refresh-btn');
    if (btn) {
      await checkRow(id, btn, btn.dataset.hasFb === '1', btn.dataset.hasIg === '1');
    }
    done++;
  }

  this.textContent = 'Check All';
  this.disabled = false;
});
</script>
</body>
</html>
