<?php
require_once __DIR__ . '/includes/Auth.php';
Auth::requireLogin();
if (!Auth::canView('brand_assets')) {
    http_response_code(403);
    exit('You Do Not Have Access To This Page.');
}
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/ImageResizer.php';

$db = Database::getConnection();
$message = '';
$error = '';
$uploadDir = __DIR__ . '/assets/uploads';

const MAX_IMAGES_PER_VEHICLE = 10;

function saveUpload(string $fieldName, string $subfolder): ?string
{
    global $uploadDir;
    if (empty($_FILES[$fieldName]['name']) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $ext = strtolower(pathinfo($_FILES[$fieldName]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        return null;
    }
    $filename = $subfolder . '_' . uniqid() . '.' . $ext;
    $destination = "$uploadDir/$subfolder/$filename";
    if (!move_uploaded_file($_FILES[$fieldName]['tmp_name'], $destination)) {
        return null;
    }
    ImageResizer::resizeInPlace($destination);
    return "assets/uploads/$subfolder/$filename";
}

/** Saves every valid file from a multi-file <input>, up to a max count. Returns the saved relative paths. */
function saveMultiUpload(string $fieldName, string $subfolder, int $maxCount): array
{
    global $uploadDir;
    $paths = [];
    if (empty($_FILES[$fieldName]['name'][0] ?? null)) {
        return $paths;
    }
    $count = count($_FILES[$fieldName]['name']);
    for ($i = 0; $i < $count && count($paths) < $maxCount; $i++) {
        if ($_FILES[$fieldName]['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }
        $ext = strtolower(pathinfo($_FILES[$fieldName]['name'][$i], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            continue;
        }
        $filename = $subfolder . '_' . uniqid() . '.' . $ext;
        $destination = "$uploadDir/$subfolder/$filename";
        if (move_uploaded_file($_FILES[$fieldName]['tmp_name'][$i], $destination)) {
            ImageResizer::resizeInPlace($destination);
            $paths[] = "assets/uploads/$subfolder/$filename";
        }
    }
    return $paths;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_vehicle') {
        $name = trim($_POST['name'] ?? '');
        $color = trim($_POST['color'] ?? '');
        $imagePaths = saveMultiUpload('reference_images', 'vehicles', MAX_IMAGES_PER_VEHICLE);

        if ($name === '' || $color === '' || empty($imagePaths)) {
            $error = 'Name, Color, And At Least One Valid Reference Photo (jpg/png/webp) Are All Required.';
        } else {
            $stmt = $db->prepare("INSERT INTO vehicle_models (name, color, reference_image) VALUES (:name, :color, :image)");
            $stmt->execute(['name' => $name, 'color' => $color, 'image' => $imagePaths[0]]);
            $vehicleId = (int)$db->lastInsertId();
            $imgStmt = $db->prepare("INSERT INTO vehicle_model_images (vehicle_model_id, image_path) VALUES (:vid, :img)");
            foreach ($imagePaths as $path) {
                $imgStmt->execute(['vid' => $vehicleId, 'img' => $path]);
            }
            $message = count($imagePaths) . ' Reference Photo(s) Added For This Vehicle.';
        }
    } elseif ($action === 'add_images') {
        $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
        $existingCount = (int)$db->query("SELECT COUNT(*) FROM vehicle_model_images WHERE vehicle_model_id = $vehicleId")->fetchColumn();
        $room = max(0, MAX_IMAGES_PER_VEHICLE - $existingCount);
        $imagePaths = $room > 0 ? saveMultiUpload('reference_images', 'vehicles', $room) : [];

        if ($room === 0) {
            $error = 'This Vehicle Already Has The Maximum Of ' . MAX_IMAGES_PER_VEHICLE . ' Reference Photos.';
        } elseif (empty($imagePaths)) {
            $error = 'No Valid Photos (jpg/png/webp) Were Uploaded.';
        } else {
            $imgStmt = $db->prepare("INSERT INTO vehicle_model_images (vehicle_model_id, image_path) VALUES (:vid, :img)");
            foreach ($imagePaths as $path) {
                $imgStmt->execute(['vid' => $vehicleId, 'img' => $path]);
            }
            $message = count($imagePaths) . ' More Photo(s) Added.';
        }
    } elseif ($action === 'save_identity') {
        $tagline = trim($_POST['tagline'] ?? '');
        $primaryColor = trim($_POST['primary_color'] ?? '');
        $secondaryColor = trim($_POST['secondary_color'] ?? '');
        $websiteUrl = trim($_POST['website_url'] ?? '');
        $lightPath = saveUpload('logo_light', 'logos');
        $darkPath = saveUpload('logo_dark', 'logos');
        $whiteBgPath = saveUpload('logo_white_bg', 'logos');

        $existing = $db->query("SELECT * FROM brand_identity WHERE id = 1")->fetch();
        $finalLight = $lightPath ?? ($existing['logo_light_path'] ?? null);
        $finalDark = $darkPath ?? ($existing['logo_dark_path'] ?? null);
        $finalWhiteBg = $whiteBgPath ?? ($existing['logo_white_bg_path'] ?? null);

        $db->prepare("
            INSERT INTO brand_identity (id, logo_light_path, logo_dark_path, logo_white_bg_path, tagline, primary_color, secondary_color, website_url)
            VALUES (1, :light, :dark, :white_bg, :tagline, :primary_color, :secondary_color, :website_url)
            ON DUPLICATE KEY UPDATE logo_light_path = :light2, logo_dark_path = :dark2, logo_white_bg_path = :white_bg2, tagline = :tagline2,
                primary_color = :primary_color2, secondary_color = :secondary_color2, website_url = :website_url2
        ")->execute([
            'light' => $finalLight, 'dark' => $finalDark, 'white_bg' => $finalWhiteBg, 'tagline' => $tagline,
            'primary_color' => $primaryColor, 'secondary_color' => $secondaryColor, 'website_url' => $websiteUrl,
            'light2' => $finalLight, 'dark2' => $finalDark, 'white_bg2' => $finalWhiteBg, 'tagline2' => $tagline,
            'primary_color2' => $primaryColor, 'secondary_color2' => $secondaryColor, 'website_url2' => $websiteUrl,
        ]);
        $message = 'Brand Identity Saved.';
    }
}

$vehicles = $db->query("SELECT * FROM vehicle_models ORDER BY name, color")->fetchAll();
foreach ($vehicles as &$v) {
    $imgStmt = $db->prepare("SELECT id, image_path FROM vehicle_model_images WHERE vehicle_model_id = :vid ORDER BY id");
    $imgStmt->execute(['vid' => $v['id']]);
    $v['images'] = $imgStmt->fetchAll();
}
unset($v);
$identity = $db->query("SELECT * FROM brand_identity WHERE id = 1")->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>(function(){var t=localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
<meta charset="UTF-8">
<title>Brand Assets</title>
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
    <div>
      <h1>Brand Assets</h1>
      <div class="subtitle">Reference Vehicles, Logo Variants & Tagline — Used To Check Dealership Post Submissions</div>
    </div>
  </header>

  <?php if ($message): ?><div class="success-msg"><?= htmlspecialchars($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="error-msg"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <h2 style="font-size:16px; margin-bottom:14px;">Brand Identity</h2>
  <form method="POST" enctype="multipart/form-data" class="search-panel" style="margin-bottom:28px;">
    <input type="hidden" name="action" value="save_identity">
    <div class="field">
      <label>Logo — Light Variant (use on dark backgrounds)</label>
      <?php if (!empty($identity['logo_light_path'])): ?>
        <img src="<?= htmlspecialchars($identity['logo_light_path']) ?>" style="height:40px; margin-bottom:8px; background:#222; padding:4px; border-radius:4px;">
      <?php endif; ?>
      <input type="file" name="logo_light" accept=".jpg,.jpeg,.png,.webp">
    </div>
    <div class="field">
      <label>Logo — Dark Variant (use on light backgrounds)</label>
      <?php if (!empty($identity['logo_dark_path'])): ?>
        <img src="<?= htmlspecialchars($identity['logo_dark_path']) ?>" style="height:40px; margin-bottom:8px; background:#eee; padding:4px; border-radius:4px;">
      <?php endif; ?>
      <input type="file" name="logo_dark" accept=".jpg,.jpeg,.png,.webp">
    </div>
    <div class="field">
      <label>Logo — Red &amp; Blue Variant (use when background is white)</label>
      <?php if (!empty($identity['logo_white_bg_path'])): ?>
        <img src="<?= htmlspecialchars($identity['logo_white_bg_path']) ?>" style="height:40px; margin-bottom:8px; background:#fff; padding:4px; border-radius:4px; border:1px solid var(--border);">
      <?php endif; ?>
      <input type="file" name="logo_white_bg" accept=".jpg,.jpeg,.png,.webp">
    </div>
    <div class="field">
      <label>Tagline</label>
      <input type="text" name="tagline" value="<?= htmlspecialchars($identity['tagline'] ?? '') ?>" placeholder="e.g. Way of Life!">
    </div>
    <div class="field">
      <label>Primary Brand Color</label>
      <input type="text" name="primary_color" value="<?= htmlspecialchars($identity['primary_color'] ?? '') ?>" placeholder="e.g. #DA020E — Suzuki Red">
    </div>
    <div class="field">
      <label>Secondary Brand Color</label>
      <input type="text" name="secondary_color" value="<?= htmlspecialchars($identity['secondary_color'] ?? '') ?>" placeholder="e.g. #000000 — Black">
    </div>
    <div class="field">
      <label>Official Website URL</label>
      <input type="text" name="website_url" value="<?= htmlspecialchars($identity['website_url'] ?? '') ?>" placeholder="e.g. https://www.suzukipakistan.com">
    </div>
    <button type="submit" class="submit">Save Brand Identity</button>
  </form>

  <h2 style="font-size:16px; margin-bottom:14px;">Approved Vehicle Models</h2>
  <div class="subtitle" style="margin-bottom:14px;">Add Up To <?= MAX_IMAGES_PER_VEHICLE ?> Reference Photos Per Model — Different Angles/Parts (Front, Rear, Rim, Bumper, Side Mirror, Fuel Tank Cap, Etc.) So The Checker Can Catch A Mismatched Part.</div>
  <?php if (empty($vehicles)): ?>
    <div class="empty-state" style="margin-bottom:24px;">No Vehicle References Added Yet.</div>
  <?php else: ?>
    <?php foreach ($vehicles as $v): ?>
    <div class="detail-card" id="vehicle-row-<?= $v['id'] ?>" style="margin-bottom:16px;">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
        <div><span class="name"><?= htmlspecialchars($v['name']) ?></span> — <?= htmlspecialchars($v['color']) ?> <span class="subtitle">(<?= count($v['images']) ?>/<?= MAX_IMAGES_PER_VEHICLE ?> Photos)</span></div>
        <button class="delete-row-btn" onclick="deleteVehicle(<?= $v['id'] ?>, this)">Delete Model</button>
      </div>
      <div style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:10px;">
        <?php foreach ($v['images'] as $img): ?>
        <div style="position:relative;" id="vehicle-img-<?= $img['id'] ?>">
          <img src="<?= htmlspecialchars($img['image_path']) ?>" style="height:70px; border-radius:4px; border:1px solid var(--border);">
          <button onclick="deleteVehicleImage(<?= $img['id'] ?>, this)" title="Delete This Photo" style="position:absolute; top:-6px; right:-6px; width:20px; height:20px; border-radius:50%; border:none; background:var(--red); color:#fff; cursor:pointer; font-size:12px; line-height:1;">×</button>
        </div>
        <?php endforeach; ?>
      </div>
      <?php if (count($v['images']) < MAX_IMAGES_PER_VEHICLE): ?>
      <form method="POST" enctype="multipart/form-data" style="display:flex; gap:8px; align-items:center;">
        <input type="hidden" name="action" value="add_images">
        <input type="hidden" name="vehicle_id" value="<?= $v['id'] ?>">
        <input type="file" name="reference_images[]" accept=".jpg,.jpeg,.png,.webp" multiple style="flex:1;">
        <button type="submit" class="submit" style="width:auto; padding:8px 16px;">Add More Photos</button>
      </form>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <form method="POST" enctype="multipart/form-data" class="search-panel">
    <input type="hidden" name="action" value="add_vehicle">
    <div class="field">
      <label>Model Name</label>
      <input type="text" name="name" placeholder="e.g. Suzuki Alto" required>
    </div>
    <div class="field">
      <label>Color</label>
      <input type="text" name="color" placeholder="e.g. Pearl White" required>
    </div>
    <div class="field" style="flex:2;">
      <label>Reference Photos (Select Up To <?= MAX_IMAGES_PER_VEHICLE ?> At Once — Front, Rear, Rim, Bumper, Side Mirror, Fuel Tank Cap, Etc.)</label>
      <input type="file" name="reference_images[]" accept=".jpg,.jpeg,.png,.webp" multiple required>
    </div>
    <button type="submit" class="submit">Add Vehicle Reference</button>
  </form>

</div>
</main>
</div>

<script>
async function deleteVehicle(id, btnEl) {
  if (!confirm('Delete This Vehicle Reference?')) return;
  btnEl.disabled = true;
  const res = await fetch('delete_vehicle_model.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `id=${id}`,
  });
  const data = await res.json();
  if (data.success) {
    document.getElementById(`vehicle-row-${id}`).remove();
  } else {
    alert(data.message || 'Delete Failed.');
    btnEl.disabled = false;
  }
}

async function deleteVehicleImage(id, btnEl) {
  if (!confirm('Delete This Reference Photo?')) return;
  btnEl.disabled = true;
  const res = await fetch('delete_vehicle_image.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `id=${id}`,
  });
  const data = await res.json();
  if (data.success) {
    document.getElementById(`vehicle-img-${id}`).remove();
  } else {
    alert(data.message || 'Delete Failed.');
    btnEl.disabled = false;
  }
}
</script>
</body>
</html>
