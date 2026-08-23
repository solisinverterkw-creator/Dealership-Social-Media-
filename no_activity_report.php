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
    $sql = "
        SELECT *, (fb_followers + ig_followers) AS total_followers
        FROM dealerships
        WHERE (fb_input != '' OR ig_search != '')"
        . (!$isSuperAdmin ? " AND id IN (" . implode(',', array_fill(0, count($scopedIds), '?')) . ")" : "") . "
        ORDER BY total_followers DESC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute(!$isSuperAdmin ? $scopedIds : []);
    $dealerships = $stmt->fetchAll();
}

$from = $_GET['from'] ?? '2026-01-01';
$to = $_GET['to'] ?? date('Y-m-d');

function activityStatus(array $d): string
{
    $hasFb = !empty($d['fb_input']);
    $hasIg = !empty($d['ig_search']);
    $checked = (!$hasFb || !empty($d['fb_posts_checked_at'])) && (!$hasIg || !empty($d['ig_posts_checked_at']));
    if (!$checked) return 'unchecked';
    $totalPosts = ($hasFb ? (int)$d['fb_posts_week'] : 0) + ($hasIg ? (int)$d['ig_posts_week'] : 0);
    return $totalPosts === 0 ? 'inactive' : 'active';
}

// Engagement rate = avg engagement per post / followers * 100.
// < 0.1% with a meaningful follower count is the classic bought-followers signal.
function engagementRate(?float $avgEngagement, int $followers, bool $checked): ?float
{
    if (!$checked || $followers === 0) return null;
    return round(($avgEngagement ?? 0) / $followers * 100, 3);
}
function engagementBadgeClass(?float $rate, int $followers): string
{
    if ($rate === null) return 'status-partial';
    if ($followers >= 500 && $rate < 0.1) return 'status-flag';
    if ($rate < 1) return 'status-partial';
    return 'status-done';
}
function engagementBadgeLabel(?float $rate, int $followers): string
{
    if ($rate === null) return 'Not Checked';
    if ($followers >= 500 && $rate < 0.1) return $rate . '% ⚠';
    return $rate . '%';
}

