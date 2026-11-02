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
    CREATE TABLE IF NOT EXISTS campaign_source_posts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        source_post_id VARCHAR(50) NOT NULL UNIQUE,
        category VARCHAR(50) NOT NULL,
        message_snippet VARCHAR(500) NULL,
        image_url VARCHAR(1000) NULL,
        post_url VARCHAR(500) NULL,
        published_at DATETIME NULL,
        detected_keywords VARCHAR(255) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$db->exec("
    CREATE TABLE IF NOT EXISTS post_image_scan_cache (
        id INT AUTO_INCREMENT PRIMARY KEY,
        post_id VARCHAR(100) NOT NULL UNIQUE,
        image_url VARCHAR(1000) NULL,
        extracted_text TEXT NULL,
        is_exchange TINYINT(1) DEFAULT 0,
        is_certified_used_car TINYINT(1) DEFAULT 0,
        classification_reason VARCHAR(500) NULL,
        scanned_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

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
        psmcl_exchange_tracked INT DEFAULT 0,
        psmcl_exchange_reshared INT DEFAULT 0,
        psmcl_exchange_status VARCHAR(30) DEFAULT 'no_source_post',
        psmcl_usedcar_tracked INT DEFAULT 0,
        psmcl_usedcar_reshared INT DEFAULT 0,
        psmcl_usedcar_status VARCHAR(30) DEFAULT 'no_source_post',
        compliance_score INT DEFAULT 0,
        checked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_dealership_campaign (dealership_id, date_from, date_to)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

if (!$isSuperAdmin && empty($scopedIds)) {
    $dealerships = [];
} else {
    $sql = "SELECT id, name, fb_input, fb_url, fb_page_id FROM dealerships WHERE 1=1"
        . (!$isSuperAdmin ? " AND id IN (" . implode(',', array_fill(0, count($scopedIds), '?')) . ")" : "")
        . " ORDER BY name";
    $stmt = $db->prepare($sql);
    $stmt->execute(!$isSuperAdmin ? $scopedIds : []);
    $dealerships = $stmt->fetchAll();
}

$defaultFrom = date('Y-m-01');
$defaultTo = date('Y-m-d');
$from = $_GET['from'] ?? $defaultFrom;
$to = $_GET['to'] ?? $defaultTo;
$hasRange = !empty($from) && !empty($to);

$sourceStats = ['exchange_tracked' => 0, 'usedcar_tracked' => 0];
$recordsMap = [];

if ($hasRange) {
    $fromDt = $from . ' 00:00:00';
    $toDt = $to . ' 23:59:59';

    // Count source posts
    $stmt = $db->prepare("SELECT category, COUNT(*) as cnt FROM campaign_source_posts WHERE published_at BETWEEN ? AND ? GROUP BY category");
    $stmt->execute([$fromDt, $toDt]);
    foreach ($stmt->fetchAll() as $row) {
        if ($row['category'] === 'exchange') {
            $sourceStats['exchange_tracked'] = (int)$row['cnt'];
        } elseif ($row['category'] === 'certified_used_car') {
            $sourceStats['usedcar_tracked'] = (int)$row['cnt'];
        }
    }

    if (!empty($dealerships)) {
        $dIds = array_column($dealerships, 'id');
        $placeholders = implode(',', array_fill(0, count($dIds), '?'));
        $stmt = $db->prepare("SELECT * FROM campaign_compliance_records WHERE dealership_id IN ($placeholders) AND date_from = ? AND date_to = ?");
        $stmt->execute(array_merge($dIds, [$from, $to]));
        foreach ($stmt->fetchAll() as $row) {
            $recordsMap[$row['dealership_id']] = $row;
        }
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
.badge-green { display:inline-block; padding:4px 8px; border-radius:4px; background:rgba(34,197,94,0.15); color:#22c55e; font-weight:600; font-size:12px; }
.badge-red { display:inline-block; padding:4px 8px; border-radius:4px; background:rgba(239,68,68,0.15); color:#ef4444; font-weight:600; font-size:12px; }
.badge-gray { display:inline-block; padding:4px 8px; border-radius:4px; background:rgba(148,163,184,0.12); color:var(--muted); font-size:12px; }
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
          <div class="subtitle">Dealership Own Content vs PSMCL Source Reshares — Scraped via Bright Data with AI Image &amp; Text Scan</div>
        </div>
        <div class="toolbar">
          <button class="btn" id="refresh-source-btn" onclick="refreshSource()">
            🔄 Refresh PSMCL Source Posts
          </button>
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

        <?php
        $ownExCount = 0;
        $ownUcCount = 0;
        foreach ($dealerships as $d) {
            $r = $recordsMap[$d['id']] ?? null;
            if ($r && $r['own_exchange_status'] === 'uploaded') $ownExCount++;
            if ($r && $r['own_usedcar_status'] === 'uploaded') $ownUcCount++;
        }
        ?>
        <div class="stat-box">
          <div style="font-size:11px; text-transform:uppercase; color:var(--muted); font-family:'IBM Plex Mono', monospace;">Own Exchange Content</div>
          <div style="font-size:24px; font-weight:700; color:#22c55e; margin-top:4px;"><?= $ownExCount ?> / <?= count($dealerships) ?></div>
          <div style="font-size:11px; color:var(--muted); margin-top:2px;">Dealerships with Own Post</div>
        </div>

        <div class="stat-box">
          <div style="font-size:11px; text-transform:uppercase; color:var(--muted); font-family:'IBM Plex Mono', monospace;">Own Used Car Content</div>
          <div style="font-size:24px; font-weight:700; color:#3b82f6; margin-top:4px;"><?= $ownUcCount ?> / <?= count($dealerships) ?></div>
          <div style="font-size:11px; color:var(--muted); margin-top:2px;">Dealerships with Own Post</div>
        </div>

        <div class="stat-box">
          <div style="font-size:11px; text-transform:uppercase; color:var(--muted); font-family:'IBM Plex Mono', monospace;">PSMCL Source Posts</div>
          <div style="font-size:24px; font-weight:700; color:#f59e0b; margin-top:4px;">
            Ex: <?= $sourceStats['exchange_tracked'] ?> | UC: <?= $sourceStats['usedcar_tracked'] ?>
          </div>
          <div style="font-size:11px; color:var(--muted); margin-top:2px;">Tracked on Suzuki Pakistan</div>
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
              <th style="width:35px;">#</th>
              <th style="width:180px;">Dealership</th>
              <th colspan="2" style="text-align:center; background:rgba(255,255,255,0.03); border-bottom:1px solid var(--border-color);">
                Dealership Own Content
              </th>
              <th colspan="2" style="text-align:center; background:rgba(255,255,255,0.06); border-bottom:1px solid var(--border-color);">
                Pak Suzuki (PSMCL) Reshares
              </th>
              <th style="width:105px; text-align:center;">Score</th>
              <th style="width:110px; text-align:center;">Checked</th>
              <th style="width:75px; text-align:center;">Action</th>
            </tr>
            <tr style="font-size:11px; opacity:0.85;">
              <th></th>
              <th></th>
              <th style="text-align:center; background:rgba(255,255,255,0.03);">Exchange (Own)</th>
              <th style="text-align:center; background:rgba(255,255,255,0.03);">Used Car (Own)</th>
              <th style="text-align:center; background:rgba(255,255,255,0.06);">Exchange (PSMCL)</th>
              <th style="text-align:center; background:rgba(255,255,255,0.06);">Used Car (PSMCL)</th>
              <th></th>
              <th></th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($dealerships as $i => $d): ?>
            <?php $r = $recordsMap[$d['id']] ?? null; ?>
            <tr id="campaign-row-<?= $d['id'] ?>">
              <td class="row-number"><?= $i + 1 ?></td>
              <td class="name">
                <strong><?= htmlspecialchars($d['name']) ?></strong>
                <?php if (!empty($d['fb_input'])): ?>
                  <div style="font-size:11px; color:var(--muted); overflow:hidden; text-overflow:ellipsis; max-width:180px; white-space:nowrap;">
                    <?= htmlspecialchars($d['fb_input']) ?>
                  </div>
                <?php endif; ?>
              </td>

              <!-- Own Exchange -->
              <td style="text-align:center;" id="cell-own-ex-<?= $d['id'] ?>">
                <?php if ($r && $r['own_exchange_status'] === 'uploaded'): ?>
                  <span class="badge-green">✅ Uploaded (<?= (int)$r['own_exchange_count'] ?>)</span>
                  <?php if (!empty($r['own_exchange_url'])): ?>
                    <div style="margin-top:4px;"><a href="<?= htmlspecialchars($r['own_exchange_url']) ?>" target="_blank" style="font-size:11px; color:var(--accent);">▶ View Post</a></div>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="badge-red">❌ Not Uploaded</span>
                <?php endif; ?>
              </td>

              <!-- Own Used Car -->
              <td style="text-align:center;" id="cell-own-uc-<?= $d['id'] ?>">
                <?php if ($r && $r['own_usedcar_status'] === 'uploaded'): ?>
                  <span class="badge-green">✅ Uploaded (<?= (int)$r['own_usedcar_count'] ?>)</span>
                  <?php if (!empty($r['own_usedcar_url'])): ?>
                    <div style="margin-top:4px;"><a href="<?= htmlspecialchars($r['own_usedcar_url']) ?>" target="_blank" style="font-size:11px; color:var(--accent);">▶ View Post</a></div>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="badge-red">❌ Not Uploaded</span>
                <?php endif; ?>
              </td>

              <!-- PSMCL Exchange -->
              <td style="text-align:center;" id="cell-psmcl-ex-<?= $d['id'] ?>">
                <?php if ($r && $r['psmcl_exchange_status'] === 'reshared'): ?>
                  <span class="badge-green">✅ Reshared (<?= (int)$r['psmcl_exchange_reshared'] ?>/<?= (int)$r['psmcl_exchange_tracked'] ?>)</span>
                <?php elseif (!$r || $r['psmcl_exchange_status'] === 'no_source_post'): ?>
                  <span class="badge-gray">— No PSMCL Post</span>
                <?php else: ?>
                  <span class="badge-red">❌ Missed (0/<?= (int)$r['psmcl_exchange_tracked'] ?>)</span>
                <?php endif; ?>
              </td>

              <!-- PSMCL Used Car -->
              <td style="text-align:center;" id="cell-psmcl-uc-<?= $d['id'] ?>">
                <?php if ($r && $r['psmcl_usedcar_status'] === 'reshared'): ?>
                  <span class="badge-green">✅ Reshared (<?= (int)$r['psmcl_usedcar_reshared'] ?>/<?= (int)$r['psmcl_usedcar_tracked'] ?>)</span>
                <?php elseif (!$r || $r['psmcl_usedcar_status'] === 'no_source_post'): ?>
                  <span class="badge-gray">— No PSMCL Post</span>
                <?php else: ?>
                  <span class="badge-red">❌ Missed (0/<?= (int)$r['psmcl_usedcar_tracked'] ?>)</span>
                <?php endif; ?>
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
        • <strong>Dealership Own Content:</strong> Scraped directly from dealership Facebook pages via Bright Data. Evaluates whether the dealership created and published its own posts/flyers for <em>Exchange</em> or <em>Certified Used Car</em>.<br>
        • <strong>Pak Suzuki (PSMCL) Content:</strong> Monitors whether dealership shared official Exchange or Used Car campaigns published on the central Suzuki Pakistan page.<br>
        • <strong>Multimodal AI Graphic Inspection:</strong> Uses Gemini Vision Flash to read text embedded inside image banners (e.g. flyers with no caption text) looking for <em>"Exchange"</em>, <em>"Certified Used Car"</em>, <em>"Trade-In"</em>, and <em>"Used Car Gala"</em>.
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

    // Update Own Exchange Cell
    const ownExCell = document.getElementById(`cell-own-ex-${id}`);
    if (ownExCell) {
      if (data.own_exchange.status === 'uploaded') {
        ownExCell.innerHTML = `<span class="badge-green">✅ Uploaded (${data.own_exchange.count})</span>` +
          (data.own_exchange.url ? `<div style="margin-top:4px;"><a href="${data.own_exchange.url}" target="_blank" style="font-size:11px; color:var(--accent);">▶ View Post</a></div>` : '');
      } else {
        ownExCell.innerHTML = `<span class="badge-red">❌ Not Uploaded</span>`;
      }
    }

    // Update Own Used Car Cell
    const ownUcCell = document.getElementById(`cell-own-uc-${id}`);
    if (ownUcCell) {
      if (data.own_usedcar.status === 'uploaded') {
        ownUcCell.innerHTML = `<span class="badge-green">✅ Uploaded (${data.own_usedcar.count})</span>` +
          (data.own_usedcar.url ? `<div style="margin-top:4px;"><a href="${data.own_usedcar.url}" target="_blank" style="font-size:11px; color:var(--accent);">▶ View Post</a></div>` : '');
      } else {
        ownUcCell.innerHTML = `<span class="badge-red">❌ Not Uploaded</span>`;
      }
    }

    // Update PSMCL Exchange Cell
    const psmclExCell = document.getElementById(`cell-psmcl-ex-${id}`);
    if (psmclExCell) {
      if (data.psmcl_exchange.status === 'reshared') {
        psmclExCell.innerHTML = `<span class="badge-green">✅ Reshared (${data.psmcl_exchange.reshared}/${data.psmcl_exchange.tracked})</span>`;
      } else if (data.psmcl_exchange.status === 'no_source_post') {
        psmclExCell.innerHTML = `<span class="badge-gray">— No PSMCL Post</span>`;
      } else {
        psmclExCell.innerHTML = `<span class="badge-red">❌ Missed (0/${data.psmcl_exchange.tracked})</span>`;
      }
    }

    // Update PSMCL Used Car Cell
    const psmclUcCell = document.getElementById(`cell-psmcl-uc-${id}`);
    if (psmclUcCell) {
      if (data.psmcl_usedcar.status === 'reshared') {
        psmclUcCell.innerHTML = `<span class="badge-green">✅ Reshared (${data.psmcl_usedcar.reshared}/${data.psmcl_usedcar.tracked})</span>`;
      } else if (data.psmcl_usedcar.status === 'no_source_post') {
        psmclUcCell.innerHTML = `<span class="badge-gray">— No PSMCL Post</span>`;
      } else {
        psmclUcCell.innerHTML = `<span class="badge-red">❌ Missed (0/${data.psmcl_usedcar.tracked})</span>`;
      }
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

async function refreshSource() {
  const btn = document.getElementById('refresh-source-btn');
  if (btn) {
    btn.disabled = true;
    btn.innerText = 'Refreshing PSMCL...';
  }
  const { from, to } = getActiveRange();

  try {
    const res = await fetch(`/refresh_campaign_source.php?from=${from}&to=${to}`);
    const data = await res.json();
    if (data.success) {
      alert(`PSMCL Source Posts Refreshed!\nExchange Posts Tracked: ${data.exchange_tracked} (${data.new_exchange} new)\nCertified Used Car Posts Tracked: ${data.usedcar_tracked} (${data.new_usedcar} new)`);
      window.location.reload();
    } else {
      alert(`Refresh failed: ${data.message || 'Unknown error'}`);
    }
  } catch (err) {
    alert(`Network error: ${err.message}`);
  } finally {
    if (btn) {
      btn.disabled = false;
      btn.innerText = '🔄 Refresh PSMCL Source Posts';
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
