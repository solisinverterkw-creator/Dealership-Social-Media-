<?php
require_once __DIR__ . '/includes/Auth.php';
Auth::requireLogin();
if (!Auth::canView('sales_report')) {
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
    $periodMonth = trim($_POST['period_month'] ?? '');

    if ($periodMonth === '') {
        $error = 'Please Select The Month This Sales Data Is For.';
    } elseif (empty($_FILES['sales_csv']['name']) || $_FILES['sales_csv']['error'] !== UPLOAD_ERR_OK) {
        $error = 'A CSV Or Excel File Is Required.';
    } else {
        $ext = strtolower(pathinfo($_FILES['sales_csv']['name'], PATHINFO_EXTENSION));
        $allRows = null;

        if ($ext === 'csv') {
            $handle = fopen($_FILES['sales_csv']['tmp_name'], 'r');
            if ($handle) {
                $allRows = [];
                while (($row = fgetcsv($handle)) !== false) {
                    $allRows[] = $row;
                }
                fclose($handle);
            }
        } elseif ($ext === 'xlsx') {
            try {
                $allRows = (new SimpleXlsxReader())->readFirstSheet($_FILES['sales_csv']['tmp_name']);
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

            if ($dealerCol === null) {
                $error = 'Could Not Find A "Dealer"/"Dealership" Column In The Header Row.';
            } else {
                // Wide "one column per product" reports (Alto, EVERY, Cultus,
                // SWIFT... each their own column) often have a merged group
                // header ("Model Wise Sale") on the header row and the real
                // per-product names on the row right below it. Detect that sub-
                // header row by the Dealer column being blank there — a merged
                // cell's continuation row has no value of its own.
                $nextRow = $allRows[$headerIndex + 1] ?? [];
                $hasSubHeaderRow = trim((string)($nextRow[$dealerCol] ?? '')) === ''
                    && count(array_filter($nextRow, fn($c) => trim((string)$c) !== '')) > 0;

                $effectiveHeader = $headerRow;
                $dataStartIndex = $headerIndex + 1;
                if ($hasSubHeaderRow) {
                    foreach ($nextRow as $col => $val) {
                        if (trim((string)$val) !== '') {
                            $effectiveHeader[$col] = $val;
                        }
                    }
                    $dataStartIndex = $headerIndex + 2;
                }

                $targetCol = SpreadsheetImportHelper::findColumn($effectiveHeader, ['target']);
                $totalCol = SpreadsheetImportHelper::findColumn($effectiveHeader, ['total']);

                // Non-product columns to skip when scanning for model/product
                // columns — everything else (in original left-to-right order)
                // is treated as a product column dynamically, whatever it's
                // called (this is also what "Grand Total" gets reinserted
                // relative to, via $totalCol's own position).
                $skipKeywords = ['sr#', 'sr.', 'sr no', 's.no', 's no', 'serial', 'dealer', 'target', 'total'];
                $productCols = [];
                foreach ($effectiveHeader as $col => $label) {
                    $labelLower = trim((string)$label);
                    if ($labelLower === '' || SpreadsheetImportHelper::matchesAnyKeyword($labelLower, $skipKeywords)) {
                        continue;
                    }
                    $productCols[$col] = $labelLower;
                }

                if (empty($productCols)) {
                    $error = 'Could Not Find Any Product/Model Columns — Check Your Column Headers.';
                } else {
                    $dealershipsByName = [];
                    foreach ($db->query("SELECT id, name FROM dealerships")->fetchAll() as $d) {
                        $dealershipsByName[SpreadsheetImportHelper::normalizeDealershipName($d['name'])] = $d['id'];
                    }

                    $insert = $db->prepare("INSERT INTO sales_records (dealership_id, product_name, quantity, period_month, column_order) VALUES (:did, :product, :qty, :month, :order)");
                    $upsertSummary = $db->prepare("
                        INSERT INTO sales_summary (dealership_id, period_month, target, grand_total, grand_total_column_order)
                        VALUES (:did, :month, :target, :total, :total_order)
                        ON DUPLICATE KEY UPDATE target = :target2, grand_total = :total2, grand_total_column_order = :total_order2
                    ");
                    // Clear this period's old rows for dealerships in this file, so
                    // re-importing the same month replaces rather than duplicates.
                    $touchedDealershipIds = [];
                    $importedCount = 0;

                    for ($i = $dataStartIndex; $i < count($allRows); $i++) {
                        $row = $allRows[$i];
                        $rowNum = $i + 1;
                        if (count(array_filter($row, fn($c) => trim((string)$c) !== '')) === 0) {
                            continue; // blank line
                        }

                        $dealershipName = trim($row[$dealerCol] ?? '');
                        if ($dealershipName === '') {
                            continue; // blank trailing row
                        }

                        $dealershipId = SpreadsheetImportHelper::findDealershipMatch($dealershipsByName, $dealershipName);
                        if (!$dealershipId) {
                            $importErrors[] = "Row {$rowNum}: Dealership \"{$dealershipName}\" Not Found — Skipped.";
                            continue;
                        }

                        if (!in_array($dealershipId, $touchedDealershipIds, true)) {
                            $db->prepare("DELETE FROM sales_records WHERE dealership_id = :id AND period_month = :month")
                               ->execute(['id' => $dealershipId, 'month' => $periodMonth]);
                            $touchedDealershipIds[] = $dealershipId;
                        }

                        foreach ($productCols as $col => $productName) {
                            $quantity = (int)($row[$col] ?? 0);
                            $insert->execute(['did' => $dealershipId, 'product' => $productName, 'qty' => $quantity, 'month' => $periodMonth, 'order' => $col]);
                            $importedCount++;
                        }

                        $targetVal = $targetCol !== null ? (int)($row[$targetCol] ?? 0) : null;
                        $totalVal = $totalCol !== null ? (int)($row[$totalCol] ?? 0) : null;
                        $upsertSummary->execute([
                            'did' => $dealershipId, 'month' => $periodMonth,
                            'target' => $targetVal, 'total' => $totalVal, 'total_order' => $totalCol,
                            'target2' => $targetVal, 'total2' => $totalVal, 'total_order2' => $totalCol,
                        ]);
                    }

                    $message = "{$importedCount} Row(s) Imported For " . date('F Y', strtotime($periodMonth . '-01')) . " (Products: " . implode(', ', $productCols) . ").";
                }
            }
        }
    }
}

$periods = $db->query("SELECT DISTINCT period_month FROM sales_records ORDER BY period_month DESC")->fetchAll(PDO::FETCH_COLUMN);
$selectedPeriod = $_GET['period'] ?? ($periods[0] ?? date('Y-m'));

// Master column order for this period — every distinct (product_name, column_order)
// pair seen this month, ordered left-to-right as originally imported.
$colStmt = $db->prepare("SELECT DISTINCT product_name, column_order FROM sales_records WHERE period_month = :period ORDER BY column_order");
$colStmt->execute(['period' => $selectedPeriod]);
$masterColumns = $colStmt->fetchAll();

$grandTotalOrderStmt = $db->prepare("SELECT grand_total_column_order FROM sales_summary WHERE period_month = :period AND grand_total_column_order IS NOT NULL LIMIT 1");
$grandTotalOrderStmt->execute(['period' => $selectedPeriod]);
$grandTotalColumnOrder = $grandTotalOrderStmt->fetchColumn();

// Build the final left-to-right column sequence, splicing in a "Grand Total"
// marker at the position it originally held among the product columns.
$columnSequence = [];
$grandTotalInserted = false;
foreach ($masterColumns as $c) {
    if ($grandTotalColumnOrder !== false && !$grandTotalInserted && (int)$c['column_order'] > (int)$grandTotalColumnOrder) {
        $columnSequence[] = ['type' => 'grand_total'];
        $grandTotalInserted = true;
    }
    $columnSequence[] = ['type' => 'product', 'name' => $c['product_name']];
}
if ($grandTotalColumnOrder !== false && !$grandTotalInserted) {
    $columnSequence[] = ['type' => 'grand_total'];
}

$scopeSql = (!$isSuperAdmin && !empty($scopedIds)) ? " AND sr.dealership_id IN (" . implode(',', array_map('intval', $scopedIds)) . ")" : '';
$rows = $db->prepare("
    SELECT sr.*, d.name AS dealership_name FROM sales_records sr
    JOIN dealerships d ON d.id = sr.dealership_id
    WHERE sr.period_month = :period{$scopeSql}
");
$rows->execute(['period' => $selectedPeriod]);
$salesRows = (!$isSuperAdmin && empty($scopedIds)) ? [] : $rows->fetchAll();

$summaryStmt = $db->prepare("SELECT dealership_id, target, grand_total FROM sales_summary WHERE period_month = :period");
$summaryStmt->execute(['period' => $selectedPeriod]);
$summaryByDealership = [];
foreach ($summaryStmt->fetchAll() as $s) {
    $summaryByDealership[$s['dealership_id']] = $s;
}

// Pivot long rows (one per dealership+product) into dealershipName => productName => quantity.
$pivot = [];
foreach ($salesRows as $r) {
    $pivot[$r['dealership_name']]['__id'] = $r['dealership_id'];
    $pivot[$r['dealership_name']][$r['product_name']] = (int)$r['quantity'];
}
ksort($pivot);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>(function(){var t=localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
<meta charset="UTF-8">
<title>Sales Report</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#1a1a19">
<link rel="apple-touch-icon" href="assets/icon-192.png">
<script>if('serviceWorker' in navigator){window.addEventListener('load',()=>navigator.serviceWorker.register('sw.js'));}</script>
<style>
.wide-report-table { border-collapse: collapse; width: 100%; font-size: 12px; }
.wide-report-table th, .wide-report-table td { border: 1px solid var(--border); padding: 6px 10px; text-align: center; white-space: nowrap; }
.wide-report-table td.name-cell { text-align: left; font-weight: 600; }
.wide-report-table thead th { background: var(--panel-alt, rgba(128,128,128,0.15)); }
.wide-report-table tbody tr:hover { background: rgba(128,128,128,0.08); }
.sortable-table thead th { cursor: pointer; user-select: none; }
.sortable-table thead th:hover { background: rgba(128,128,128,0.25); }
</style>
</head>
<body>
<div class="app-layout">
<?php require __DIR__ . '/includes/Sidebar.php'; ?>
<main class="main-content">
<div class="container">

  <header>
    <div>
      <h1>Sales Report — <?= date('d M, Y') ?></h1>
      <div class="subtitle">Product/Model-Wise Sales Per Dealership — Imported From CSV/Excel</div>
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
  <form method="POST" enctype="multipart/form-data" class="search-panel no-print" style="margin-bottom:28px;">
    <input type="hidden" name="action" value="import_csv">
    <div class="field">
      <label>Month This Data Is For</label>
      <input type="month" name="period_month" value="<?= htmlspecialchars(date('Y-m')) ?>" required>
    </div>
    <div class="field" style="flex:2;">
      <label>Sales CSV Or Excel</label>
      <input type="file" name="sales_csv" accept=".csv,.xlsx" required>
    </div>
    <button type="submit" class="submit">Import CSV</button>
  </form>
  <?php endif; ?>

  <?php if (!empty($periods)): ?>
  <form method="GET" class="search-panel no-print" style="margin-bottom:24px;">
    <div class="field">
      <label>Viewing Month</label>
      <select name="period" onchange="this.form.submit()">
        <?php foreach ($periods as $p): ?>
          <option value="<?= htmlspecialchars($p) ?>" <?= $p === $selectedPeriod ? 'selected' : '' ?>><?= date('F Y', strtotime($p . '-01')) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>
  <?php endif; ?>

  <?php if (empty($pivot)): ?>
    <div class="empty-state">No Sales Data For This Month Yet — Import A CSV Above.</div>
  <?php else: ?>
  <div class="table-wrap" style="overflow-x:auto;">
    <table class="wide-report-table sortable-table" id="sales-report-table">
      <thead>
        <tr>
          <th>Sr#</th>
          <th>Dealer</th>
          <th>Target</th>
          <?php foreach ($columnSequence as $c): ?>
            <th><?= $c['type'] === 'grand_total' ? 'Grand Total' : htmlspecialchars(SpreadsheetImportHelper::friendlyProductLabel($c['name'])) ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php $sr = 0; foreach ($pivot as $dealershipName => $products): $sr++; $dId = $products['__id']; $summary = $summaryByDealership[$dId] ?? null; ?>
        <tr>
          <td><?= $sr ?></td>
          <td class="name-cell"><?= htmlspecialchars($dealershipName) ?></td>
          <td><?= $summary && $summary['target'] !== null ? number_format($summary['target']) : '—' ?></td>
          <?php foreach ($columnSequence as $c): ?>
            <?php if ($c['type'] === 'grand_total'): ?>
              <td><?= $summary && $summary['grand_total'] !== null ? number_format($summary['grand_total']) : '—' ?></td>
            <?php else: ?>
              <td><?= number_format($products[$c['name']] ?? 0) ?></td>
            <?php endif; ?>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

</div>
</main>
</div>

<script>
// Click a column header to sort by it — numeric columns sort numerically, a
// cell's data-sort-value attribute wins if present, otherwise text sorts
// A-Z/Z-A. Clicking the same header again flips direction.
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
