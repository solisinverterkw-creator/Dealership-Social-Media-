<?php
require_once __DIR__ . '/includes/Auth.php';
Auth::requireSuperAdmin();
require_once __DIR__ . '/includes/Database.php';

$db = Database::getConnection();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $db->prepare("
        INSERT INTO dealerships (name, fb_input, ig_search, yt_search, google_search, fb_target, ig_target, yt_target, google_review_target)
        VALUES (:name, :fb, :ig, :yt, :gr, :fb_target, :ig_target, :yt_target, :gr_target)
    ");
    $stmt->execute([
        'name' => trim($_POST['name']),
        'fb' => trim($_POST['fb_input']),
        'ig' => trim($_POST['ig_search']),
        'yt' => trim($_POST['yt_search']),
        'gr' => trim($_POST['google_search']),
        'fb_target' => (int)($_POST['fb_target'] ?? 0),
        'ig_target' => (int)($_POST['ig_target'] ?? 0),
        'yt_target' => (int)($_POST['yt_target'] ?? 0),
        'gr_target' => (int)($_POST['google_review_target'] ?? 0),
    ]);
    $message = 'Dealership Added.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>(function(){var t=localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
<meta charset="UTF-8">
<title>Add Dealership</title>
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
    <h1>Add Dealership</h1>
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

  <form method="POST" class="search-panel">
    <div class="field">
      <label>Dealership Name</label>
      <input type="text" name="name" placeholder="e.g. United Motors" required>
    </div>
    <div class="field">
      <label><span class="dot fb"></span>Facebook Page (exact URL/username)</label>
      <input type="text" name="fb_input" placeholder="facebook.com/UnitedMotors">
    </div>
    <div class="field">
      <label><span class="dot ig"></span>Instagram Profile (URL or @username)</label>
      <input type="text" name="ig_search" placeholder="instagram.com/unitedmotors">
    </div>
    <div class="field">
      <label><span class="dot yt"></span>YouTube Channel Name</label>
      <input type="text" name="yt_search" placeholder="United Motors Official">
    </div>
    <div class="field">
      <label><span class="dot gr"></span>Google Business Name + City</label>
      <input type="text" name="google_search" placeholder="United Motors Multan">
    </div>

    <hr style="border-color:var(--border); margin:8px 0;">
    <div class="subtitle" style="margin-bottom:4px;">Targets (Optional)</div>
    <div class="field">
      <label><span class="dot fb"></span>FB Followers Target</label>
      <input type="number" name="fb_target" min="0" placeholder="0">
    </div>
    <div class="field">
      <label><span class="dot ig"></span>IG Followers Target</label>
      <input type="number" name="ig_target" min="0" placeholder="0">
    </div>
    <div class="field">
      <label><span class="dot yt"></span>YT Subscribers Target</label>
      <input type="number" name="yt_target" min="0" placeholder="0">
    </div>
    <div class="field">
      <label><span class="dot gr"></span>Google Reviews Target</label>
      <input type="number" name="google_review_target" min="0" placeholder="0">
    </div>

    <button type="submit" class="submit">Add Dealership</button>
  </form>
</div>
<script>
function setTheme(theme) {
  document.documentElement.setAttribute('data-theme', theme);
  localStorage.setItem('theme', theme);
}
document.getElementById('theme-select').value = document.documentElement.getAttribute('data-theme') || 'dark';
</script>
</body>
</html>
