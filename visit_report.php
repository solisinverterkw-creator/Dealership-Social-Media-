<?php
require_once __DIR__ . '/includes/Auth.php';
Auth::requireLogin();
if (!Auth::canView('visit_report')) {
    http_response_code(403);
    exit('You Do Not Have Access To This Page.');
}
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/SpreadsheetImportHelper.php';

$db = Database::getConnection();
$isSuperAdmin = Auth::isSuperAdmin();
$scopedIds = Auth::dealershipIds();
$dealershipScopeSql = (!$isSuperAdmin && !empty($scopedIds)) ? " WHERE id IN (" . implode(',', array_map('intval', $scopedIds)) . ")" : '';
$dealerships = (!$isSuperAdmin && empty($scopedIds)) ? [] : $db->query("SELECT id, name FROM dealerships{$dealershipScopeSql} ORDER BY name")->fetchAll();

$dealershipId = (int)($_GET['dealership_id'] ?? 0);
$dealership = null;
$salesRows = [];
$stockRows = [];
$salesPeriodLabel = '';
$salesColumnSequence = [];
$salesSummary = null;
$salesPivot = [];
$stockMasterColumns = [];
$stockPivot = [];
$ageingRows = [];
$crmParameters = [];
$crmScoreByParam = [];
$crmPeriodLabel = '';

