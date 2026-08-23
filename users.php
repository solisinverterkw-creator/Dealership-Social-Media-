<?php
require_once __DIR__ . '/includes/Auth.php';
Auth::requireSuperAdmin();
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/SidebarSections.php';

$db = Database::getConnection();
$sidebarSections = sidebarSectionsList();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $isSuperAdmin = isset($_POST['is_super_admin']) ? 1 : 0;
    $dealershipIds = array_values(array_unique(array_map('intval', $_POST['dealership_ids'] ?? [])));
    $canRefresh = isset($_POST['can_refresh']) ? 1 : 0;
    $canEdit = isset($_POST['can_edit']) ? 1 : 0;
    $canDelete = isset($_POST['can_delete']) ? 1 : 0;
    $selectedSections = array_values(array_intersect($_POST['sidebar_sections'] ?? [], array_keys($sidebarSections)));

    if ($username === '' || $password === '') {
        $error = 'Username And Password Are Both Required.';
    } elseif (!$isSuperAdmin && empty($dealershipIds)) {
        $error = 'A Non-Super-Admin User Must Be Linked To At Least One Dealership.';
    } else {
        $check = $db->prepare("SELECT id FROM users WHERE username = :username");
        $check->execute(['username' => $username]);
        if ($check->fetch()) {
            $error = 'This Username Already Exists.';
        } else {
            $db->beginTransaction();
            $stmt = $db->prepare("
                INSERT INTO users (username, password_hash, is_super_admin, can_refresh, can_edit, can_delete)
                VALUES (:username, :hash, :is_super_admin, :can_refresh, :can_edit, :can_delete)
            ");
            $stmt->execute([
                'username' => $username,
                'hash' => password_hash($password, PASSWORD_DEFAULT),
                'is_super_admin' => $isSuperAdmin,
                'can_refresh' => $isSuperAdmin ? 1 : $canRefresh,
                'can_edit' => $isSuperAdmin ? 1 : $canEdit,
                'can_delete' => $isSuperAdmin ? 1 : $canDelete,
            ]);
            $newUserId = (int)$db->lastInsertId();

            if (!$isSuperAdmin) {
                $insertUD = $db->prepare("INSERT INTO user_dealerships (user_id, dealership_id) VALUES (:uid, :did)");
                foreach ($dealershipIds as $did) {
                    $insertUD->execute(['uid' => $newUserId, 'did' => $did]);
                }

                $insertSection = $db->prepare("INSERT INTO user_sidebar_sections (user_id, section_key) VALUES (:uid, :key)");
                foreach ($selectedSections as $key) {
                    $insertSection->execute(['uid' => $newUserId, 'key' => $key]);
                }
            }
            $db->commit();
            $message = 'User Added.';
        }
    }
}

