<?php
require_once __DIR__ . '/includes/Auth.php';
Auth::requireLogin();
if (!Auth::canView('crm_report')) {
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

// The template is free-text and admin-editable (crm_parameters.php), so
// matching import columns to parameters by keyword/text is fragile — instead
// we match POSITIONALLY: the column right after "Dealer Name" is parameter
// #1 (lowest display_order), the next is parameter #2, and so on. The
// exported template (Export Template button) is the source of truth for the
// expected column order.
$parameters = $db->query("SELECT * FROM crm_parameters ORDER BY display_order, id")->fetchAll();
$totalMaxPoints = array_sum(array_column($parameters, 'max_points'));

// Importing overwrites data for every dealership found in the file, not
// just the uploader's own — stays super-admin-only even though viewing this
// report can now be granted to scoped users.
if ($isSuperAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_csv') {
    $periodMonth = trim($_POST['period_month'] ?? '');

    if ($periodMonth === '') {
        $error = 'Please Select The Month This CRM Data Is For.';
    } elseif (empty($parameters)) {
        $error = 'No CRM Parameters Defined Yet — Add Them In CRM Parameters First.';
    } elseif (empty($_FILES['crm_csv']['name']) || $_FILES['crm_csv']['error'] !== UPLOAD_ERR_OK) {
        $error = 'A CSV Or Excel File Is Required.';
    } else {
        $ext = strtolower(pathinfo($_FILES['crm_csv']['name'], PATHINFO_EXTENSION));
        $allRows = null;

        if ($ext === 'csv') {
            $handle = fopen($_FILES['crm_csv']['tmp_name'], 'r');
            if ($handle) {
                $allRows = [];
                while (($row = fgetcsv($handle)) !== false) {
                    $allRows[] = $row;
                }
                fclose($handle);
            }
        } elseif ($ext === 'xlsx') {
            try {
                $allRows = (new SimpleXlsxReader())->readFirstSheet($_FILES['crm_csv']['tmp_name']);
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
            $headerIndex = SpreadsheetImportHelper::findHeaderRowIndex($allRows, ['dealer', 'company']);
            $headerRow = $allRows[$headerIndex] ?? [];
            $dealerCol = SpreadsheetImportHelper::findColumn($headerRow, ['dealer', 'dealership', 'company']);

            if ($dealerCol === null) {
                $error = 'Could Not Find A "Dealer"/"Dealership"/"Company" Column In The Header Row.';
            } else {
                // Every non-blank column after the dealer column, in order,
                // is one KPI's "Points Obtained" — positionally matched to
                // $parameters (already ordered by display_order).
                $scoreCols = [];
                foreach ($headerRow as $col => $label) {
                    if ($col <= $dealerCol) {
                        continue;
                    }
                    if (trim((string)$label) === '') {
                        continue;
                    }
                    $scoreCols[] = $col;
                }

                if (count($scoreCols) < count($parameters)) {
                    $error = 'Found ' . count($scoreCols) . ' Score Column(s) After "Dealer" But There Are ' . count($parameters) . ' CRM Parameters — Check The File Matches The Template (Use "Export Template" Below).';
                } else {
                    $dealershipsByName = [];
                    foreach ($db->query("SELECT id, name FROM dealerships")->fetchAll() as $d) {
                        $dealershipsByName[SpreadsheetImportHelper::normalizeDealershipName($d['name'])] = $d['id'];
                    }

                    $upsert = $db->prepare("
                        INSERT INTO crm_scores (dealership_id, crm_parameter_id, period_month, points_obtained)
                        VALUES (:did, :pid, :month, :pts)
                        ON DUPLICATE KEY UPDATE points_obtained = :pts2
                    ");
                    $importedCount = 0;

                    for ($i = $headerIndex + 1; $i < count($allRows); $i++) {
                        $row = $allRows[$i];
                        $rowNum = $i + 1;
                        if (count(array_filter($row, fn($c) => trim((string)$c) !== '')) === 0) {
                            continue; // blank line
                        }

                        $dealershipName = trim($row[$dealerCol] ?? '');
                        if ($dealershipName === '') {
                            continue;
                        }

                        $dealershipId = SpreadsheetImportHelper::findDealershipMatch($dealershipsByName, $dealershipName);
                        if (!$dealershipId) {
                            $importErrors[] = "Row {$rowNum}: Dealership \"{$dealershipName}\" Not Found — Skipped.";
                            continue;
                        }

                        foreach ($parameters as $idx => $param) {
                            $col = $scoreCols[$idx];
                            $pointsRaw = trim((string)($row[$col] ?? ''));
                            if ($pointsRaw === '') {
                                continue;
                            }
                            $points = (float)$pointsRaw;
                            $upsert->execute(['did' => $dealershipId, 'pid' => $param['id'], 'month' => $periodMonth, 'pts' => $points, 'pts2' => $points]);
                            $importedCount++;
                        }
                    }

                    $message = "{$importedCount} Score(s) Imported For " . date('F Y', strtotime($periodMonth . '-01')) . ".";
                }
            }
        }
    }
}

$periods = $db->query("SELECT DISTINCT period_month FROM crm_scores ORDER BY period_month DESC")->fetchAll(PDO::FETCH_COLUMN);
$selectedPeriod = $_GET['period'] ?? ($periods[0] ?? date('Y-m'));

$dealershipScopeSql = (!$isSuperAdmin && !empty($scopedIds)) ? " WHERE id IN (" . implode(',', array_map('intval', $scopedIds)) . ")" : '';
$dealerships = (!$isSuperAdmin && empty($scopedIds)) ? [] : $db->query("SELECT * FROM dealerships{$dealershipScopeSql} ORDER BY name")->fetchAll();
$selectedDealershipId = (int)($_GET['dealership_id'] ?? 0);

$scoreScopeSql = (!$isSuperAdmin && !empty($scopedIds)) ? " AND cs.dealership_id IN (" . implode(',', array_map('intval', $scopedIds)) . ")" : '';
$scoreRows = $db->prepare("
    SELECT cs.*, d.name AS dealership_name FROM crm_scores cs
    JOIN dealerships d ON d.id = cs.dealership_id
    WHERE cs.period_month = :period{$scoreScopeSql}
");
if (!$isSuperAdmin && empty($scopedIds)) {
    $scoreRowsResult = [];
} else {
    $scoreRows->execute(['period' => $selectedPeriod]);
    $scoreRowsResult = $scoreRows->fetchAll();
}

// Pivot into dealershipName => parameterId => points_obtained.
$pivot = [];
foreach ($scoreRowsResult as $r) {
    $pivot[$r['dealership_name']]['__id'] = $r['dealership_id'];
    $pivot[$r['dealership_name']][$r['crm_parameter_id']] = (float)$r['points_obtained'];
}
ksort($pivot);

// Single-dealership scorecard view (Sr#/Parameters/Criteria/Max Pts/Points
// Obtained, like the original scorecard sheet) — only built when a specific
// dealership is picked from the filter below.
$selectedDealership = null;
$scoreByParam = [];
if ($selectedDealershipId) {
    foreach ($dealerships as $d) {
        if ((int)$d['id'] === $selectedDealershipId) {
            $selectedDealership = $d;
            break;
        }
    }
    if ($selectedDealership) {
        $oneStmt = $db->prepare("SELECT crm_parameter_id, points_obtained FROM crm_scores WHERE dealership_id = :id AND period_month = :period");
        $oneStmt->execute(['id' => $selectedDealershipId, 'period' => $selectedPeriod]);
        foreach ($oneStmt->fetchAll() as $r) {
            $scoreByParam[$r['crm_parameter_id']] = (float)$r['points_obtained'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>(function(){var t=localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
<meta charset="UTF-8">
<title>CRM Report</title>
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
.wide-report-table td.below-target { color: var(--red); font-weight: 600; background: rgba(208,59,59,0.12); }
</style>
</head>
<body>
<div class="app-layout">
<?php require __DIR__ . '/includes/Sidebar.php'; ?>
<main class="main-content">
<div class="container">

  <header>
    <div>
      <h1>CRM Report</h1>
      <div class="subtitle">CRM &amp; Dealership Infrastructure Scorecard — Points Obtained Per KPI, Per Dealership, Per Month. Cells Below Max Points Are Highlighted. Total Max: <?= number_format($totalMaxPoints) ?></div>
    </div>
    <div class="toolbar">
      <a href="export_crm_report.php?period=<?= urlencode($selectedPeriod) ?><?= $selectedDealershipId ? '&dealership_id=' . $selectedDealershipId : '' ?>" class="btn primary">Export CSV</a>
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
  <form method="POST" enctype="multipart/form-data" class="search-panel no-print" style="margin-bottom:16px;">
    <input type="hidden" name="action" value="import_csv">
    <div class="field">
      <label>Month This Data Is For</label>
      <input type="month" name="period_month" value="<?= htmlspecialchars(date('Y-m')) ?>" required>
    </div>
    <div class="field" style="flex:2;">
      <label>CRM Scores CSV Or Excel (Dealer Name, Then One Column Per KPI In Template Order)</label>
      <input type="file" name="crm_csv" accept=".csv,.xlsx" required>
    </div>
    <button type="submit" class="submit">Import CSV</button>
  </form>

  <div class="no-print" style="margin-bottom:24px;">
    <a href="export_crm_report.php?template=1" class="btn">Export Template (Blank, With Current Parameters)</a>
  </div>
  <?php endif; ?>

  <form method="GET" class="search-panel no-print" style="margin-bottom:24px;">
    <div class="field">
      <label>Dealership</label>
      <select name="dealership_id" onchange="this.form.submit()">
        <option value="0">— All Dealerships —</option>
        <?php foreach ($dealerships as $d): ?>
          <option value="<?= $d['id'] ?>" <?= $selectedDealershipId === (int)$d['id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if (!empty($periods)): ?>
    <div class="field">
      <label>Viewing Month</label>
      <select name="period" onchange="this.form.submit()">
        <?php foreach ($periods as $p): ?>
          <option value="<?= htmlspecialchars($p) ?>" <?= $p === $selectedPeriod ? 'selected' : '' ?>><?= date('F Y', strtotime($p . '-01')) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
  </form>

  <?php if ($selectedDealershipId && !$selectedDealership): ?>
    <div class="error-msg">Dealership Not Found.</div>
  <?php elseif ($selectedDealership): ?>
    <?php
      // Parameters with Max Points = 0 aren't scored at all — their
      // points_obtained holds a direct achievement % instead, shown as its
      // own "X%" figure and left out of the points total entirely.
      $dealerTotalObtained = 0;
      foreach ($parameters as $p) {
          if ((float)$p['max_points'] === 0.0) {
              continue;
          }
          $dealerTotalObtained += $scoreByParam[$p['id']] ?? 0;
      }
    ?>
    <div class="detail-card" style="margin-bottom:16px;">
      <h2 style="display:flex; justify-content:space-between; align-items:center; font-size:16px;">
        <span><?= htmlspecialchars($selectedDealership['name']) ?></span>
        <span class="subtitle"><?= date('F Y', strtotime($selectedPeriod . '-01')) ?></span>
      </h2>
    </div>
    <?php if (empty($scoreByParam)): ?>
      <div class="empty-state">No CRM Scores For This Dealership This Month Yet.</div>
    <?php else: ?>
    <div class="table-wrap" style="overflow-x:auto;">
      <table class="wide-report-table">
        <thead>
          <tr>
            <th>Sr#</th>
            <th style="text-align:left;">Parameters</th>
            <th style="text-align:left;">Criteria</th>
            <th>Max Pts</th>
            <th>Points Obtained</th>
          </tr>
        </thead>
        <tbody>
          <?php
            // Parameters scored against a per-dealership target (set via
            // Edit Dealership, not the monthly raw sheet) show that number
            // in the Criteria column so it's obvious what's being measured
            // against for THIS dealership.
            $dealerTargetFieldByCalcKey = [
                'digital_enquiry_targets' => 'digital_enquiry_target',
                'stage_won_conversion' => 'digital_enquiry_conversion_target',
            ];
          ?>
          <?php foreach ($parameters as $i => $p): ?>
            <?php
              $isDirectResult = (float)$p['max_points'] === 0.0;
              $obtained = $scoreByParam[$p['id']] ?? null;
              $belowTarget = $obtained !== null && $obtained < ($isDirectResult ? 100 : (float)$p['max_points']);

              $criteriaText = $p['criteria'] ?? '';
              $targetField = $dealerTargetFieldByCalcKey[$p['calc_key']] ?? null;
              if ($targetField !== null && ($selectedDealership[$targetField] ?? null) !== null) {
                  $criteriaText = trim($criteriaText . ' (Target: ' . number_format($selectedDealership[$targetField], 0) . ')');
              }
            ?>
            <tr>
              <td><?= $i + 1 ?></td>
              <td style="text-align:left; white-space:normal;"><?= htmlspecialchars($p['parameter_name']) ?></td>
              <td style="text-align:left; white-space:normal;"><?= htmlspecialchars($criteriaText) ?></td>
              <td><?= $isDirectResult ? '—' : number_format($p['max_points'], 0) ?></td>
              <td class="<?= $belowTarget ? 'below-target' : '' ?>"><?= $obtained !== null ? number_format($obtained, 1) . ($isDirectResult ? '%' : '') : '—' ?></td>
            </tr>
          <?php endforeach; ?>
          <tr style="font-weight:600;">
            <td></td>
            <td style="text-align:left;" colspan="2">TOTAL CRM POINTS</td>
            <td><?= number_format($totalMaxPoints, 0) ?></td>
            <td><?= number_format($dealerTotalObtained, 1) ?></td>
          </tr>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  <?php elseif (empty($pivot)): ?>
    <div class="empty-state">No CRM Data For This Month Yet — Import A CSV Above.</div>
  <?php else: ?>
  <div class="table-wrap" style="overflow-x:auto;">
    <table class="wide-report-table">
      <thead>
        <tr>
          <th>Sr#</th>
          <th>Dealer</th>
          <?php foreach ($parameters as $p): ?>
            <?php $isDirectResult = (float)$p['max_points'] === 0.0; ?>
            <th><?= htmlspecialchars($p['parameter_name']) ?><?= $isDirectResult ? '' : ' (' . number_format($p['max_points'], 0) . ')' ?></th>
          <?php endforeach; ?>
          <th>Total (<?= number_format($totalMaxPoints, 0) ?>)</th>
        </tr>
      </thead>
      <tbody>
        <?php $sr = 0; foreach ($pivot as $dealershipName => $scores): $sr++; ?>
        <?php
          // Parameters with Max Points = 0 aren't scored — their
          // points_obtained holds a direct achievement % instead, excluded
          // from the points total.
          $dealerTotal = 0;
          foreach ($parameters as $p) {
              if ((float)$p['max_points'] === 0.0) {
                  continue;
              }
              $dealerTotal += $scores[$p['id']] ?? 0;
          }
        ?>
        <tr>
          <td><?= $sr ?></td>
          <td class="name-cell"><?= htmlspecialchars($dealershipName) ?></td>
          <?php foreach ($parameters as $p): ?>
            <?php
              $isDirectResult = (float)$p['max_points'] === 0.0;
              $obtained = $scores[$p['id']] ?? null;
              $belowTarget = $obtained !== null && $obtained < ($isDirectResult ? 100 : (float)$p['max_points']);
            ?>
            <td class="<?= $belowTarget ? 'below-target' : '' ?>"><?= $obtained !== null ? number_format($obtained, 1) . ($isDirectResult ? '%' : '') : '—' ?></td>
          <?php endforeach; ?>
          <td><strong><?= number_format($dealerTotal, 1) ?></strong></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

</div>
</main>
</div>
</body>
</html>
