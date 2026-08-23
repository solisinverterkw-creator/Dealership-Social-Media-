<?php
require_once __DIR__ . '/includes/Auth.php';
Auth::requireLogin();
if (!Auth::canView('crm_data_quality')) {
    http_response_code(403);
    exit('You Do Not Have Access To This Page.');
}
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/SimpleXlsxReader.php';
require_once __DIR__ . '/includes/SpreadsheetImportHelper.php';
require_once __DIR__ . '/includes/DataQualityAnalyzer.php';

$db = Database::getConnection();
$error = '';
$report = null; // built only on a successful upload — nothing is saved to the DB, this is a one-off check

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check') {
    if (empty($_FILES['data_file']['name']) || $_FILES['data_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'A CSV Or Excel File Is Required.';
    } else {
        $ext = strtolower(pathinfo($_FILES['data_file']['name'], PATHINFO_EXTENSION));
        $allRows = null;

        if ($ext === 'csv') {
            $handle = fopen($_FILES['data_file']['tmp_name'], 'r');
            if ($handle) {
                $allRows = [];
                while (($row = fgetcsv($handle)) !== false) {
                    $allRows[] = $row;
                }
                fclose($handle);
            }
        } elseif ($ext === 'xlsx') {
            try {
                $allRows = (new SimpleXlsxReader())->readFirstSheet($_FILES['data_file']['tmp_name']);
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
            // Score each of the first few rows by how many distinct
            // column-keyword groups it matches, instead of trusting the
            // first row with any single "dealer"/"company" hit — a real
            // header row matches several groups at once (Dealership, Email,
            // Phone, Contact Name...), while a stray DATA row can coincidentally
            // trip just one (e.g. an Enquiry Source value like "Dealership
            // Walk-in" contains the whole word "dealership"). Highest score
            // wins, so the real header always beats a single-keyword false
            // positive regardless of which one comes first in the file.
            $headerKeywordGroups = [
                ['dealer', 'dealership', 'company'],
                ['email'],
                ['phone', 'contact number', 'mobile', 'cell number', 'whatsapp'],
                ['contact name', 'customer name'],
                ['purchase type'],
                ['inquiry no', 'inquiry number', 'enquiry no', 'enquiry number'],
            ];
            $headerIndex = 0;
            $bestHeaderScore = -1;
            $headerScanLimit = min(count($allRows), 50);
            for ($r = 0; $r < $headerScanLimit; $r++) {
                $score = 0;
                foreach ($headerKeywordGroups as $keywords) {
                    if (SpreadsheetImportHelper::findColumn($allRows[$r], $keywords) !== null) {
                        $score++;
                    }
                }
                if ($score > $bestHeaderScore) {
                    $bestHeaderScore = $score;
                    $headerIndex = $r;
                }
            }
            $headerRow = $allRows[$headerIndex] ?? [];
            $dealerCol = SpreadsheetImportHelper::findColumn($headerRow, ['dealer', 'dealership', 'company']);
            $emailCol = SpreadsheetImportHelper::findColumn($headerRow, ['email']);
            $phoneCol = SpreadsheetImportHelper::findColumn($headerRow, ['phone', 'contact number', 'mobile', 'cell number', 'whatsapp']);
            $nameCol = SpreadsheetImportHelper::findColumn($headerRow, ['contact name', 'customer name']);
            $purchaseTypeCol = SpreadsheetImportHelper::findColumn($headerRow, ['purchase type']);
            $inquiryNoCol = SpreadsheetImportHelper::findColumn($headerRow, ['inquiry no', 'inquiry number', 'enquiry no', 'enquiry number']);

            if ($dealerCol === null) {
                $error = 'Could Not Find A "Dealer"/"Dealership"/"Company" Column In The Header Row.';
            } else {
                // Auto-detect numeric columns (besides Dealer/Email/Phone/Name)
                // for Benford's Law — any column whose non-blank values are
                // almost entirely numeric, sampled across the data rows.
                $excludedCols = array_filter([$dealerCol, $emailCol, $phoneCol, $nameCol, $purchaseTypeCol, $inquiryNoCol], fn($c) => $c !== null);
                $numericColCandidates = [];
                foreach ($headerRow as $col => $label) {
                    $label = trim((string)$label);
                    if ($label === '' || in_array($col, $excludedCols, true)) {
                        continue;
                    }
                    $numericColCandidates[$col] = $label;
                }

                $dealershipsByName = [];
                foreach ($db->query("SELECT id, name FROM dealerships")->fetchAll() as $d) {
                    $dealershipsByName[SpreadsheetImportHelper::normalizeDealershipName($d['name'])] = $d['name'];
                }

                $perDealer = [];
                $emailOccurrences = [];  // email => [contact names]
                $phoneOccurrences = [];  // normalized phone => [contact names]
                $numericValuesByCol = array_fill_keys(array_keys($numericColCandidates), []);
                $numericSampleCount = array_fill_keys(array_keys($numericColCandidates), 0);
                $numericNumericCount = array_fill_keys(array_keys($numericColCandidates), 0);
                $issueRows = []; // flat list of rows with any flagged issue, for the CSV export

                $totalRows = 0;
                $totalEmailMissing = 0;
                $totalPhoneMissing = 0;
                $totalNoContact = 0; // missing BOTH email and phone
                $totalPurchaseTypeMissing = 0;

                for ($i = $headerIndex + 1; $i < count($allRows); $i++) {
                    $row = $allRows[$i];
                    if (count(array_filter($row, fn($c) => trim((string)$c) !== '')) === 0) {
                        continue; // blank line
                    }

                    $dealerRaw = trim($row[$dealerCol] ?? '');
                    if ($dealerRaw === '') {
                        continue;
                    }
                    $dealerName = $dealershipsByName[SpreadsheetImportHelper::normalizeDealershipName($dealerRaw)] ?? ($dealerRaw . ' (Unmatched Dealer Name)');

                    if (!isset($perDealer[$dealerName])) {
                        $perDealer[$dealerName] = [
                            'total' => 0, 'email_present' => 0, 'email_valid' => 0,
                            'phone_present' => 0, 'phone_valid' => 0, 'name_present' => 0,
                            'dup_email' => 0, 'dup_phone' => 0,
                            'email_missing' => 0, 'phone_missing' => 0, 'no_contact' => 0,
                            'purchase_type_present' => 0, 'purchase_type_missing' => 0,
                        ];
                    }
                    $perDealer[$dealerName]['total']++;
                    $totalRows++;

                    $contactName = $nameCol !== null ? trim((string)($row[$nameCol] ?? '')) : '';
                    if ($contactName !== '') {
                        $perDealer[$dealerName]['name_present']++;
                    }
                    $inquiryNo = $inquiryNoCol !== null ? trim((string)($row[$inquiryNoCol] ?? '')) : '';
                    $rowIssues = [];

                    $emailRaw = '';
                    $hasEmail = false;
                    if ($emailCol !== null) {
                        $emailRaw = trim((string)($row[$emailCol] ?? ''));
                        if ($emailRaw !== '') {
                            $hasEmail = true;
                            $perDealer[$dealerName]['email_present']++;
                            $emailKey = strtolower($emailRaw);
                            $emailOccurrences[$emailKey][] = $contactName ?: '(no name)';
                            if (DataQualityAnalyzer::isValidEmail($emailRaw)) {
                                $perDealer[$dealerName]['email_valid']++;
                            }
                        } else {
                            $perDealer[$dealerName]['email_missing']++;
                            $totalEmailMissing++;
                            $rowIssues[] = 'Missing Email';
                        }
                    }

                    $phoneRaw = '';
                    $hasPhone = false;
                    if ($phoneCol !== null) {
                        $phoneRaw = trim((string)($row[$phoneCol] ?? ''));
                        if ($phoneRaw !== '') {
                            $hasPhone = true;
                            $perDealer[$dealerName]['phone_present']++;
                            $phoneKey = preg_replace('/\D/', '', $phoneRaw);
                            if ($phoneKey !== '') {
                                $phoneOccurrences[$phoneKey][] = $contactName ?: '(no name)';
                            }
                            if (DataQualityAnalyzer::isValidPakPhone($phoneRaw)) {
                                $perDealer[$dealerName]['phone_valid']++;
                            }
                        } else {
                            $perDealer[$dealerName]['phone_missing']++;
                            $totalPhoneMissing++;
                            $rowIssues[] = 'Missing Phone';
                        }
                    }

                    if ($emailCol !== null && $phoneCol !== null && !$hasEmail && !$hasPhone) {
                        $perDealer[$dealerName]['no_contact']++;
                        $totalNoContact++;
                    }

                    if ($purchaseTypeCol !== null) {
                        $purchaseTypeRaw = trim((string)($row[$purchaseTypeCol] ?? ''));
                        if ($purchaseTypeRaw !== '') {
                            $perDealer[$dealerName]['purchase_type_present']++;
                        } else {
                            $perDealer[$dealerName]['purchase_type_missing']++;
                            $totalPurchaseTypeMissing++;
                            $rowIssues[] = 'Missing Purchase Type';
                        }
                    }

                    if (!empty($rowIssues)) {
                        $issueRows[] = [
                            'dealer' => $dealerName,
                            'contact_name' => $contactName,
                            'email' => $emailRaw,
                            'phone' => $phoneRaw,
                            'inquiry_no' => $inquiryNo,
                            'issues' => implode('; ', $rowIssues),
                        ];
                    }

                    foreach ($numericColCandidates as $col => $label) {
                        $val = trim((string)($row[$col] ?? ''));
                        if ($val === '') {
                            continue;
                        }
                        $numericSampleCount[$col]++;
                        if (is_numeric($val)) {
                            $numericNumericCount[$col]++;
                            $numericValuesByCol[$col][] = (float)$val;
                        }
                    }
                }

                // Duplicate detection: same email/phone appearing more than
                // once — a real customer submitting a follow-up enquiry looks
                // the same as a fabricated duplicate from raw data alone, so
                // this is a "worth reviewing" flag, not proof of fraud.
                $duplicateEmails = [];
                foreach ($emailOccurrences as $email => $names) {
                    if (count($names) > 1) {
                        $duplicateEmails[$email] = $names;
                    }
                }
                $duplicatePhones = [];
                foreach ($phoneOccurrences as $phone => $names) {
                    if (count($names) > 1) {
                        $duplicatePhones[$phone] = $names;
                    }
                }

                // Attribute duplicate-driven rows back to each dealership —
                // approximate by dealer of the row's first occurrence isn't
                // tracked here, so instead flag any dealer whose rows include
                // a duplicated email/phone at all (re-scan is cheap).
                if (!empty($duplicateEmails) || !empty($duplicatePhones)) {
                    for ($i = $headerIndex + 1; $i < count($allRows); $i++) {
                        $row = $allRows[$i];
                        $dealerRaw = trim($row[$dealerCol] ?? '');
                        if ($dealerRaw === '') {
                            continue;
                        }
                        $dealerName = $dealershipsByName[SpreadsheetImportHelper::normalizeDealershipName($dealerRaw)] ?? ($dealerRaw . ' (Unmatched Dealer Name)');
                        if (!isset($perDealer[$dealerName])) {
                            continue;
                        }
                        $dupIssues = [];
                        if ($emailCol !== null) {
                            $emailKey = strtolower(trim((string)($row[$emailCol] ?? '')));
                            if ($emailKey !== '' && isset($duplicateEmails[$emailKey])) {
                                $perDealer[$dealerName]['dup_email']++;
                                $dupIssues[] = 'Duplicate Email';
                            }
                        }
                        if ($phoneCol !== null) {
                            $phoneKey = preg_replace('/\D/', '', trim((string)($row[$phoneCol] ?? '')));
                            if ($phoneKey !== '' && isset($duplicatePhones[$phoneKey])) {
                                $perDealer[$dealerName]['dup_phone']++;
                                $dupIssues[] = 'Duplicate Phone';
                            }
                        }
                        if (!empty($dupIssues)) {
                            $issueRows[] = [
                                'dealer' => $dealerName,
                                'contact_name' => $nameCol !== null ? trim((string)($row[$nameCol] ?? '')) : '',
                                'email' => $emailCol !== null ? trim((string)($row[$emailCol] ?? '')) : '',
                                'phone' => $phoneCol !== null ? trim((string)($row[$phoneCol] ?? '')) : '',
                                'inquiry_no' => $inquiryNoCol !== null ? trim((string)($row[$inquiryNoCol] ?? '')) : '',
                                'issues' => implode('; ', $dupIssues),
                            ];
                        }
                    }
                }

                // Outlier detection on total enquiry volume per dealership —
                // a dealership reporting a suspiciously high/low count
                // relative to the group.
                $totalsByDealer = array_map(fn($d) => (float)$d['total'], $perDealer);
                $volumeOutliers = DataQualityAnalyzer::zScoreOutliers($totalsByDealer);

                // Composite Data Quality Score per dealership — simple
                // average of completeness/validity/uniqueness percentages.
                $requiredFieldCount = ($emailCol !== null ? 1 : 0) + ($phoneCol !== null ? 1 : 0) + ($nameCol !== null ? 1 : 0);
                foreach ($perDealer as $dealerName => &$stats) {
                    $completeness = $requiredFieldCount > 0
                        ? (($stats['email_present'] + $stats['phone_present'] + $stats['name_present']) / ($requiredFieldCount * $stats['total'])) * 100
                        : 100;
                    $emailValidity = $stats['email_present'] > 0 ? ($stats['email_valid'] / $stats['email_present']) * 100 : null;
                    $phoneValidity = $stats['phone_present'] > 0 ? ($stats['phone_valid'] / $stats['phone_present']) * 100 : null;
                    $uniqueness = 100 - ((($stats['dup_email'] + $stats['dup_phone']) / max(1, $stats['total'] * 2)) * 100);

                    $components = array_filter([$completeness, $emailValidity, $phoneValidity, $uniqueness], fn($v) => $v !== null);
                    $stats['completeness'] = round($completeness, 1);
                    $stats['email_validity'] = $emailValidity !== null ? round($emailValidity, 1) : null;
                    $stats['phone_validity'] = $phoneValidity !== null ? round($phoneValidity, 1) : null;
                    $stats['uniqueness'] = round($uniqueness, 1);
                    $stats['quality_score'] = round(array_sum($components) / count($components), 1);
                }
                unset($stats);
                ksort($perDealer);

                // Benford's Law on each detected numeric column — only where
                // the column is reliably numeric (>=80% of sampled values)
                // and there's enough data (checked inside benfordLawCheck()).
                $benfordResults = [];
                foreach ($numericColCandidates as $col => $label) {
                    if ($numericSampleCount[$col] === 0 || ($numericNumericCount[$col] / $numericSampleCount[$col]) < 0.8) {
                        continue;
                    }
                    $result = DataQualityAnalyzer::benfordLawCheck($numericValuesByCol[$col]);
                    if ($result !== null) {
                        $benfordResults[$label] = $result;
                    }
                }

                $report = [
                    'total_rows' => $totalRows,
                    'per_dealer' => $perDealer,
                    'duplicate_emails' => $duplicateEmails,
                    'duplicate_phones' => $duplicatePhones,
                    'volume_outliers' => $volumeOutliers,
                    'benford' => $benfordResults,
                    'total_email_missing' => $totalEmailMissing,
                    'total_phone_missing' => $totalPhoneMissing,
                    'total_no_contact' => $totalNoContact,
                    'total_purchase_type_missing' => $totalPurchaseTypeMissing,
                    'issue_rows' => $issueRows,
                    'columns_detected' => [
                        'dealer' => $headerRow[$dealerCol] ?? '',
                        'email' => $emailCol !== null ? $headerRow[$emailCol] : null,
                        'phone' => $phoneCol !== null ? $headerRow[$phoneCol] : null,
                        'name' => $nameCol !== null ? $headerRow[$nameCol] : null,
                        'purchase_type' => $purchaseTypeCol !== null ? $headerRow[$purchaseTypeCol] : null,
                        'inquiry_no' => $inquiryNoCol !== null ? $headerRow[$inquiryNoCol] : null,
                    ],
                ];
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
<title>CRM Data Quality Checker</title>
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
.flag-bad { color: var(--red); }
.flag-warn { color: var(--amber, #fab219); }
.wide-report-table td.flag-bad { font-weight: 600; background: rgba(208,59,59,0.12); }
.wide-report-table td.flag-warn { font-weight: 600; background: rgba(250,178,25,0.12); }
</style>
</head>
<body>
<div class="app-layout">
<?php require __DIR__ . '/includes/Sidebar.php'; ?>
<main class="main-content">
<div class="container">

  <header>
    <div>
      <h1>CRM Data Quality Checker</h1>
      <div class="subtitle">Upload Any Raw CRM Enquiry Export To Check Completeness, Validity, Duplicates, And Statistical Anomalies — Nothing Is Saved, This Is A One-Off Check.</div>
    </div>
  </header>

  <?php if ($error): ?><div class="error-msg"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <form method="POST" enctype="multipart/form-data" class="search-panel" style="margin-bottom:24px;">
    <input type="hidden" name="action" value="check">
    <div class="field" style="flex:2;">
      <label>Raw CRM Enquiry CSV Or Excel (Any Columns — Dealer/Company, Email, Phone, Contact Name Auto-Detected)</label>
      <input type="file" name="data_file" accept=".csv,.xlsx" required>
    </div>
    <button type="submit" class="submit">Check Data Quality</button>
  </form>

  <?php if ($report): ?>

  <div class="detail-card" style="margin-bottom:20px;">
    <div class="detail-grid">
      <div><div class="stat-label">Total Rows</div><div class="stat-value"><?= number_format($report['total_rows']) ?></div></div>
      <div><div class="stat-label">Dealer Column</div><div class="stat-value" style="font-size:14px;"><?= htmlspecialchars($report['columns_detected']['dealer']) ?></div></div>
      <div><div class="stat-label">Email Column</div><div class="stat-value" style="font-size:14px;"><?= $report['columns_detected']['email'] ? htmlspecialchars($report['columns_detected']['email']) : 'Not Found' ?></div></div>
      <div><div class="stat-label">Phone Column</div><div class="stat-value" style="font-size:14px;"><?= $report['columns_detected']['phone'] ? htmlspecialchars($report['columns_detected']['phone']) : 'Not Found' ?></div></div>
      <div><div class="stat-label">Contact Name Column</div><div class="stat-value" style="font-size:14px;"><?= $report['columns_detected']['name'] ? htmlspecialchars($report['columns_detected']['name']) : 'Not Found' ?></div></div>
      <div><div class="stat-label">Purchase Type Column</div><div class="stat-value" style="font-size:14px;"><?= $report['columns_detected']['purchase_type'] ? htmlspecialchars($report['columns_detected']['purchase_type']) : 'Not Found' ?></div></div>
    </div>
  </div>

  <div class="detail-card" style="margin-bottom:20px;">
    <div class="detail-grid">
      <div><div class="stat-label">Missing Email</div><div class="stat-value <?= $report['total_email_missing'] > 0 ? 'flag-bad' : '' ?>"><?= number_format($report['total_email_missing']) ?></div></div>
      <div><div class="stat-label">Missing Phone</div><div class="stat-value <?= $report['total_phone_missing'] > 0 ? 'flag-bad' : '' ?>"><?= number_format($report['total_phone_missing']) ?></div></div>
      <div><div class="stat-label">Missing Both Email &amp; Phone</div><div class="stat-value <?= $report['total_no_contact'] > 0 ? 'flag-bad' : '' ?>"><?= number_format($report['total_no_contact']) ?></div></div>
      <div><div class="stat-label">Missing Purchase Type</div><div class="stat-value <?= $report['total_purchase_type_missing'] > 0 ? 'flag-bad' : '' ?>"><?= number_format($report['total_purchase_type_missing']) ?></div></div>
      <div><div class="stat-label">Duplicate Emails</div><div class="stat-value <?= count($report['duplicate_emails']) > 0 ? 'flag-warn' : '' ?>"><?= number_format(count($report['duplicate_emails'])) ?></div></div>
      <div><div class="stat-label">Duplicate Phones</div><div class="stat-value <?= count($report['duplicate_phones']) > 0 ? 'flag-warn' : '' ?>"><?= number_format(count($report['duplicate_phones'])) ?></div></div>
    </div>
  </div>

  <div style="margin-bottom:20px; display:flex; gap:12px;">
    <button type="button" id="export-issues-btn" class="submit" style="width:auto; padding:10px 20px;">Export Flagged Rows (CSV)<?= empty($report['issue_rows']) ? ' — None Found' : '' ?></button>
    <button type="button" id="export-summary-btn" class="submit" style="width:auto; padding:10px 20px;">Export Per-Dealer Summary (CSV)</button>
  </div>

  <h2 style="font-size:16px; margin-bottom:14px;">Per-Dealership Data Quality</h2>
  <div class="table-wrap" style="margin-bottom:24px; overflow-x:auto;">
    <table class="wide-report-table">
      <thead>
        <tr>
          <th>Dealer</th>
          <th>Total Rows</th>
          <th>Completeness %</th>
          <th>Email Valid %</th>
          <th>Phone Valid %</th>
          <th>Missing Email</th>
          <th>Missing Phone</th>
          <th>Missing Purchase Type</th>
          <th>Uniqueness %</th>
          <th>Volume Outlier (Z)</th>
          <th>Data Quality Score</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($report['per_dealer'] as $dealerName => $s): ?>
        <tr>
          <td class="name-cell"><?= htmlspecialchars($dealerName) ?></td>
          <td><?= number_format($s['total']) ?></td>
          <td class="<?= $s['completeness'] < 90 ? 'flag-warn' : '' ?>"><?= $s['completeness'] ?>%</td>
          <td class="<?= $s['email_validity'] !== null && $s['email_validity'] < 90 ? 'flag-bad' : '' ?>"><?= $s['email_validity'] !== null ? $s['email_validity'] . '%' : '—' ?></td>
          <td class="<?= $s['phone_validity'] !== null && $s['phone_validity'] < 90 ? 'flag-bad' : '' ?>"><?= $s['phone_validity'] !== null ? $s['phone_validity'] . '%' : '—' ?></td>
          <td class="<?= $s['email_missing'] > 0 ? 'flag-bad' : '' ?>"><?= number_format($s['email_missing']) ?></td>
          <td class="<?= $s['phone_missing'] > 0 ? 'flag-bad' : '' ?>"><?= number_format($s['phone_missing']) ?></td>
          <td class="<?= $s['purchase_type_missing'] > 0 ? 'flag-bad' : '' ?>"><?= number_format($s['purchase_type_missing']) ?></td>
          <td class="<?= $s['uniqueness'] < 90 ? 'flag-warn' : '' ?>"><?= $s['uniqueness'] ?>%</td>
          <td class="<?= isset($report['volume_outliers'][$dealerName]) ? 'flag-warn' : '' ?>"><?= isset($report['volume_outliers'][$dealerName]) ? $report['volume_outliers'][$dealerName] : '—' ?></td>
          <td class="<?= $s['quality_score'] < 70 ? 'flag-bad' : ($s['quality_score'] < 90 ? 'flag-warn' : '') ?>"><strong><?= $s['quality_score'] ?>%</strong></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if (!empty($report['benford'])): ?>
  <h2 style="font-size:16px; margin-bottom:14px;">Benford's Law Check (Numeric Columns)</h2>
  <div class="table-wrap" style="margin-bottom:24px; overflow-x:auto;">
    <table class="wide-report-table">
      <thead>
        <tr><th>Column</th><th>Sample Size</th><th>Chi-Square</th><th>Result</th></tr>
      </thead>
      <tbody>
        <?php foreach ($report['benford'] as $col => $r): ?>
        <tr>
          <td class="name-cell"><?= htmlspecialchars($col) ?></td>
          <td><?= number_format($r['sample_size']) ?></td>
          <td><?= $r['chi_square'] ?></td>
          <td class="<?= $r['deviates'] ? 'flag-bad' : '' ?>"><?= $r['deviates'] ? 'Deviates From Benford\'s Law — Review' : 'Consistent With Natural Data' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php if (!empty($report['duplicate_emails'])): ?>
  <h2 style="font-size:16px; margin-bottom:14px;">Duplicate Emails (<?= count($report['duplicate_emails']) ?>)</h2>
  <div class="table-wrap" style="margin-bottom:24px; overflow-x:auto;">
    <table class="wide-report-table">
      <thead><tr><th>Email</th><th>Times Used</th><th style="text-align:left;">Contact Names</th></tr></thead>
      <tbody>
        <?php foreach (array_slice($report['duplicate_emails'], 0, 30, true) as $email => $names): ?>
        <tr>
          <td class="name-cell"><?= htmlspecialchars($email) ?></td>
          <td><?= count($names) ?></td>
          <td style="text-align:left; white-space:normal;"><?= htmlspecialchars(implode(', ', array_unique($names))) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (count($report['duplicate_emails']) > 30): ?><div class="subtitle" style="margin-top:8px;">...And <?= count($report['duplicate_emails']) - 30 ?> More.</div><?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($report['duplicate_phones'])): ?>
  <h2 style="font-size:16px; margin-bottom:14px;">Duplicate Phone Numbers (<?= count($report['duplicate_phones']) ?>)</h2>
  <div class="table-wrap" style="margin-bottom:24px; overflow-x:auto;">
    <table class="wide-report-table">
      <thead><tr><th>Phone</th><th>Times Used</th><th style="text-align:left;">Contact Names</th></tr></thead>
      <tbody>
        <?php foreach (array_slice($report['duplicate_phones'], 0, 30, true) as $phone => $names): ?>
        <tr>
          <td class="name-cell"><?= htmlspecialchars($phone) ?></td>
          <td><?= count($names) ?></td>
          <td style="text-align:left; white-space:normal;"><?= htmlspecialchars(implode(', ', array_unique($names))) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (count($report['duplicate_phones']) > 30): ?><div class="subtitle" style="margin-top:8px;">...And <?= count($report['duplicate_phones']) - 30 ?> More.</div><?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($report['issue_rows'])): ?>
  <h2 style="font-size:16px; margin-bottom:14px;">Flagged Rows (<?= count($report['issue_rows']) ?>) — Missing Email/Phone/Purchase Type Or Duplicates</h2>
  <div class="table-wrap" style="margin-bottom:24px; overflow-x:auto;">
    <table class="wide-report-table">
      <thead><tr><th>Dealer</th><th>Contact Name</th><th>Email</th><th>Phone</th><th>Inquiry No</th><th style="text-align:left;">Issue(s)</th></tr></thead>
      <tbody>
        <?php foreach (array_slice($report['issue_rows'], 0, 200) as $row): ?>
        <tr>
          <td class="name-cell"><?= htmlspecialchars($row['dealer']) ?></td>
          <td><?= htmlspecialchars($row['contact_name'] ?: '—') ?></td>
          <td><?= htmlspecialchars($row['email'] ?: '—') ?></td>
          <td><?= htmlspecialchars($row['phone'] ?: '—') ?></td>
          <td><?= htmlspecialchars($row['inquiry_no'] ?: '—') ?></td>
          <td class="flag-bad" style="text-align:left; white-space:normal;"><?= htmlspecialchars($row['issues']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (count($report['issue_rows']) > 200): ?><div class="subtitle" style="margin-top:8px;">Showing First 200 Of <?= count($report['issue_rows']) ?> — Use "Export Flagged Rows (CSV)" Above For The Full List.</div><?php endif; ?>
  </div>
  <?php endif; ?>

  <?php endif; ?>

</div>
</main>
</div>
<?php if ($report): ?>
<script id="issue-rows-data" type="application/json"><?= json_encode($report['issue_rows'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script id="dealer-summary-data" type="application/json"><?= json_encode(array_map(
    fn($dealerName, $s) => [
        'dealer' => $dealerName, 'total' => $s['total'], 'completeness' => $s['completeness'],
        'email_validity' => $s['email_validity'], 'phone_validity' => $s['phone_validity'],
        'email_missing' => $s['email_missing'], 'phone_missing' => $s['phone_missing'],
        'purchase_type_missing' => $s['purchase_type_missing'], 'uniqueness' => $s['uniqueness'],
        'quality_score' => $s['quality_score'],
    ],
    array_keys($report['per_dealer']), array_values($report['per_dealer'])
), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script>
(function () {
    var csvEscape = function (v) {
        v = (v === null || v === undefined) ? '' : String(v);
        return '"' + v.replace(/"/g, '""') + '"';
    };
    var downloadCsv = function (headers, rows, filename) {
        var lines = [headers.map(csvEscape).join(',')];
        rows.forEach(function (r) { lines.push(r.map(csvEscape).join(',')); });
        var blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    };

    document.getElementById('export-issues-btn')?.addEventListener('click', function () {
        var rows = JSON.parse(document.getElementById('issue-rows-data').textContent);
        downloadCsv(
            ['Dealer', 'Contact Name', 'Email', 'Phone', 'Inquiry No', 'Issue(s)'],
            rows.map(function (r) { return [r.dealer, r.contact_name, r.email, r.phone, r.inquiry_no, r.issues]; }),
            'crm_data_quality_flagged_rows.csv'
        );
    });

    document.getElementById('export-summary-btn')?.addEventListener('click', function () {
        var rows = JSON.parse(document.getElementById('dealer-summary-data').textContent);
        downloadCsv(
            ['Dealer', 'Total Rows', 'Completeness %', 'Email Valid %', 'Phone Valid %', 'Missing Email', 'Missing Phone', 'Missing Purchase Type', 'Uniqueness %', 'Data Quality Score'],
            rows.map(function (r) {
                return [r.dealer, r.total, r.completeness, r.email_validity, r.phone_validity, r.email_missing, r.phone_missing, r.purchase_type_missing, r.uniqueness, r.quality_score];
            }),
            'crm_data_quality_dealer_summary.csv'
        );
    });
})();
</script>
<?php endif; ?>
</body>
</html>
