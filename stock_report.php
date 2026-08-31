<?php
require_once __DIR__ . '/includes/Auth.php';
Auth::requireLogin();
if (!Auth::canView('stock_report')) {
    http_response_code(403);
    exit('You Do Not Have Access To This Page.');
}
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/SimpleXlsxReader.php';
require_once __DIR__ . '/includes/SpreadsheetImportHelper.php';

// Helper function to extract simplified product variant from full product description
// e.g., "SUZUKI ALTO AET306 VXR AGS M1 658 CC" => "ALTO VXR"
function extractProductVariant($fullProductName) {
    $fullProductName = trim($fullProductName);
    if (empty($fullProductName)) {
        return '';
    }
    
    // Remove leading "SUZUKI" if present
    $productName = preg_replace('/^SUZUKI\s+/i', '', $fullProductName);
    $productName = trim($productName);
    
    // Extract model name and variant using pattern matching
    // Patterns: "ALTO VXR", "CULTUS VXL", "SWIFT GL CVT", "FRONX GLX", "EVERY VXR"
    
    if (preg_match('/^(ALTO|CULTUS|SWIFT|EVERY|FRONX)\s+([A-Z]+)(?:\s+[A-Z]+)?\s+/i', $productName, $matches)) {
        $model = strtoupper($matches[1]);
        $variant = strtoupper($matches[2]);
        
        // Handle CVT specially (can be two words: "GL CVT")
        if (preg_match('/^(ALTO|CULTUS|SWIFT|EVERY|FRONX)\s+([A-Z]+\s+CVT)/i', $productName, $cvtMatches)) {
            $model = strtoupper($cvtMatches[1]);
            $variant = strtoupper($cvtMatches[2]);
        }
        
        return "{$model} {$variant}";
    }
    
    // Fallback: extract first two words (model and variant)
    $parts = explode(' ', $productName);
    if (count($parts) >= 2) {
        return strtoupper($parts[0] . ' ' . $parts[1]);
    }
    
    return strtoupper($productName);
}

$db = Database::getConnection();
$message = '';
$error = '';
$importErrors = [];
$isSuperAdmin = Auth::isSuperAdmin();
$scopedIds = Auth::dealershipIds();

// Dealerships that should never appear in the Stock Report at all (business
// decision, not a data-matching issue) — resolved to IDs once so both import
// and display can skip them silently rather than reporting them as "not found".
$excludedStockNames = ['suzuki habib motors', 'suzuki habib motors alipur'];
$excludedStockIds = [];
foreach ($db->query("SELECT id, name FROM dealerships")->fetchAll() as $d) {
    if (in_array(SpreadsheetImportHelper::normalizeDealershipName($d['name']), $excludedStockNames, true)) {
        $excludedStockIds[] = (int)$d['id'];
    }
}
if (!empty($excludedStockIds)) {
    $placeholders = implode(',', $excludedStockIds);
    $db->exec("DELETE FROM stock_records WHERE dealership_id IN ($placeholders)");
}