$noActivity = array_values(array_filter($dealerships, fn($d) => activityStatus($d) === 'inactive'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>(function(){var t=localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
<meta charset="UTF-8">
<title>Follower Activity Report</title>
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
      <h1>Follower Activity Report</h1>
      <div class="subtitle">Followers + Posting Activity Side By Side — Spot High-Follower, No-Activity Pages</div>
    </div>
    <div class="toolbar">
      <?php if (Auth::can('refresh')): ?>
      <button class="btn primary" id="check-all">Check All</button>
      <?php endif; ?>
    </div>
  </header>

  <form method="GET" class="search-panel" style="margin-bottom:24px; display:flex; gap:16px; align-items:flex-end; flex-wrap:wrap;">
    <div class="field" style="margin-bottom:0;">
      <label>Activity Since</label>
      <input type="date" name="from" value="<?= htmlspecialchars($from) ?>">
    </div>
    <div class="field" style="margin-bottom:0;">
      <label>Activity Until</label>
      <input type="date" name="to" value="<?= htmlspecialchars($to) ?>">
    </div>
    <button type="submit" class="submit" style="width:auto; padding:11px 24px;">Load</button>
  </form>

  <div class="detail-card" id="no-activity-card">
    <h2>No Activity Since <?= date('d M Y', strtotime($from)) ?> — <span id="no-activity-count"><?= count($noActivity) ?></span> Dealership<span id="no-activity-plural"><?= count($noActivity) === 1 ? '' : 's' ?></span></h2>
    <div id="no-activity-names" style="font-family:'IBM Plex Mono', monospace; font-size:13px; color:var(--red); line-height:1.8;">
      <?php if (!empty($noActivity)): ?>
        <?php foreach ($noActivity as $j => $nd): ?><?= $j ? ', ' : '' ?><?= htmlspecialchars($nd['name']) ?> (<?= number_format($nd['total_followers']) ?> Followers)<?php endforeach; ?>
      <?php else: ?>
        None — Click "Check All" To Verify This Range.
      <?php endif; ?>
    </div>
  </div>

  <div class="table-wrap">
    <?php if (empty($dealerships)): ?>
      <div class="empty-state">No Dealerships With Facebook Or Instagram Set.</div>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Dealership</th>
          <th>FB Followers</th>
          <th>IG Followers</th>
          <th>FB Posts</th>
          <th>IG Posts</th>
          <th>FB Engagement</th>
          <th>IG Engagement</th>
          <th>Status</th>
          <th>Last Checked</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($dealerships as $i => $d): ?>
        <?php $status = activityStatus($d); ?>
        <tr id="row-<?= $d['id'] ?>" data-name="<?= htmlspecialchars($d['name']) ?>" data-followers="<?= $d['total_followers'] ?>" data-fb-followers="<?= (int)$d['fb_followers'] ?>" data-ig-followers="<?= (int)$d['ig_followers'] ?>">
          <td class="row-number"><?= $i + 1 ?></td>
          <td class="name"><?= htmlspecialchars($d['name']) ?></td>
          <td class="metric fb-color"><?= number_format($d['fb_followers']) ?></td>
          <td class="metric ig-color"><?= number_format($d['ig_followers']) ?></td>
          <td class="metric fb-color" id="fbposts-<?= $d['id'] ?>"><?= number_format($d['fb_posts_week']) ?></td>
          <td class="metric ig-color" id="igposts-<?= $d['id'] ?>"><?= number_format($d['ig_posts_week']) ?></td>
          <?php
            $fbChecked = !empty($d['fb_input']) && !empty($d['fb_posts_checked_at']);
            $igChecked = !empty($d['ig_search']) && !empty($d['ig_posts_checked_at']);
            $fbRate = engagementRate($d['fb_engagement_avg'], (int)$d['fb_followers'], $fbChecked);
            $igRate = engagementRate($d['ig_engagement_avg'], (int)$d['ig_followers'], $igChecked);
          ?>
          <td><span class="status-badge <?= engagementBadgeClass($fbRate, (int)$d['fb_followers']) ?>" id="fbeng-<?= $d['id'] ?>" data-followers="<?= (int)$d['fb_followers'] ?>"><?= engagementBadgeLabel($fbRate, (int)$d['fb_followers']) ?></span></td>
          <td><span class="status-badge <?= engagementBadgeClass($igRate, (int)$d['ig_followers']) ?>" id="igeng-<?= $d['id'] ?>" data-followers="<?= (int)$d['ig_followers'] ?>"><?= engagementBadgeLabel($igRate, (int)$d['ig_followers']) ?></span></td>
          <td>
            <span class="status-badge <?= $status === 'inactive' ? 'status-pending' : ($status === 'active' ? 'status-done' : 'status-partial') ?>" id="activity-<?= $d['id'] ?>">
              <?= $status === 'inactive' ? 'No Activity' : ($status === 'active' ? 'Active' : 'Not Checked') ?>
            </span>
          </td>
          <td class="timestamp" id="ts-<?= $d['id'] ?>">
            <?php
              $dates = array_filter([$d['fb_posts_checked_at'], $d['ig_posts_checked_at']]);
              $latest = !empty($dates) ? max($dates) : null;
              echo $latest ? date('d M, H:i', strtotime($latest)) : 'never';
            ?>
          </td>
          <td>
            <?php if (Auth::can('refresh')): ?>
            <button class="refresh-btn"
                    data-has-fb="<?= !empty($d['fb_input']) ? '1' : '' ?>"
                    data-has-ig="<?= !empty($d['ig_search']) ? '1' : '' ?>"
                    onclick="checkRow(<?= $d['id'] ?>, this, this.dataset.hasFb === '1', this.dataset.hasIg === '1')">Check</button>
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

function refreshNoActivitySummary() {
  const rows = Array.from(document.querySelectorAll('tr[id^="row-"]'));
  const inactive = rows
    .filter(row => {
      const badge = row.querySelector('[id^="activity-"]');
      return badge && badge.textContent.trim() === 'No Activity';
    })
    .map(row => ({ name: row.dataset.name, followers: parseInt(row.dataset.followers, 10) || 0 }))
    .sort((a, b) => b.followers - a.followers);

  document.getElementById('no-activity-count').textContent = inactive.length;
  document.getElementById('no-activity-plural').textContent = inactive.length === 1 ? '' : 's';
  document.getElementById('no-activity-names').textContent = inactive.length
    ? inactive.map(x => `${x.name} (${x.followers.toLocaleString()} Followers)`).join(', ')
    : 'None Found In This Range.';
}

function setActivityStatus(id, fbPosts, igPosts, hasFb, hasIg) {
  const badge = document.getElementById(`activity-${id}`);
  if (!badge) return;
  const total = (hasFb ? fbPosts : 0) + (hasIg ? igPosts : 0);
  badge.className = 'status-badge ' + (total === 0 ? 'status-pending' : 'status-done');
  badge.textContent = total === 0 ? 'No Activity' : 'Active';
}

function setEngagementBadge(elId, avgEngagement, followers) {
  const badge = document.getElementById(elId);
  if (!badge) return;
  if (followers === 0) {
    badge.className = 'status-badge status-partial';
    badge.textContent = 'Not Checked';
    return;
  }
  const rate = Math.round((avgEngagement / followers) * 100 * 1000) / 1000;
  let cls = 'status-done';
  let label = `${rate}%`;
  if (followers >= 500 && rate < 0.1) {
    cls = 'status-flag';
    label = `${rate}% ⚠`;
  } else if (rate < 1) {
    cls = 'status-partial';
  }
  badge.className = 'status-badge ' + cls;
  badge.textContent = label;
}

async function checkRow(id, btnEl, hasFb, hasIg) {
  if (btnEl) { btnEl.classList.add('loading'); btnEl.textContent = '...'; }
  const errors = [];
  let fbPosts = 0, igPosts = 0;
  const row = document.getElementById(`row-${id}`);
  const fbFollowers = row ? parseInt(row.dataset.fbFollowers, 10) || 0 : 0;
  const igFollowers = row ? parseInt(row.dataset.igFollowers, 10) || 0 : 0;

  try {
    if (hasFb) {
      const timer = btnEl ? startElapsedTimer(btnEl, 'FB') : null;
      const r = await fetch(`check_fb_posts.php?id=${id}&from=${rangeFrom}&to=${rangeTo}`);
      const d = await r.json();
      if (timer) stopElapsedTimer(timer);
      if (d.success) {
        fbPosts = d.fb_posts_week;
        document.getElementById(`fbposts-${id}`).textContent = fbPosts.toLocaleString();
        setEngagementBadge(`fbeng-${id}`, d.fb_engagement_avg || 0, fbFollowers);
      } else errors.push('FB: ' + d.message);
    }
    if (hasIg) {
      const r = await fetch(`check_ig_posts.php?id=${id}&from=${rangeFrom}&to=${rangeTo}`);
      const d = await r.json();
      if (d.success) {
        igPosts = d.ig_posts_week;
        document.getElementById(`igposts-${id}`).textContent = igPosts.toLocaleString();
        setEngagementBadge(`igeng-${id}`, d.ig_engagement_avg || 0, igFollowers);
      } else errors.push('IG: ' + d.message);
    }

    setActivityStatus(id, fbPosts, igPosts, hasFb, hasIg);
    refreshNoActivitySummary();

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
