<?php
require_once __DIR__ . '/includes/Auth.php';
Auth::requireLogin();
require_once __DIR__ . '/includes/Database.php';

$db = Database::getConnection();
$scopedIds = Auth::dealershipIds();
$isSuperAdmin = Auth::isSuperAdmin();

if ($isSuperAdmin) {
    $accessibleDealerships = $db->query("SELECT id, name FROM dealerships ORDER BY name")->fetchAll();
} elseif (!empty($scopedIds)) {
    $placeholders = implode(',', array_fill(0, count($scopedIds), '?'));
    $stmt = $db->prepare("SELECT id, name FROM dealerships WHERE id IN ($placeholders) ORDER BY name");
    $stmt->execute($scopedIds);
    $accessibleDealerships = $stmt->fetchAll();
} else {
    $accessibleDealerships = [];
}

// History list
if ($isSuperAdmin) {
    $history = $db->query("
        SELECT ps.*, d.name AS dealership_name FROM post_submissions ps
        JOIN dealerships d ON d.id = ps.dealership_id
        ORDER BY ps.submitted_at DESC LIMIT 30
    ")->fetchAll();
} elseif (!empty($scopedIds)) {
    $placeholders = implode(',', array_fill(0, count($scopedIds), '?'));
    $stmt = $db->prepare("
        SELECT ps.*, d.name AS dealership_name FROM post_submissions ps
        JOIN dealerships d ON d.id = ps.dealership_id
        WHERE ps.dealership_id IN ($placeholders)
        ORDER BY ps.submitted_at DESC LIMIT 30
    ");
    $stmt->execute($scopedIds);
    $history = $stmt->fetchAll();
} else {
    $history = [];
}

$allVehicleModels = $db->query("SELECT id, name, color FROM vehicle_models ORDER BY name ASC")->fetchAll();

function statusBadgeClass(string $status): string
{
    return $status === 'approved' ? 'status-done' : ($status === 'rejected' ? 'status-flag' : 'status-pending');
}

function statusBadgeLabel(string $status): string
{
    if ($status === 'approved') return '✓ Approved';
    if ($status === 'rejected') return '✗ Rejected';
    return ucfirst($status);
}

function statusBorderColor(string $status): string
{
    return $status === 'approved' ? 'var(--green)' : ($status === 'rejected' ? 'var(--red)' : 'var(--border)');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>(function(){var t=localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
<meta charset="UTF-8">
<title>Post Approval</title>
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
      <h1>Post Approval</h1>
      <div class="subtitle">Submit A Post For Brand-Compliance Checking — Approval Only, Nothing Publishes Automatically</div>
    </div>
  </header>

  <div id="submit-msg"></div>

  <?php if (empty($accessibleDealerships)): ?>
    <div class="empty-state">No Dealership Linked To Your Account.</div>
  <?php else: ?>
  <form id="check-form" class="search-panel" style="margin-bottom:28px;">
    <div class="field">
      <label>Dealership</label>
      <select name="dealership_id" required>
        <?php foreach ($accessibleDealerships as $d): ?>
          <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>Target Vehicle Model</label>
      <select name="vehicle_model_id" required>
        <option value="">-- Select Vehicle Model --</option>
        <?php foreach ($allVehicleModels as $vm): ?>
          <option value="<?= $vm['id'] ?>"><?= htmlspecialchars($vm['name']) ?> (<?= htmlspecialchars($vm['color']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>Post Image</label>
      <input type="file" name="post_image" accept=".jpg,.jpeg,.png,.webp" required>
    </div>
    <div class="field">
      <label>Caption</label>
      <textarea name="caption" rows="3" placeholder="Post caption text..."></textarea>
    </div>
    <button type="submit" class="submit" id="check-submit-btn">Check & Submit</button>
  </form>
  <?php endif; ?>

  <h2 style="font-size:16px; margin-bottom:14px;">Recent Submissions</h2>
  <div id="submissions-list">
    <?php if (empty($history)): ?>
      <div class="empty-state" id="submissions-empty-state">No Submissions Yet.</div>
    <?php else: ?>
      <?php foreach ($history as $h): ?>
      <div class="detail-card" id="submission-card-<?= $h['id'] ?>" data-submission-status="<?= $h['status'] ?>" style="margin-bottom:16px; border-color:<?= statusBorderColor($h['status']) ?>;">
        <h2 style="display:flex; justify-content:space-between; align-items:center; font-size:14px;">
          <span><?= htmlspecialchars($h['dealership_name']) ?></span>
          <span class="status-badge <?= statusBadgeClass($h['status']) ?>"><?= statusBadgeLabel($h['status']) ?></span>
        </h2>
        <img src="<?= htmlspecialchars($h['image_path']) ?>" style="max-width:300px; border-radius:8px; margin:12px 0; display:block;">
        <?php if (!empty($h['caption'])): ?><p style="margin:0 0 10px; font-size:13px; white-space:pre-wrap;"><?= htmlspecialchars($h['caption']) ?></p><?php endif; ?>
        <?php if (!empty($h['reasons'])): ?>
          <ul style="margin:0; padding-left:20px; color:var(--muted); font-size:13px; line-height:1.8;">
            <?php foreach (explode(' | ', $h['reasons']) as $r): ?><li><?= htmlspecialchars($r) ?></li><?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:8px;">
          <div class="timestamp"><?= date('d M, H:i', strtotime($h['submitted_at'])) ?></div>
          <?php if ($isSuperAdmin): ?>
            <button class="delete-row-btn" onclick="deleteSubmission(<?= $h['id'] ?>, this)">Delete</button>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

</div>
</main>
</div>

<script>
const isSuperAdmin = <?= json_encode($isSuperAdmin) ?>;

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
}

function buildSubmissionBox({ id, dealershipName, status, imagePath, caption, reasons, submittedAt }) {
  const borderColor = status === 'approved' ? 'var(--green)' : (status === 'rejected' ? 'var(--red)' : 'var(--border)');
  const badgeClass = status === 'approved' ? 'status-done' : (status === 'rejected' ? 'status-flag' : 'status-pending');
  const badgeLabel = status === 'approved' ? '✓ Approved' : (status === 'rejected' ? '✗ Rejected' : status);

  const card = document.createElement('div');
  card.className = 'detail-card';
  card.id = `submission-card-${id}`;
  card.style.marginBottom = '16px';
  card.style.borderColor = borderColor;

  const reasonsHtml = (reasons && reasons.length)
    ? `<ul style="margin:0; padding-left:20px; color:var(--muted); font-size:13px; line-height:1.8;">${reasons.map(r => `<li>${escapeHtml(r)}</li>`).join('')}</ul>`
    : '';
  const captionHtml = caption ? `<p style="margin:0 0 10px; font-size:13px; white-space:pre-wrap;">${escapeHtml(caption)}</p>` : '';
  const deleteBtnHtml = isSuperAdmin ? `<button class="delete-row-btn" onclick="deleteSubmission(${id}, this)">Delete</button>` : '';

  card.innerHTML = `
    <h2 style="display:flex; justify-content:space-between; align-items:center; font-size:14px;">
      <span>${escapeHtml(dealershipName)}</span>
      <span class="status-badge ${badgeClass}">${badgeLabel}</span>
    </h2>
    <img src="${imagePath}" style="max-width:300px; border-radius:8px; margin:12px 0; display:block;">
    ${captionHtml}
    ${reasonsHtml}
    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:8px;">
      <div class="timestamp">${escapeHtml(submittedAt)}</div>
      ${deleteBtnHtml}
    </div>
  `;
  return card;
}

async function deleteSubmission(id, btnEl) {
  if (!confirm('Delete this submission? This cannot be undone.')) return;
  btnEl.disabled = true;
  try {
    const res = await fetch('delete_post_submission.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `id=${id}`,
    });
    const data = await res.json();
    if (data.success) {
      document.getElementById(`submission-card-${id}`)?.remove();
      const list = document.getElementById('submissions-list');
      if (list && !list.querySelector('.detail-card')) {
        list.innerHTML = '<div class="empty-state" id="submissions-empty-state">No Submissions Yet.</div>';
      }
    } else {
      alert(data.message || 'Delete Failed.');
      btnEl.disabled = false;
    }
  } catch (e) {
    alert('Could Not Reach The Server.');
    btnEl.disabled = false;
  }
}

function compressImageFile(file, maxDim = 720, quality = 0.85) {
  return new Promise((resolve) => {
    if (!file || !file.type.startsWith('image/')) {
      resolve(file);
      return;
    }
    const reader = new FileReader();
    reader.onload = (e) => {
      const img = new Image();
      img.onload = () => {
        let w = img.width;
        let h = img.height;
        if (w <= maxDim && h <= maxDim) {
          resolve(file);
          return;
        }
        if (w >= h) {
          h = Math.round((h / w) * maxDim);
          w = maxDim;
        } else {
          w = Math.round((w / h) * maxDim);
          h = maxDim;
        }
        const canvas = document.createElement('canvas');
        canvas.width = w;
        canvas.height = h;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(img, 0, 0, w, h);
        canvas.toBlob((blob) => {
          if (blob) {
            const compressedFile = new File([blob], file.name.replace(/\.[^/.]+$/, "") + ".jpg", {
              type: 'image/jpeg',
              lastModified: Date.now()
            });
            resolve(compressedFile);
          } else {
            resolve(file);
          }
        }, 'image/jpeg', quality);
      };
      img.onerror = () => resolve(file);
      img.src = e.target.result;
    };
    reader.onerror = () => resolve(file);
    reader.readAsDataURL(file);
  });
}

document.getElementById('check-form')?.addEventListener('submit', async function (e) {
  e.preventDefault();
  const form = e.target;
  const btn = document.getElementById('check-submit-btn');
  const msgEl = document.getElementById('submit-msg');

  btn.disabled = true;
  const originalText = btn.textContent;
  const timer = startElapsedTimer(btn, 'Checking');
  msgEl.innerHTML = '';

  try {
    const fileInput = form.querySelector('input[name="post_image"]');
    const rawFile = fileInput?.files[0];

    const formData = new FormData();
    formData.append('dealership_id', form.querySelector('[name="dealership_id"]')?.value || '');
    formData.append('vehicle_model_id', form.querySelector('[name="vehicle_model_id"]')?.value || '');
    formData.append('caption', form.querySelector('[name="caption"]')?.value || '');

    if (rawFile) {
      const compressedBlob = await compressImageFile(rawFile, 720, 0.85);
      formData.append('post_image', compressedBlob, rawFile.name);
    }

    // Step 1: Save upload & prepare prompt payload (0.05s)
    const prepRes = await fetch('prepare_compliance_payload.php', {
      method: 'POST',
      body: formData,
    });
    const prepData = await prepRes.json();

    if (!prepData.success) {
      stopElapsedTimer(timer);
      btn.disabled = false;
      btn.textContent = originalText;
      msgEl.innerHTML = `<div class="error-msg">${escapeHtml(prepData.message || 'Preparation Failed.')}</div>`;
      return;
    }

    // Step 2: Direct browser call with Strict High-Precision Vision Auditor (gemini-flash-latest)
    const modelsToTry = ['gemini-flash-latest', 'gemini-flash-lite-latest'];
    let geminiResult = { approved: false, reasons: ['Gemini API Direct Fetch Failed'], suggestion: null };
    let successCall = false;

    for (const model of modelsToTry) {
      try {
        const geminiUrl = `https://generativelanguage.googleapis.com/v1beta/models/${model}:generateContent?key=${prepData.api_key}`;
        const geminiRes = await fetch(geminiUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(prepData.payload),
        });

        if (geminiRes.ok) {
          const geminiJson = await geminiRes.json();
          const text = geminiJson.candidates?.[0]?.content?.parts?.[0]?.text;
          if (text) {
            const cleanText = text.replace(/```json/gi, '').replace(/```/g, '').trim();
            geminiResult = JSON.parse(cleanText);
            successCall = true;
            break;
          }
        } else {
          const errJson = await geminiRes.json().catch(() => ({}));
          const errMsg = errJson.error?.message || `HTTP ${geminiRes.status}`;
          geminiResult.reasons = [errMsg];
        }
      } catch (gErr) {
        geminiResult.reasons = [`Client Direct AI Fetch Error: ${gErr.message}`];
      }
    }

    // Step 3: Save result to DB (0.05s)
    const saveRes = await fetch('save_compliance_result.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        submission_id: prepData.submission_id,
        approved: geminiResult.approved,
        reasons: geminiResult.reasons,
        suggestion: geminiResult.suggestion,
      }),
    });
    const saveData = await saveRes.json();

    stopElapsedTimer(timer);
    btn.disabled = false;
    btn.textContent = originalText;

    const isApproved = saveData.status === 'approved';
    msgEl.innerHTML = `<div class="${isApproved ? 'success-msg' : 'error-msg'}">${isApproved ? 'Post Approved.' : 'Post Rejected.'}</div>`;

    document.getElementById('submissions-empty-state')?.remove();
    const list = document.getElementById('submissions-list');
    const box = buildSubmissionBox({
      id: prepData.submission_id,
      dealershipName: prepData.dealership_name,
      status: saveData.status,
      imagePath: prepData.image_path,
      caption: prepData.caption,
      reasons: saveData.reasons,
      submittedAt: 'Just now',
    });
    list.insertBefore(box, list.firstChild);
    form.reset();
  } catch (err) {
    stopElapsedTimer(timer);
    btn.disabled = false;
    btn.textContent = originalText;
    msgEl.innerHTML = `<div class="error-msg">Check Failed: ${escapeHtml(err.message || 'Could Not Reach The Server.')}</div>`;
  }
});
</script>
</body>
</html>