$users = $db->query("
    SELECT u.id, u.username, u.is_super_admin, u.can_refresh, u.can_edit, u.can_delete, u.created_at,
        GROUP_CONCAT(d.name ORDER BY d.name SEPARATOR ', ') AS dealership_names
    FROM users u
    LEFT JOIN user_dealerships ud ON ud.user_id = u.id
    LEFT JOIN dealerships d ON d.id = ud.dealership_id
    GROUP BY u.id
    ORDER BY u.created_at
")->fetchAll();
$dealershipsList = $db->query("SELECT id, name FROM dealerships ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>(function(){var t=localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
<meta charset="UTF-8">
<title>User Management</title>
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
<div class="container">
  <header>
    <h1>User Management</h1>
    <div class="subtitle"><a href="index.php" style="color:var(--accent)">← Back To Dashboard</a></div>
  </header>

  <?php if ($message): ?><div class="success-msg"><?= $message ?></div><?php endif; ?>
  <?php if ($error): ?><div class="error-msg"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="table-wrap" style="margin-bottom:24px;">
    <table>
      <thead>
        <tr>
          <th>Username</th>
          <th>Role</th>
          <th>Dealership(s)</th>
          <th>Permissions</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
        <tr id="user-row-<?= $u['id'] ?>">
          <td class="name"><?= htmlspecialchars($u['username']) ?></td>
          <td><?= $u['is_super_admin'] ? 'Super Admin' : 'User' ?></td>
          <td><?= $u['is_super_admin'] ? '— All (Super Admin) —' : htmlspecialchars($u['dealership_names'] ?? '— Unassigned —') ?></td>
          <td>
            <?php if ($u['is_super_admin']): ?>
              <span class="status-badge status-done">All</span>
            <?php else: ?>
              <span class="status-badge <?= $u['can_refresh'] ? 'status-done' : 'status-pending' ?>">Refresh</span>
              <span class="status-badge <?= $u['can_edit'] ? 'status-done' : 'status-pending' ?>">Edit</span>
              <span class="status-badge <?= $u['can_delete'] ? 'status-done' : 'status-pending' ?>">Delete</span>
            <?php endif; ?>
          </td>
          <td class="actions-cell">
            <?php if ($u['id'] != $_SESSION['user_id']): ?>
              <?php if (!$u['is_super_admin']): ?>
              <a class="edit-btn" href="edit_user.php?id=<?= $u['id'] ?>">Edit</a>
              <?php endif; ?>
              <button class="delete-row-btn" onclick="deleteUser(<?= $u['id'] ?>, <?= htmlspecialchars(json_encode($u['username'])) ?>, this)">Delete</button>
            <?php else: ?>
              <span style="color:var(--muted); font-size:11px;">(You)</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <form method="POST" class="search-panel">
    <div class="field">
      <label>New Username</label>
      <input type="text" name="username" required>
    </div>
    <div class="field">
      <label>Password</label>
      <input type="text" name="password" required>
    </div>
    <div class="field">
      <label>Dealership(s) (Ignored If Super Admin)</label>
      <div style="max-height:180px; overflow-y:auto; border:1px solid var(--border); border-radius:6px; padding:10px; background:var(--input-bg);">
        <?php foreach ($dealershipsList as $d): ?>
          <label style="display:flex; align-items:center; gap:8px; font-weight:400; margin-bottom:6px; font-size:13px;">
            <input type="checkbox" name="dealership_ids[]" value="<?= $d['id'] ?>" style="width:auto;">
            <?= htmlspecialchars($d['name']) ?>
          </label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="field" style="display:flex; align-items:center; gap:8px; flex-direction:row;">
      <input type="checkbox" name="is_super_admin" id="is_super_admin" style="width:auto;" onchange="togglePermissionFields(this.checked)">
      <label for="is_super_admin" style="margin:0;">Make Super Admin (Can Manage Other Users)</label>
    </div>

    <div id="permission-fields">
      <hr style="border-color:var(--border); margin:8px 0;">

      <div class="permissions-panel" id="permissions-panel">
        <div class="permissions-panel-header">
          <h3>Sidebar &amp; Action Permissions</h3>
          <div class="permissions-toolbar">
            <button type="button" class="perm-toolbar-btn select-all" onclick="setAllPermissions(true)">✓ Select All</button>
            <button type="button" class="perm-toolbar-btn clear-all" onclick="setAllPermissions(false)">✕ Clear All</button>
          </div>
        </div>

        <div class="perm-group-title">Sidebar Sections — Control Which Menu Items This User Sees</div>
        <div class="permission-grid">
          <?php foreach ($sidebarSections as $key => $section): ?>
          <label class="permission-card">
            <input type="checkbox" name="sidebar_sections[]" value="<?= htmlspecialchars($key) ?>" checked>
            <span class="perm-check">✓</span>
            <span class="perm-label"><?= htmlspecialchars($section['label']) ?></span>
          </label>
          <?php endforeach; ?>
        </div>

        <div class="perm-group-title">Action Permissions — What This User Can Perform On Their Assigned Dealerships</div>
        <div class="permission-grid">
          <label class="permission-card">
            <input type="checkbox" name="can_refresh" checked>
            <span class="perm-check">✓</span>
            <span class="perm-label">Refresh</span>
            <span class="perm-desc">Re-Fetch FB/IG/YT/Google Data + Reshare Checks</span>
          </label>
          <label class="permission-card">
            <input type="checkbox" name="can_edit">
            <span class="perm-check">✓</span>
            <span class="perm-label">Edit Records</span>
            <span class="perm-desc">Edit Dealership Info (FB/IG/YT/Google Search Fields)</span>
          </label>
          <label class="permission-card">
            <input type="checkbox" name="can_delete">
            <span class="perm-check">✓</span>
            <span class="perm-label">Delete Records</span>
            <span class="perm-desc">Can Delete A Dealership Permanently</span>
          </label>
        </div>
      </div>
    </div>

    <button type="submit" class="submit">Add User</button>
  </form>
</div>
</main>
</div>

<script>
function togglePermissionFields(isSuperAdmin) {
  document.getElementById('permission-fields').style.display = isSuperAdmin ? 'none' : '';
}
function setAllPermissions(checked) {
  document.querySelectorAll('#permissions-panel input[type="checkbox"]').forEach(cb => cb.checked = checked);
}

async function deleteUser(id, username, btnEl) {
  if (!confirm(`Delete "${username}"?`)) return;
  btnEl.disabled = true;
  const res = await fetch('delete_user.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `id=${id}`,
  });
  const data = await res.json();
  if (data.success) {
    document.getElementById(`user-row-${id}`).remove();
  } else {
    alert(data.message || 'Delete Failed.');
    btnEl.disabled = false;
  }
}
</script>
</body>
</html>
