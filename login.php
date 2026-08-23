<?php
require_once __DIR__ . '/includes/Auth.php';
Auth::start();

if (Auth::check()) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if (Auth::attempt($username, $password)) {
        header('Location: index.php');
        exit;
    }
    $error = 'Incorrect Username Or Password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>(function(){var t=localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
<meta charset="UTF-8">
<title>Login — ROSP - Dealers Social Media</title>
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
    <h1 class="brand-h1">ROSP - Dealers Social Media Login</h1>
    <select class="theme-select" id="theme-select" style="width:auto; margin:12px 0 0;" onchange="setTheme(this.value)">
      <option value="dark">🌙 Dark</option>
      <option value="light">☀️ Light</option>
      <option value="midnight">🌌 Midnight</option>
      <option value="slate">🪨 Slate</option>
      <option value="contrast">◐ High Contrast</option>
    </select>
  </header>

  <?php if ($error): ?><div class="error-msg"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <form method="POST" class="search-panel">
    <div class="field">
      <label>Username</label>
      <input type="text" name="username" required autofocus>
    </div>
    <div class="field">
      <label>Password</label>
      <input type="password" name="password" required>
    </div>
    <button type="submit" class="submit">Login</button>
  </form>

  <div class="footer">Developed By Wasim Javed</div>
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
