<?php
require_once __DIR__ . '/includes/Auth.php';
Auth::requireLogin();
require_once __DIR__ . '/includes/EmailValidator.php';
require_once __DIR__ . '/includes/SimpleXlsxReader.php';

$results = [];
$error = '';
$includeSmtp = false;
$smtpAutoDisabled = false;

/** Any cell containing "@" is a candidate email — works regardless of column/header. */
function extractEmailsFromCsv(string $path): array
{
    $emails = [];
    $handle = fopen($path, 'r');
    if ($handle === false) {
        return [];
    }
    while (($row = fgetcsv($handle)) !== false) {
        foreach ($row as $cell) {
            $cell = trim($cell);
            if ($cell !== '' && str_contains($cell, '@')) {
                $emails[] = $cell;
            }
        }
    }
    fclose($handle);
    return array_values(array_unique($emails));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    set_time_limit(0); // no cap on how many emails can be checked in one go

    $emails = array_filter(array_map('trim', explode("\n", $_POST['emails'] ?? '')));

    if (!empty($_FILES['email_file']['name']) && $_FILES['email_file']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['email_file']['name'], PATHINFO_EXTENSION));
        $tmpPath = $_FILES['email_file']['tmp_name'];

        if ($ext === 'csv') {
            $emails = array_merge($emails, extractEmailsFromCsv($tmpPath));
        } elseif ($ext === 'xlsx') {
            $reader = new SimpleXlsxReader();
            $fromFile = $reader->extractCandidateEmails($tmpPath);
            if (empty($fromFile)) {
                $error = 'Could Not Read Any Emails From This Excel File — Try Saving It As .csv Instead.';
            }
            $emails = array_merge($emails, $fromFile);
        } else {
            $error = 'Only .xlsx Or .csv Files Are Supported.';
        }
    }

    $emails = array_values(array_unique(array_filter($emails)));
    $includeSmtp = !empty($_POST['include_smtp']);
    $smtpAutoDisabled = false;

    if (!empty($emails)) {
        $validator = new EmailValidator();
        foreach ($emails as $email) {
            $results[] = $validator->validate($email, $includeSmtp);
        }
        $smtpAutoDisabled = $includeSmtp && $validator->isSmtpDisabledForBatch();
    } elseif (!$error) {
        $error = 'No Emails Found To Check.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script>(function(){var t=localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
<meta charset="UTF-8">
<title>Email Validator</title>
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
      <h1>Email Validator</h1>
      <div class="subtitle">Format · MX Record · Disposable · Role-Based · SMTP (Optional) — Up To 5-Point Check</div>
    </div>
  </header>

  <?php if ($error): ?><div class="error-msg" style="margin-bottom:16px;"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <form method="POST" enctype="multipart/form-data" class="search-panel" style="margin-bottom:24px;" onsubmit="return startValidating(this)">
    <div class="field" style="flex:1;">
      <label>Email(s) — One Per Line</label>
      <textarea name="emails" rows="5" placeholder="someone@example.com&#10;info@example.com"><?= htmlspecialchars($_POST['emails'] ?? '') ?></textarea>
    </div>
    <div class="field">
      <label>Or Upload A File (.xlsx / .csv) — Unlimited Emails, Any Column</label>
      <input type="file" name="email_file" accept=".xlsx,.csv">
    </div>
    <div class="field" style="display:flex; align-items:center; gap:8px; flex-direction:row;">
      <input type="checkbox" name="include_smtp" id="include_smtp" style="width:auto;" <?= !empty($_POST['include_smtp']) ? 'checked' : '' ?>>
      <label for="include_smtp" style="margin:0;">Also Run SMTP-Level Check (Slower — 1 Extra Server Connection Per Email; Needs Outbound Port 25, Which Many Hosts Block)</label>
    </div>
    <button type="submit" class="submit" id="validate-btn">Validate</button>
    <div id="validating-msg" style="display:none; margin-top:12px; color:var(--muted); font-size:13px;">
      ⏳ Checking each email (MX + disposable lookup takes ~0.2-0.5s per email) — please keep this tab open, do not refresh or close it...
    </div>
  </form>
  <script>
    function startValidating(form) {
      document.getElementById('validate-btn').disabled = true;
      document.getElementById('validate-btn').textContent = 'Validating...';
      document.getElementById('validating-msg').style.display = 'block';
      return true; // let the normal form submit proceed
    }
  </script>

  <?php if ($smtpAutoDisabled): ?>
  <div class="error-msg" style="margin-bottom:16px;">⚠ SMTP Check Auto-Disabled Mid-Batch — 2 Consecutive Mail Servers Did Not Respond, So Remaining Emails Skipped It (Shown As "Unknown") Instead Of Waiting On Every One. Other Checks Still Ran Normally. Note: Gmail/Yahoo/Outlook/iCloud/AOL Are Always Skipped For SMTP (They Block This Check By Policy) And Never Count Toward This — If You're Seeing This On A Batch With Other Domains, Their Mail Servers Are The Ones Not Responding.</div>
  <?php endif; ?>

  <?php if (!empty($results)): ?>
  <div class="subtitle" style="margin-bottom:12px; display:flex; justify-content:space-between; align-items:center;">
    <span><?= count($results) ?> Email(s) Checked.</span>
    <form method="POST" action="export_email_validation.php">
      <input type="hidden" name="results_json" value="<?= htmlspecialchars(json_encode($results)) ?>">
      <button type="submit" class="btn primary" style="width:auto; padding:8px 18px;">Export CSV</button>
    </form>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Email</th>
          <th>Format</th>
          <th>MX Record</th>
          <th>Disposable</th>
          <th>Role-Based</th>
          <?php if ($includeSmtp): ?><th>SMTP</th><?php endif; ?>
          <th>Overall</th>
          <th>Notes</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($results as $r): ?>
        <tr>
          <td class="name"><?= htmlspecialchars($r['email']) ?></td>
          <td>
            <span class="status-badge <?= $r['format_valid'] ? 'status-done' : 'status-flag' ?>">
              <?= $r['format_valid'] ? 'Valid' : 'Invalid' ?>
            </span>
          </td>
          <td>
            <?php if (!$r['format_valid']): ?>
              <span class="status-badge status-pending">—</span>
            <?php else: ?>
              <span class="status-badge <?= $r['mx_valid'] ? 'status-done' : 'status-flag' ?>">
                <?= $r['mx_valid'] ? 'Found' : 'Missing' ?>
              </span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($r['is_disposable'] === null): ?>
              <span class="status-badge status-pending">Unknown</span>
            <?php elseif ($r['is_disposable']): ?>
              <span class="status-badge status-flag">Yes</span>
            <?php else: ?>
              <span class="status-badge status-done">No</span>
            <?php endif; ?>
          </td>
          <td>
            <span class="status-badge <?= $r['is_role_based'] ? 'status-partial' : 'status-done' ?>">
              <?= $r['is_role_based'] ? 'Yes' : 'No' ?>
            </span>
          </td>
          <?php if ($includeSmtp): ?>
          <td>
            <?php if ($r['smtp_valid'] === null): ?>
              <span class="status-badge status-pending">Unknown</span>
            <?php elseif ($r['smtp_valid']): ?>
              <span class="status-badge status-done">Accepted</span>
            <?php else: ?>
              <span class="status-badge status-flag">Rejected</span>
            <?php endif; ?>
          </td>
          <?php endif; ?>
          <td>
            <span class="status-badge <?= $r['overall_valid'] ? 'status-done' : 'status-flag' ?>">
              <?= $r['overall_valid'] ? '✓ Valid' : '✗ Invalid' ?>
            </span>
          </td>
          <td class="timestamp" style="max-width:260px; white-space:normal;"><?= htmlspecialchars($r['summary']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <div class="subtitle" style="margin-top:16px; line-height:1.6;">
    <strong>Format</strong> = whether the email is written correctly (RFC syntax check).<br>
    <strong>MX Record</strong> = whether the domain is real and can actually receive email (catches typos and fake domains).<br>
    <strong>Disposable</strong> = whether it's a fake/temporary service like 10-minute-mail (checked via DeBounce's free API).<br>
    <strong>Role-Based</strong> = whether it's a generic address like info@ or admin@ rather than one specific person's — such addresses now count as Overall "Invalid," since they don't reach a specific individual.<br>
    <strong>SMTP (Optional)</strong> = connects directly to the mail server and asks "does this mailbox exist?" (no email is sent — just a handshake, then QUIT). Gmail/Yahoo/Outlook/iCloud/AOL/etc. are always shown as "Unknown" here — these providers intentionally don't answer automated verification requests (a real anti-spam measure on their end, not something any tool can bypass), so for those, the MX/Disposable/Role-Based checks above are the meaningful signal. For other (business/custom) domains, if the mail server doesn't respond it also shows "Unknown" and doesn't count against validity.
  </div>

</div>
</main>
</div>
</body>
</html>
