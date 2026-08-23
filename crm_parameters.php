<?php
require_once __DIR__ . '/includes/Auth.php';
Auth::requireLogin();
if (!Auth::canView('crm_parameters')) {
    http_response_code(403);
    exit('You Do Not Have Access To This Page.');
}
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/SimpleXlsxReader.php';
require_once __DIR__ . '/includes/SpreadsheetImportHelper.php';
require_once __DIR__ . '/includes/CrmScoreCalculator.php';

$db = Database::getConnection();
$message = '';
$error = '';
$importErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'import_raw') {
        $parameterId = (int)($_POST['crm_parameter_id'] ?? 0);
        $periodMonth = trim($_POST['period_month'] ?? '');

        if (!$parameterId || $periodMonth === '') {
            $error = 'Missing Parameter Or Period.';
        } elseif (empty($_FILES['raw_file']['name']) || $_FILES['raw_file']['error'] !== UPLOAD_ERR_OK) {
            $error = 'A CSV Or Excel File Is Required.';
        } else {
            $ext = strtolower(pathinfo($_FILES['raw_file']['name'], PATHINFO_EXTENSION));
            $allRows = null;

            if ($ext === 'csv') {
                $handle = fopen($_FILES['raw_file']['tmp_name'], 'r');
                if ($handle) {
                    $allRows = [];
                    while (($row = fgetcsv($handle)) !== false) {
                        $allRows[] = $row;
                    }
                    fclose($handle);
                }
            } elseif ($ext === 'xlsx') {
                try {
                    $allRows = (new SimpleXlsxReader())->readFirstSheet($_FILES['raw_file']['tmp_name']);
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
                // Some raw exports (e.g. a CRM lead list) identify the
                // dealership via a "Company" column instead of "Dealer" —
                // checked first/alongside so header-row detection doesn't
                // walk past the real header looking for "dealer" and land on
                // a data row whose own text happens to contain it (e.g. an
                // "Enquiry Source" value of "Dealership Walk in").
                $headerIndex = SpreadsheetImportHelper::findHeaderRowIndex($allRows, ['dealer', 'company']);
                $headerRow = $allRows[$headerIndex] ?? [];
                $dealerCol = SpreadsheetImportHelper::findColumn($headerRow, ['dealer', 'dealership', 'company']);

                if ($dealerCol === null) {
                    $error = 'Could Not Find A "Dealer"/"Dealership"/"Company" Column In The Header Row.';
                } else {
                    // Every other non-blank header, whatever it's called, is
                    // kept as its own raw field — different parameters need
                    // completely different raw columns (e.g. VoIP Calling
                    // needs "Total Calls"/"VoIP Calls", Timely Follow-Up
                    // needs "Total Enquiries"/"On-Time Count"). "Enquiry
                    // Source" (Facebook/Instagram/Walk-in/etc.), "Stage"
                    // (Won/Pre WON/Closed/etc.), "Status" (Completed/Pending/
                    // etc., e.g. FRONX Test Drive), and "Business Days
                    // Difference" (Pipeline Tracking) are handled separately
                    // below — the first three by counting matching rows, the
                    // last by tracking the worst (max) value per dealership
                    // instead of summing it.
                    $rawCols = [];
                    $sourceCol = null;
                    $stageCol = null;
                    $statusCol = null;
                    $businessDaysCol = null;
                    foreach ($headerRow as $col => $label) {
                        $label = trim((string)$label);
                        if ($col === $dealerCol || $label === '') {
                            continue;
                        }
                        if ($sourceCol === null && SpreadsheetImportHelper::matchesAnyKeyword($label, ['source'])) {
                            $sourceCol = $col;
                            continue;
                        }
                        if ($stageCol === null && SpreadsheetImportHelper::matchesAnyKeyword($label, ['stage'])) {
                            $stageCol = $col;
                            continue;
                        }
                        if ($statusCol === null && SpreadsheetImportHelper::matchesAnyKeyword($label, ['status'])) {
                            $statusCol = $col;
                            continue;
                        }
                        if ($businessDaysCol === null && SpreadsheetImportHelper::matchesAnyKeyword($label, ['business days'])) {
                            $businessDaysCol = $col;
                            continue;
                        }
                        $rawCols[$col] = $label;
                    }

                    if (empty($rawCols) && $sourceCol === null && $stageCol === null && $statusCol === null && $businessDaysCol === null) {
                        $error = 'Could Not Find Any Raw-Data Columns Besides "Dealer" — Check Your Column Headers.';
                    } else {
                        $dealershipsByName = [];
                        foreach ($db->query("SELECT id, name FROM dealerships")->fetchAll() as $d) {
                            $dealershipsByName[SpreadsheetImportHelper::normalizeDealershipName($d['name'])] = $d['id'];
                        }

                        // The raw sheet is often one row per ENQUIRY/CALL, not
                        // one row per dealership (e.g. Detailing Of Enquiry —
                        // every enquiry has its own Total Fields Filled/In
                        // View). So every numeric raw column is SUMMED across
                        // all rows belonging to the same dealership, plus a
                        // "Row Count" field (how many source rows fed the sum)
                        // for calculators that need an average/count instead.
                        $sumsByDealership = [];
                        $rowCountByDealership = [];
                        $digitalSourceCountByDealership = [];
                        $wonStageCountByDealership = [];
                        $completedStatusCountByDealership = [];
                        $maxBusinessDaysByDealership = [];

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

                            if (!isset($sumsByDealership[$dealershipId])) {
                                $sumsByDealership[$dealershipId] = array_fill_keys($rawCols, 0.0);
                                $rowCountByDealership[$dealershipId] = 0;
                                $digitalSourceCountByDealership[$dealershipId] = 0;
                                $wonStageCountByDealership[$dealershipId] = 0;
                                $completedStatusCountByDealership[$dealershipId] = 0;
                                $maxBusinessDaysByDealership[$dealershipId] = 0.0;
                            }
                            foreach ($rawCols as $col => $label) {
                                $sumsByDealership[$dealershipId][$label] += (float)($row[$col] ?? 0);
                            }
                            if ($sourceCol !== null) {
                                // Exact match only — "Dealer Facebook"/"Dealer
                                // Instagram" specifically (the dealership's own
                                // page), not any source that merely mentions
                                // Facebook/Instagram (e.g. a company-wide
                                // "PSMC_Facebook" source doesn't count here).
                                $sourceValue = strtolower(trim((string)($row[$sourceCol] ?? '')));
                                if ($sourceValue === 'dealer facebook' || $sourceValue === 'dealer instagram') {
                                    $digitalSourceCountByDealership[$dealershipId]++;
                                }
                            }
                            if ($stageCol !== null) {
                                // Exact match only — "Won", not "Pre WON"
                                // (a different, not-yet-final stage).
                                $stageValue = strtolower(trim((string)($row[$stageCol] ?? '')));
                                if ($stageValue === 'won') {
                                    $wonStageCountByDealership[$dealershipId]++;
                                }
                            }
                            if ($statusCol !== null) {
                                // Substring match ("complete" catches
                                // "Completed"/"Complete"/etc.) rather than an
                                // exact spelling, since the exact wording used
                                // in this Status column isn't fixed/known.
                                $statusValue = strtolower(trim((string)($row[$statusCol] ?? '')));
                                if (str_contains($statusValue, 'complete')) {
                                    $completedStatusCountByDealership[$dealershipId]++;
                                }
                            }
                            if ($businessDaysCol !== null) {
                                $businessDaysValue = (float)($row[$businessDaysCol] ?? 0);
                                $maxBusinessDaysByDealership[$dealershipId] = max($maxBusinessDaysByDealership[$dealershipId], $businessDaysValue);
                            }
                            $rowCountByDealership[$dealershipId]++;
                        }

                        $upsert = $db->prepare("
                            INSERT INTO crm_raw_data (dealership_id, crm_parameter_id, period_month, raw_json)
                            VALUES (:did, :pid, :month, :raw)
                            ON DUPLICATE KEY UPDATE raw_json = :raw2
                        ");
                        $importedCount = 0;

                        foreach ($sumsByDealership as $dealershipId => $sums) {
                            $rawData = $sums;
                            $rawData['Row Count'] = $rowCountByDealership[$dealershipId];
                            if ($sourceCol !== null) {
                                $rawData['Digital Enquiries (Facebook + Instagram Source)'] = $digitalSourceCountByDealership[$dealershipId];
                            }
                            if ($stageCol !== null) {
                                $rawData['Won Enquiries (Stage)'] = $wonStageCountByDealership[$dealershipId];
                            }
                            if ($statusCol !== null) {
                                $rawData['Completed (Status)'] = $completedStatusCountByDealership[$dealershipId];
                            }
                            if ($businessDaysCol !== null) {
                                $rawData['Max Business Days Difference'] = $maxBusinessDaysByDealership[$dealershipId];
                            }
                            $rawJson = json_encode($rawData);
                            $upsert->execute(['did' => $dealershipId, 'pid' => $parameterId, 'month' => $periodMonth, 'raw' => $rawJson, 'raw2' => $rawJson]);
                            $importedCount++;
                        }

                        $message = "{$importedCount} Dealership(s) — Raw Data Imported (Summed From " . array_sum($rowCountByDealership) . " Row(s)) For " . date('F Y', strtotime($periodMonth . '-01')) . ".";
                    }
                }
            }
        }
    } elseif ($action === 'recalculate') {
        $parameterId = (int)($_POST['crm_parameter_id'] ?? 0);
        $periodMonth = trim($_POST['period_month'] ?? '');

        $paramStmt = $db->prepare("SELECT * FROM crm_parameters WHERE id = :id");
        $paramStmt->execute(['id' => $parameterId]);
        $param = $paramStmt->fetch();

        if (!$param || $periodMonth === '') {
            $error = 'Missing Parameter Or Period.';
        } elseif ($param['calc_key'] === null) {
            $error = 'No Calculation Logic Defined Yet For "' . $param['parameter_name'] . '".';
        } else {
            // Joined in for calc_keys that need dealership-level settings
            // rather than (or in addition to) the raw sheet — e.g. Digital
            // Enquiry Targets' per-dealership target, set via Edit Dealership.
            $rawStmt = $db->prepare("
                SELECT rd.dealership_id, rd.raw_json, d.*
                FROM crm_raw_data rd
                JOIN dealerships d ON d.id = rd.dealership_id
                WHERE rd.crm_parameter_id = :pid AND rd.period_month = :month
            ");
            $rawStmt->execute(['pid' => $parameterId, 'month' => $periodMonth]);

            $upsertScore = $db->prepare("
                INSERT INTO crm_scores (dealership_id, crm_parameter_id, period_month, points_obtained)
                VALUES (:did, :pid, :month, :pts)
                ON DUPLICATE KEY UPDATE points_obtained = :pts2
            ");
            $calculatedCount = 0;
            $skippedCount = 0;

            foreach ($rawStmt->fetchAll() as $rd) {
                $raw = json_decode($rd['raw_json'], true) ?? [];
                $points = CrmScoreCalculator::calculate($param['calc_key'], $raw, (float)$param['max_points'], $rd);
                if ($points === null) {
                    $skippedCount++;
                    continue;
                }
                $upsertScore->execute(['did' => $rd['dealership_id'], 'pid' => $parameterId, 'month' => $periodMonth, 'pts' => $points, 'pts2' => $points]);
                $calculatedCount++;
            }

            $message = "{$calculatedCount} Dealership(s) Recalculated For \"{$param['parameter_name']}\"." . ($skippedCount ? " ({$skippedCount} Skipped — No Logic Yet.)" : '');
        }
    } elseif ($action === 'add') {
        $name = trim($_POST['parameter_name'] ?? '');
        $criteria = trim($_POST['criteria'] ?? '');
        $maxPoints = $_POST['max_points'] ?? '';

        if ($name === '' || $maxPoints === '') {
            $error = 'Parameter Name And Max Points Are Required.';
        } else {
            $nextOrder = (int)$db->query("SELECT COALESCE(MAX(display_order), 0) + 1 FROM crm_parameters")->fetchColumn();
            $db->prepare("INSERT INTO crm_parameters (display_order, parameter_name, criteria, max_points) VALUES (:o, :n, :c, :m)")
               ->execute(['o' => $nextOrder, 'n' => $name, 'c' => $criteria, 'm' => (float)$maxPoints]);
            $message = 'Parameter Added.';
        }
    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['parameter_name'] ?? '');
        $criteria = trim($_POST['criteria'] ?? '');
        $maxPoints = $_POST['max_points'] ?? '';
        $order = (int)($_POST['display_order'] ?? 0);

        if ($id && $name !== '' && $maxPoints !== '') {
            $db->prepare("UPDATE crm_parameters SET parameter_name = :n, criteria = :c, max_points = :m, display_order = :o WHERE id = :id")
               ->execute(['n' => $name, 'c' => $criteria, 'm' => (float)$maxPoints, 'o' => $order, 'id' => $id]);
            $message = 'Parameter Updated.';
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $db->prepare("DELETE FROM crm_parameters WHERE id = :id")->execute(['id' => $id]);
            $message = 'Parameter Deleted.';
        }
    }

    // import_raw/recalculate are only ever called via fetch() (see the JS
    // below) — respond with just the message/error instead of re-rendering
    // the whole page, since the caller reloads afterward anyway.
    if (in_array($action, ['import_raw', 'recalculate'], true)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => $error === '', 'message' => $error ?: $message, 'importErrors' => $importErrors]);
        exit;
    }
}

