<?php
require_once __DIR__ . '/includes/Auth.php';
Auth::requireLogin();
if (!Auth::canView('yt_monthly') && !Auth::isSuperAdmin()) {
    http_response_code(403);
    exit('Access Denied');
}
require_once __DIR__ . '/includes/Database.php';

$db = Database::getConnection();
$scopedIds = Auth::dealershipIds();
$isSuperAdmin = Auth::isSuperAdmin();

// Auto create table if not exists in MySQL
$db->exec("
    CREATE TABLE IF NOT EXISTS yt_testimonial_records (
        id INT AUTO_INCREMENT PRIMARY KEY,
        dealership_id INT NOT NULL,
        month VARCHAR(7) NOT NULL,
        fronx_video_id VARCHAR(50) NULL,
        fronx_video_title VARCHAR(255) NULL,
        fronx_video_url VARCHAR(255) NULL,
        fronx_status VARCHAR(30) DEFAULT 'missing',
        fronx_reason TEXT NULL,
        every_video_id VARCHAR(50) NULL,
        every_video_title VARCHAR(255) NULL,
        every_video_url VARCHAR(255) NULL,
        every_status VARCHAR(30) DEFAULT 'missing',
        every_reason TEXT NULL,
        compliance_score INT DEFAULT 0,
        checked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_dealership_month (dealership_id, month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

if (!$isSuperAdmin && empty($scopedIds)) {
    $dealerships = [];
} else {
    $sql = "SELECT id, name, yt_search, yt_channel_id FROM dealerships WHERE 1=1"
        . (!$isSuperAdmin ? " AND id IN (" . implode(',', array_fill(0, count($scopedIds), '?')) . ")" : "")
        . " ORDER BY name";
    $stmt = $db->prepare($sql);
    $stmt->execute(!$isSuperAdmin ? $scopedIds : []);
    $dealerships = $stmt->fetchAll();
}

$selectedMonth = $_GET['month'] ?? date('Y-m');

// Available 6 months
$availableMonths = [];
$cursor = new DateTime(date('Y-m-01'));
for ($i = 0; $i < 6; $i++) {
    $availableMonths[] = [
        'key' => $cursor->format('Y-m'),
        'label' => $cursor->format('F Y')
    ];
    $cursor->modify('-1 month');
}

// Fetch records for selected month
$recordsMap = [];
if (!empty($dealerships)) {
    $dIds = array_column($dealerships, 'id');
    $placeholders = implode(',', array_fill(0, count($dIds), '?'));
    $stmt = $db->prepare("SELECT * FROM yt_testimonial_records WHERE dealership_id IN ($placeholders) AND month = ?");
    $stmt->execute(array_merge($dIds, [$selectedMonth]));
    foreach ($stmt->fetchAll() as $row) {
        $recordsMap[$row['dealership_id']] = $row;
    }
}

$compliantCount = 0;
$partialCount = 0;
$nonCompliantCount = 0;
foreach ($recordsMap as $r) {
    if ((int)$r['compliance_score'] === 100) $compliantCount++;
    elseif ((int)$r['compliance_score'] === 50) $partialCount++;
    else $nonCompliantCount++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>(function(){var t=localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
<meta charset="UTF-8">
<title>YouTube Customer Testimonial Compliance — ROSP Suzuki</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#1a1a19">
</head>
<body>
<div class="app-layout">
<?php require __DIR__ . '/includes/Sidebar.php'; ?>
<main class="main-content">
<div class="container">

  <header>
    <div>
      <h1 class="brand-h1">YouTube Customer Testimonial Compliance</h1>
      <div class="subtitle">AI-Verified Customer Feedback Videos for Suzuki Fronx &amp; Every</div>
    </div>
  </header>

  <div class="stat-cards" style="margin-bottom: 24px;">
    <div class="stat-card-icon">
      <div class="badge" style="background:rgba(34,197,94,0.15); color:#22c55e;">✅</div>
      <div class="stat-value" style="color:#22c55e;"><?= $compliantCount ?></div>
      <div class="stat-label">100% Compliant (Both Done)</div>
    </div>
    <div class="stat-card-icon">
      <div class="badge" style="background:rgba(245,158,11,0.15); color:#f59e0b;"><?= $partialCount ?></div>
      <div class="stat-value" style="color:#f59e0b;"><?= $partialCount ?></div>
      <div class="stat-label">Partial (1 Model Verified)</div>
    </div>
    <div class="stat-card-icon">
      <div class="badge" style="background:rgba(239,68,68,0.15); color:#ef4444;">❌</div>
      <div class="stat-value" style="color:#ef4444;"><?= $nonCompliantCount ?></div>
      <div class="stat-label">Non-Compliant / Missing</div>
    </div>
    <div class="stat-card-icon">
      <div class="badge" style="background:rgba(99,102,241,0.15); color:#6366f1;">🏢</div>
      <div class="stat-value" style="color:#6366f1;"><?= count($dealerships) ?></div>
      <div class="stat-label">Total Dealerships</div>
    </div>
  </div>

  <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:16px; margin-bottom: 20px;">
    <form method="GET" class="search-panel" style="margin-bottom:0; display:flex; gap:12px; align-items:center;">
      <label style="font-size:13px; font-weight:600; text-transform:uppercase; color:var(--muted); margin:0;">Audit Month:</label>
      <select name="month" onchange="this.form.submit()" style="background:var(--panel); color:var(--text); border:1px solid var(--border); padding:8px 14px; border-radius:6px; font-size:14px; font-weight:500;">
        <?php foreach ($availableMonths as $m): ?>
          <option value="<?= $m['key'] ?>" <?= $m['key'] === $selectedMonth ? 'selected' : '' ?>><?= $m['label'] ?></option>
        <?php endforeach; ?>
      </select>
    </form>

    <div class="toolbar" style="margin:0;">
      <button class="btn primary" id="check-all-btn" onclick="checkAllDealerships()">
        ⚡ Check All Dealerships (AI Verify)
      </button>
    </div>
  </div>

  <div class="table-wrap">
    <table style="width:100%; border-collapse:collapse;">
      <thead>
        <tr>
          <th style="width:40px;">#</th>
          <th style="width:200px;">Dealership Name</th>
          <th style="width:160px; text-align:center;">Fronx Testimonial</th>
          <th style="width:160px; text-align:center;">Every Testimonial</th>
          <th>AI Remarks Verification</th>
          <th style="width:110px; text-align:center;">Compliance</th>
          <th style="width:90px; text-align:center;">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($dealerships as $i => $d): 
          $rec = $recordsMap[$d['id']] ?? null;
        ?>
        <tr id="row-<?= $d['id'] ?>">
          <td class="row-number"><?= $i + 1 ?></td>
          <td class="name">
            <strong><?= htmlspecialchars($d['name']) ?></strong>
            <?php if (!empty($d['yt_search'])): ?>
              <div style="font-size:11px; color:var(--muted);">Channel: <?= htmlspecialchars($d['yt_search']) ?></div>
            <?php endif; ?>
          </td>

          <!-- Fronx Status -->
          <td style="text-align:center;" id="cell-fronx-<?= $d['id'] ?>">
            <?php if ($rec && $rec['fronx_status'] === 'verified'): ?>
              <span style="display:inline-block; padding:4px 8px; border-radius:4px; background:rgba(34,197,94,0.15); color:#22c55e; font-weight:600; font-size:12px;">✅ Verified</span>
              <?php if (!empty($rec['fronx_video_url'])): ?>
                <div style="margin-top:4px;"><a href="<?= htmlspecialchars($rec['fronx_video_url']) ?>" target="_blank" style="font-size:11px; color:var(--accent);">▶ Watch Video</a></div>
              <?php endif; ?>
            <?php elseif ($rec && $rec['fronx_status'] === 'rejected'): ?>
              <span style="display:inline-block; padding:4px 8px; border-radius:4px; background:rgba(239,68,68,0.15); color:#ef4444; font-weight:600; font-size:12px;">❌ Rejected</span>
              <?php if (!empty($rec['fronx_video_url'])): ?>
                <div style="margin-top:4px;"><a href="<?= htmlspecialchars($rec['fronx_video_url']) ?>" target="_blank" style="font-size:11px; color:var(--muted);">▶ Watch</a></div>
              <?php endif; ?>
            <?php else: ?>
              <span style="display:inline-block; padding:4px 8px; border-radius:4px; background:rgba(148,163,184,0.12); color:var(--muted); font-size:12px;">— Missing</span>
            <?php endif; ?>
          </td>

          <!-- Every Status -->
          <td style="text-align:center;" id="cell-every-<?= $d['id'] ?>">
            <?php if ($rec && $rec['every_status'] === 'verified'): ?>
              <span style="display:inline-block; padding:4px 8px; border-radius:4px; background:rgba(34,197,94,0.15); color:#22c55e; font-weight:600; font-size:12px;">✅ Verified</span>
              <?php if (!empty($rec['every_video_url'])): ?>
                <div style="margin-top:4px;"><a href="<?= htmlspecialchars($rec['every_video_url']) ?>" target="_blank" style="font-size:11px; color:var(--accent);">▶ Watch Video</a></div>
              <?php endif; ?>
            <?php elseif ($rec && $rec['every_status'] === 'rejected'): ?>
              <span style="display:inline-block; padding:4px 8px; border-radius:4px; background:rgba(239,68,68,0.15); color:#ef4444; font-weight:600; font-size:12px;">❌ Rejected</span>
              <?php if (!empty($rec['every_video_url'])): ?>
                <div style="margin-top:4px;"><a href="<?= htmlspecialchars($rec['every_video_url']) ?>" target="_blank" style="font-size:11px; color:var(--muted);">▶ Watch</a></div>
              <?php endif; ?>
            <?php else: ?>
              <span style="display:inline-block; padding:4px 8px; border-radius:4px; background:rgba(148,163,184,0.12); color:var(--muted); font-size:12px;">— Missing</span>
            <?php endif; ?>
          </td>

          <!-- Remarks Verification Detail -->
          <td id="cell-remarks-<?= $d['id'] ?>" style="font-size:12px; line-height:1.5;">
            <?php if ($rec): ?>
              <?php if (!empty($rec['fronx_reason'])): ?>
                <div><strong>Fronx:</strong> <span style="color:var(--text);"><?= htmlspecialchars($rec['fronx_reason']) ?></span></div>
              <?php endif; ?>
              <?php if (!empty($rec['every_reason'])): ?>
                <div style="margin-top:4px;"><strong>Every:</strong> <span style="color:var(--text);"><?= htmlspecialchars($rec['every_reason']) ?></span></div>
              <?php endif; ?>
              <?php if (empty($rec['fronx_reason']) && empty($rec['every_reason'])): ?>
                <span style="color:var(--muted);">No videos evaluated yet.</span>
              <?php endif; ?>
            <?php else: ?>
              <span style="color:var(--muted);">Not checked for this month. Click "Check" to evaluate.</span>
            <?php endif; ?>
          </td>

          <!-- Compliance Score Badge -->
          <td style="text-align:center;" id="cell-score-<?= $d['id'] ?>">
            <?php if ($rec): ?>
              <?php if ((int)$rec['compliance_score'] === 100): ?>
                <span class="status-badge status-done" style="font-size:12px; padding:4px 10px;">100% Compliant</span>
              <?php elseif ((int)$rec['compliance_score'] === 50): ?>
                <span class="status-badge status-partial" style="font-size:12px; padding:4px 10px;">50% Partial</span>
              <?php else: ?>
                <span class="status-badge status-pending" style="font-size:12px; padding:4px 10px;">Non-Compliant</span>
              <?php endif; ?>
            <?php else: ?>
              <span style="color:var(--muted); font-size:12px;">—</span>
            <?php endif; ?>
          </td>

          <!-- Action Button -->
          <td style="text-align:center;">
            <button class="refresh-btn" id="btn-<?= $d['id'] ?>" onclick="checkOneDealership(<?= $d['id'] ?>, this)" style="padding:6px 12px; font-size:12px;">
              Check
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

</div>
</main>
</div>

<script>
const currentMonth = <?= json_encode($selectedMonth) ?>;

async function checkOneDealership(id, btnEl) {
  if (btnEl) {
    btnEl.classList.add('loading');
    btnEl.disabled = true;
    btnEl.textContent = 'Analyzing...';
  }

  const rowEl = document.getElementById(`row-${id}`);
  if (rowEl) rowEl.style.opacity = '0.7';

  try {
    const res = await fetch(`check_yt_testimonial.php?id=${id}&month=${currentMonth}`, { credentials: 'same-origin' });
    const data = await res.json();

    if (!data.success) {
      alert(`Error checking dealership: ${data.message}`);
      return false;
    }

    // Update Fronx Cell
    const fCell = document.getElementById(`cell-fronx-${id}`);
    if (fCell) {
      if (data.fronx.status === 'verified') {
        fCell.innerHTML = `<span style="display:inline-block; padding:4px 8px; border-radius:4px; background:rgba(34,197,94,0.15); color:#22c55e; font-weight:600; font-size:12px;">✅ Verified</span>` +
                          (data.fronx.video_url ? `<div style="margin-top:4px;"><a href="${data.fronx.video_url}" target="_blank" style="font-size:11px; color:var(--accent);">▶ Watch Video</a></div>` : '');
      } else if (data.fronx.status === 'rejected') {
        fCell.innerHTML = `<span style="display:inline-block; padding:4px 8px; border-radius:4px; background:rgba(239,68,68,0.15); color:#ef4444; font-weight:600; font-size:12px;">❌ Rejected</span>` +
                          (data.fronx.video_url ? `<div style="margin-top:4px;"><a href="${data.fronx.video_url}" target="_blank" style="font-size:11px; color:var(--muted);">▶ Watch</a></div>` : '');
      } else {
        fCell.innerHTML = `<span style="display:inline-block; padding:4px 8px; border-radius:4px; background:rgba(148,163,184,0.12); color:var(--muted); font-size:12px;">— Missing</span>`;
      }
    }

    // Update Every Cell
    const eCell = document.getElementById(`cell-every-${id}`);
    if (eCell) {
      if (data.every.status === 'verified') {
        eCell.innerHTML = `<span style="display:inline-block; padding:4px 8px; border-radius:4px; background:rgba(34,197,94,0.15); color:#22c55e; font-weight:600; font-size:12px;">✅ Verified</span>` +
                          (data.every.video_url ? `<div style="margin-top:4px;"><a href="${data.every.video_url}" target="_blank" style="font-size:11px; color:var(--accent);">▶ Watch Video</a></div>` : '');
      } else if (data.every.status === 'rejected') {
        eCell.innerHTML = `<span style="display:inline-block; padding:4px 8px; border-radius:4px; background:rgba(239,68,68,0.15); color:#ef4444; font-weight:600; font-size:12px;">❌ Rejected</span>` +
                          (data.every.video_url ? `<div style="margin-top:4px;"><a href="${data.every.video_url}" target="_blank" style="font-size:11px; color:var(--muted);">▶ Watch</a></div>` : '');
      } else {
        eCell.innerHTML = `<span style="display:inline-block; padding:4px 8px; border-radius:4px; background:rgba(148,163,184,0.12); color:var(--muted); font-size:12px;">— Missing</span>`;
      }
    }

    // Update Remarks Cell
    const rCell = document.getElementById(`cell-remarks-${id}`);
    if (rCell) {
      let html = '';
      if (data.fronx.reason) html += `<div><strong>Fronx:</strong> <span style="color:var(--text);">${data.fronx.reason}</span></div>`;
      if (data.every.reason) html += `<div style="margin-top:4px;"><strong>Every:</strong> <span style="color:var(--text);">${data.every.reason}</span></div>`;
      rCell.innerHTML = html || '<span style="color:var(--muted);">Evaluated.</span>';
    }

    // Update Score Cell
    const sCell = document.getElementById(`cell-score-${id}`);
    if (sCell) {
      if (data.compliance_score === 100) {
        sCell.innerHTML = `<span class="status-badge status-done" style="font-size:12px; padding:4px 10px;">100% Compliant</span>`;
      } else if (data.compliance_score === 50) {
        sCell.innerHTML = `<span class="status-badge status-partial" style="font-size:12px; padding:4px 10px;">50% Partial</span>`;
      } else {
        sCell.innerHTML = `<span class="status-badge status-pending" style="font-size:12px; padding:4px 10px;">Non-Compliant</span>`;
      }
    }

    return true;
  } catch (err) {
    console.error(err);
    alert('Network error while checking dealership.');
    return false;
  } finally {
    if (rowEl) rowEl.style.opacity = '1';
    if (btnEl) {
      btnEl.classList.remove('loading');
      btnEl.disabled = false;
      btnEl.textContent = 'Re-Check';
    }
  }
}

async function checkAllDealerships() {
  const mainBtn = document.getElementById('check-all-btn');
  const rows = document.querySelectorAll('tr[id^="row-"]');
  if (!confirm(`Are you sure you want to AI-audit all ${rows.length} dealerships for ${currentMonth}?`)) {
    return;
  }

  mainBtn.disabled = true;
  let done = 0;

  for (const row of rows) {
    const id = row.id.replace('row-', '');
    const btn = row.querySelector('.refresh-btn');
    mainBtn.textContent = `Analyzing ${done + 1}/${rows.length}...`;
    await checkOneDealership(id, btn);
    done++;
    await new Promise(r => setTimeout(r, 600));
  }

  mainBtn.textContent = '⚡ Check All Dealerships (AI Verify)';
  mainBtn.disabled = false;
  alert('All dealerships checked successfully! Page will refresh to update totals.');
  window.location.reload();
}
</script>
</body>
</html>
