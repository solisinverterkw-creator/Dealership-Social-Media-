<?php
require_once __DIR__ . '/includes/Auth.php';
Auth::requireLogin();
if (!Auth::canView('manual_publish')) {
    http_response_code(403);
    exit('You Do Not Have Access To This Page.');
}
require_once __DIR__ . '/includes/Database.php';

$db = Database::getConnection();
$sourceUrlSetting = $db->query("SELECT setting_value FROM app_settings WHERE setting_key = 'source_page_url'")->fetchColumn();
$zapierPageCount = (int)($db->query("SELECT setting_value FROM app_settings WHERE setting_key = 'zapier_connected_pages_count'")->fetchColumn() ?: 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>(function(){var t=localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
<meta charset="UTF-8">
<title>Publish Content</title>
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

  <div class="search-panel" style="margin-bottom:24px; display:flex; gap:16px; align-items:flex-end; flex-wrap:wrap;">
    <div class="field" style="margin-bottom:0;">
      <label>Source Page From</label>
      <input type="date" id="source-from-input">
    </div>
    <div class="field" style="margin-bottom:0;">
      <label>Source Page To</label>
      <input type="date" id="source-to-input">
    </div>
    <button type="button" class="submit" style="width:auto; padding:11px 24px;" onclick="loadRecentPosts(this)">Load By Date Range</button>
  </div>

  <header>
    <div>
      <h1>Publish Content</h1>
      <div class="subtitle">Source Page's Recent Posts — Publish New Ones To All Active Dealer Pages</div>
    </div>
    <div class="toolbar">
      <button class="btn primary" id="load-posts-btn" onclick="loadRecentPosts(this)">Check Source Page Now</button>
    </div>
  </header>

  <div class="search-panel" style="margin-bottom:24px;">
    <div class="field" style="flex:1;">
      <label>Source Page URL</label>
      <input type="text" id="source-url-input" value="<?= htmlspecialchars($sourceUrlSetting ?? '') ?>" placeholder="https://www.facebook.com/YourSourcePage">
    </div>
    <button type="button" class="submit" onclick="saveSourceUrl()">Save</button>
  </div>
  <div id="source-url-msg" style="margin-bottom:16px;"></div>

  <div class="search-panel" style="margin-bottom:24px;">
    <div class="field">
      <label>Zapier-Connected Pages (Update Manually As You Add Dealerships In Zapier)</label>
      <input type="number" id="zapier-count-input" value="<?= $zapierPageCount ?>" min="0" style="max-width:120px;">
    </div>
    <button type="button" class="submit" onclick="saveZapierCount()">Save</button>
  </div>
  <div id="zapier-count-msg" style="margin-bottom:16px;"></div>

  <?php if ($zapierPageCount === 0): ?>
    <div class="empty-state">No Zapier-Connected Pages Set. Enter A Count Above Once Dealerships Are Added In Zapier.</div>
  <?php else: ?>

  <div class="subtitle" style="margin-bottom:16px;">
    <?= $zapierPageCount ?> Dealer Page(s) Via Zapier Will Receive New Posts.
  </div>

  <div id="posts-list"></div>

  <?php endif; ?>

</div>
</main>
</div>

<script>
const zapierCount = <?= $zapierPageCount ?>;
const publishButtonLabel = 'Publish (Via Zapier)';

// fetch_source_recent_posts.php responds immediately with {status:'started'}
// (Bright Data can take several minutes, past LiteSpeed's own connection
// timeout) and keeps working server-side — poll refresh_status.php until the
// background job finishes.
async function pollSourcePosts(jobKey, maxWaitMs = 300000, intervalMs = 3000) {
  const start = Date.now();
  while (Date.now() - start < maxWaitMs) {
    await new Promise(resolve => setTimeout(resolve, intervalMs));
    const r = await fetch(`refresh_status.php?id=${encodeURIComponent(jobKey)}&metric=source_posts`);
    const d = await r.json();
    if (d.status === 'done') return { success: true, ...d };
    if (d.status === 'error') return { success: false, ...d };
  }
  return { success: false, message: 'Timed Out Waiting For Background Check.' };
}

async function loadRecentPosts(btnEl) {
  btnEl = btnEl || document.getElementById('load-posts-btn');
  const originalText = btnEl.textContent;
  btnEl.disabled = true;
  const timer = startElapsedTimer(btnEl, 'Checking');

  const listEl = document.getElementById('posts-list');
  listEl.innerHTML = '<div class="subtitle">Scanning the source page — this usually takes 20-90s, occasionally longer if Bright Data retries.</div>';

  const from = document.getElementById('source-from-input')?.value;
  const to = document.getElementById('source-to-input')?.value;
  const usingRange = Boolean(from && to);
  const url = usingRange
    ? `fetch_source_recent_posts.php?from=${from}&to=${to}`
    : 'fetch_source_recent_posts.php';
  const jobKey = usingRange ? `${from}_${to}` : 'recent';

  try {
    const res = await fetch(url);
    let data = await res.json();
    if (data.status === 'started') {
      data = await pollSourcePosts(jobKey);
    }

    if (!data.success) {
      listEl.innerHTML = `<div class="error-msg">${data.message}</div>`;
      return;
    }

    if (data.posts.length === 0) {
      listEl.innerHTML = `<div class="empty-state">No Posts Found ${usingRange ? 'In This Date Range' : 'On The Source Page'}.</div>`;
      return;
    }

    listEl.innerHTML = '';
    data.posts.forEach(post => {
      listEl.appendChild(buildPostCard(post));
    });
  } catch (e) {
    listEl.innerHTML = `<div class="error-msg">Could Not Reach The Source Page.</div>`;
  } finally {
    stopElapsedTimer(timer);
    btnEl.disabled = false;
    btnEl.textContent = originalText;
  }
}

function buildPostCard(post) {
  const card = document.createElement('div');
  card.className = 'detail-card';
  card.id = `post-card-${post.id}`;

  const dateStr = post.created_time ? new Date(post.created_time).toLocaleString() : '';
  const statusBadge = post.is_processed
    ? '<span class="status-badge status-done">Already Published</span>'
    : '<span class="status-badge status-partial">New — Not Yet Published</span>';

  card.innerHTML = `
    <h2 style="display:flex; justify-content:space-between; align-items:center; font-size:14px;">
      <span>${dateStr}</span>
      ${statusBadge}
    </h2>
    <p style="margin:12px 0; font-size:13px; white-space:pre-wrap;">${escapeHtml(post.message || '(No Text)')}</p>
    ${post.image_url ? `<img src="${post.image_url}" style="max-width:280px; border-radius:6px; border:1px solid var(--border); margin-bottom:12px;">` : ''}
    ${post.video_url ? `<video src="${post.video_url}" controls style="max-width:280px; border-radius:6px; border:1px solid var(--border); margin-bottom:12px;"></video>` : ''}
    <div style="display:flex; gap:10px;">
      <button class="btn primary" ${post.is_processed || zapierCount === 0 ? 'disabled' : ''} onclick='publishPost(${JSON.stringify(post)})'>
        ${publishButtonLabel}
      </button>
      ${post.is_processed ? `<button class="btn" onclick='unpublishPost(${JSON.stringify(post)})'>Undo Publish (Republish Later)</button>` : ''}
    </div>
    <div class="progress-wrap" id="progress-wrap-${post.id}" style="display:none; margin-top:14px;">
      <div class="progress-bar-track"><div class="progress-bar-fill" id="progress-bar-fill-${post.id}" style="width:0%"></div></div>
      <div class="progress-text" id="progress-text-${post.id}"></div>
    </div>
  `;
  return card;
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
}

async function publishPost(post) {
  const card = document.getElementById(`post-card-${post.id}`);
  const btn = card.querySelector('button.btn.primary');
  btn.disabled = true;

  const progressWrap = document.getElementById(`progress-wrap-${post.id}`);
  const progressFill = document.getElementById(`progress-bar-fill-${post.id}`);
  const progressText = document.getElementById(`progress-text-${post.id}`);
  progressWrap.style.display = 'block';

  await publishToAllTargets(post, progressFill, progressText, btn);
}

async function publishToAllTargets(post, progressFill, progressText, btn) {
  // Only Zapier actually reshares (Facebook rejects Graph API's reshare-look
  // attempt without extra Page permissions — see FacebookPoster.php) — Target
  // Pages/Graph API publishing was dropped from this flow for that reason.
  let failed = 0;

  progressFill.style.width = '50%';
  progressText.textContent = `Sending To Zapier...`;
  btn.textContent = `Publishing...`;

  try {
    const zr = await fetch('send_to_zapier.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        message: post.message || '',
        image_url: post.image_url || '',
        video_url: post.video_url || '',
        source_post_id: post.id,
        source_url: post.source_url,
      }),
    });
    const zdata = await zr.json();
    if (!zdata.success) failed++;
  } catch (e) {
    failed++;
  }

  progressFill.style.width = '100%';
  progressText.textContent = failed ? `100% Complete — Zapier Failed` : `100% Complete — Sent To Zapier`;

  await fetch('mark_source_post_processed.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ source_post_id: post.id, message_snippet: post.message || '' }),
  });

  btn.textContent = 'Published';
}

