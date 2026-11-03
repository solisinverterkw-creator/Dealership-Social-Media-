<?php
require_once __DIR__ . '/includes/Auth.php';
Auth::requireLogin();
if (!Auth::canView('reshare_compliance') && !Auth::canView('campaign_compliance') && !Auth::isSuperAdmin()) {
    http_response_code(403);
    exit('Access Denied');
}
require_once __DIR__ . '/includes/Database.php';

$db = Database::getConnection();
$scopedIds = Auth::dealershipIds();
$isSuperAdmin = Auth::isSuperAdmin();

// Auto create tables if not exists
$db->exec("
    CREATE TABLE IF NOT EXISTS campaign_compliance_records (
        id INT AUTO_INCREMENT PRIMARY KEY,
        dealership_id INT NOT NULL,
        date_from DATE NOT NULL,
        date_to DATE NOT NULL,
        own_exchange_count INT DEFAULT 0,
        own_exchange_url VARCHAR(500) NULL,
        own_exchange_status VARCHAR(30) DEFAULT 'not_uploaded',
        own_exchange_notes TEXT NULL,
        own_usedcar_count INT DEFAULT 0,
        own_usedcar_url VARCHAR(500) NULL,
        own_usedcar_status VARCHAR(30) DEFAULT 'not_uploaded',
        own_usedcar_notes TEXT NULL,
        compliance_score INT DEFAULT 0,
        checked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_dealership_campaign (dealership_id, date_from, date_to)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

if (!$isSuperAdmin && !empty($scopedIds)) {
    $sql = "SELECT id, name, fb_input, fb_url, fb_page_id FROM dealerships WHERE id <= 21 AND id IN (" . implode(',', array_fill(0, count($scopedIds), '?')) . ") ORDER BY name";
    $stmt = $db->prepare($sql);
    $stmt->execute($scopedIds);
    $dealerships = $stmt->fetchAll();
} else {
    $sql = "SELECT id, name, fb_input, fb_url, fb_page_id FROM dealerships WHERE id <= 21 ORDER BY name";
    $stmt = $db->query($sql);
    $dealerships = $stmt->fetchAll();
}

$defaultFrom = date('Y-m-d', strtotime('-30 days'));
$defaultTo = date('Y-m-d');
$from = $_GET['from'] ?? $defaultFrom;
$to = $_GET['to'] ?? $defaultTo;
$hasRange = !empty($from) && !empty($to);

$recordsMap = [];
$stats = [
    'dealerships_with_exchange' => 0,
    'dealerships_with_usedcar' => 0,
    'total_detected_posts' => 0
];

if ($hasRange && !empty($dealerships)) {
    $dIds = array_column($dealerships, 'id');
    $placeholders = implode(',', array_fill(0, count($dIds), '?'));
    $stmt = $db->prepare("SELECT * FROM campaign_compliance_records WHERE dealership_id IN ($placeholders) AND date_from = ? AND date_to = ?");
    $stmt->execute(array_merge($dIds, [$from, $to]));
    foreach ($stmt->fetchAll() as $row) {
        $recordsMap[$row['dealership_id']] = $row;
        $exCnt = (int)($row['own_exchange_count'] ?? 0);
        $ucCnt = (int)($row['own_usedcar_count'] ?? 0);
        if ($exCnt > 0) $stats['dealerships_with_exchange']++;
        if ($ucCnt > 0) $stats['dealerships_with_usedcar']++;
        $stats['total_detected_posts'] += ($exCnt + $ucCnt);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>(function(){var t=localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Exchange &amp; Certified Used Car Compliance Report</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#1a1a19">
<link rel="apple-touch-icon" href="assets/icon-192.png">
<style>
.badge-green { display:inline-block; padding:4px 10px; border-radius:4px; background:rgba(34,197,94,0.15); color:#22c55e; font-weight:600; font-size:12px; }
.badge-red { display:inline-block; padding:4px 10px; border-radius:4px; background:rgba(239,68,68,0.15); color:#ef4444; font-weight:600; font-size:12px; }
.badge-gray { display:inline-block; padding:4px 10px; border-radius:4px; background:rgba(148,163,184,0.12); color:var(--muted); font-size:12px; }
.stat-box { background:var(--card-bg, #1e1e1e); padding:16px 20px; border-radius:8px; border:1px solid var(--border-color, #333); }
</style>
</head>
<body>
<div class="app-layout">
  <?php include __DIR__ . '/includes/Sidebar.php'; ?>
  <main class="main-content">
    <div class="container">
      <header>
        <div>
          <h1>Exchange &amp; Certified Used Car Compliance Report</h1>
          <div class="subtitle">Dealership Facebook Page Scraped via Bright Data with AI Caption &amp; Graphic Inspection</div>
        </div>
        <div class="toolbar">
          <a class="btn" href="export_campaign_compliance.php?from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>">
            📥 Export Report (CSV / Excel)
          </a>
          <button class="btn primary" id="check-all-btn" onclick="checkAllDealerships()">
            ⚡ Check Now (All Dealerships)
          </button>
        </div>
      </header>

      <div class="progress-wrap" id="check-progress-wrap" style="display:none; margin-bottom:20px;">
        <div class="progress-bar-track">
          <div class="progress-bar-fill" id="check-progress-fill" style="width:0%"></div>
        </div>
        <div class="progress-text" id="check-progress-text"></div>
      </div>

      <form method="GET" class="search-panel" style="margin-bottom:24px; display:flex; gap:16px; align-items:flex-end; flex-wrap:wrap;">
        <div class="field" style="margin-bottom:0;">
          <label>Tracking From</label>
          <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" required>
        </div>
        <div class="field" style="margin-bottom:0;">
          <label>Tracking To</label>
          <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" required>
        </div>
        <button type="submit" class="submit" style="width:auto; padding:11px 24px;">Load</button>
      </form>

      <?php if ($hasRange): ?>
      <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:24px;">
        <div class="stat-box">
          <div style="font-size:11px; text-transform:uppercase; color:var(--muted); font-family:'IBM Plex Mono', monospace;">Total Dealerships</div>
          <div style="font-size:24px; font-weight:700; color:var(--text); margin-top:4px;"><?= count($dealerships) ?></div>
          <div style="font-size:11px; color:var(--muted); margin-top:2px;">Network Scope</div>
        </div>

        <div class="stat-box">
          <div style="font-size:11px; text-transform:uppercase; color:var(--muted); font-family:'IBM Plex Mono', monospace;">Dealerships with Exchange</div>
          <div style="font-size:24px; font-weight:700; color:#22c55e; margin-top:4px;"><?= $stats['dealerships_with_exchange'] ?> / <?= count($dealerships) ?></div>
          <div style="font-size:11px; color:var(--muted); margin-top:2px;">At least 1 Exchange Post</div>
        </div>

        <div class="stat-box">
          <div style="font-size:11px; text-transform:uppercase; color:var(--muted); font-family:'IBM Plex Mono', monospace;">Dealerships with Used Car</div>
          <div style="font-size:24px; font-weight:700; color:#3b82f6; margin-top:4px;"><?= $stats['dealerships_with_usedcar'] ?> / <?= count($dealerships) ?></div>
          <div style="font-size:11px; color:var(--muted); margin-top:2px;">At least 1 Used Car Post</div>
        </div>

        <div class="stat-box">
          <div style="font-size:11px; text-transform:uppercase; color:var(--muted); font-family:'IBM Plex Mono', monospace;">Total Campaign Posts</div>
          <div style="font-size:24px; font-weight:700; color:#f59e0b; margin-top:4px;"><?= $stats['total_detected_posts'] ?></div>
          <div style="font-size:11px; color:var(--muted); margin-top:2px;">Identified in Date Range</div>
        </div>
      </div>
      <?php endif; ?>

      <div class="table-wrap">
        <?php if (empty($dealerships)): ?>
          <div class="empty-state">No dealerships found.</div>
        <?php else: ?>
        <table style="width:100%; border-collapse:collapse;">
          <thead>
            <tr>
              <th style="width:40px;">#</th>
              <th style="width:220px;">Dealership</th>
              <th style="text-align:center; width:150px;">Exchange Posts</th>
              <th style="text-align:center; width:180px;">Certified Used Car Posts</th>
              <th style="text-align:center; width:110px;">Total Posts</th>
              <th style="text-align:center; width:100px;">Score</th>
              <th style="text-align:center; width:120px;">Checked</th>
              <th style="text-align:center; width:90px;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($dealerships as $i => $d): ?>
            <?php 
              $r = $recordsMap[$d['id']] ?? null;
              $exCount = $r ? (int)($r['own_exchange_count'] ?? 0) : 0;
              $ucCount = $r ? (int)($r['own_usedcar_count'] ?? 0) : 0;
              $totPosts = $exCount + $ucCount;
            ?>
            <tr id="campaign-row-<?= $d['id'] ?>">
              <td class="row-number"><?= $i + 1 ?></td>
              <td class="name">
                <strong><?= htmlspecialchars($d['name']) ?></strong>
                <?php if (!empty($d['fb_input'])): ?>
                  <div style="font-size:11px; color:var(--muted); overflow:hidden; text-overflow:ellipsis; max-width:220px; white-space:nowrap;">
                    <?= htmlspecialchars($d['fb_input']) ?>
                  </div>
                <?php endif; ?>
              </td>

              <!-- Exchange Posts -->
              <td style="text-align:center;" id="cell-ex-<?= $d['id'] ?>">
                <?php if ($exCount > 0): ?>
                  <span class="badge-green">✅ <?= $exCount ?> <?= $exCount === 1 ? 'Post' : 'Posts' ?></span>
                <?php else: ?>
                  <span class="badge-red">❌ 0 Posts</span>
                <?php endif; ?>
              </td>

              <!-- Certified Used Car Posts -->
              <td style="text-align:center;" id="cell-uc-<?= $d['id'] ?>">
                <?php if ($ucCount > 0): ?>
                  <span class="badge-green">✅ <?= $ucCount ?> <?= $ucCount === 1 ? 'Post' : 'Posts' ?></span>
                <?php else: ?>
                  <span class="badge-red">❌ 0 Posts</span>
                <?php endif; ?>
              </td>

              <!-- Total Posts -->
              <td style="text-align:center; font-weight:600;" id="cell-tot-<?= $d['id'] ?>">
                <?= $totPosts ?> Posts
              </td>

              <!-- Score -->
              <td style="text-align:center;" id="cell-score-<?= $d['id'] ?>">
                <?php
                $score = $r ? (int)$r['compliance_score'] : 0;
                if ($r && $score === 100): ?>
                  <span class="status-badge status-done" style="background:rgba(34,197,94,0.2); color:#22c55e; font-weight:700;">100%</span>
                <?php elseif ($r && $score >= 50): ?>
                  <span class="status-badge" style="background:rgba(245,158,11,0.2); color:#f59e0b; font-weight:700;"><?= $score ?>%</span>
                <?php elseif ($r && !empty($r['checked_at'])): ?>
                  <span class="status-badge status-flag" style="background:rgba(239,68,68,0.2); color:#ef4444; font-weight:700;"><?= $score ?>%</span>
                <?php else: ?>
                  <span style="color:var(--muted); font-size:12px;">—</span>
                <?php endif; ?>
              </td>

              <!-- Checked At -->
              <td style="text-align:center; font-size:11px; color:var(--muted);" id="cell-checked-<?= $d['id'] ?>">
                <?= ($r && !empty($r['checked_at'])) ? date('d M, H:i', strtotime($r['checked_at'])) : 'Never' ?>
              </td>

              <!-- Action -->
              <td style="text-align:center;">
                <button class="refresh-btn" id="btn-check-<?= $d['id'] ?>" onclick="checkOneDealership(<?= $d['id'] ?>, this)" style="padding:5px 12px; font-size:11px;">
                  Check
                </button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>

      <div class="subtitle" style="margin-top:20px; line-height:1.6; font-size:12px; color:var(--muted);">
        <strong>Auditing Notes:</strong><br>
        • <strong>Dealership Page Scraper:</strong> Fetches all posts directly from the dealership's Facebook page published within the selected date range.<br>
        • <strong>Step 1 (Caption &amp; Text Scan):</strong> First inspects post captions, text, and descriptions for keywords: <em>Exchange, Certified Used Car, Used Car Gala, Trade-In, تبادلہ, سرٹیفائیڈ یوزڈ کار</em>.<br>
        • <strong>Step 2 (Multimodal AI Image Scan):</strong> When graphic flyers or banners are attached, Gemini Vision analyzes the image graphic directly to detect embedded promotional text.<br>
        • <strong>Simplified Post Count:</strong> Reports the exact number of verified Exchange and Certified Used Car posts detected for each dealership.
      </div>
    </div>
  </main>
</div>

<script>
const fromVal = <?= json_encode($from) ?>;
const toVal = <?= json_encode($to) ?>;

function getActiveRange() {
  const f = document.querySelector('input[name="from"]');
  const t = document.querySelector('input[name="to"]');
  return {
    from: (f && f.value) || fromVal,
    to: (t && t.value) || toVal
  };
}

async function checkOneDealership(id, btnEl) {
  if (btnEl) {
    btnEl.disabled = true;
    btnEl.classList.add('loading');
    btnEl.innerText = 'Scanning...';
  }
  const { from, to } = getActiveRange();

  try {
    const res = await fetch(`/check_campaign_dealership.php?id=${id}&from=${from}&to=${to}`);
    const data = await res.json();
    if (!data.success) {
      alert(`Dealership ${id}: ${data.message || 'Evaluation failed'}`);
      return;
    }

    // Update Exchange Posts Cell
    const exCell = document.getElementById(`cell-ex-${id}`);
    if (exCell) {
      if (data.exchange_count > 0) {
        exCell.innerHTML = `<span class="badge-green">✅ ${data.exchange_count} ${data.exchange_count === 1 ? 'Post' : 'Posts'}</span>`;
      } else {
        exCell.innerHTML = `<span class="badge-red">❌ 0 Posts</span>`;
      }
    }

    // Update Used Car Posts Cell
    const ucCell = document.getElementById(`cell-uc-${id}`);
    if (ucCell) {
      if (data.usedcar_count > 0) {
        ucCell.innerHTML = `<span class="badge-green">✅ ${data.usedcar_count} ${data.usedcar_count === 1 ? 'Post' : 'Posts'}</span>`;
      } else {
        ucCell.innerHTML = `<span class="badge-red">❌ 0 Posts</span>`;
      }
    }

    // Update Total Posts Cell
    const totCell = document.getElementById(`cell-tot-${id}`);
    if (totCell) {
      totCell.innerText = `${data.total_posts || 0} Posts`;
    }

    // Update Score Cell
    const scoreCell = document.getElementById(`cell-score-${id}`);
    if (scoreCell) {
      if (data.compliance_score === 100) {
        scoreCell.innerHTML = `<span class="status-badge status-done" style="background:rgba(34,197,94,0.2); color:#22c55e; font-weight:700;">100%</span>`;
      } else if (data.compliance_score >= 50) {
        scoreCell.innerHTML = `<span class="status-badge" style="background:rgba(245,158,11,0.2); color:#f59e0b; font-weight:700;">${data.compliance_score}%</span>`;
      } else {
        scoreCell.innerHTML = `<span class="status-badge status-flag" style="background:rgba(239,68,68,0.2); color:#ef4444; font-weight:700;">${data.compliance_score}%</span>`;
      }
    }

    // Update Checked Timestamp
    const chCell = document.getElementById(`cell-checked-${id}`);
    if (chCell) {
      chCell.innerText = data.checked_at || 'Just now';
    }

  } catch (err) {
    console.error(err);
    alert(`Error evaluating dealership ${id}: ${err.message}`);
  } finally {
    if (btnEl) {
      btnEl.disabled = false;
      btnEl.classList.remove('loading');
      btnEl.innerText = 'Check';
    }
  }
}

async function checkAllDealerships() {
  const rows = Array.from(document.querySelectorAll('tr[id^="campaign-row-"]'));
  if (rows.length === 0) return;

  const btn = document.getElementById('check-all-btn');
  const progressWrap = document.getElementById('check-progress-wrap');
  const progressFill = document.getElementById('check-progress-fill');
  const progressText = document.getElementById('check-progress-text');

  if (btn) btn.disabled = true;
  if (progressWrap) progressWrap.style.display = 'block';

  let done = 0;
  for (const row of rows) {
    const id = row.id.replace('campaign-row-', '');
    if (progressText) progressText.textContent = `Evaluating Dealership ${done + 1} of ${rows.length}...`;
    const checkBtn = row.querySelector('.refresh-btn');
    await checkOneDealership(id, checkBtn);
    done++;
    if (progressFill) progressFill.style.width = `${Math.round((done / rows.length) * 100)}%`;
  }

  if (progressText) progressText.textContent = `Completed — All ${rows.length} dealerships verified!`;
  if (btn) btn.disabled = false;
}
</script>
</body>
</html>
