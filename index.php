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

function dealershipPercent(array $d): int
{
    $applicable = (!empty($d['fb_input']) ? 1 : 0) + (!empty($d['ig_search']) ? 1 : 0)
                + (!empty($d['yt_search']) ? 1 : 0) + (!empty($d['google_search']) ? 1 : 0);
    if ($applicable === 0) return 0;
    // There's no separate "checked" timestamp per platform — last_refreshed being
    // set means all applicable platforms were fetched together last time.
    return !empty($d['last_refreshed']) ? 100 : 0;
}

$rowPercents = [];
foreach ($dealerships as $d) {
    $rowPercents[$d['id']] = dealershipPercent($d);
}
$overallPercent = !empty($rowPercents) ? (int)round(array_sum($rowPercents) / count($rowPercents)) : 0;

// --- KPI totals for the icon stat cards ---
$totalFbFollowers = array_sum(array_column($dealerships, 'fb_followers'));
$totalIgFollowers = array_sum(array_column($dealerships, 'ig_followers'));
$totalYtSubscribers = array_sum(array_column($dealerships, 'yt_subscribers'));
$totalGoogleReviews = array_sum(array_column($dealerships, 'google_review_count'));

// --- Chart 1 data: YouTube videos per month, last 6 months ---
$monthKeys = [];
$cursor = new DateTime(date('Y-m-01', strtotime('-5 months')));
$end = new DateTime(date('Y-m-01'));
while ($cursor <= $end) {
    $monthKeys[] = $cursor->format('Y-m');
    $cursor->modify('+1 month');
}
$ytRows = [];
if (!Auth::isSuperAdmin()) {
    if (!empty($scopedIds)) {
        $placeholders = implode(',', array_fill(0, count($scopedIds), '?'));
        $ytStmt = $db->prepare("SELECT month, SUM(video_count) AS total FROM yt_monthly_stats WHERE dealership_id IN ($placeholders) AND month >= ? GROUP BY month");
        $ytStmt->execute([...$scopedIds, $monthKeys[0]]);
        $ytRows = $ytStmt->fetchAll();
    }
} else {
    $ytStmt = $db->prepare("SELECT month, SUM(video_count) AS total FROM yt_monthly_stats WHERE month >= :since GROUP BY month");
    $ytStmt->execute(['since' => $monthKeys[0]]);
    $ytRows = $ytStmt->fetchAll();
}
$ytMap = [];
foreach ($ytRows as $row) {
    $ytMap[$row['month']] = (int)$row['total'];
}
$ytChartData = [];
foreach ($monthKeys as $mk) {
    $ytChartData[$mk] = $ytMap[$mk] ?? 0;
}

// --- Chart 2 data: FB vs IG posts this week, from already-loaded $dealerships ---
$fbPostsTotal = array_sum(array_column($dealerships, 'fb_posts_week'));
$igPostsTotal = array_sum(array_column($dealerships, 'ig_posts_week'));

/**
 * Single-series bar chart: thin bars, 4px rounded data-ends, direct value
 * labels, a recessive baseline — no axis clutter, per the dataviz mark spec.
 */
function renderMonthlyBarChart(array $data, string $color): string
{
    $width = 340; $height = 160;
    $marginBottom = 22; $marginTop = 24;
    $chartHeight = $height - $marginBottom - $marginTop;
    $count = count($data);
    if ($count === 0) {
        return '<div class="empty-state">No Data Yet.</div>';
    }
    $maxVal = max(1, max($data));
    $gap = 12;
    $barWidth = max(10, ($width - ($gap * ($count + 1))) / $count);

    $bars = '';
    $i = 0;
    foreach ($data as $label => $value) {
        $x = $gap + $i * ($barWidth + $gap);
        $barHeight = ($value / $maxVal) * $chartHeight;
        $y = $marginTop + $chartHeight - $barHeight;
        $labelShort = date('M', strtotime($label . '-01'));
        $bars .= sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="4" fill="%s"/>', $x, $y, $barWidth, max(2, $barHeight), $color);
        $bars .= sprintf('<text x="%.1f" y="%.1f" font-size="10" text-anchor="middle" class="chart-value-label">%d</text>', $x + $barWidth / 2, $y - 6, $value);
        $bars .= sprintf('<text x="%.1f" y="%.1f" font-size="10" text-anchor="middle">%s</text>', $x + $barWidth / 2, $height - 6, htmlspecialchars($labelShort));
        $i++;
    }
    $baseline = $marginTop + $chartHeight;
    return sprintf(
        '<svg viewBox="0 0 %d %d" width="100%%" style="max-width:%dpx; height:auto; display:block;">
            <line x1="0" y1="%.1f" x2="%d" y2="%.1f" stroke="var(--border)" stroke-width="1"/>
            %s
        </svg>',
        $width, $height, $width, $baseline, $width, $baseline, $bars
    );
}