async function unpublishPost(post) {
  const card = document.getElementById(`post-card-${post.id}`);
  const undoBtn = card.querySelector('button.btn:not(.primary)');
  if (undoBtn) { undoBtn.disabled = true; undoBtn.textContent = 'Undoing...'; }

  try {
    const res = await fetch('unmark_source_post_processed.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ source_post_id: post.id }),
    });
    const data = await res.json();
    if (!data.success) {
      alert(data.message || 'Could Not Undo Publish.');
      if (undoBtn) { undoBtn.disabled = false; undoBtn.textContent = 'Undo Publish (Republish Later)'; }
      return;
    }
    post.is_processed = false;
    card.replaceWith(buildPostCard(post));
  } catch (e) {
    alert('Could Not Reach The Server.');
    if (undoBtn) { undoBtn.disabled = false; undoBtn.textContent = 'Undo Publish (Republish Later)'; }
  }
}

async function saveSourceUrl() {
  const input = document.getElementById('source-url-input');
  const msgEl = document.getElementById('source-url-msg');
  const url = input.value.trim();
  if (!url) {
    msgEl.innerHTML = `<div class="error-msg">Source Page URL Is Empty.</div>`;
    return;
  }

  msgEl.innerHTML = `<div class="subtitle">Checking...</div>`;
  try {
    const res = await fetch('update_source_page_url.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ url }),
    });
    const data = await res.json();
    if (!data.success) {
      msgEl.innerHTML = `<div class="error-msg">${data.message}</div>`;
      return;
    }
    msgEl.innerHTML = `<div class="success-msg">Source Page Saved.</div>`;
    loadRecentPosts();
  } catch (e) {
    msgEl.innerHTML = `<div class="error-msg">Could Not Save Source Page.</div>`;
  }
}

async function saveZapierCount() {
  const input = document.getElementById('zapier-count-input');
  const msgEl = document.getElementById('zapier-count-msg');
  const count = parseInt(input.value, 10) || 0;

  msgEl.innerHTML = `<div class="subtitle">Saving...</div>`;
  try {
    const res = await fetch('update_zapier_count.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ count }),
    });
    const data = await res.json();
    if (!data.success) {
      msgEl.innerHTML = `<div class="error-msg">${data.message}</div>`;
      return;
    }
    msgEl.innerHTML = `<div class="success-msg">Saved. Refresh The Page To See Updated Totals.</div>`;
  } catch (e) {
    msgEl.innerHTML = `<div class="error-msg">Could Not Save.</div>`;
  }
}

// Does NOT auto-load on page open — every load/refresh would silently consume
// a RapidAPI request. Posts are only fetched when "Check Source Page Now" is
// clicked, or right after saving a new source URL.
const initialListEl = document.getElementById('posts-list');
if (initialListEl) {
  initialListEl.innerHTML = '<div class="empty-state">Click "Check Source Page Now" To Load Posts.</div>';
}
</script>
</body>
</html>
