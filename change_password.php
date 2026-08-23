<?php
require_once __DIR__ . '/includes/Auth.php';
Auth::requireLogin();
require_once __DIR__ . '/includes/Database.php';

$db = Database::getConnection();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = trim($_POST['new_password'] ?? '');
    $confirmPassword = trim($_POST['confirm_password'] ?? '');

    $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = :id");
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
        $error = 'Current Password Is Incorrect.';
    } elseif (strlen($newPassword) < 6) {
        $error = 'New Password Must Be At Least 6 Characters.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'New Password And Confirmation Do Not Match.';
    } else {
        $db->prepare("UPDATE users SET password_hash = :hash WHERE id = :id")
           ->execute(['hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $_SESSION['user_id']]);
        $message = 'Password Changed Successfully.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>(function(){var t=localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
<meta charset="UTF-8">
<title>Change Password</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#1a1a19">
<link rel="apple-touch-icon" href="assets/icon-192.png">
<script>if('serviceWorker' in navigator){window.addEventListener('load',()=>navigator.serviceWorker.register('sw.js'));}</script>
</head>
<body>
<div class="app-layout">
<?php require __DIR__ . '/includes/Sidebar.php'; ?>
<main class="main-content">
<div class="container narrow">
  <header>
    <h1>Change Password</h1>
    <div class="subtitle">Signed In As <?= htmlspecialchars($_SESSION['username']) ?></div>
  </header>

  <?php if ($message): ?><div class="success-msg"><?= htmlspecialchars($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="error-msg"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <form method="POST" class="search-panel">
    <div class="field">
      <label>Current Password</label>
      <input type="password" name="current_password" required>
    </div>
    <div class="field">
      <label>New Password</label>
      <input type="password" name="new_password" required minlength="6">
    </div>
    <div class="field">
      <label>Confirm New Password</label>
      <input type="password" name="confirm_password" required minlength="6">
    </div>
    <button type="submit" class="submit">Change Password</button>
  </form>
</div>
</main>
</div>
</body>
</html>
