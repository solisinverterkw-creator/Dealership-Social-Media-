<?php
header('Content-Type: application/json');
require_once __DIR__ . '/includes/Auth.php';
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Login required']);
    exit;
}
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/FacebookLookup.php';
require_once __DIR__ . '/includes/FacebookPoster.php';
require_once __DIR__ . '/includes/RefreshStatus.php';
set_time_limit(0); // Bright Data can take longer than PHP's default execution limit if it falls back to polling

$id = $_GET['id'] ?? null;
if (!$id) {
    echo json_encode(['success' => false, 'message' => 'ID missing']);
    exit;
}
if (!Auth::canAccessDealership((int)$id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}
if (!Auth::can('refresh')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You Do Not Have Permission To Refresh Data.']);
    exit;
}

// Release the session file lock now — PHP's default session handler holds an
// exclusive lock on the session file for the entire request, and this script
// keeps running in the background for minutes after responding (see below).
// Without this, refresh_status.php's polling requests block waiting on the
// same lock and time out themselves.
session_write_close();

$db = Database::getConnection();
$stmt = $db->prepare("SELECT fb_input, fb_followers, fb_page_id, fb_page_access_token FROM dealerships WHERE id = :id");
$stmt->execute(['id' => $id]);
$d = $stmt->fetch();

if (!$d) {
    echo json_encode(['success' => false, 'message' => 'Dealership Not Found']);
    exit;
}

// A dealership that's granted real Page admin access (Business Manager partner/System
// User token) uses the official Graph API — reliable, no scraper quota/blocking issues.
// Everyone else stays on the Bright Data scraper until they're onboarded too.
if (!empty($d['fb_page_access_token'])) {
    if (empty($d['fb_page_id'])) {
        echo json_encode(['success' => false, 'message' => 'Graph API Token Set But fb_page_id Is Missing.', 'fb_followers' => (int)$d['fb_followers']]);
        exit;
    }
    $fb = (new FacebookPoster())->getPageFollowers($d['fb_page_id'], $d['fb_page_access_token']);
    if (!$fb['success']) {
        echo json_encode(['success' => false, 'message' => $fb['message'], 'fb_followers' => (int)$d['fb_followers']]);
        exit;
    }
    $db->prepare("UPDATE dealerships SET fb_followers = :v, last_refreshed = NOW() WHERE id = :id")
       ->execute(['v' => $fb['followers'], 'id' => $id]);
    echo json_encode(['success' => true, 'fb_followers' => $fb['followers']]);
    exit;
}

if (empty($d['fb_input'])) {
    echo json_encode(['success' => true, 'skipped' => true, 'fb_followers' => (int)$d['fb_followers']]);
    exit;
}

// Bright Data can take several minutes (retry/polling fallback) — well past
// LiteSpeed's own ~121s connection timeout, which otherwise kills the request
// before the browser ever sees a response. Respond immediately instead and
// keep working server-side; the frontend polls refresh_status.php for the
// real result via RefreshStatus.
RefreshStatus::start('fb', $id);

ignore_user_abort(true);
header('Connection: close');
ob_start();
echo json_encode(['status' => 'started']);
$responseSize = ob_get_length();
header("Content-Length: {$responseSize}");
ob_end_flush();
flush();
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} elseif (function_exists('litespeed_finish_request')) {
    litespeed_finish_request();
}

$fb = (new FacebookLookup())->getFollowerCount($d['fb_input']);
if (!$fb['success']) {
    RefreshStatus::fail('fb', $id, $fb['message'], ['fb_followers' => (int)$d['fb_followers']]);
    exit;
}

$db->prepare("UPDATE dealerships SET fb_followers = :v, fb_page_id = COALESCE(fb_page_id, :page_id), last_refreshed = NOW() WHERE id = :id")
   ->execute(['v' => $fb['followers'], 'page_id' => $fb['page_id'], 'id' => $id]);

RefreshStatus::finish('fb', $id, ['fb_followers' => $fb['followers']]);