// Importing overwrites data for every dealership found in the file, not
// just the uploader's own — stays super-admin-only even though viewing this
// report can now be granted to scoped users.
if ($isSuperAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_csv') {
    if (empty($_FILES['stock_csv']['name']) || $_FILES['stock_csv']['error'] !== UPLOAD_ERR_OK) {
        $error = 'A CSV Or Excel File Is Required.';
    } else {
        $ext = strtolower(pathinfo($_FILES['stock_csv']['name'], PATHINFO_EXTENSION));
        $allRows = null;

        if ($ext === 'csv') {
            $handle = fopen($_FILES['stock_csv']['tmp_name'], 'r');
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
                // Multi-tab workbooks (Summary, Payments, Open Orders, etc.) don't
                // always have the stock data on the first sheet — prefer a tab
                // literally named "Undelivered Stock" when one exists, falling
                // back to sheet1 otherwise.
                $allRows = $xlsxReader->readSheetByName($_FILES['stock_csv']['tmp_name'], 'Undelivered Stock')
                    ?? $xlsxReader->readFirstSheet($_FILES['stock_csv']['tmp_name']);
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
                // Raw transactional exports (one row per order/delivery/unit,
                // with a "Product Desc" column) need auto-pivoting — grouping
                // by dealer + product and counting rows — instead of the wide
                // "one column per product" parsing below. Prefer "Dealer Name"
                // specifically here: these exports also carry a plain "Dealer"
                // column with a numeric code, which the generic match would
                // otherwise grab instead of the actual name.
                $dealerNameCol = SpreadsheetImportHelper::findColumn($headerRow, ['dealer name']) ?? $dealerCol;
                // Some exports have both a short branch/depot code column
                // ("REGIO": PK01, PK02...) and the actual zone code column
                // ("Region": ROSP, ROCP...) — the meaningful one for filtering
                // tends to be the later of the two.
                $regionCol = SpreadsheetImportHelper::findColumn($headerRow, ['region'], true);
                $productDescCol = SpreadsheetImportHelper::findColumn($headerRow, ['product desc', 'product description']);
                if ($productDescCol === null) {
                    $genericProductCol = SpreadsheetImportHelper::findColumn($headerRow, ['product']);
                    $isProductCode = $genericProductCol !== null
                        && SpreadsheetImportHelper::matchesAnyKeyword(trim((string)($headerRow[$genericProductCol] ?? '')), ['code']);
                    if ($genericProductCol !== null && !$isProductCode) {
                        $productDescCol = $genericProductCol;
                    }
                }

                if ($productDescCol !== null) {
                    // ---- Raw transactional: auto-pivot (count rows per dealer+product) ----
                    $dealershipsByName = [];
                    foreach ($db->query("SELECT id, name FROM dealerships")->fetchAll() as $d) {
                        $dealershipsByName[SpreadsheetImportHelper::normalizeDealershipName($d['name'])] = $d['id'];
                    }

                    $counts = [];        // [dealershipId][productName] => count
                    $regionByDealer = []; // dealershipId => region code
                    $productOrder = [];  // productName => first-seen column order
                    $orderCounter = 0;
                    $transactionRows = 0;

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
                        $productName = trim($row[$productDescCol] ?? '');
                        if ($productName === '') {
                            continue;
                        }

                        $dealershipId = SpreadsheetImportHelper::findDealershipMatch($dealershipsByName, $dealershipName);
                        if (!$dealershipId) {
                            $importErrors[] = "Row {$rowNum}: Dealership \"{$dealershipName}\" Not Found — Skipped.";
                            continue;
                        }
                        if (in_array($dealershipId, $excludedStockIds, true)) {
                            continue; // intentionally excluded from this report
                        }

                        if (!isset($productOrder[$productName])) {
                            $productOrder[$productName] = $orderCounter++;
                        }
                        $counts[$dealershipId][$productName] = ($counts[$dealershipId][$productName] ?? 0) + 1;
                        if ($regionCol !== null) {
                            $regionVal = trim((string)($row[$regionCol] ?? ''));
                            if ($regionVal !== '') {
                                $regionByDealer[$dealershipId] = $regionVal;
                            }
                        }
                        $transactionRows++;
                    }

                    if (empty($counts)) {
                        $error = 'No Matching Dealership Rows Found To Import.';
                    } else {
                        $insert = $db->prepare("INSERT INTO stock_records (dealership_id, product_name, quantity, column_order) VALUES (:did, :product, :qty, :order)");
                        $updateRegion = $db->prepare("UPDATE dealerships SET region = :region WHERE id = :id");
                        $importedCount = 0;
                        foreach ($counts as $dealershipId => $products) {
                            $db->prepare("DELETE FROM stock_records WHERE dealership_id = :id")->execute(['id' => $dealershipId]);
                            foreach ($products as $productName => $qty) {
                                $insert->execute(['did' => $dealershipId, 'product' => $productName, 'qty' => $qty, 'order' => $productOrder[$productName]]);
                                $importedCount++;
                            }
                            if (isset($regionByDealer[$dealershipId])) {
                                $updateRegion->execute(['region' => $regionByDealer[$dealershipId], 'id' => $dealershipId]);
                            }
                        }
                        $message = "{$transactionRows} Transaction Row(s) Auto-Pivoted Into {$importedCount} Dealership/Product Stock Entries.";
                    }
                } else {
                    // ---- Wide pre-aggregated: one column per product/variant ----
                    // Stock summaries are often a deep, multi-level header (Model
                    // > Variant > Normal/CUC, each level its own row with merged
                    // group cells) — combine however many header rows sit above
                    // the first real data row into one leaf-level label per column.
                    $combined = SpreadsheetImportHelper::combineMultiRowHeader($allRows, $headerIndex, $dealerCol);
                    $effectiveHeader = $combined['header'];
                    $dataStartIndex = $combined['dataStartIndex'];

                    $securityCol = SpreadsheetImportHelper::findColumn($effectiveHeader, ['security']);

                    // "TTL"/"Total" columns are per-model subtotals (there can be
                    // several, not just one overall total like Sales' Grand Total) —
                    // skipped rather than stored, so they don't get double-counted
                    // as their own product.
                    $skipKeywords = ['sr#', 'sr.', 'sr no', 's.no', 's no', 'serial', 'dealer', 'security', 'ttl', 'total'];
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

                        // Replace this dealership's stock snapshot on each import, so
                        // the report always reflects the latest uploaded file, not a
                        // growing pile of every past import.
                        $touchedDealershipIds = [];
                        $insert = $db->prepare("INSERT INTO stock_records (dealership_id, product_name, quantity, column_order) VALUES (:did, :product, :qty, :order)");
                        $updateSecurity = $db->prepare("UPDATE dealerships SET security_amount = :amt WHERE id = :id");
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
                            if (in_array($dealershipId, $excludedStockIds, true)) {
                                continue; // intentionally excluded from this report
                            }

                            if (!in_array($dealershipId, $touchedDealershipIds, true)) {
                                $db->prepare("DELETE FROM stock_records WHERE dealership_id = :id")->execute(['id' => $dealershipId]);
                                $touchedDealershipIds[] = $dealershipId;
                            }

                            foreach ($productCols as $col => $productName) {
                                $quantity = (int)($row[$col] ?? 0);
                                $insert->execute(['did' => $dealershipId, 'product' => $productName, 'qty' => $quantity, 'order' => $col]);
                                $importedCount++;
                            }

                            if ($securityCol !== null) {
                                $securityAmount = trim((string)($row[$securityCol] ?? ''));
                                if ($securityAmount !== '') {
                                    $updateSecurity->execute(['amt' => (float)$securityAmount, 'id' => $dealershipId]);
                                }
                            }
                        }

                        $message = "{$importedCount} Row(s) Imported (" . count($productCols) . " Product/Variant Columns).";
                    }
                }
            }
        }
    }
}