/**
 * Two-series categorical comparison bar chart. Colors carry identity (fixed
 * hues from the app's existing validated palette), backed up by direct
 * category labels + a legend, so identity is never color-alone.
 */
function renderComparisonBarChart(array $series): string
{
    $width = 220; $height = 160;
    $marginBottom = 22; $marginTop = 24;
    $chartHeight = $height - $marginBottom - $marginTop;
    $maxVal = max(1, max(array_column($series, 'value')));
    $gap = 30;
    $barWidth = 56;

    $bars = '';
    $i = 0;
    foreach ($series as $label => $info) {
        $x = $gap + $i * ($barWidth + $gap);
        $barHeight = ($info['value'] / $maxVal) * $chartHeight;
        $y = $marginTop + $chartHeight - $barHeight;
        $bars .= sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="4" fill="%s"/>', $x, $y, $barWidth, max(2, $barHeight), $info['color']);
        $bars .= sprintf('<text x="%.1f" y="%.1f" font-size="12" text-anchor="middle" class="chart-value-label">%d</text>', $x + $barWidth / 2, $y - 8, $info['value']);
        $bars .= sprintf('<text x="%.1f" y="%.1f" font-size="10" text-anchor="middle">%s</text>', $x + $barWidth / 2, $height - 6, htmlspecialchars($label));
        $i++;
    }
    $baseline = $marginTop + $chartHeight;
    return sprintf(
        '<svg viewBox="0 0 %d %d" width="100%%" style="max-width:%dpx; height:auto; display:block;">
            <line x1="0" y1="%.1f" x2="%d" y2="%.1f" stroke="var(--border)" stroke-width="1"/>
            %s
        </svg>',
        $width, $height, $width, $baseline, $width, $baseline, $bars
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>(function(){var t=localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
<meta charset="UTF-8">
<title>ROSP - Dealers Social Media</title>
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
      <h1 class="brand-h1">ROSP - Dealers Social Media</h1>
      <div class="subtitle"><?= count($dealerships) ?> Dealerships · Facebook, Instagram, YouTube, Google Reviews</div>
    </div>
    <div class="toolbar">
      <?php if (Auth::isSuperAdmin()): ?>
      <a href="add_dealership.php" class="btn">+ Add Dealership</a>
      <?php endif; ?>
      <a href="export_csv.php" class="btn">Export CSV</a>
      <?php if (Auth::can('refresh')): ?>
      <button class="btn primary" id="refresh-all">Refresh All (FB+IG+YT+Google)</button>
      <?php endif; ?>
      <a href="logout.php" class="btn">Logout</a>
    </div>
  </header>

  <div class="progress-wrap" id="progress-wrap">
    <div class="progress-bar-track"><div class="progress-bar-fill" id="progress-bar-fill" style="width:<?= $overallPercent ?>%"></div></div>
    <div class="progress-text" id="progress-text"><?= $overallPercent ?>% Complete — <?= count($dealerships) ?> Dealerships</div>
  </div>

  <div class="feature-cards">
    <a href="report.php" class="feature-card">
      <div class="feature-card-title" style="color:var(--fb)">Social Media Report</div>
      <div class="feature-card-desc">Compare All Dealerships — Rankings, Totals & Detail View</div>
    </a>
    <a href="weekly_posts.php" class="feature-card">
      <div class="feature-card-title" style="color:var(--ig)">Posting Activity</div>
      <div class="feature-card-desc">Facebook & Instagram Post Counts By Date Range</div>
    </a>
    <a href="yt_monthly.php" class="feature-card">
      <div class="feature-card-title" style="color:var(--yt)">YT Monthly Videos</div>
      <div class="feature-card-desc">YouTube Uploads Per Month, Across All Dealerships</div>
    </a>
    <?php if (Auth::isSuperAdmin()): ?>
    <a href="users.php" class="feature-card">
      <div class="feature-card-title" style="color:var(--amber)">User Management</div>
      <div class="feature-card-desc">Add Or Remove Dashboard Users</div>
    </a>
    <?php endif; ?>
  </div>

  <div class="stat-cards">
    <div class="stat-card-icon">
      <div class="badge" style="background:rgba(57,135,229,0.15); color:var(--fb);">📘</div>
      <div class="stat-value" style="color:var(--fb)"><?= number_format($totalFbFollowers) ?></div>
      <div class="stat-label">Total FB Followers</div>
      <a href="report.php" class="stat-link" style="color:var(--fb)">View All →</a>
    </div>
    <div class="stat-card-icon">
      <div class="badge" style="background:rgba(213,81,129,0.15); color:var(--ig);">📷</div>
      <div class="stat-value" style="color:var(--ig)"><?= number_format($totalIgFollowers) ?></div>
      <div class="stat-label">Total IG Followers</div>
      <a href="report.php" class="stat-link" style="color:var(--ig)">View All →</a>
    </div>
    <div class="stat-card-icon">
      <div class="badge" style="background:rgba(230,103,103,0.15); color:var(--yt);">▶️</div>
      <div class="stat-value" style="color:var(--yt)"><?= number_format($totalYtSubscribers) ?></div>
      <div class="stat-label">Total YT Subscribers</div>
      <a href="yt_monthly.php" class="stat-link" style="color:var(--yt)">View All →</a>
    </div>
    <div class="stat-card-icon">
      <div class="badge" style="background:rgba(25,158,112,0.15); color:var(--gr);">⭐</div>
      <div class="stat-value" style="color:var(--gr)"><?= number_format($totalGoogleReviews) ?></div>
      <div class="stat-label">Total Google Reviews</div>
      <a href="report.php" class="stat-link" style="color:var(--gr)">View All →</a>
    </div>
  </div>

  <div class="chart-cards">
    <div class="chart-card">
      <h3>YouTube Videos Per Month</h3>
      <div class="chart-subtitle">Last 6 Months<?= Auth::isSuperAdmin() ? ' — All Dealerships' : '' ?></div>
      <?= renderMonthlyBarChart($ytChartData, 'var(--yt)') ?>
    </div>
    <div class="chart-card">
      <h3>FB vs IG Posts This Week</h3>
      <div class="chart-subtitle">Last Checked Values<?= Auth::isSuperAdmin() ? ' — All Dealerships' : '' ?></div>
      <?= renderComparisonBarChart([
        'FB' => ['value' => $fbPostsTotal, 'color' => 'var(--fb)'],
        'IG' => ['value' => $igPostsTotal, 'color' => 'var(--ig)'],
      ]) ?>
      <div class="chart-legend">
        <span class="legend-item"><span class="legend-dot" style="background:var(--fb)"></span>Facebook</span>
        <span class="legend-item"><span class="legend-dot" style="background:var(--ig)"></span>Instagram</span>
      </div>
    </div>
  </div>

  <?php if (!empty($dealerships)): ?>
  <div class="search-bar">
    <input type="text" id="search-input" placeholder="Search Dealerships By Name..." oninput="filterRows(this.value)">
    <span id="search-count" class="search-count"></span>
  </div>
  <?php endif; ?>

  <div class="table-wrap">
    <?php if (empty($dealerships)): ?>
      <div class="empty-state">No Dealerships Added Yet. Click "+ Add Dealership" To Get Started.</div>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Dealership</th>
          <th>FB Followers</th>
          <th>IG Followers</th>
          <th>YT Subscribers</th>
          <th>Google Reviews</th>
          <th>Last Refreshed</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($dealerships as $i => $d): ?>
        <tr id="row-<?= $d['id'] ?>">
          <td class="row-number"><?= $i + 1 ?></td>
          <td class="name"><?= htmlspecialchars($d['name']) ?></td>
          <td class="metric fb-color" id="fb-<?= $d['id'] ?>"><?= number_format($d['fb_followers']) ?></td>
          <td class="metric ig-color" id="ig-<?= $d['id'] ?>"><?= number_format($d['ig_followers']) ?></td>
          <td class="metric yt-color" id="yt-<?= $d['id'] ?>"><?= number_format($d['yt_subscribers']) ?></td>
          <td class="metric gr-color" id="gr-<?= $d['id'] ?>">
            <?= number_format($d['google_review_count']) ?> <span style="color:var(--muted)">(<?= $d['google_rating'] ?>★)</span>
          </td>
          <td class="timestamp" id="ts-<?= $d['id'] ?>"><?= $d['last_refreshed'] ? date('d M, H:i', strtotime($d['last_refreshed'])) : 'never' ?></td>
          <td class="actions-cell">
            <?php if (Auth::can('refresh')): ?>
            <button class="refresh-btn"
                    data-has-fb="<?= !empty($d['fb_input']) ? '1' : '' ?>"
                    data-has-ig="<?= !empty($d['ig_search']) ? '1' : '' ?>"
                    data-has-yt="<?= !empty($d['yt_search']) ? '1' : '' ?>"
                    data-has-gr="<?= !empty($d['google_search']) ? '1' : '' ?>"
                    onclick="refreshOne(<?= $d['id'] ?>, this)">Refresh</button>
            <?php endif; ?>
            <?php $p = $rowPercents[$d['id']]; ?>
            <span class="status-badge <?= $p >= 100 ? 'status-done' : ($p > 0 ? 'status-partial' : 'status-pending') ?>" id="status-<?= $d['id'] ?>"><?= $p ?>%</span>
            <?php if (Auth::can('edit')): ?>
            <a class="edit-btn" href="edit_dealership.php?id=<?= $d['id'] ?>">Edit</a>
            <?php endif; ?>
            <?php if (Auth::can('delete')): ?>
            <button class="delete-row-btn" onclick="deleteDealership(<?= $d['id'] ?>, <?= htmlspecialchars(json_encode($d['name'])) ?>, this)">Delete</button>
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
function setRowPercent(id, done, applicable) {
  const statusEl = document.getElementById(`status-${id}`);
  if (!statusEl) return;
  const percent = applicable > 0 ? Math.round((done / applicable) * 100) : 0;
  statusEl.textContent = `${percent}%`;
  statusEl.classList.remove('status-done', 'status-partial', 'status-pending');
  statusEl.classList.add(percent >= 100 ? 'status-done' : (percent > 0 ? 'status-partial' : 'status-pending'));
}

function updateOverallProgress() {
  const badges = document.querySelectorAll('.status-badge');
  let sum = 0;
  badges.forEach(b => sum += parseInt(b.textContent, 10) || 0);
  const total = badges.length;
  const percent = total > 0 ? Math.round(sum / total) : 0;
  document.getElementById('progress-bar-fill').style.width = `${percent}%`;
  document.getElementById('progress-text').textContent = `${percent}% Complete — ${total} Dealerships`;
}

// refresh_fb.php/refresh_ig.php respond immediately with {status:'started'}
// (Bright Data can take several minutes, past LiteSpeed's own connection
// timeout) and keep working server-side — poll refresh_status.php until the
// background job finishes, shaping the result like the old synchronous
// {success, message, ...} response so callers don't need to change.
async function pollRefreshStatus(id, metric, maxWaitMs = 300000, intervalMs = 3000) {
  const start = Date.now();
  while (Date.now() - start < maxWaitMs) {
    await new Promise(resolve => setTimeout(resolve, intervalMs));
    const r = await fetch(`refresh_status.php?id=${id}&metric=${metric}`);
    const d = await r.json();
    if (d.status === 'done') return { success: true, ...d };
    if (d.status === 'error') return { success: false, ...d };
  }
  return { success: false, message: 'Timed Out Waiting For Background Refresh.' };
}

async function refreshOne(id, btnEl) {
  const hasFb = btnEl?.dataset.hasFb === '1';
  const hasIg = btnEl?.dataset.hasIg === '1';
  const hasYt = btnEl?.dataset.hasYt === '1';
  const hasGr = btnEl?.dataset.hasGr === '1';
  const applicable = (hasFb ? 1 : 0) + (hasIg ? 1 : 0) + (hasYt ? 1 : 0) + (hasGr ? 1 : 0);
  const errors = [];
  let done = 0;

  if (btnEl) { btnEl.classList.add('loading'); btnEl.textContent = '...'; }

  try {
    if (hasFb) {
      const timer = btnEl ? startElapsedTimer(btnEl, 'FB') : null;
      const r = await fetch(`refresh_fb.php?id=${id}`);
      let d = await r.json();
      if (d.status === 'started') d = await pollRefreshStatus(id, 'fb');
      if (timer) stopElapsedTimer(timer);
      if (btnEl) btnEl.textContent = '...';
      if (d.success) document.getElementById(`fb-${id}`).textContent = d.fb_followers.toLocaleString();
      else errors.push('FB: ' + d.message);
      done++; setRowPercent(id, done, applicable); updateOverallProgress();
    }
    if (hasIg) {
      const timer = btnEl ? startElapsedTimer(btnEl, 'IG') : null;
      const r = await fetch(`refresh_ig.php?id=${id}`);
      let d = await r.json();
      if (d.status === 'started') d = await pollRefreshStatus(id, 'ig');
      if (timer) stopElapsedTimer(timer);
      if (btnEl) btnEl.textContent = '...';
      if (d.success) document.getElementById(`ig-${id}`).textContent = d.ig_followers.toLocaleString();
      else errors.push('IG: ' + d.message);
      done++; setRowPercent(id, done, applicable); updateOverallProgress();
    }
    if (hasYt) {
      const r = await fetch(`refresh_yt.php?id=${id}`);
      const d = await r.json();
      if (d.success) document.getElementById(`yt-${id}`).textContent = d.yt_subscribers.toLocaleString();
      else errors.push('YT: ' + d.message);
      done++; setRowPercent(id, done, applicable); updateOverallProgress();
    }
    if (hasGr) {
      const r = await fetch(`refresh_gr.php?id=${id}`);
      const d = await r.json();
      if (d.success) document.getElementById(`gr-${id}`).innerHTML = `${d.google_review_count.toLocaleString()} <span style="color:var(--muted)">(${d.google_rating}★)</span>`;
      else errors.push('Google: ' + d.message);
      done++; setRowPercent(id, done, applicable); updateOverallProgress();
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
    if (btnEl) { btnEl.classList.remove('loading'); btnEl.textContent = 'Refresh'; }
  }
}

async function deleteDealership(id, name, btnEl) {
  if (!confirm(`Delete "${name}"? This cannot be undone.`)) return;
  if (btnEl) { btnEl.disabled = true; btnEl.textContent = '...'; }
  const res = await fetch('delete_dealership.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `id=${id}`,
  });
  const data = await res.json();
  if (data.success) {
    document.getElementById(`row-${id}`).remove();
  } else {
    alert('Delete Failed.');
    if (btnEl) { btnEl.disabled = false; btnEl.textContent = 'Delete'; }
  }
}

function filterRows(query) {
  const q = query.trim().toLowerCase();
  const rows = document.querySelectorAll('tr[id^="row-"]');
  let visible = 0;
  rows.forEach(row => {
    const name = row.querySelector('.name').textContent.toLowerCase();
    const match = name.includes(q);
    row.style.display = match ? '' : 'none';
    if (match) visible++;
  });
  const countEl = document.getElementById('search-count');
  countEl.textContent = q ? `Showing ${visible} / ${rows.length}` : '';
}

document.getElementById('refresh-all')?.addEventListener('click', async function () {
  const rows = Array.from(document.querySelectorAll('tr[id^="row-"]')).filter(r => r.style.display !== 'none');
  this.disabled = true;

  let done = 0;
  for (const row of rows) {
    const id = row.id.replace('row-', '');
    this.textContent = `Refreshing ${done + 1}/${rows.length}...`;

    const btn = row.querySelector('.refresh-btn');
    await refreshOne(id, btn);   // one at a time — to avoid API rate limits
    done++;
  }

  this.textContent = 'Refresh All (FB+IG+YT+Google)';
  this.disabled = false;
});
</script>
</body>
</html>
