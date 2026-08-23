<?php
require_once __DIR__ . '/includes/Auth.php';
Auth::requireLogin();
if (!Auth::canView('ageing_report')) {
    http_response_code(403);
    exit('You Do Not Have Access To This Page.');
}
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/SimpleXlsxReader.php';
require_once __DIR__ . '/includes/SpreadsheetImportHelper.php';

$db = Database::getConnection();
$message = '';
$error = '';
$importErrors = [];
$isSuperAdmin = Auth::isSuperAdmin();
$scopedIds = Auth::dealershipIds();

// Importing overwrites data for every dealership found in the file, not
// just the uploader's own — stays super-admin-only even though viewing this
// report can now be granted to scoped users.
if ($isSuperAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_csv') {
    if (empty($_FILES['ageing_csv']['name']) || $_FILES['ageing_csv']['error'] !== UPLOAD_ERR_OK) {
        $error = 'A CSV Or Excel File Is Required.';
    } else {
        $ext = strtolower(pathinfo($_FILES['ageing_csv']['name'], PATHINFO_EXTENSION));
        $allRows = null;

        if ($ext === 'csv') {
            $handle = fopen($_FILES['ageing_csv']['tmp_name'], 'r');
            if ($handle) {
                $allRows = [];
                while (($row = fgetcsv($handle)) !== false) {
                    $allRows[] = $row;
                }
                fclose($handle);
            }
        } elseif ($ext === 'xlsx') {
            try {
                $xlsxReader = new SimpleXlsxReader();
                // Same multi-tab handling as the Stock Report — prefer a tab
                // literally named "Undelivered Stock" when one exists.
                $allRows = $xlsxReader->readSheetByName($_FILES['ageing_csv']['tmp_name'], 'Undelivered Stock')
                    ?? $xlsxReader->readFirstSheet($_FILES['ageing_csv']['tmp_name']);
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        } else {
            $error = 'Only .csv Or .xlsx Files Are Supported (Legacy .xls Is Not).';
        }

        if ($allRows === null && $error === '') {
            $error = 'Could Not Read The Uploaded File.';
        }

        if ($allRows !== null) {
            $headerIndex = SpreadsheetImportHelper::findHeaderRowIndex($allRows, ['dealer']);
            $headerRow = $allRows[$headerIndex] ?? [];
            $dealerCol = SpreadsheetImportHelper::findColumn($headerRow, ['dealer']);
            $dealerNameCol = SpreadsheetImportHelper::findColumn($headerRow, ['dealer name']) ?? $dealerCol;
            // "model name" is checked before the plain "model"/"product" fallbacks
            // because some ageing exports also have a separate short "MODEL" CODE
            // column (e.g. "A2L412") ahead of "MODEL NAME" (e.g. "SUZUKI SWIFT GL
            // M2 1197 CC") — matching generic "model" first would grab the code
            // column instead of the actual readable vehicle name.
            $productCol = SpreadsheetImportHelper::findColumn($headerRow, ['product desc', 'product description'])
                ?? SpreadsheetImportHelper::findColumn($headerRow, ['model name'])
                ?? SpreadsheetImportHelper::findColumn($headerRow, ['product'])
                ?? SpreadsheetImportHelper::findColumn($headerRow, ['model']);
            $chassisCol = SpreadsheetImportHelper::findColumn($headerRow, ['chassis']);
            // "deilvery date" tolerates a common typo (i/l transposed) seen in
            // some raw exports of this report.
            $deliveryDateCol = SpreadsheetImportHelper::findColumn($headerRow, ['delivery date', 'deilvery date']);

            if ($dealerNameCol === null || $chassisCol === null || $deliveryDateCol === null) {
                $error = 'Could Not Find "Dealer Name", "Chassis", And "Delivery Date" Columns In The Header Row.';
            } else {
                $dealershipsByName = [];
                foreach ($db->query("SELECT id, name FROM dealerships")->fetchAll() as $d) {
                    $dealershipsByName[SpreadsheetImportHelper::normalizeDealershipName($d['name'])] = $d['id'];
                }

                $touchedDealershipIds = [];
                $insert = $db->prepare("INSERT INTO ageing_records (dealership_id, product_name, chassis_number, delivery_date) VALUES (:did, :product, :chassis, :date)");
                $importedCount = 0;

                for ($i = $headerIndex + 1; $i < count($allRows); $i++) {
                    $row = $allRows[$i];
                    $rowNum = $i + 1;
                    if (count(array_filter($row, fn($c) => trim((string)$c) !== '')) === 0) {
                        continue; // blank line
                    }

                    $dealershipName = trim($row[$dealerNameCol] ?? '');
                    if ($dealershipName === '') {
                        continue;
                    }
                    $chassis = trim($row[$chassisCol] ?? '');
                    $deliveryDateRaw = trim($row[$deliveryDateCol] ?? '');
                    if ($chassis === '' || $deliveryDateRaw === '') {
                        continue; // no chassis/date to age against
                    }

                    $dealershipId = SpreadsheetImportHelper::findDealershipMatch($dealershipsByName, $dealershipName);
                    if (!$dealershipId) {
                        $importErrors[] = "Row {$rowNum}: Dealership \"{$dealershipName}\" Not Found — Skipped.";
                        continue;
                    }

                    $deliveryDate = SpreadsheetImportHelper::parseFlexibleDate($deliveryDateRaw);
                    if ($deliveryDate === null) {
                        $importErrors[] = "Row {$rowNum}: Could Not Parse Delivery Date \"{$deliveryDateRaw}\" — Skipped.";
                        continue;
                    }

                    $productName = $productCol !== null ? trim($row[$productCol] ?? '') : '';

                    if (!in_array($dealershipId, $touchedDealershipIds, true)) {
                        $db->prepare("DELETE FROM ageing_records WHERE dealership_id = :id")->execute(['id' => $dealershipId]);
                        $touchedDealershipIds[] = $dealershipId;
                    }

                    $insert->execute(['did' => $dealershipId, 'product' => $productName, 'chassis' => $chassis, 'date' => $deliveryDate]);
                    $importedCount++;
                }

                $message = "{$importedCount} Vehicle(s) Imported.";
            }
        }
    }
}

// Stock CSV import — a full company-wide chassis-level "currently in stock"
// snapshot (not per-dealership, unlike ageing_records above), used purely to
// filter which ageing_records vehicles are still actually sitting in stock —
// a vehicle already sold/delivered drops out of the file and so no longer
// counts as aged, even though its old row is still in ageing_records. Wiped
// and reloaded whole on every import (not per-touched-dealer) since the
// source file is always a complete network snapshot, not a partial one.
if ($isSuperAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_stock_csv') {
    if (empty($_FILES['stock_chassis_csv']['name']) || $_FILES['stock_chassis_csv']['error'] !== UPLOAD_ERR_OK) {
        $error = 'A CSV Or Excel File Is Required.';
    } else {
        $ext = strtolower(pathinfo($_FILES['stock_chassis_csv']['name'], PATHINFO_EXTENSION));
        $allRows = null;

        if ($ext === 'csv') {
            $handle = fopen($_FILES['stock_chassis_csv']['tmp_name'], 'r');
            if ($handle) {
                $allRows = [];
                while (($row = fgetcsv($handle)) !== false) {
                    $allRows[] = $row;
                }
                fclose($handle);
            }
        } elseif ($ext === 'xlsx') {
            try {
                $allRows = (new SimpleXlsxReader())->readFirstSheet($_FILES['stock_chassis_csv']['tmp_name']);
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        } else {
            $error = 'Only .csv Or .xlsx Files Are Supported (Legacy .xls Is Not).';
        }

        if ($allRows === null && $error === '') {
            $error = 'Could Not Read The Uploaded File.';
        }

        if ($allRows !== null) {
            $headerIndex = SpreadsheetImportHelper::findHeaderRowIndex($allRows, ['dealer']);
            $headerRow = $allRows[$headerIndex] ?? [];
            $dealerCol = SpreadsheetImportHelper::findColumn($headerRow, ['dealer']);
            $dealerNameCol = SpreadsheetImportHelper::findColumn($headerRow, ['dealer name']) ?? $dealerCol;
            $chassisCol = SpreadsheetImportHelper::findColumn($headerRow, ['chassis']);

            if ($dealerNameCol === null || $chassisCol === null) {
                $error = 'Could Not Find "Dealer Name" And "Chassis" Columns In The Header Row.';
            } else {
                $dealershipsByName = [];
                foreach ($db->query("SELECT id, name FROM dealerships")->fetchAll() as $d) {
                    $dealershipsByName[SpreadsheetImportHelper::normalizeDealershipName($d['name'])] = $d['id'];
                }

                $stockInsertRows = [];
                for ($i = $headerIndex + 1; $i < count($allRows); $i++) {
                    $row = $allRows[$i];
                    $rowNum = $i + 1;
                    if (count(array_filter($row, fn($c) => trim((string)$c) !== '')) === 0) {
                        continue; // blank line
                    }

                    $dealershipName = trim($row[$dealerNameCol] ?? '');
                    $chassis = trim($row[$chassisCol] ?? '');
                    if ($dealershipName === '' || $chassis === '') {
                        continue;
                    }

                    $dealershipId = SpreadsheetImportHelper::findDealershipMatch($dealershipsByName, $dealershipName);
                    if (!$dealershipId) {
                        $importErrors[] = "Row {$rowNum}: Dealership \"{$dealershipName}\" Not Found — Skipped.";
                        continue;
                    }

                    $stockInsertRows[] = [$dealershipId, $chassis];
                }

                if (empty($stockInsertRows)) {
                    $error = 'No Matching Chassis Rows Found To Import.';
                } else {
                    $db->exec("DELETE FROM stock_chassis_records");
                    $insert = $db->prepare("INSERT INTO stock_chassis_records (dealership_id, chassis_number) VALUES (:did, :chassis)");
                    foreach ($stockInsertRows as [$did, $chassis]) {
                        $insert->execute(['did' => $did, 'chassis' => $chassis]);
                    }
                    $message = count($stockInsertRows) . ' Chassis Imported Into The Current Stock Snapshot.';
                }
            }
        }
    }
}

// "Days aged" is measured against the last day of the CURRENT calendar month
// (not today), so it stays the same all month and only moves forward once
// the month rolls over — matches how the business tracks this internally.
$monthEnd = new DateTime(date('Y-m-t'));

$ageingRecordsCount = (int)$db->query("SELECT COUNT(*) FROM ageing_records")->fetchColumn();
$stockChassisCount = (int)$db->query("SELECT COUNT(*) FROM stock_chassis_records")->fetchColumn();

// Only a vehicle whose chassis is found in BOTH the imported Ageing data
// (for its delivery date) AND the imported Stock snapshot (proof it's still
// actually sitting in stock, not already sold) counts toward ageing.
$rows = $db->query("
    SELECT ar.*, d.name AS dealership_name, d.region FROM ageing_records ar
    JOIN dealerships d ON d.id = ar.dealership_id
    WHERE EXISTS (
        SELECT 1 FROM stock_chassis_records scr
        WHERE UPPER(TRIM(scr.chassis_number)) = UPPER(TRIM(ar.chassis_number))
    )
")->fetchAll();

foreach ($rows as &$r) {
    $deliveryDt = new DateTime($r['delivery_date']);
    $r['days_aged'] = (int)$monthEnd->diff($deliveryDt)->format('%r%a') * -1;
}
unset($r);

// Only vehicles aged 60+ days count as an ageing concern worth reporting.
$rows = array_values(array_filter($rows, fn($r) => $r['days_aged'] >= 60));

if (!$isSuperAdmin) {
    $rows = array_values(array_filter($rows, fn($r) => in_array((int)$r['dealership_id'], $scopedIds, true)));
}

usort($rows, fn($a, $b) => $b['days_aged'] <=> $a['days_aged']);

$regions = $db->query("SELECT DISTINCT region FROM dealerships WHERE region IS NOT NULL AND region != '' ORDER BY region")->fetchAll(PDO::FETCH_COLUMN);
$selectedRegion = trim($_GET['region'] ?? '');
if ($selectedRegion !== '') {
    $rows = array_values(array_filter($rows, fn($r) => $r['region'] === $selectedRegion));
}

// Grouped by dealership, worst (highest days aged) dealership first — and
// within each dealership, its own oldest vehicle first.
$grouped = [];
foreach ($rows as $r) {
    $grouped[$r['dealership_name']][] = $r;
}
uasort($grouped, fn($a, $b) => $b[0]['days_aged'] <=> $a[0]['days_aged']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>(function(){var t=localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
<meta charset="UTF-8">
<title>Ageing Report</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#1a1a19">
<link rel="apple-touch-icon" href="assets/icon-192.png">
<script>if('serviceWorker' in navigator){window.addEventListener('load',()=>navigator.serviceWorker.register('sw.js'));}</script>
<style>
.sortable-table thead th { cursor: pointer; user-select: none; }
.sortable-table thead th:hover { background: rgba(128,128,128,0.25); }
.flag-warn { color: var(--amber, #fab219); }
</style>
</head>
<body>
<div class="app-layout">
<?php require __DIR__ . '/includes/Sidebar.php'; ?>
<main class="main-content">
<div class="container">

  <header>
    <div>
      <h1>Ageing Report — <?= date('d M, Y') ?></h1>
      <div class="subtitle">Days Since Delivery Date, Measured Against <?= $monthEnd->format('d M, Y') ?> (Last Day Of This Month) — Per Vehicle/Chassis. Only Counts A Vehicle If Its Chassis Is Found In Both The Ageing Import And The Current Stock Import. Dealerships With The Oldest Stock Listed First.</div>
    </div>
    <div class="toolbar">
      <a href="export_ageing_report.php<?= $selectedRegion !== '' ? '?region=' . urlencode($selectedRegion) : '' ?>" class="btn primary">Export CSV</a>
    </div>
  </header>

  <?php if ($message): ?><div class="success-msg"><?= htmlspecialchars($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="error-msg"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if (!empty($importErrors)): ?>
    <div class="error-msg">
      <?= count($importErrors) ?> Row(s) Skipped:<br>
      <?= implode('<br>', array_map('htmlspecialchars', array_slice($importErrors, 0, 20))) ?>
      <?= count($importErrors) > 20 ? '<br>...' : '' ?>
    </div>
  <?php endif; ?>

  <?php if ($isSuperAdmin): ?>
  <div class="detail-card" style="margin-bottom:24px;">
    <div class="detail-grid">
      <div><div class="stat-label">Ageing Records On File</div><div class="stat-value"><?= number_format($ageingRecordsCount) ?></div></div>
      <div><div class="stat-label">Stock Chassis On File</div><div class="stat-value <?= $stockChassisCount === 0 ? 'flag-warn' : '' ?>"><?= number_format($stockChassisCount) ?></div></div>
    </div>
  </div>

  <form method="POST" enctype="multipart/form-data" class="search-panel" style="margin-bottom:12px;">
    <input type="hidden" name="action" value="import_csv">
    <div class="field" style="flex:2;">
      <label>1. Ageing CSV Or Excel (Needs Dealer Name, Chassis, Delivery Date Columns)</label>
      <input type="file" name="ageing_csv" accept=".csv,.xlsx" required>
    </div>
    <button type="submit" class="submit">Import Ageing CSV</button>
  </form>

  <form method="POST" enctype="multipart/form-data" class="search-panel" style="margin-bottom:24px;">
    <input type="hidden" name="action" value="import_stock_csv">
    <div class="field" style="flex:2;">
      <label>2. Stock CSV Or Excel — Current Stock Snapshot (Needs Dealer Name, Chassis Columns)</label>
      <input type="file" name="stock_chassis_csv" accept=".csv,.xlsx" required>
    </div>
    <button type="submit" class="submit">Import Stock CSV</button>
  </form>
  <div class="subtitle" style="margin-bottom:24px;">Stock CSV Import Replaces The Whole Current Stock Snapshot (Not Per-Dealership) — Always Upload The Full, Latest Stock File.</div>
  <?php endif; ?>

  <?php if (!empty($regions)): ?>
  <form method="GET" class="search-panel" style="margin-bottom:24px;">
    <div class="field">
      <label>Region</label>
      <select name="region" onchange="this.form.submit()">
        <option value="">— All Regions —</option>
        <?php foreach ($regions as $r): ?>
          <option value="<?= htmlspecialchars($r) ?>" <?= $r === $selectedRegion ? 'selected' : '' ?>><?= htmlspecialchars($r) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>
  <?php endif; ?>

  <?php if (empty($grouped)): ?>
    <?php if ($ageingRecordsCount === 0 || $stockChassisCount === 0): ?>
      <div class="empty-state">Import Both An Ageing CSV And A Stock CSV Above — A Vehicle Only Counts As Aged Once Its Chassis Is Found In Both.</div>
    <?php else: ?>
      <div class="empty-state">No Vehicles Currently Aged 60+ Days (Or None Of The Ageing Chassis Matched The Current Stock Snapshot).</div>
    <?php endif; ?>
  <?php else: ?>
    <?php foreach ($grouped as $dealershipName => $vehicles): ?>
    <div class="detail-card" style="margin-bottom:16px;">
      <h2 style="font-size:14px; margin-bottom:10px; display:flex; justify-content:space-between;">
        <span><?= htmlspecialchars($dealershipName) ?></span>
        <span class="subtitle">Oldest: <?= number_format($vehicles[0]['days_aged']) ?> Days · <?= count($vehicles) ?> Vehicle(s)</span>
      </h2>
      <div class="table-wrap">
        <table class="sortable-table">
          <thead>
            <tr>
              <th>Vehicle</th>
              <th>Chassis</th>
              <th>Delivery Date</th>
              <th class="th-center">Days Aged</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($vehicles as $r): ?>
            <tr>
              <td><?= htmlspecialchars($r['product_name']) ?></td>
              <td class="timestamp"><?= htmlspecialchars($r['chassis_number']) ?></td>
              <td class="timestamp" data-sort-value="<?= htmlspecialchars($r['delivery_date']) ?>"><?= date('d M, Y', strtotime($r['delivery_date'])) ?></td>
              <td class="th-center metric"><?= number_format($r['days_aged']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

</div>
</main>
</div>

<script>
// Click a column header to sort by it — numeric columns sort numerically,
// a cell's data-sort-value attribute wins if present (e.g. Delivery Date's
// raw Y-m-d, so it sorts chronologically instead of alphabetically by the
// displayed "13 Jul, 2026" text), otherwise text sorts A-Z/Z-A. Clicking the
// same header again flips direction.
function makeSortableTables(selector) {
  document.querySelectorAll(selector).forEach(table => {
    const headers = Array.from(table.querySelectorAll('thead th'));
    headers.forEach((th, colIndex) => {
      th.addEventListener('click', () => {
        const tbody = table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr'));
        const nextDir = th.getAttribute('data-sort-dir') === 'asc' ? 'desc' : 'asc';

        headers.forEach(h => {
          h.removeAttribute('data-sort-dir');
          h.textContent = h.textContent.replace(/ [▲▼]$/, '');
        });
        th.setAttribute('data-sort-dir', nextDir);
        th.textContent += nextDir === 'asc' ? ' ▲' : ' ▼';

        const cellValue = (row) => {
          const cell = row.children[colIndex];
          if (!cell) return '';
          return (cell.getAttribute('data-sort-value') || cell.textContent || '').trim();
        };

        rows.sort((a, b) => {
          const va = cellValue(a);
          const vb = cellValue(b);
          const na = parseFloat(va.replace(/,/g, ''));
          const nb = parseFloat(vb.replace(/,/g, ''));
          const bothNumeric = va !== '' && vb !== '' && !isNaN(na) && !isNaN(nb);
          const cmp = bothNumeric ? na - nb : va.localeCompare(vb, undefined, { numeric: true, sensitivity: 'base' });
          return nextDir === 'asc' ? cmp : -cmp;
        });

        rows.forEach(row => tbody.appendChild(row));
      });
    });
  });
}
document.addEventListener('DOMContentLoaded', () => makeSortableTables('.sortable-table'));
</script>
</body>
</html>
