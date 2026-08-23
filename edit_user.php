<?php
require_once __DIR__ . '/includes/Auth.php';
Auth::requireSuperAdmin();
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/SidebarSections.php';

$db = Database::getConnection();
$sidebarSections = sidebarSectionsList();
$id = $_GET['id'] ?? $_POST['id'] ?? null;
if (!$id) {
    header('Location: users.php');
    exit;
}

$stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute(['id' => $id]);
$user = $stmt->fetch();

if (!$user || $user['is_super_admin']) {
    header('Location: users.php');
    exit;
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dealershipIds = array_values(array_unique(array_map('intval', $_POST['dealership_ids'] ?? [])));
    $canRefresh = isset($_POST['can_refresh']) ? 1 : 0;
    $canEdit = isset($_POST['can_edit']) ? 1 : 0;
    $canDelete = isset($_POST['can_delete']) ? 1 : 0;
    $newPassword = trim($_POST['new_password'] ?? '');
    $selectedSections = array_values(array_intersect($_POST['sidebar_sections'] ?? [], array_keys($sidebarSections)));

    if (empty($dealershipIds)) {
        $message = 'Error: A User Must Be Linked To At Least One Dealership.';
    } elseif ($newPassword !== '' && strlen($newPassword) < 6) {
        $message = 'Error: New Password Must Be At Least 6 Characters.';
    } else {
        $db->beginTransaction();
        if ($newPassword !== '') {
            $db->prepare("UPDATE users SET can_refresh = :r, can_edit = :e, can_delete = :d, password_hash = :hash WHERE id = :id")
               ->execute(['r' => $canRefresh, 'e' => $canEdit, 'd' => $canDelete, 'hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $id]);
        } else {
            $db->prepare("UPDATE users SET can_refresh = :r, can_edit = :e, can_delete = :d WHERE id = :id")
               ->execute(['r' => $canRefresh, 'e' => $canEdit, 'd' => $canDelete, 'id' => $id]);
        }

        $db->prepare("DELETE FROM user_dealerships WHERE user_id = :id")->execute(['id' => $id]);
        $insertUD = $db->prepare("INSERT INTO user_dealerships (user_id, dealership_id) VALUES (:uid, :did)");
        foreach ($dealershipIds as $did) {
            $insertUD->execute(['uid' => $id, 'did' => $did]);
        }

        $db->prepare("DELETE FROM user_sidebar_sections WHERE user_id = :id")->execute(['id' => $id]);
        $insertSection = $db->prepare("INSERT INTO user_sidebar_sections (user_id, section_key) VALUES (:uid, :key)");
        foreach ($selectedSections as $key) {
            $insertSection->execute(['uid' => $id, 'key' => $key]);
        }

        $db->commit();
        $message = $newPassword !== '' ? 'User Updated And Password Reset.' : 'User Updated.';
    }
}

$assignedStmt = $db->prepare("SELECT dealership_id FROM user_dealerships WHERE user_id = :id");
$assignedStmt->execute(['id' => $id]);
$assignedIds = array_map('intval', $assignedStmt->fetchAll(PDO::FETCH_COLUMN));

$assignedSectionsStmt = $db->prepare("SELECT section_key FROM user_sidebar_sections WHERE user_id = :id");
$assignedSectionsStmt->execute(['id' => $id]);
$assignedSections = $assignedSectionsStmt->fetchAll(PDO::FETCH_COLUMN);

$dealershipsList = $db->query("SELECT id, name FROM dealerships ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>(function(){var t=localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
<meta charset="UTF-8">
<title>Edit User</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#1a1a19">
<link rel="apple-touch-icon" href="assets/icon-192.png">
<script>if('serviceWorker' in navigator){window.addEventListener('load',()=>navigator.serviceWorker.register('sw.js'));}</script>
</head>
<body>
<div class="container" style="max-width:820px;">
  <header>
    <h1>Edit User — <?= htmlspecialchars($user['username']) ?></h1>
    <div class="subtitle"><a href="users.php" style="color:var(--accent)">← Back To User Management</a></div>
  </header>

  <?php if ($message): ?><div class="<?= str_starts_with($message, 'Error') ? 'error-msg' : 'success-msg' ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>

  <form method="POST" class="search-panel">
    <input type="hidden" name="id" value="<?= $user['id'] ?>">
    <div class="field">
      <label>Dealership(s)</label>
      <div style="max-height:220px; overflow-y:auto; border:1px solid var(--border); border-radius:6px; padding:10px; background:var(--input-bg);">
        <?php foreach ($dealershipsList as $d): ?>
          <label style="display:flex; align-items:center; gap:8px; font-weight:400; margin-bottom:6px; font-size:13px;">
            <input type="checkbox" name="dealership_ids[]" value="<?= $d['id'] ?>" style="width:auto;" <?= in_array((int)$d['id'], $assignedIds, true) ? 'checked' : '' ?>>
            <?= htmlspecialchars($d['name']) ?>
          </label>
        <?php endforeach; ?>
      </div>
    </div>

    <hr style="border-color:var(--border); margin:8px 0;">
    <div class="field">
      <label>Reset Password (Leave Blank To Keep Current Password)</label>
      <input type="text" name="new_password" placeholder="New password — min. 6 characters" minlength="6">
    </div>

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
          <input type="checkbox" name="sidebar_sections[]" value="<?= htmlspecialchars($key) ?>" <?= in_array($key, $assignedSections, true) ? 'checked' : '' ?>>
          <span class="perm-check">✓</span>
          <span class="perm-label"><?= htmlspecialchars($section['label']) ?></span>
        </label>
        <?php endforeach; ?>
      </div>

      <div class="perm-group-title">Action Permissions — What This User Can Perform On Their Assigned Dealerships</div>
      <div class="permission-grid">
        <label class="permission-card">
          <input type="checkbox" name="can_refresh" <?= $user['can_refresh'] ? 'checked' : '' ?>>
          <span class="perm-check">✓</span>
          <span class="perm-label">Refresh</span>
          <span class="perm-desc">Re-Fetch FB/IG/YT/Google Data + Reshare Checks</span>
        </label>
        <label class="permission-card">
          <input type="checkbox" name="can_edit" <?= $user['can_edit'] ? 'checked' : '' ?>>
          <span class="perm-check">✓</span>
          <span class="perm-label">Edit Records</span>
          <span class="perm-desc">Edit Dealership Info (FB/IG/YT/Google Search Fields)</span>
        </label>
        <label class="permission-card">
          <input type="checkbox" name="can_delete" <?= $user['can_delete'] ? 'checked' : '' ?>>
          <span class="perm-check">✓</span>
          <span class="perm-label">Delete Records</span>
          <span class="perm-desc">Can Delete A Dealership Permanently</span>
        </label>
      </div>
    </div>

    <button type="submit" class="submit">Save Changes</button>
  </form>
</div>
<script>
function setTheme(theme) {
  document.documentElement.setAttribute('data-theme', theme);
  localStorage.setItem('theme', theme);
}
function setAllPermissions(checked) {
  document.querySelectorAll('#permissions-panel input[type="checkbox"]').forEach(cb => cb.checked = checked);
}
</script>
</body>
</html>