// Master column list — every distinct product variant on record, ordered by a
// hand-specified variant priority (e.g. "ALTO VXR" before "ALTO AGS")
// rather than plain alphabetical.
$variantPriority = [
    'ALTO VXR', 'ALTO VXR AGS', 'ALTO AGS', 'ALTO VXL AGS',
    'CULTUS VXR', 'CULTUS VXL', 'CULTUS AGS',
    'FRONX GL', 'FRONX GL AT', 'FRONX GLX',
    'SWIFT MT', 'SWIFT GL', 'SWIFT GL CVT', 'SWIFT GLX',
    'EVERY VXR', 'EVERY',
];

// Get all distinct extracted product variants
$productNames = $db->query("SELECT DISTINCT product_name FROM stock_records")->fetchAll(PDO::FETCH_COLUMN);
$extractedVariants = array_unique(array_map('extractProductVariant', $productNames));
$extractedVariants = array_filter($extractedVariants); // Remove empty strings

// Sort by priority
$sortedVariants = [];
foreach ($variantPriority as $priority) {
    if (in_array($priority, $extractedVariants, true)) {
        $sortedVariants[] = $priority;
        unset($extractedVariants[array_search($priority, $extractedVariants, true)]);
    }
}
// Add remaining variants in alphabetical order
sort($extractedVariants);
$masterColumns = array_map(fn($v) => ['variant' => $v], array_merge($sortedVariants, $extractedVariants));

$regions = $db->query("SELECT DISTINCT region FROM dealerships WHERE region IS NOT NULL AND region != '' ORDER BY region")->fetchAll(PDO::FETCH_COLUMN);
$selectedRegion = trim($_GET['region'] ?? '');

$rowsQuery = "
    SELECT sr.*, d.name AS dealership_name, d.security_amount, d.region FROM stock_records sr
    JOIN dealerships d ON d.id = sr.dealership_id
";
$rowsConditions = [];
$rowsParams = [];
if (!empty($excludedStockIds)) {
    $rowsConditions[] = "d.id NOT IN (" . implode(',', $excludedStockIds) . ")";
}
if ($selectedRegion !== '') {
    $rowsConditions[] = "d.region = :region";
    $rowsParams['region'] = $selectedRegion;
}
if (!$isSuperAdmin) {
    $rowsConditions[] = !empty($scopedIds) ? "d.id IN (" . implode(',', array_map('intval', $scopedIds)) . ")" : "1 = 0";
}
if (!empty($rowsConditions)) {
    $rowsQuery .= " WHERE " . implode(' AND ', $rowsConditions);
}
$rowsStmt = $db->prepare($rowsQuery);
$rowsStmt->execute($rowsParams);
$rows = $rowsStmt->fetchAll();

// Group by dealership and extracted product variant
$pivot = [];
$variantAppearanceOrder = []; // Track order of first appearance for each variant

