<?php
require_once __DIR__ . '/includes/Auth.php';
Auth::requireLogin();
require_once __DIR__ . '/includes/Database.php';

$db = Database::getConnection();
$scopedIds = Auth::dealershipIds();
$isSuperAdmin = Auth::isSuperAdmin();

// A source post is only flagged "missed" once dealerships have had a full day
// to reshare it — checked daily, so this is effectively "checked, still missing
// as of the next day's run," not a same-day false alarm.
$graceHours = 20;

$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$hasRange = $from !== '' && $to !== '';
$fromDateTime = $hasRange ? $from . ' 00:00:00' : null;
$toDateTime = $hasRange ? $to . ' 23:59:59' : null;

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

$rows = [];
$missing = [];

if ($hasRange) {
    $statsStmt = $db->prepare("
        SELECT
            COUNT(*) AS tracked,
            SUM(reshared = 1) AS reshared_count,
            SUM(reshared = 0) AS missed_count,
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
        $statsStmt->execute(['id' => $d['id'], 'from' => $fromDateTime, 'to' => $toDateTime]);
        $stats = $statsStmt->fetch();

        $ownStatsStmt->execute(['id' => $d['id'], 'from_date' => $from, 'to_date' => $to]);
        $ownStats = $ownStatsStmt->fetch();

        $rows[] = [
            'id' => $d['id'],
            'name' => $d['name'],
            'own_posts' => $ownStats ? (int)$ownStats['own_post_count'] : null,
            'tracked' => (int)($stats['tracked'] ?? 0),
            'reshared' => (int)($stats['reshared_count'] ?? 0),
            'missed' => (int)($stats['missed_count'] ?? 0),
            'last_checked_at' => $stats['last_checked_at'] ?? null,
        ];
    }

    if ($isSuperAdmin || !empty($scopedIds)) {
        $missingQuery = "
            SELECT rc.dealership_id, d.name AS dealership_name, rc.message_snippet, rc.published_at
            FROM reshare_checks rc
            JOIN dealerships d ON d.id = rc.dealership_id
            WHERE rc.reshared = 0 AND rc.published_at BETWEEN ? AND ?"
            . (!$isSuperAdmin ? " AND rc.dealership_id IN (" . implode(',', array_fill(0, count($scopedIds), '?')) . ")" : "") . "
            ORDER BY rc.published_at ASC
        ";
        $missingStmt = $db->prepare($missingQuery);
        $missingParams = [$fromDateTime, $toDateTime];
        if (!$isSuperAdmin) {
            $missingParams = [...$missingParams, ...$scopedIds];
        }
        $missingStmt->execute($missingParams);
        $missing = $missingStmt->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>(function(){var t=localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
<meta charset="UTF-8">
<title>Reshare Compliance Report</title>
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
      <h1>Reshare Compliance Report</h1>
      <div class="subtitle">Own Posts vs Reshares Of Suzuki Pakistan's Source Posts — Checked On Demand</div>
    </div>
    <div class="toolbar">
      <?php if (Auth::isSuperAdmin()): ?>
      <button class="btn" id="refresh-source-btn" onclick="refreshSource()" <?= $hasRange ? '' : 'disabled title="Select A Tracking From And To Date, Then Click Load, First"' ?>>Refresh Source Posts</button>
      <?php endif; ?>
      <?php if (Auth::can('refresh')): ?>
      <button class="btn primary" id="check-all-btn" onclick="checkAll()" <?= $hasRange ? '' : 'disabled title="Select A Tracking From And To Date, Then Click Load, First"' ?>>Check Now (All Dealerships)</button>
      <?php endif; ?>
      <?php if ($hasRange): ?>
      <a class="btn" href="export_reshare_compliance.php?type=summary&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>">Export Summary CSV</a>
      <?php endif; ?>
    </div>
  </header>
  <div class="progress-wrap" id="check-progress-wrap" style="display:none;">
    <div class="progress-bar-track"><div class="progress-bar-fill" id="check-progress-fill" style="width:0%"></div></div>
    <div class="progress-text" id="check-progress-text"></div>
  </div>

  <form method="GET" class="search-panel" style="margin-bottom:24px; display:flex; gap:16px; align-items:flex-end; flex-wrap:wrap;">
    <div class="field" style="margin-bottom:0;">
      <label>Tracking From</label>
      <input type="date" name="from" value="<?= htmlspecialchars($from) ?>">
    </div>
    <div class="field" style="margin-bottom:0;">
      <label>Tracking To</label>
      <input type="date" name="to" value="<?= htmlspecialchars($to) ?>">
    </div>
    <button type="submit" class="submit" style="width:auto; padding:11px 24px;">Load</button>
  </form>

  <?php if (!empty($missing)): ?>
  <div class="table-wrap" style="margin-bottom:24px; border-color:var(--red);">
    <div style="padding:12px 16px; display:flex; justify-content:space-between; align-items:center;">
      <span style="font-weight:600; color:var(--red);">⚠ Missed Reshares (<?= count($missing) ?>)</span>
      <a class="btn" href="export_reshare_compliance.php?type=missed&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>">Export CSV</a>
    </div>
    <table>
      <thead>
        <tr>
          <th>Dealership</th>
          <th>Source Post (Snippet)</th>
          <th>First Seen</th>
          <th>Pending</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($missing as $m): ?>
        <?php $hoursAgo = (int)round((time() - strtotime($m['published_at'])) / 3600); ?>
        <tr>
          <td class="name"><?= htmlspecialchars($m['dealership_name']) ?></td>
          <td><?= htmlspecialchars(mb_substr($m['message_snippet'], 0, 80)) ?><?= mb_strlen($m['message_snippet']) > 80 ? '…' : '' ?></td>
          <td class="timestamp"><?= date('d M, H:i', strtotime($m['published_at'])) ?></td>
          <td><span class="status-badge status-flag"><?= $hoursAgo ?>h</span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <div class="table-wrap">
    <?php if (!$hasRange): ?>
      <div class="empty-state">Select A Tracking From And To Date, Then Click "Load", To View This Report.</div>
    <?php elseif (empty($rows)): ?>
      <div class="empty-state">No Dealerships Added Yet.</div>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Dealership</th>
          <th>Own Posts (Selected Range)</th>
          <th>Source Posts Tracked</th>
          <th>Reshared</th>
          <th>Missed</th>
          <th>Last Checked</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $i => $r): ?>
        <tr id="reshare-row-<?= $r['id'] ?>">
          <td class="row-number"><?= $i + 1 ?></td>
          <td class="name"><?= htmlspecialchars($r['name']) ?></td>
          <td class="metric fb-color" id="own-<?= $r['id'] ?>"><?= $r['own_posts'] !== null ? number_format($r['own_posts']) : '—' ?></td>
          <td class="metric" id="tracked-<?= $r['id'] ?>"><?= number_format($r['tracked']) ?></td>
          <td class="metric" style="color:var(--green)" id="reshared-<?= $r['id'] ?>"><?= number_format($r['reshared']) ?></td>
          <td class="metric" id="missed-<?= $r['id'] ?>">
            <?php if ($r['missed'] > 0): ?>
              <span class="status-badge status-flag"><?= $r['missed'] ?></span>
            <?php else: ?>
              <span class="status-badge status-done">0</span>
            <?php endif; ?>
          </td>
          <td class="timestamp" id="checked-<?= $r['id'] ?>"><?= $r['last_checked_at'] ? date('d M, H:i', strtotime($r['last_checked_at'])) : 'never' ?></td>
          <td class="actions-cell">
            <?php if (Auth::can('refresh')): ?>
            <button class="refresh-btn" onclick="checkOne(<?= $r['id'] ?>, this)">Check</button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <?php if ($hasRange): ?>
  <div class="subtitle" style="margin-top:16px; line-height:1.6;">
    <strong>Own Posts</strong> = dealership's own posts in the selected range that are NOT reshares of a tracked source post — computed fresh, fully paginated over the range, each time "Check" runs. Shows "—" until checked for this exact range.<br>
    <strong>Tracked</strong> = Suzuki Pakistan source posts published between <?= date('d M Y', strtotime($from)) ?> and <?= date('d M Y', strtotime($to)) ?> (click "Refresh Source Posts" to (re)populate this list for the range above).<br>
    <strong>Reshared</strong> = of those, how many were found (by text match) anywhere on the dealership's page in this same range.<br>
    <strong>Missed</strong> = source posts still not found on the dealership's page <?= $graceHours ?>+ hours after they were published.
  </div>
  <?php endif; ?>

</div>
</main>
</div>

<script>
// check_reshare_dealership.php's own response only reflects THIS ONE scrape's
// match against the dealership's current recent posts — it does NOT reflect
// the persisted (monotonic, never-goes-back-to-0) "reshared" status in the DB,
// nor the page's selected date range. Reloading (preserving from/to) is what
// shows the true, authoritative numbers.
// Reads the actual date INPUT fields, not the URL — so changing the range and
// clicking "Check" (without first clicking "Load") still uses what's on screen,
// instead of silently falling back to a stale/default range.
// Falls back to the range this page was actually loaded with (not a generic
// "30 days ago") if the date input's live value is ever empty/incomplete —
// so a Check/Check All never silently jumps back to the default range.
const pageFrom = <?= json_encode($from) ?>;
const pageTo = <?= json_encode($to) ?>;

function currentRange() {
  const fromInput = document.querySelector('input[name="from"]');
  const toInput = document.querySelector('input[name="to"]');
  return {
    from: (fromInput && fromInput.value) || pageFrom,
    to: (toInput && toInput.value) || pageTo,
  };
}

// Reloads using whatever range is currently in the input fields (not the old
// URL) — so the numbers shown after a check match the range that was just checked.
function reloadPreservingRange() {
  const { from, to } = currentRange();
  window.location.href = `reshare_compliance_report.php?from=${from}&to=${to}`;
}

// check_reshare_dealership.php/refresh_reshare_source.php respond immediately
// with {status:'started'} (Bright Data can take several minutes, past
// LiteSpeed's own connection timeout) and keep working server-side — poll
// refresh_status.php until the background job finishes.
async function pollJobStatus(jobKey, metric, maxWaitMs = 300000, intervalMs = 3000) {
  const start = Date.now();
  while (Date.now() - start < maxWaitMs) {
    await new Promise(resolve => setTimeout(resolve, intervalMs));
    const r = await fetch(`refresh_status.php?id=${encodeURIComponent(jobKey)}&metric=${metric}`);
    const d = await r.json();
    if (d.status === 'done') return { success: true, ...d };
    if (d.status === 'error') return { success: false, ...d };
  }
  return { success: false, message: 'Timed Out Waiting For Background Check.' };
}

async function checkOne(id, btnEl, reload = true) {
  if (btnEl) { btnEl.classList.add('loading'); btnEl.disabled = true; }
  const timer = btnEl ? startElapsedTimer(btnEl, 'Checking') : null;
  try {
    const { from, to } = currentRange();
    const r = await fetch(`check_reshare_dealership.php?id=${id}&from=${from}&to=${to}`);
    let d = await r.json();
    if (d.status === 'started') {
      d = await pollJobStatus(`${id}_${from}_${to}`, 'reshare_check');
    }
    if (!d.success) {
      alert(`Dealership ${id}: ${d.message}`);
      return;
    }
    if (reload) {
      reloadPreservingRange();
    }
  } catch (e) {
    console.error(e);
  } finally {
    if (timer) stopElapsedTimer(timer);
    if (btnEl) { btnEl.classList.remove('loading'); btnEl.disabled = false; btnEl.textContent = 'Check'; }
  }
}

async function refreshSource() {
  const btn = document.getElementById('refresh-source-btn');
  btn.disabled = true;
  const timer = startElapsedTimer(btn, 'Refreshing');
  try {
    const { from, to } = currentRange();
    const r = await fetch(`refresh_reshare_source.php?from=${from}&to=${to}`);
    let d = await r.json();
    if (d.status === 'started') {
      d = await pollJobStatus(`${from}_${to}`, 'reshare_source');
    }
    if (d.success) {
      alert(`Source posts refreshed — ${d.tracked} tracked, ${d.new_posts} new.`);
    } else {
      alert(`Failed: ${d.message}`);
    }
  } catch (e) {
    console.error(e);
  } finally {
    stopElapsedTimer(timer);
    btn.disabled = false;
    btn.textContent = 'Refresh Source Posts';
  }
}

async function checkAll() {
  const rows = Array.from(document.querySelectorAll('tr[id^="reshare-row-"]'));
  if (rows.length === 0) return;

  const btn = document.getElementById('check-all-btn');
  const progressWrap = document.getElementById('check-progress-wrap');
  const progressFill = document.getElementById('check-progress-fill');
  const progressText = document.getElementById('check-progress-text');

  btn.disabled = true;
  progressWrap.style.display = '';

  let done = 0;
  for (const row of rows) {
    const id = row.id.replace('reshare-row-', '');
    progressText.textContent = `Checking ${done + 1}/${rows.length}...`;
    const checkBtn = row.querySelector('.refresh-btn');
    await checkOne(id, checkBtn, false); // one at a time — this host rate-limits fast; no reload mid-loop
    done++;
    progressFill.style.width = `${Math.round((done / rows.length) * 100)}%`;
  }

  progressText.textContent = `Done — ${rows.length} dealership(s) checked. Reloading...`;
  reloadPreservingRange();
}
</script>
</body>
</html>
