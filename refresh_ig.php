<?php
header('Content-Type: application/json');
require_once __DIR__ . '/includes/Auth.php';
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Login required']);
    exit;
}
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/InstagramLookup.php';
require_once __DIR__ . '/includes/FacebookPoster.php';
require_once __DIR__ . '/includes/RefreshStatus.php';
set_time_limit(0);

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

// Release the session file lock now — see refresh_fb.php for why (this
// script keeps running in the background after responding, and would
// otherwise block refresh_status.php's polling requests on the same lock).
session_write_close();

$db = Database::getConnection();
$stmt = $db->prepare("SELECT ig_search, ig_followers, fb_page_id, fb_page_access_token, ig_business_account_id FROM dealerships WHERE id = :id");
$stmt->execute(['id' => $id]);
$d = $stmt->fetch();

if (!$d) {
    echo json_encode(['success' => false, 'message' => 'Dealership Not Found']);
    exit;
}

// Instagram rides on the same Facebook Page token — no separate IG login needed,
// as long as the IG account is a Business/Creator account linked to that Page.
if (!empty($d['fb_page_access_token']) && !empty($d['fb_page_id'])) {
    $ig = (new FacebookPoster())->getInstagramFollowers($d['fb_page_id'], $d['fb_page_access_token'], $d['ig_business_account_id'] ?: null);
    if (!$ig['success']) {
        echo json_encode(['success' => false, 'message' => $ig['message'], 'ig_followers' => (int)$d['ig_followers']]);
        exit;
    }
    $db->prepare("UPDATE dealerships SET ig_followers = :v, ig_business_account_id = COALESCE(ig_business_account_id, :ig_id), last_refreshed = NOW() WHERE id = :id")
       ->execute(['v' => $ig['followers'], 'ig_id' => $ig['ig_business_account_id'], 'id' => $id]);
    echo json_encode(['success' => true, 'ig_followers' => $ig['followers']]);
    exit;
}

if (empty($d['ig_search'])) {
    echo json_encode(['success' => true, 'skipped' => true, 'ig_followers' => (int)$d['ig_followers']]);
    exit;
}

// Same LiteSpeed-vs-Bright-Data timeout problem as refresh_fb.php — respond
// immediately and continue server-side; frontend polls refresh_status.php.
RefreshStatus::start('ig', $id);

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

$ig = (new InstagramLookup())->getFollowerCount($d['ig_search']);
if (!$ig['success']) {
    RefreshStatus::fail('ig', $id, $ig['message'], ['ig_followers' => (int)$d['ig_followers']]);
    exit;
}

$db->prepare("UPDATE dealerships SET ig_followers = :v, last_refreshed = NOW() WHERE id = :id")
   ->execute(['v' => $ig['followers'], 'id' => $id]);

RefreshStatus::finish('ig', $id, ['ig_followers' => $ig['followers']]);