foreach ($rows as $r) {
    $dealership = $r['dealership_name'];
    $extractedVariant = extractProductVariant($r['product_name']);
    
    // Track first appearance order of variants
    if (!isset($variantAppearanceOrder[$extractedVariant])) {
        $variantAppearanceOrder[$extractedVariant] = count($variantAppearanceOrder);
    }
    
    if (!isset($pivot[$dealership])) {
        $pivot[$dealership] = [];
    }
    
    $pivot[$dealership]['__security'] = $r['security_amount'];
    
    // Sum quantities if the same variant appears multiple times (e.g., different full product names)
    $pivot[$dealership][$extractedVariant] = ($pivot[$dealership][$extractedVariant] ?? 0) + (int)$r['quantity'];
}

// Highest total stock first.
$computeRowTotal = fn(array $products) => array_sum(array_diff_key($products, ['__security' => true]));
uasort($pivot, fn($a, $b) => $computeRowTotal($b) <=> $computeRowTotal($a));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>(function(){var t=localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
<meta charset="UTF-8">
<title>Stock Report</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#1a1a19">
<link rel="apple-touch-icon" href="assets/icon-192.png">
<script>if('serviceWorker' in navigator){window.addEventListener('load',()=>navigator.serviceWorker.register('sw.js'));}</script>
<style>
.wide-report-table { border-collapse: collapse; width: 100%; font-size: 11px; table-layout: fixed; }
.wide-report-table th, .wide-report-table td { border: 1px solid var(--border); padding: 3px 4px; text-align: center; }
.wide-report-table td { white-space: nowrap; }
.wide-report-table th { white-space: normal; word-break: break-word; line-height: 1.25; font-size: 10px; max-width: 70px; }
.wide-report-table th:nth-child(1), .wide-report-table td:nth-child(1) { width: 30px; }
.wide-report-table th:nth-child(2), .wide-report-table td:nth-child(2) { width: 200px; max-width: 200px; white-space: normal; }
.wide-report-table td.name-cell { text-align: left; font-weight: 600; white-space: normal; }
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
      <h1>Stock Report — <?= date('d M, Y') ?></h1>
      <div class="subtitle">Vehicle Count Per Dealership By Model/Variant — Imported From CSV/Excel. Each Import Replaces That Dealership's Previous Snapshot.</div>
    </div>
    <div class="toolbar no-print">
      <a href="export_stock_report.php<?= $selectedRegion !== '' ? '?region=' . urlencode($selectedRegion) : '' ?>" class="btn primary">Export CSV</a>
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
  <form method="POST" enctype="multipart/form-data" class="search-panel no-print" style="margin-bottom:12px;">
    <input type="hidden" name="action" value="import_csv">
    <div class="field" style="flex:2;">
      <label>Stock CSV Or Excel</label>
      <input type="file" name="stock_csv" accept=".csv,.xlsx" required>
    </div>
    <button type="submit" class="submit">Import CSV</button>
  </form>
  <div class="subtitle no-print" style="margin-bottom:24px;">A "Security Amount" Column In Your File Updates Each Dealership's Overall Security Amount (Shown As The Last Column Below). "TTL"/"Total" Columns Are Treated As Subtotals And Not Imported As Products.</div>
  <?php endif; ?>

  <?php if (!empty($regions)): ?>
  <form method="GET" class="search-panel no-print" style="margin-bottom:24px;">
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

  <?php if (empty($pivot)): ?>
    <div class="empty-state">No Stock Data Yet — Import A CSV Above.</div>
  <?php else: ?>
  <div class="table-wrap" style="overflow-x:auto;">
    <table class="wide-report-table sortable-table" id="stock-report-table">
      <thead>
        <tr>
          <th>SR#</th>
          <th>DEALER</th>
          <?php foreach ($masterColumns as $c): ?>
            <th><?= htmlspecialchars($c['variant']) ?></th>
          <?php endforeach; ?>
          <th>TOTAL</th>
          <th>AVAILABLE SECURITY AMOUNT</th>
        </tr>
      </thead>
      <tbody>
        <?php $sr = 0; foreach ($pivot as $dealershipName => $products): $sr++;
          $rowTotal = 0;
          foreach ($masterColumns as $c) { $rowTotal += $products[$c['variant']] ?? 0; }
        ?>
        <tr>
          <td><?= $sr ?></td>
          <td class="name-cell"><?= htmlspecialchars($dealershipName) ?></td>
          <?php foreach ($masterColumns as $c): ?>
            <td><?= number_format($products[$c['variant']] ?? 0) ?></td>
          <?php endforeach; ?>
          <td style="font-weight:600;"><?= number_format($rowTotal) ?></td>
          <td><?= $products['__security'] !== null ? number_format($products['__security'], 2) : '—' ?></td>
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