$parameters = $db->query("SELECT * FROM crm_parameters ORDER BY display_order, id")->fetchAll();
$totalMaxPoints = array_sum(array_column($parameters, 'max_points'));

$selectedPeriod = trim($_GET['period'] ?? '') ?: date('Y-m');

$dealershipNames = $db->query("SELECT name FROM dealerships ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$dealershipCount = count($dealershipNames);

$rawCountStmt = $db->prepare("SELECT crm_parameter_id, COUNT(*) AS cnt FROM crm_raw_data WHERE period_month = :month GROUP BY crm_parameter_id");
$rawCountStmt->execute(['month' => $selectedPeriod]);
$rawCountByParam = [];
foreach ($rawCountStmt->fetchAll() as $r) {
    $rawCountByParam[$r['crm_parameter_id']] = (int)$r['cnt'];
}

$scoreCountStmt = $db->prepare("SELECT crm_parameter_id, COUNT(*) AS cnt FROM crm_scores WHERE period_month = :month GROUP BY crm_parameter_id");
$scoreCountStmt->execute(['month' => $selectedPeriod]);
$scoreCountByParam = [];
foreach ($scoreCountStmt->fetchAll() as $r) {
    $scoreCountByParam[$r['crm_parameter_id']] = (int)$r['cnt'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>(function(){var t=localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
<meta charset="UTF-8">
<title>CRM Parameters</title>
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
      <h1>CRM Parameters</h1>
      <div class="subtitle">One Shared Scorecard Template (Parameters, Criteria, Max Points) — Used For Every Dealership's CRM Report. Total Max Points: <?= number_format($totalMaxPoints) ?></div>
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

  <form method="GET" class="search-panel" style="margin-bottom:16px;">
    <div class="field">
      <label>Raw Data / Calculation Month</label>
      <input type="month" name="period" value="<?= htmlspecialchars($selectedPeriod) ?>" onchange="this.form.submit()">
    </div>
  </form>

  <details style="margin-bottom:20px;">
    <summary style="cursor:pointer; font-size:13px; color:var(--muted);">Dealership Names (<?= $dealershipCount ?>) — Use These Exact Names In Your Raw Data Excel's "Dealer Name" Column</summary>
    <div class="detail-card" style="margin-top:10px; column-count:3; column-gap:24px; font-size:12px; line-height:1.9;">
      <?php foreach ($dealershipNames as $name): ?>
        <div><?= htmlspecialchars($name) ?></div>
      <?php endforeach; ?>
    </div>
  </details>

  <div class="table-wrap" style="margin-bottom:24px;">
    <?php if (empty($parameters)): ?>
      <div class="empty-state">No Parameters Yet — Add One Below.</div>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Parameter</th>
          <th>Criteria</th>
          <th class="th-center">Max Points</th>
          <th></th>
          <th>Raw Data (<?= date('M Y', strtotime($selectedPeriod . '-01')) ?>)</th>
          <th>Calculated Scores</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($parameters as $p): ?>
        <tr id="param-row-<?= $p['id'] ?>">
          <form method="POST">
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="id" value="<?= $p['id'] ?>">
          <td><input type="number" name="display_order" value="<?= $p['display_order'] ?>" style="width:50px;"></td>
          <td><input type="text" name="parameter_name" value="<?= htmlspecialchars($p['parameter_name']) ?>" style="width:100%;"></td>
          <td><textarea name="criteria" rows="2" style="width:100%;"><?= htmlspecialchars($p['criteria'] ?? '') ?></textarea></td>
          <td class="th-center"><input type="number" step="0.01" name="max_points" value="<?= $p['max_points'] ?>" style="width:70px; text-align:center;"></td>
          <td class="actions-cell">
            <button type="submit" class="refresh-btn">Save</button>
          </form>
          <button class="delete-row-btn" onclick="deleteParameter(<?= $p['id'] ?>, <?= htmlspecialchars(json_encode($p['parameter_name'])) ?>)">Delete</button>
          </td>
          <td style="text-align:left; white-space:nowrap;">
            <div style="font-size:11px; color:var(--muted); margin-bottom:4px;"><?= $rawCountByParam[$p['id']] ?? 0 ?> / <?= $dealershipCount ?> Uploaded</div>
            <input type="file" id="raw-file-<?= $p['id'] ?>" accept=".csv,.xlsx" style="width:140px; font-size:11px;">
            <button type="button" class="refresh-btn" onclick="uploadRawData(<?= $p['id'] ?>)" style="font-size:11px;">Upload</button>
          </td>
          <td style="text-align:left; white-space:nowrap;">
            <div style="font-size:11px; color:var(--muted); margin-bottom:4px;">
              <?= $scoreCountByParam[$p['id']] ?? 0 ?> / <?= $dealershipCount ?> Calculated
            </div>
            <button type="button" class="refresh-btn" onclick="recalculate(<?= $p['id'] ?>, <?= htmlspecialchars(json_encode($p['parameter_name'])) ?>)" style="font-size:11px;">Recalculate</button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <h2 style="font-size:16px; margin-bottom:14px;">Add Parameter</h2>
  <form method="POST" class="search-panel">
    <input type="hidden" name="action" value="add">
    <div class="field" style="flex:2;">
      <label>Parameter Name</label>
      <input type="text" name="parameter_name" placeholder="e.g. Timely Follow-Up (20 Min Std)" required>
    </div>
    <div class="field" style="flex:3;">
      <label>Criteria (Scoring Bands, Free Text)</label>
      <input type="text" name="criteria" placeholder="e.g. Within time~20 | +20 min~15 | +40~10">
    </div>
    <div class="field">
      <label>Max Points</label>
      <input type="number" step="0.01" name="max_points" placeholder="20" required>
    </div>
    <button type="submit" class="submit">Add Parameter</button>
  </form>

</div>
</main>
</div>

<script>
const CRM_SELECTED_PERIOD = <?= json_encode($selectedPeriod) ?>;

async function deleteParameter(id, name) {
  if (!confirm(`Delete "${name}"? Any imported scores for it will also be removed.`)) return;
  const res = await fetch('crm_parameters.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `action=delete&id=${id}`,
  });
  if (res.ok) {
    document.getElementById(`param-row-${id}`).remove();
  }
}

async function uploadRawData(parameterId) {
  const fileInput = document.getElementById(`raw-file-${parameterId}`);
  if (!fileInput.files.length) {
    alert('Choose A CSV Or Excel File First.');
    return;
  }
  const formData = new FormData();
  formData.append('action', 'import_raw');
  formData.append('crm_parameter_id', parameterId);
  formData.append('period_month', CRM_SELECTED_PERIOD);
  formData.append('raw_file', fileInput.files[0]);

  const res = await fetch('crm_parameters.php', { method: 'POST', body: formData });
  const data = await res.json().catch(() => null);
  alert(data ? data.message : 'Upload Failed.');
  window.location.href = `crm_parameters.php?period=${encodeURIComponent(CRM_SELECTED_PERIOD)}`;
}

async function recalculate(parameterId, name) {
  const formData = new FormData();
  formData.append('action', 'recalculate');
  formData.append('crm_parameter_id', parameterId);
  formData.append('period_month', CRM_SELECTED_PERIOD);

  const res = await fetch('crm_parameters.php', { method: 'POST', body: formData });
  const data = await res.json().catch(() => null);
  alert(data ? data.message : 'Recalculate Failed.');
  window.location.href = `crm_parameters.php?period=${encodeURIComponent(CRM_SELECTED_PERIOD)}`;
}
</script>
</body>
</html>
