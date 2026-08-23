<?php
require_once __DIR__ . '/includes/Auth.php';
Auth::requireLogin();
require_once __DIR__ . '/includes/Database.php';

$db = Database::getConnection();
$id = $_GET['id'] ?? $_POST['id'] ?? null;
if (!$id) {
    header('Location: index.php');
    exit;
}
if (!Auth::canAccessDealership((int)$id)) {
    http_response_code(403);
    exit('You Do Not Have Access To This Dealership.');
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Auth::can('edit')) {
    http_response_code(403);
    exit('You Do Not Have Permission To Edit This Dealership.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $db->prepare("
        UPDATE dealerships SET
            name = :name,
            fb_input = :fb,
            ig_search = :ig,
            yt_search = :yt,
            google_search = :gr
        WHERE id = :id
    ");
    $stmt->execute([
        'name' => trim($_POST['name']),
        'fb' => trim($_POST['fb_input']),
        'ig' => trim($_POST['ig_search']),
        'yt' => trim($_POST['yt_search']),
        'gr' => trim($_POST['google_search']),
        'id' => $id,
    ]);

    // Graph API token/page ID — super-admin only, since a leaked token can post/manage
    // that dealership's real Facebook Page. A blank token clears Graph API use for
    // this dealership (falls back to the scraper again). A blank page ID leaves the
    // existing one alone — it's also the scraper's cached ID, not just Graph API's.
    if (Auth::isSuperAdmin()) {
        $db->prepare("UPDATE dealerships SET fb_page_id = COALESCE(NULLIF(:page_id, ''), fb_page_id), fb_page_access_token = :token WHERE id = :id")
           ->execute([
               'page_id' => trim($_POST['fb_page_id'] ?? ''),
               'token' => trim($_POST['fb_page_access_token'] ?? '') ?: null,
               'id' => $id,
           ]);
    }

    // Targets are super-admin-only, even if a scoped user's browser somehow submits them.
    if (Auth::isSuperAdmin()) {
        $targetValues = [
            'fb_target' => (int)($_POST['fb_target'] ?? 0),
            'ig_target' => (int)($_POST['ig_target'] ?? 0),
            'yt_target' => (int)($_POST['yt_target'] ?? 0),
            'gr_target' => (int)($_POST['google_review_target'] ?? 0),
        ];

        $applyToAll = !empty($_POST['apply_targets_all']);
        $targetSql = "
            UPDATE dealerships SET
                fb_target = :fb_target,
                ig_target = :ig_target,
                yt_target = :yt_target,
                google_review_target = :gr_target"
            . ($applyToAll ? "" : " WHERE id = :id");
        $targetStmt = $db->prepare($targetSql);
        $targetStmt->execute($applyToAll ? $targetValues : $targetValues + ['id' => $id]);

        // Digital Enquiry targets are dealership-specific (never "apply to
        // all" — every dealership has its own number) and reviewed roughly
        // every 6 months rather than monthly, so they're always scoped to
        // just this one dealership.
        $digitalEnquiryTarget = trim($_POST['digital_enquiry_target'] ?? '');
        $digitalEnquiryConversionTarget = trim($_POST['digital_enquiry_conversion_target'] ?? '');
        $db->prepare("UPDATE dealerships SET digital_enquiry_target = :det, digital_enquiry_conversion_target = :dct WHERE id = :id")
           ->execute([
               'det' => $digitalEnquiryTarget !== '' ? (int)$digitalEnquiryTarget : null,
               'dct' => $digitalEnquiryConversionTarget !== '' ? (int)$digitalEnquiryConversionTarget : null,
               'id' => $id,
           ]);

        $message = $applyToAll ? 'Dealership Updated. Targets Applied To All Dealerships.' : 'Dealership Updated.';
    } else {
        $message = 'Dealership Updated.';
    }
}

$stmt = $db->prepare("SELECT * FROM dealerships WHERE id = :id");
$stmt->execute(['id' => $id]);
$d = $stmt->fetch();

if (!$d) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>(function(){var t=localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
<meta charset="UTF-8">
<title>Edit Dealership</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#1a1a19">
<link rel="apple-touch-icon" href="assets/icon-192.png">
<script>if('serviceWorker' in navigator){window.addEventListener('load',()=>navigator.serviceWorker.register('sw.js'));}</script>
</head>
<body>
<div class="container narrow">
  <header>
    <h1>Edit Dealership</h1>
    <div class="subtitle"><a href="index.php" style="color:var(--accent)">← Back To Dashboard</a></div>
    <select class="theme-select" id="theme-select" style="width:auto; margin:12px 0 0;" onchange="setTheme(this.value)">
      <option value="dark">🌙 Dark</option>
      <option value="light">☀️ Light</option>
      <option value="midnight">🌌 Midnight</option>
      <option value="slate">🪨 Slate</option>
      <option value="contrast">◐ High Contrast</option>
    </select>
  </header>

  <?php if ($message): ?><div class="success-msg"><?= $message ?></div><?php endif; ?>
  <?php if (!Auth::can('edit')): ?><div class="subtitle" style="margin-bottom:16px;">Read-Only — You Do Not Have Permission To Edit This Dealership.</div><?php endif; ?>

  <?php $readonly = !Auth::can('edit') ? 'disabled' : ''; ?>
  <form method="POST" class="search-panel">
    <input type="hidden" name="id" value="<?= $d['id'] ?>">
    <div class="field">
      <label>Dealership Name</label>
      <input type="text" name="name" value="<?= htmlspecialchars($d['name']) ?>" required <?= $readonly ?>>
    </div>
    <div class="field">
      <label><span class="dot fb"></span>Facebook Page (exact URL/username)</label>
      <input type="text" name="fb_input" value="<?= htmlspecialchars($d['fb_input'] ?? '') ?>" placeholder="facebook.com/UnitedMotors" <?= $readonly ?>>
    </div>
    <div class="field">
      <label><span class="dot ig"></span>Instagram Profile (URL or @username)</label>
      <input type="text" name="ig_search" value="<?= htmlspecialchars($d['ig_search'] ?? '') ?>" placeholder="instagram.com/unitedmotors" <?= $readonly ?>>
    </div>
    <div class="field">
      <label><span class="dot yt"></span>YouTube Channel Name</label>
      <input type="text" name="yt_search" value="<?= htmlspecialchars($d['yt_search'] ?? '') ?>" placeholder="United Motors Official" <?= $readonly ?>>
    </div>
    <div class="field">
      <label><span class="dot gr"></span>Google Business Name + City</label>
      <input type="text" name="google_search" value="<?= htmlspecialchars($d['google_search'] ?? '') ?>" placeholder="United Motors Multan" <?= $readonly ?>>
    </div>

    <?php if (Auth::isSuperAdmin()): ?>
    <hr style="border-color:var(--border); margin:8px 0;">
    <div class="subtitle" style="margin-bottom:4px;">Graph API Access (Super Admin Only) — Leave Blank To Keep Using The Scraper</div>
    <div class="field">
      <label><span class="dot fb"></span>Facebook Page ID (Numeric)</label>
      <input type="text" name="fb_page_id" value="<?= htmlspecialchars($d['fb_page_id'] ?? '') ?>" placeholder="e.g. 100063955213535">
    </div>
    <div class="field">
      <label><span class="dot fb"></span>Facebook Page Access Token (Long-Lived / System User)</label>
      <input type="text" name="fb_page_access_token" value="<?= htmlspecialchars($d['fb_page_access_token'] ?? '') ?>" placeholder="Paste token here once granted admin access">
    </div>
    <?php if (!empty($d['ig_business_account_id'])): ?>
    <div class="field">
      <label><span class="dot ig"></span>Instagram Business Account ID (Auto-Resolved)</label>
      <input type="text" value="<?= htmlspecialchars($d['ig_business_account_id']) ?>" disabled>
    </div>
    <?php endif; ?>

    <hr style="border-color:var(--border); margin:8px 0;">
    <div class="subtitle" style="margin-bottom:4px;">Targets (Super Admin Only)</div>
    <div class="field">
      <label><span class="dot fb"></span>FB Followers Target</label>
      <input type="number" name="fb_target" min="0" value="<?= (int)($d['fb_target'] ?? 0) ?>">
    </div>
    <div class="field">
      <label><span class="dot ig"></span>IG Followers Target</label>
      <input type="number" name="ig_target" min="0" value="<?= (int)($d['ig_target'] ?? 0) ?>">
    </div>
    <div class="field">
      <label><span class="dot yt"></span>YT Subscribers Target</label>
      <input type="number" name="yt_target" min="0" value="<?= (int)($d['yt_target'] ?? 0) ?>">
    </div>
    <div class="field">
      <label><span class="dot gr"></span>Google Reviews Target</label>
      <input type="number" name="google_review_target" min="0" value="<?= (int)($d['google_review_target'] ?? 0) ?>">
    </div>
    <div class="field" style="display:flex; flex-direction:row; align-items:center; gap:8px;">
      <input type="checkbox" name="apply_targets_all" value="1" id="apply_targets_all" style="width:auto;">
      <label for="apply_targets_all" style="margin:0;">Apply These Targets To All Dealerships</label>
    </div>

    <hr style="border-color:var(--border); margin:8px 0;">
    <div class="subtitle" style="margin-bottom:4px;">Digital Enquiry Targets (Super Admin Only) — Dealership-Specific, Reviewed Every ~6 Months, Never Applied To All</div>
    <div class="field">
      <label>Digital Enquiry Target</label>
      <input type="number" name="digital_enquiry_target" min="0" value="<?= htmlspecialchars((string)($d['digital_enquiry_target'] ?? '')) ?>">
    </div>
    <div class="field">
      <label>Digital Enquiry Conversion Target</label>
      <input type="number" name="digital_enquiry_conversion_target" min="0" value="<?= htmlspecialchars((string)($d['digital_enquiry_conversion_target'] ?? '')) ?>">
    </div>
    <?php endif; ?>

    <?php if (Auth::can('edit')): ?>
    <button type="submit" class="submit">Save Changes</button>
    <?php endif; ?>
  </form>

  <?php if (Auth::can('delete')): ?>
  <button type="button" class="delete-btn" onclick="deleteDealership(<?= $d['id'] ?>, <?= htmlspecialchars(json_encode($d['name'])) ?>)">Delete Dealership</button>
  <?php endif; ?>
</div>

<script>
async function deleteDealership(id, name) {
  if (!confirm(`Delete "${name}"? This cannot be undone.`)) return;
  const res = await fetch('delete_dealership.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `id=${id}`,
  });
  const data = await res.json();
  if (data.success) {
    window.location.href = 'index.php';
  } else {
    alert('Delete Failed.');
  }
}
function setTheme(theme) {
  document.documentElement.setAttribute('data-theme', theme);
  localStorage.setItem('theme', theme);
}
document.getElementById('theme-select').value = document.documentElement.getAttribute('data-theme') || 'dark';
</script>
</body>
</html>
