<?php
require_once __DIR__ . '/includes/Auth.php';
Auth::requireLogin();
if (!Auth::canView('exchange_token')) {
    http_response_code(403);
    exit('You Do Not Have Access To This Page.');
}
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/FacebookPoster.php';

$db = Database::getConnection();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $pageId = trim($_POST['page_id'] ?? '');
    $shortToken = trim($_POST['short_token'] ?? '');
    $dealershipId = $_POST['dealership_id'] ?? '';
    $dealershipId = $dealershipId !== '' ? (int)$dealershipId : null;

    if ($name === '' || $pageId === '' || $shortToken === '') {
        $error = 'Name, Page ID, And Short-Lived Token Are All Required.';
    } else {
        $poster = new FacebookPoster();

        $exchange = $poster->exchangeForLongLivedToken($shortToken);
        if (!$exchange['success']) {
            $error = 'Exchange Failed: ' . $exchange['message'];
        } else {
            $pageToken = $poster->getPageAccessToken($pageId, $exchange['long_lived_token']);
            if (!$pageToken['success']) {
                $error = 'Could Not Fetch Page Token: ' . $pageToken['message'];
            } else {
                $stmt = $db->prepare("INSERT INTO target_pages (name, page_id, page_access_token, dealership_id) VALUES (:name, :page_id, :token, :dealership_id)");
                $stmt->execute(['name' => $name, 'page_id' => $pageId, 'token' => $pageToken['page_access_token'], 'dealership_id' => $dealershipId]);

                // Also save onto the dealership record itself — this is what
                // refresh_fb.php/refresh_ig.php check to use the official Graph
                // API (accurate) instead of the Bright Data scraper for this
                // dealership's follower counts.
                if ($dealershipId !== null) {
                    $db->prepare("UPDATE dealerships SET fb_page_access_token = :token, fb_page_id = :page_id WHERE id = :id")
                       ->execute(['token' => $pageToken['page_access_token'], 'page_id' => $pageId, 'id' => $dealershipId]);
                }

                $message = 'Long-Lived Page Token Generated And Saved For "' . htmlspecialchars($pageToken['name'] ?? $name) . '".'
                    . ($dealershipId !== null ? ' This Dealership Will Now Use The Accurate Official API For Facebook/Instagram Follower Refresh.' : '');
            }
        }
    }
}

$dealershipsList = $db->query("SELECT id, name FROM dealerships ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>(function(){var t=localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
<meta charset="UTF-8">
<title>Exchange Token</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#1a1a19">
<link rel="apple-touch-icon" href="assets/icon-192.png">
</head>
<body>
<div class="app-layout">
<?php require __DIR__ . '/includes/Sidebar.php'; ?>
<main class="main-content">
<div class="container narrow">
  <header>
    <h1>Exchange Token</h1>
    <div class="subtitle"><a href="target_pages.php" style="color:var(--accent)">← Back To Target Pages</a></div>
  </header>

  <p class="subtitle" style="margin-bottom:20px;">
    Paste the short-lived token a dealership generated for you in Graph API Explorer
    (Page selected, <code>pages_show_list</code>/<code>pages_read_engagement</code>/<code>pages_manage_posts</code>
    granted). This exchanges it for a long-lived Page token and saves it straight into Target Pages —
    no manual curl commands needed.
  </p>

  <?php if ($message): ?><div class="success-msg"><?= $message ?></div><?php endif; ?>
  <?php if ($error): ?><div class="error-msg"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <form method="POST" class="search-panel">
    <div class="field">
      <label>Dealership Name</label>
      <input type="text" name="name" placeholder="e.g. Suzuki United Motors" value="<?= htmlspecialchars($name ?? '') ?>" required>
    </div>
    <div class="field">
      <label>Linked Dealership (For Scoped-User Access)</label>
      <select name="dealership_id">
        <option value="">— None —</option>
        <?php foreach ($dealershipsList as $d): ?>
          <option value="<?= $d['id'] ?>" <?= (isset($dealershipId) && $dealershipId === (int)$d['id']) ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label><span class="dot fb"></span>Facebook Page ID</label>
      <input type="text" name="page_id" placeholder="100064372222141" value="<?= htmlspecialchars($pageId ?? '') ?>" required>
    </div>
    <div class="field">
      <label>Short-Lived Token (From Graph API Explorer)</label>
      <input type="text" name="short_token" placeholder="EAAOkXMt..." value="<?= htmlspecialchars($shortToken ?? '') ?>" required>
    </div>
    <button type="submit" class="submit">Exchange &amp; Save As Target Page</button>
  </form>
</div>
</main>
</div>
</body>
</html>
