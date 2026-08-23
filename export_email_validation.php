<?php
require_once __DIR__ . '/includes/Auth.php';
Auth::requireLogin();

$results = json_decode($_POST['results_json'] ?? '[]', true);
if (!is_array($results)) {
    http_response_code(400);
    exit('Invalid Export Data.');
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=email_validation_' . date('Y-m-d_His') . '.csv');

$out = fopen('php://output', 'w');
fputs($out, "\xEF\xBB\xBF");

$hasSmtp = !empty($results) && array_key_exists('smtp_valid', $results[0]);

$header = ['Email', 'Format Valid', 'MX Record Found', 'Disposable', 'Role-Based'];
if ($hasSmtp) {
    $header[] = 'SMTP Check';
}
$header[] = 'Overall Valid';
$header[] = 'Notes';
fputcsv($out, $header);

foreach ($results as $r) {
    $disposable = $r['is_disposable'] === null ? 'Unknown' : ($r['is_disposable'] ? 'Yes' : 'No');
    $row = [
        $r['email'] ?? '',
        !empty($r['format_valid']) ? 'Yes' : 'No',
        !empty($r['mx_valid']) ? 'Yes' : 'No',
        $disposable,
        !empty($r['is_role_based']) ? 'Yes' : 'No',
    ];
    if ($hasSmtp) {
        $smtp = !array_key_exists('smtp_valid', $r) || $r['smtp_valid'] === null ? 'Unknown' : ($r['smtp_valid'] ? 'Accepted' : 'Rejected');
        $row[] = $smtp;
    }
    $row[] = !empty($r['overall_valid']) ? 'Yes' : 'No';
    $row[] = $r['summary'] ?? '';
    fputcsv($out, $row);
}

fclose($out);