if ($dealershipId && Auth::canAccessDealership($dealershipId)) {
    $stmt = $db->prepare("SELECT * FROM dealerships WHERE id = :id");
    $stmt->execute(['id' => $dealershipId]);
    $dealership = $stmt->fetch();

    if ($dealership) {
        $latestPeriod = $db->prepare("SELECT MAX(period_month) FROM sales_records WHERE dealership_id = :id");
        $latestPeriod->execute(['id' => $dealershipId]);
        $period = $latestPeriod->fetchColumn();

        if ($period) {
            $salesStmt = $db->prepare("SELECT * FROM sales_records WHERE dealership_id = :id AND period_month = :period ORDER BY product_name");
            $salesStmt->execute(['id' => $dealershipId, 'period' => $period]);
            $salesRows = $salesStmt->fetchAll();
            $salesPeriodLabel = date('F Y', strtotime($period . '-01'));

            // Same column layout as sales_report.php (product columns in their
            // original order, with "Grand Total" spliced back in at the spot
            // it held in the source sheet) — just this one dealership's row.
            $colStmt = $db->prepare("SELECT DISTINCT product_name, column_order FROM sales_records WHERE period_month = :period ORDER BY column_order");
            $colStmt->execute(['period' => $period]);
            $salesMasterColumns = $colStmt->fetchAll();

            $gtStmt = $db->prepare("SELECT grand_total_column_order FROM sales_summary WHERE period_month = :period AND grand_total_column_order IS NOT NULL LIMIT 1");
            $gtStmt->execute(['period' => $period]);
            $gtColumnOrder = $gtStmt->fetchColumn();

            $gtInserted = false;
            foreach ($salesMasterColumns as $c) {
                if ($gtColumnOrder !== false && !$gtInserted && (int)$c['column_order'] > (int)$gtColumnOrder) {
                    $salesColumnSequence[] = ['type' => 'grand_total'];
                    $gtInserted = true;
                }
                $salesColumnSequence[] = ['type' => 'product', 'name' => $c['product_name']];
            }
            if ($gtColumnOrder !== false && !$gtInserted) {
                $salesColumnSequence[] = ['type' => 'grand_total'];
            }

            $summaryStmt = $db->prepare("SELECT target, grand_total FROM sales_summary WHERE dealership_id = :id AND period_month = :period");
            $summaryStmt->execute(['id' => $dealershipId, 'period' => $period]);
            $salesSummary = $summaryStmt->fetch();

            foreach ($salesRows as $s) {
                $salesPivot[$s['product_name']] = (int)$s['quantity'];
            }
        }

        $stockStmt = $db->prepare("SELECT * FROM stock_records WHERE dealership_id = :id ORDER BY product_name");
        $stockStmt->execute(['id' => $dealershipId]);
        $stockRows = $stockStmt->fetchAll();

        // Same variant-priority column order as stock_report.php — just this
        // one dealership's row.
        $variantPriority = [
            'Alto VXR', 'Alto VXR AGS', 'Alto AGS', 'Alto VXL AGS',
            'FRONX GL AT', 'FRONX GLX',
            'SWIFT MT', 'Swift GL', 'Swift GL CVT', 'SWIFT GLX',
            'CULTUS VXR', 'CULTUS VXL', 'CULTUS AGS',
            'EVERY',
        ];
        $allStockProductNames = $db->query("SELECT DISTINCT product_name FROM stock_records")->fetchAll(PDO::FETCH_COLUMN);
        $stockMasterColumns = SpreadsheetImportHelper::sortProductColumnsByPriority($allStockProductNames, $variantPriority);
        foreach ($stockRows as $s) {
            $stockPivot[$s['product_name']] = (int)$s['quantity'];
        }

        // Same "days aged against this month's last date" + 60-day cutoff +
        // chassis-must-also-be-in-the-current-Stock-import filter as
        // ageing_report.php — just this one dealership's vehicles.
        $monthEnd = new DateTime(date('Y-m-t'));
        $ageingStmt = $db->prepare("
            SELECT ar.* FROM ageing_records ar
            WHERE ar.dealership_id = :id
              AND EXISTS (
                  SELECT 1 FROM stock_chassis_records scr
                  WHERE UPPER(TRIM(scr.chassis_number)) = UPPER(TRIM(ar.chassis_number))
              )
        ");
        $ageingStmt->execute(['id' => $dealershipId]);
        foreach ($ageingStmt->fetchAll() as $ar) {
            $deliveryDt = new DateTime($ar['delivery_date']);
            $ar['days_aged'] = (int)$monthEnd->diff($deliveryDt)->format('%r%a') * -1;
            if ($ar['days_aged'] >= 60) {
                $ageingRows[] = $ar;
            }
        }
        usort($ageingRows, fn($a, $b) => $b['days_aged'] <=> $a['days_aged']);

        // Product-wise count for display — "Alto VXR: 3", not one row per chassis.
        $ageingByProduct = [];
        foreach ($ageingRows as $ar) {
            $label = SpreadsheetImportHelper::shortenProductLabel($ar['product_name'], $variantPriority);
            if (!isset($ageingByProduct[$label])) {
                $ageingByProduct[$label] = ['count' => 0, 'oldest_days' => 0];
            }
            $ageingByProduct[$label]['count']++;
            $ageingByProduct[$label]['oldest_days'] = max($ageingByProduct[$label]['oldest_days'], $ar['days_aged']);
        }
        // Same vehicle column order as the Stock table right above it.
        $ageingProductLabels = SpreadsheetImportHelper::sortProductColumnsByPriority(array_keys($ageingByProduct), $variantPriority);

        // CRM & Dealership Infrastructure scorecard — same "one shared
        // template, monthly points imported via Excel" data as crm_report.php,
        // just this one dealership's latest month.
        $crmParameters = $db->query("SELECT * FROM crm_parameters ORDER BY display_order, id")->fetchAll();
        $latestCrmPeriod = $db->prepare("SELECT MAX(period_month) FROM crm_scores WHERE dealership_id = :id");
        $latestCrmPeriod->execute(['id' => $dealershipId]);
        $crmPeriod = $latestCrmPeriod->fetchColumn();
        if ($crmPeriod) {
            $crmPeriodLabel = date('F Y', strtotime($crmPeriod . '-01'));
            $crmStmt = $db->prepare("SELECT crm_parameter_id, points_obtained FROM crm_scores WHERE dealership_id = :id AND period_month = :period");
            $crmStmt->execute(['id' => $dealershipId, 'period' => $crmPeriod]);
            foreach ($crmStmt->fetchAll() as $cs) {
                $crmScoreByParam[$cs['crm_parameter_id']] = (float)$cs['points_obtained'];
            }
        }

    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>(function(){var t=localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
<meta charset="UTF-8">
<title>Visit Report</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#1a1a19">
<link rel="apple-touch-icon" href="assets/icon-192.png">
<script>if('serviceWorker' in navigator){window.addEventListener('load',()=>navigator.serviceWorker.register('sw.js'));}</script>
<style>
:root {
  --print-font-size: 14px;
  --print-sr-width: 20px;
  --print-cell-padding: 3px 4px;
}
@page { margin: 16mm 12mm; }
@media print {
  .sidebar, .sidebar-toggle, .no-print { display: none !important; }
  /* Flex/grid containers don't fragment across printed pages reliably in
     Chrome — page-break-after/avoid on headings inside one is silently
     ignored. Drop back to plain block flow for print so pagination works. */
  .app-layout { display: block !important; }
  .main-content { display: block !important; margin-left: 0 !important; width: 100% !important; padding: 0 !important; }
  body { background: #fff !important; color: #000 !important; }
  /* Always print the plain formatted text, never the scrollable textarea —
     even if the user forgot to hit Save while still in edit mode. */
  #weak-areas-textarea { display: none !important; }
  #weak-areas-display { display: block !important; }
  /* Chrome doesn't reliably keep a heading with its own separate content
     box together across a page break (the heading gets stranded alone at
     the bottom, or content still jumps to the next page leaving a blank
     gap) — so each heading + its content is wrapped in one atomic
     ".print-atomic" box instead, which prints as a single unbreakable unit. */
  .print-atomic, .detail-card, .wide-report-table { page-break-inside: avoid; break-inside: avoid; }
  h2 { page-break-after: avoid; break-after: avoid; }
  /* Weak Areas text is long and open-ended (AI-generated, can run several
     paragraphs) — forcing it to stay in one unbroken block wastes whatever
     blank space is left on the previous page. Let it flow/split normally so
     it starts filling that space instead of always jumping to a fresh page. */
  .weak-areas-box { page-break-inside: auto !important; break-inside: auto !important; }
  /* Print as plain text, not a bordered/shadowed card. */
  .weak-areas-box { border: none !important; box-shadow: none !important; background: transparent !important; padding: 0 !important; }
  /* CSS Grid inside a break-inside:avoid box confuses Chrome's print
     pagination height calculation — fall back to flex-wrap for print. */
  .detail-grid { display: flex !important; flex-wrap: wrap !important; }
  .detail-grid > div { flex: 1 1 150px; }
  /* Uniform size throughout the printed report, overriding the on-screen
     sizes (16px headings, 18px stat values, 10-11px tables, etc.) — value
     is adjustable live via the Print Settings panel above (--print-font-size). */
  body, h1, h2, .subtitle, .stat-label, .stat-value,
  .wide-report-table, .wide-report-table th, .wide-report-table td,
  #weak-areas-display, #weak-areas-textarea { font-size: var(--print-font-size) !important; }
  /* Sr# is a 1-2 digit number, not a name — it shouldn't inherit the
     220px first-column width meant for the Dealer/name columns. Also
     adjustable live via the Print Settings panel (--print-sr-width). */
  .crm-table th:first-child, .crm-table td:first-child { width: var(--print-sr-width) !important; max-width: var(--print-sr-width) !important; padding-left: 4px !important; padding-right: 4px !important; }
  .wide-report-table th, .wide-report-table td { padding: var(--print-cell-padding) !important; }
  h1 { font-weight: 600; }
  /* Proper alignment: left-align all label/text columns, center-align
     numeric columns, and keep the Weak Areas prose fully justified. */
  .wide-report-table th { text-align: center !important; }
  .wide-report-table td { text-align: center; }
  .wide-report-table th:first-child, .wide-report-table td:first-child,
  .wide-report-table td[style*="text-align:left"] { text-align: left !important; }
  h2 { text-align: left; }
  .detail-grid { text-align: left; }
  #weak-areas-display { text-align: justify !important; }
  /* Never print the dashed "editing" outline, even if formatting mode was
     left switched on when Print Report was clicked. */
  #report-content { outline: none !important; }
}
#report-content[contenteditable="true"] { outline: 2px dashed var(--accent, #4a90d9); outline-offset: 6px; cursor: text; }
.signature-block { display:flex; gap:32px; margin-top:40px; flex-wrap:wrap; }
.signature-line { flex:1; min-width:200px; border-top:1px solid var(--border); padding-top:8px; margin-top:60px; }
.wide-report-table { border-collapse: collapse; width: 100%; font-size: 11px; table-layout: fixed; }
.wide-report-table th, .wide-report-table td { border: 1px solid var(--border); padding: 3px 4px; text-align: center; }
.wide-report-table td { white-space: nowrap; }
.wide-report-table th { white-space: normal; word-break: break-word; line-height: 1.25; font-size: 10px; max-width: 70px; }
.wide-report-table th:first-child, .wide-report-table td:first-child { width: 220px; max-width: 220px; white-space: normal; }
.wide-report-table thead th { background: var(--panel-alt, rgba(128,128,128,0.15)); }
#weak-areas-textarea { width: 100%; min-height: 140px; font-family: inherit; font-size: 13px; line-height: 1.7; padding: 12px; border: 1px solid var(--border); border-radius: 6px; background: var(--panel, transparent); color: inherit; resize: vertical; }
</style>
</head>
<body>
<div class="app-layout">
<?php require __DIR__ . '/includes/Sidebar.php'; ?>
<main class="main-content">
<div class="container">

  <header>
    <div>
      <h1>Visit Report</h1>
    </div>
    <?php if ($dealership): ?>
    <div class="toolbar no-print" style="align-items:center; gap:20px; flex-wrap:wrap;">
      <div style="display:flex; align-items:center; gap:6px;">
        <label for="print-font-size" style="font-size:12px; white-space:nowrap;">Print Font Size</label>
        <input type="range" id="print-font-size" min="10" max="18" step="1" value="14" oninput="updatePrintVar('--print-font-size', this.value+'px'); document.getElementById('print-font-size-val').textContent=this.value+'px';">
        <span id="print-font-size-val" style="font-size:12px; min-width:32px;">14px</span>
      </div>
      <div style="display:flex; align-items:center; gap:6px;">
        <label for="print-sr-width" style="font-size:12px; white-space:nowrap;">Sr# Column Width</label>
        <input type="range" id="print-sr-width" min="16" max="60" step="2" value="20" oninput="updatePrintVar('--print-sr-width', this.value+'px'); document.getElementById('print-sr-width-val').textContent=this.value+'px';">
        <span id="print-sr-width-val" style="font-size:12px; min-width:32px;">20px</span>
      </div>
      <div style="display:flex; align-items:center; gap:6px;">
        <label for="print-cell-padding" style="font-size:12px; white-space:nowrap;">Table Cell Padding</label>
        <input type="range" id="print-cell-padding" min="1" max="10" step="1" value="3" oninput="updatePrintVar('--print-cell-padding', this.value+'px '+this.value+'px'); document.getElementById('print-cell-padding-val').textContent=this.value+'px';">
        <span id="print-cell-padding-val" style="font-size:12px; min-width:32px;">3px</span>
      </div>
      <button class="btn primary" onclick="printReport()">Print Report</button>
    </div>
    <?php endif; ?>
  </header>
  <div class="subtitle no-print" style="margin-top:-20px; margin-bottom:20px;">Print Settings Only Affect The Printed/PDF Output — Adjust Them, Then Click Print Report.</div>

  <form method="GET" class="search-panel no-print" style="margin-bottom:28px;">
    <div class="field" style="flex:2;">
      <label>Dealership</label>
      <select name="dealership_id" onchange="this.form.submit()" required>
        <option value="">— Select A Dealership —</option>
        <?php foreach ($dealerships as $d): ?>
          <option value="<?= $d['id'] ?>" <?= $dealershipId === (int)$d['id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>

  <?php if ($dealershipId && !$dealership): ?>
    <div class="error-msg">Dealership Not Found.</div>
  <?php elseif ($dealership): ?>

  <div class="toolbar no-print" id="format-toolbar-wrap" style="margin-bottom:16px; align-items:center; gap:10px; flex-wrap:wrap; border:1px solid var(--border); border-radius:8px; padding:10px 14px;">
    <button type="button" class="btn" id="format-mode-btn" onclick="toggleFormatMode()">Enable Formatting</button>
    <span id="format-toolbar" style="display:none; gap:8px; align-items:center; flex-wrap:wrap;">
      <button type="button" class="btn" title="Bold" onclick="document.execCommand('bold')" style="font-weight:700;">B</button>
      <button type="button" class="btn" title="Italic" onclick="document.execCommand('italic')" style="font-style:italic;">I</button>
      <button type="button" class="btn" title="Underline" onclick="document.execCommand('underline')" style="text-decoration:underline;">U</button>
      <select title="Font Size" onchange="applyFontSize(this.value); this.selectedIndex=0;">
        <option value="">Font Size</option>
        <option value="10">10px</option>
        <option value="11">11px</option>
        <option value="12">12px</option>
        <option value="14">14px</option>
        <option value="16">16px</option>
        <option value="18">18px</option>
        <option value="20">20px</option>
        <option value="24">24px</option>
      </select>
      <button type="button" class="btn" title="Align Left" onclick="document.execCommand('justifyLeft')">Left</button>
      <button type="button" class="btn" title="Align Center" onclick="document.execCommand('justifyCenter')">Center</button>
      <button type="button" class="btn" title="Align Right" onclick="document.execCommand('justifyRight')">Right</button>
      <button type="button" class="btn" title="Justify" onclick="document.execCommand('justifyFull')">Justify</button>
    </span>
    <span class="subtitle" style="font-size:11px;">Select Text In The Report Below To Format It — Same Formatting Prints In The PDF. Not Saved Between Visits.</span>
  </div>

  <div id="report-content">
  <div class="detail-card" style="margin-bottom:20px;">
    <div style="display:flex; justify-content:flex-end; margin-bottom:12px;">
      <img src="assets/suzuki-logo.png" alt="Suzuki" style="height:40px;">
    </div>
    <h2 style="display:flex; justify-content:space-between; align-items:center;">
      <span><?= htmlspecialchars($dealership['name']) ?></span>
      <span class="subtitle">Report Date: <?= date('d M, Y') ?></span>
    </h2>
  </div>

  <h2 style="font-size:16px; margin-bottom:14px;">Sales — <?= $salesPeriodLabel ?: 'No Data' ?></h2>
  <div class="table-wrap" style="margin-bottom:24px; overflow-x:auto;">
    <?php if (empty($salesRows)): ?>
      <div class="empty-state">No Sales Data Imported For This Dealership Yet.</div>
    <?php else: ?>
    <table class="wide-report-table">
      <thead>
        <tr>
          <th>Dealer</th>
          <th>Target</th>
          <?php foreach ($salesColumnSequence as $c): ?>
            <th><?= $c['type'] === 'grand_total' ? 'Grand Total' : htmlspecialchars(SpreadsheetImportHelper::friendlyProductLabel($c['name'])) ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td style="text-align:left; font-weight:600;"><?= htmlspecialchars($dealership['name']) ?></td>
          <td><?= $salesSummary && $salesSummary['target'] !== null ? number_format($salesSummary['target']) : '—' ?></td>
          <?php foreach ($salesColumnSequence as $c): ?>
            <?php if ($c['type'] === 'grand_total'): ?>
              <td><?= $salesSummary && $salesSummary['grand_total'] !== null ? number_format($salesSummary['grand_total']) : '—' ?></td>
            <?php else: ?>
              <td><?= number_format($salesPivot[$c['name']] ?? 0) ?></td>
            <?php endif; ?>
          <?php endforeach; ?>
        </tr>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <h2 style="font-size:16px; margin-bottom:14px;">Stock</h2>
  <div class="table-wrap" style="margin-bottom:24px; overflow-x:auto;">
    <?php if (empty($stockRows)): ?>
      <div class="empty-state">No Stock Data Imported For This Dealership Yet.</div>
    <?php else: ?>
    <table class="wide-report-table">
      <thead>
        <tr>
          <th>Dealer</th>
          <?php foreach ($stockMasterColumns as $p): ?>
            <th title="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars(SpreadsheetImportHelper::shortenProductLabel($p, $variantPriority)) ?></th>
          <?php endforeach; ?>
          <th>Total</th>
          <th>Available Security Amount</th>
        </tr>
      </thead>
      <tbody>
        <?php $stockTotal = array_sum($stockPivot); ?>
        <tr>
          <td style="text-align:left; font-weight:600;"><?= htmlspecialchars($dealership['name']) ?></td>
          <?php foreach ($stockMasterColumns as $p): ?>
            <td><?= number_format($stockPivot[$p] ?? 0) ?></td>
          <?php endforeach; ?>
          <td style="font-weight:600;"><?= number_format($stockTotal) ?></td>
          <td><?= $dealership['security_amount'] !== null ? number_format($dealership['security_amount'], 2) : '—' ?></td>
        </tr>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <h2 style="font-size:16px; margin-bottom:14px;">Ageing (60+ Days)</h2>
  <div class="table-wrap" style="margin-bottom:24px; overflow-x:auto;">
    <?php if (empty($ageingByProduct)): ?>
      <div class="empty-state">No Vehicles Aged 60+ Days For This Dealership.</div>
    <?php else: ?>
    <table class="wide-report-table">
      <thead>
        <tr>
          <th>Metric</th>
          <?php foreach ($ageingProductLabels as $label): ?>
            <th><?= htmlspecialchars($label) ?></th>
          <?php endforeach; ?>
          <th>Total</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td style="text-align:left; font-weight:600;">Count</td>
          <?php foreach ($ageingProductLabels as $label): ?>
            <td><?= number_format($ageingByProduct[$label]['count']) ?></td>
          <?php endforeach; ?>
          <td style="font-weight:600;"><?= number_format(array_sum(array_column($ageingByProduct, 'count'))) ?></td>
        </tr>
        <tr>
          <td style="text-align:left; font-weight:600;">Oldest (Days)</td>
          <?php foreach ($ageingProductLabels as $label): ?>
            <td><?= number_format($ageingByProduct[$label]['oldest_days']) ?></td>
          <?php endforeach; ?>
          <td></td>
        </tr>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <div class="print-atomic">
  <h2 style="font-size:16px; margin-bottom:14px;">CRM &amp; Dealership Performance<?= $crmPeriodLabel ? ' — ' . htmlspecialchars($crmPeriodLabel) : '' ?></h2>
  <div class="table-wrap" style="margin-bottom:24px; overflow-x:auto;">
    <?php if (empty($crmParameters)): ?>
      <div class="empty-state">No CRM Parameters Defined Yet.</div>
    <?php elseif (empty($crmScoreByParam)): ?>
      <div class="empty-state">No CRM Scores Imported For This Dealership Yet.</div>
    <?php else: ?>
    <?php
      // Parameters with Max Points = 0 aren't scored at all — their
      // points_obtained holds a direct achievement % instead, shown as its
      // own "X%" figure and left out of the points total entirely.
      $crmTotalMax = array_sum(array_column($crmParameters, 'max_points'));
      $crmTotalObtained = 0;
      foreach ($crmParameters as $p) {
          if ((float)$p['max_points'] === 0.0) {
              continue;
          }
          $crmTotalObtained += $crmScoreByParam[$p['id']] ?? 0;
      }
    ?>
    <table class="wide-report-table crm-table">
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
          // Parameters scored against a per-dealership target (set via Edit
          // Dealership, not the monthly raw sheet) show that number in the
          // Criteria column so it's obvious what's being measured against.
          $dealerTargetFieldByCalcKey = [
              'digital_enquiry_targets' => 'digital_enquiry_target',
              'stage_won_conversion' => 'digital_enquiry_conversion_target',
          ];
        ?>
        <?php foreach ($crmParameters as $i => $p): ?>
          <?php
            $isDirectResult = (float)$p['max_points'] === 0.0;
            $obtained = $crmScoreByParam[$p['id']] ?? null;
            $belowTarget = $obtained !== null && $obtained < ($isDirectResult ? 100 : (float)$p['max_points']);

            $criteriaText = $p['criteria'] ?? '';
            $targetField = $dealerTargetFieldByCalcKey[$p['calc_key']] ?? null;
            if ($targetField !== null && ($dealership[$targetField] ?? null) !== null) {
                $criteriaText = trim($criteriaText . ' (Target: ' . number_format($dealership[$targetField], 0) . ')');
            }
          ?>
          <tr<?= $belowTarget ? ' style="color:var(--red); font-weight:600; background:rgba(208,59,59,0.12);"' : '' ?>>
            <td><?= $i + 1 ?></td>
            <td style="text-align:left; white-space:normal;"><?= htmlspecialchars($p['parameter_name']) ?></td>
            <td style="text-align:left; white-space:normal;"><?= htmlspecialchars($criteriaText) ?></td>
            <td><?= $isDirectResult ? '—' : number_format($p['max_points'], 0) ?></td>
            <td><?= $obtained !== null ? number_format($obtained, 1) . ($isDirectResult ? '%' : '') : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        <tr style="font-weight:600;">
          <td></td>
          <td style="text-align:left; white-space:normal;" colspan="2">TOTAL CRM POINTS</td>
          <td><?= number_format($crmTotalMax, 0) ?></td>
          <td><?= number_format($crmTotalObtained, 1) ?></td>
        </tr>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  </div>

  <div class="print-atomic" style="margin-bottom:24px;">
  <h2 style="font-size:16px; margin-bottom:14px; font-weight:700;">Social Media &amp; Reviews</h2>
  <div class="detail-card" style="padding:24px; background:var(--panel); border:1px solid var(--border); border-radius:16px;">
    
    <!-- Row 1: 7 Metrics -->
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap:16px; margin-bottom:20px; padding-bottom:20px; border-bottom:1px solid var(--border);">
      <div>
        <div class="stat-label" style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin-bottom:6px;">FB FOLLOWERS</div>
        <div class="stat-value" style="font-size:18px; font-weight:700; color:var(--fb);">
          <?= number_format($dealership['fb_followers']) ?> <?= targetBadge((int)$dealership['fb_followers'], (int)($dealership['fb_target'] ?? 0)) ?>
        </div>
      </div>
      <div>
        <div class="stat-label" style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin-bottom:6px;">IG FOLLOWERS</div>
        <div class="stat-value" style="font-size:18px; font-weight:700; color:var(--ig);">
          <?= number_format($dealership['ig_followers']) ?> <?= targetBadge((int)$dealership['ig_followers'], (int)($dealership['ig_target'] ?? 0)) ?>
        </div>
      </div>
      <div>
        <div class="stat-label" style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin-bottom:6px;">YT SUBSCRIBERS</div>
        <div class="stat-value" style="font-size:18px; font-weight:700; color:var(--yt);">
          <?= number_format($dealership['yt_subscribers']) ?> <?= targetBadge((int)$dealership['yt_subscribers'], (int)($dealership['yt_target'] ?? 0)) ?>
        </div>
      </div>
      <div>
        <div class="stat-label" style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin-bottom:6px;">YT VIDEOS</div>
        <div class="stat-value" style="font-size:18px; font-weight:700;">
          <?= number_format($dealership['yt_videos']) ?>
        </div>
      </div>
      <div>
        <div class="stat-label" style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin-bottom:6px;">YT TOTAL VIEWS</div>
        <div class="stat-value" style="font-size:18px; font-weight:700;">
          <?= number_format($dealership['yt_views']) ?>
        </div>
      </div>
      <div>
        <div class="stat-label" style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin-bottom:6px;">GOOGLE REVIEWS</div>
        <div class="stat-value" style="font-size:18px; font-weight:700; color:var(--gr);">
          <?= number_format($dealership['google_review_count']) ?> <?= targetBadge((int)$dealership['google_review_count'], (int)($dealership['google_review_target'] ?? 0)) ?>
        </div>
      </div>
      <div>
        <div class="stat-label" style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin-bottom:6px;">GOOGLE RATING</div>
        <div class="stat-value" style="font-size:18px; font-weight:700;">
          <?= $dealership['google_rating'] ?>★
        </div>
      </div>
    </div>

    <!-- Row 2: 4 Metrics -->
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap:16px;">
      <div>
        <div class="stat-label" style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin-bottom:6px;">FB POSTS (LAST CHECK)</div>
        <div class="stat-value" style="font-size:15px; font-weight:700;"><?= number_format($dealership['fb_posts_week']) ?>/week</div>
      </div>
      <div>
        <div class="stat-label" style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin-bottom:6px;">IG POSTS (LAST CHECK)</div>
        <div class="stat-value" style="font-size:15px; font-weight:700;"><?= number_format($dealership['ig_posts_week']) ?>/week</div>
      </div>
      <div>
        <div class="stat-label" style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin-bottom:6px;">YT VIDEOS (LAST CHECK)</div>
        <div class="stat-value" style="font-size:15px; font-weight:700;"><?= number_format($dealership['yt_videos_month']) ?>/month</div>
      </div>
      <div>
        <div class="stat-label" style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin-bottom:6px;">LAST REFRESHED</div>
        <div class="stat-value" style="font-size:14px; font-weight:600;">
          <?= $dealership['last_refreshed'] ? date('d M, H:i', strtotime($dealership['last_refreshed'])) : 'never' ?>
        </div>
      </div>
    </div>

  </div>
  </div>

  <h2 class="weak-areas-heading" style="font-size:16px; margin-bottom:14px; display:flex; justify-content:space-between; align-items:center;">
    <span>Weak Areas</span>
    <button type="button" class="btn no-print" id="weak-areas-edit-btn" onclick="toggleWeakAreasEdit()" style="font-size:12px; display:none;">Edit</button>
  </h2>
  <div class="detail-card weak-areas-box" style="margin-bottom:24px;">
    <div id="weak-areas-error" class="error-msg" style="margin-bottom:12px; display:none;"></div>
    <div id="weak-areas-loading" class="subtitle no-print">Generating... <span id="weak-areas-timer">0s</span></div>
    <div id="weak-areas-display" style="font-size:14px; line-height:1.85; text-align:justify; letter-spacing:0.1px; white-space:pre-wrap;"></div>
    <textarea id="weak-areas-textarea" name="weak_areas_text" style="display:none;"></textarea>
  </div>

  <div class="signature-block">
    <div class="signature-line">DSM Signature</div>
    <div class="signature-line">Manager Signature</div>
    <div class="signature-line">Dealer Owner Signature</div>
  </div>

  </div>

  <?php endif; ?>

</div>
</main>
</div>

<script>
<?php if ($dealership): ?>
(function() {
  const loadingEl = document.getElementById('weak-areas-loading');
  const timerEl = document.getElementById('weak-areas-timer');
  const errorEl = document.getElementById('weak-areas-error');
  const displayEl = document.getElementById('weak-areas-display');
  const editBtn = document.getElementById('weak-areas-edit-btn');

  const startedAt = Date.now();
  const timerInterval = setInterval(() => {
    timerEl.textContent = Math.round((Date.now() - startedAt) / 1000) + 's';
  }, 500);

  fetch('get_weak_areas.php?dealership_id=<?= (int)$dealershipId ?>')
    .then(res => res.json())
    .then(data => {
      clearInterval(timerInterval);
      loadingEl.style.display = 'none';
      if (data.success) {
        displayEl.textContent = data.text;
        document.getElementById('weak-areas-textarea').value = data.text;
        editBtn.style.display = 'inline-block';
      } else {
        errorEl.textContent = 'Could Not Generate Weak-Areas Analysis: ' + data.message;
        errorEl.style.display = 'block';
      }
    })
    .catch(err => {
      clearInterval(timerInterval);
      loadingEl.style.display = 'none';
      errorEl.textContent = 'Could Not Reach The Server To Generate Weak-Areas Analysis.';
      errorEl.style.display = 'block';
    });
})();
<?php endif; ?>

function toggleWeakAreasEdit() {
  const display = document.getElementById('weak-areas-display');
  const textarea = document.getElementById('weak-areas-textarea');
  const btn = document.getElementById('weak-areas-edit-btn');
  if (!display || !textarea || !btn) return;

  const isEditing = textarea.style.display !== 'none';
  if (!isEditing) {
    textarea.value = display.textContent;
    display.style.display = 'none';
    textarea.style.display = 'block';
    btn.textContent = 'Save';
    textarea.focus();
  } else {
    display.textContent = textarea.value;
    textarea.style.display = 'none';
    display.style.display = 'block';
    btn.textContent = 'Edit';
  }
}

function updatePrintVar(name, value) {
  document.documentElement.style.setProperty(name, value);
}

function toggleFormatMode() {
  const el = document.getElementById('report-content');
  const btn = document.getElementById('format-mode-btn');
  const toolbar = document.getElementById('format-toolbar');
  const enabling = el.getAttribute('contenteditable') !== 'true';
  el.setAttribute('contenteditable', enabling ? 'true' : 'false');
  toolbar.style.display = enabling ? 'inline-flex' : 'none';
  btn.textContent = enabling ? 'Disable Formatting' : 'Enable Formatting';
}

// execCommand('fontSize') only accepts the old 1-7 HTML scale, so it's used
// as a marker (size="7") then swapped for a real pixel value via inline style.
function applyFontSize(px) {
  if (!px) return;
  document.execCommand('fontSize', false, '7');
  document.querySelectorAll('#report-content font[size="7"]').forEach(function (el) {
    el.removeAttribute('size');
    el.style.fontSize = px + 'px';
  });
}

function printReport() {
  // If still in edit mode, save first so the printed page shows the latest
  // text instead of the scrollable textarea.
  const textarea = document.getElementById('weak-areas-textarea');
  if (textarea && textarea.style.display !== 'none') {
    toggleWeakAreasEdit();
  }
  window.print();
}
</script>
</body>
</html>
