<?php
require_once __DIR__ . '/includes/Auth.php';
Auth::requireLogin();
require_once __DIR__ . '/includes/Database.php';

$db = Database::getConnection();
$message = '';
$error = '';
$scopedIds = Auth::dealershipIds();
$isSuperAdmin = Auth::isSuperAdmin();

if ($isSuperAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $pageId = trim($_POST['page_id'] ?? '');
    $token = trim($_POST['page_access_token'] ?? '');
    $dealershipId = $_POST['dealership_id'] ?? '';
    $dealershipId = $dealershipId !== '' ? (int)$dealershipId : null;

    if ($name === '' || $pageId === '' || $token === '') {
        $error = 'Name, Page ID, And Page Access Token Are All Required.';
    } else {
        $stmt = $db->prepare("INSERT INTO target_pages (name, page_id, page_access_token, dealership_id) VALUES (:name, :page_id, :token, :dealership_id)");
        $stmt->execute(['name' => $name, 'page_id' => $pageId, 'token' => $token, 'dealership_id' => $dealershipId]);
        $message = 'Target Page Added.';
    }
}

if ($isSuperAdmin) {
    $targetPages = $db->query("SELECT * FROM target_pages ORDER BY name")->fetchAll();
    $dealershipsList = $db->query("SELECT id, name FROM dealerships ORDER BY name")->fetchAll();
} elseif (empty($scopedIds)) {
    $targetPages = [];
} else {
    $placeholders = implode(',', array_fill(0, count($scopedIds), '?'));
    $stmt = $db->prepare("SELECT * FROM target_pages WHERE dealership_id IN ($placeholders) ORDER BY name");
    $stmt->execute($scopedIds);
    $targetPages = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>(function(){var t=localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
<meta charset="UTF-8">
<title>Target Pages</title>
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
    <h1>Target Pages</h1>
    <div class="subtitle"><a href="index.php" style="color:var(--accent)">← Back To Dashboard</a></div>
  </header>

  <?php if ($message): ?><div class="success-msg"><?= $message ?></div><?php endif; ?>
  <?php if ($error): ?><div class="error-msg"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="table-wrap" style="margin-bottom:24px;">
    <table>
      <thead>
        <tr>
          <th>Name</th>
          <th>Page ID</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($targetPages as $tp): ?>
        <tr id="target-row-<?= $tp['id'] ?>">
          <td class="name"><?= htmlspecialchars($tp['name']) ?></td>
          <td class="timestamp"><?= htmlspecialchars($tp['page_id']) ?></td>
          <td>
            <span class="status-badge <?= $tp['is_active'] ? 'status-done' : 'status-pending' ?>" id="status-<?= $tp['id'] ?>">
              <?= $tp['is_active'] ? 'Active' : 'Inactive' ?>
            </span>
          </td>
          <td class="actions-cell">
            <?php if ($isSuperAdmin): ?>
            <button class="refresh-btn" onclick="toggleActive(<?= $tp['id'] ?>, this)"><?= $tp['is_active'] ? 'Deactivate' : 'Activate' ?></button>
            <button class="delete-row-btn" onclick="deleteTarget(<?= $tp['id'] ?>, <?= htmlspecialchars(json_encode($tp['name'])) ?>, this)">Delete</button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (empty($targetPages)): ?>
      <div class="empty-state"><?= $isSuperAdmin ? 'No Target Pages Added Yet.' : 'No Target Page Linked To Your Dealership Yet. Contact Your Super Admin.' ?></div>
    <?php endif; ?>
  </div>

  <?php if ($isSuperAdmin): ?>
  <form method="POST" class="search-panel">
    <div class="field">
      <label>Dealership Name</label>
      <input type="text" name="name" placeholder="e.g. Suzuki United Motors" required>
    </div>
    <div class="field">
      <label>Linked Dealership (For Scoped-User Access)</label>
      <select name="dealership_id">
        <option value="">— None —</option>
        <?php foreach ($dealershipsList as $d): ?>
          <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label><span class="dot fb"></span>Facebook Page ID</label>
      <input type="text" name="page_id" placeholder="100064372222141" required>
    </div>
    <div class="field">
      <label>Page Access Token</label>
      <input type="text" name="page_access_token" placeholder="Long-lived Page Access Token" required>
    </div>
    <button type="submit" class="submit">Add Target Page</button>
  </form>
  <?php endif; ?>
</div>
</main>
</div>

<script>
async function toggleActive(id, btnEl) {
  btnEl.disabled = true;
  const res = await fetch('toggle_target_page.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `id=${id}`,
  });
  const data = await res.json();
  if (data.success) {
    const badge = document.getElementById(`status-${id}`);
    badge.textContent = data.is_active ? 'Active' : 'Inactive';
    badge.className = 'status-badge ' + (data.is_active ? 'status-done' : 'status-pending');
    btnEl.textContent = data.is_active ? 'Deactivate' : 'Activate';
  } else {
    alert(data.message || 'Failed To Update.');
  }
  btnEl.disabled = false;
}

async function deleteTarget(id, name, btnEl) {
  if (!confirm(`Delete "${name}"? This cannot be undone.`)) return;
  btnEl.disabled = true;
  const res = await fetch('delete_target_page.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `id=${id}`,
  });
  const data = await res.json();
  if (data.success) {
    document.getElementById(`target-row-${id}`).remove();
  } else {
    alert(data.message || 'Delete Failed.');
    btnEl.disabled = false;
  }
}
</script>
</body>
</html>
