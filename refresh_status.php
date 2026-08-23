<?php
header('Content-Type: application/json');
require_once __DIR__ . '/includes/Auth.php';
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Login required']);
    exit;
}
require_once __DIR__ . '/includes/RefreshStatus.php';

$id = $_GET['id'] ?? null;
$metric = $_GET['metric'] ?? null;
$sharedMetrics = ['source_posts', 'reshare_source'];
if (!$id || !in_array($metric, ['fb', 'ig', 'source_posts', 'reshare_check', 'reshare_source'], true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid Request']);
    exit;
}
// Shared/global jobs (source page checks) aren't tied to one dealership, so
// they only need a logged-in user, not per-dealership access. reshare_check's
// job key starts with the dealership id but isn't a plain int, so it's
// checked separately below instead of via canAccessDealership() directly.
if (!in_array($metric, $sharedMetrics, true)) {
    $dealershipId = $metric === 'reshare_check' ? (int)strtok($id, '_') : (int)$id;
    if (!Auth::canAccessDealership($dealershipId)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Access denied']);
        exit;
    }
}

echo json_encode(RefreshStatus::read($metric, $id) ?? ['status' => 'unknown']);
