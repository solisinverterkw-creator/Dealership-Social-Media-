<?php
header('Content-Type: application/json');
require_once __DIR__ . '/includes/Auth.php';
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Login required']);
    exit;
}
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/FacebookPostsLookup.php';
require_once __DIR__ . '/includes/RefreshStatus.php';
set_time_limit(0); // Bright Data can take longer than PHP's default execution limit if it falls back to polling

$id = $_GET['id'] ?? null;
$from = $_GET['from'] ?? null;
$to = $_GET['to'] ?? null;
if (!$id || !$from || !$to) {
    echo json_encode(['success' => false, 'message' => 'id/from/to missing']);
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

$db = Database::getConnection();
$stmt = $db->prepare("SELECT * FROM dealerships WHERE id = :id");
$stmt->execute(['id' => $id]);
$d = $stmt->fetch();

if (!$d) {
    echo json_encode(['success' => false, 'message' => 'Dealership not found']);
    exit;
}
if (empty($d['fb_input'])) {
    echo json_encode(['success' => false, 'message' => 'Facebook page not set.']);
    exit;
}

$fromDateTime = $from . ' 00:00:00';
$toDateTime = $to . ' 23:59:59';

// Source posts actually published within the selected range — not "whenever we
// happened to fetch them," so the range the user picks is the range that's checked.
$sourceStmt = $db->prepare("
    SELECT source_post_id AS id, message_snippet AS snippet, published_at
    FROM processed_source_posts
    WHERE message_snippet IS NOT NULL AND message_snippet != ''
      AND published_at BETWEEN :from AND :to
");
$sourceStmt->execute(['from' => $fromDateTime, 'to' => $toDateTime]);
$sourcePosts = $sourceStmt->fetchAll();

if (empty($sourcePosts)) {
    echo json_encode(['success' => false, 'message' => 'No tracked source posts in this date range — click "Refresh Source Posts" with this range first.']);
    exit;
}

// Bright Data can take several minutes — well past LiteSpeed's own ~121s
// connection timeout, which otherwise kills the request before the browser
// ever sees a response. Respond immediately and keep working server-side;
// the frontend polls refresh_status.php for the real result.
$jobKey = "{$id}_{$from}_{$to}";
RefreshStatus::start('reshare_check', $jobKey);
session_write_close();

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

$lookup = new FacebookPostsLookup();

// Fully paginated over the dealership's OWN page for the exact same range —
// this is the fix for false "missed" results: the old approach only looked at
// the dealership's most recent ~10-15 posts, so a reshare could have already
// scrolled further back in a busy week and be wrongly reported as missing.
$dealershipResult = $lookup->getPostsInRange($d['fb_input'], $from, $to, $d['fb_page_id']);
if (!$dealershipResult['success']) {
    RefreshStatus::fail('reshare_check', $jobKey, $dealershipResult['message']);
    exit;
}
$dealershipPosts = $dealershipResult['posts'];

$match = $lookup->matchPostsAgainstSourcePosts($dealershipPosts, $sourcePosts);

$findRow = $db->prepare("SELECT id, reshared FROM reshare_checks WHERE dealership_id = :did AND source_post_id = :pid");
$insertRow = $db->prepare("
    INSERT INTO reshare_checks (dealership_id, source_post_id, message_snippet, first_seen_at, published_at, reshared, reshared_detected_at, last_checked_at)
    VALUES (:did, :pid, :snippet, NOW(), :published_at, :reshared, :detected_at, NOW())
");
$markReshared = $db->prepare("UPDATE reshare_checks SET reshared = 1, reshared_detected_at = NOW(), last_checked_at = NOW() WHERE id = :id");
$touchChecked = $db->prepare("UPDATE reshare_checks SET last_checked_at = NOW(), published_at = COALESCE(published_at, :published_at) WHERE id = :id");

foreach ($sourcePosts as $sourcePost) {
    $matched = $match['matches'][$sourcePost['id']] ?? false;

    $findRow->execute(['did' => $id, 'pid' => $sourcePost['id']]);
    $existing = $findRow->fetch();

    if (!$existing) {
        $insertRow->execute([
            'did' => $id,
            'pid' => $sourcePost['id'],
            'snippet' => $sourcePost['snippet'],
            'published_at' => $sourcePost['published_at'],
            'reshared' => $matched ? 1 : 0,
            'detected_at' => $matched ? date('Y-m-d H:i:s') : null,
        ]);
    } elseif (!$existing['reshared'] && $matched) {
        $markReshared->execute(['id' => $existing['id']]);
    } else {
        $touchChecked->execute(['id' => $existing['id'], 'published_at' => $sourcePost['published_at']]);
    }
}

if ($d['fb_page_id'] !== $dealershipResult['page_id']) {
    $db->prepare("UPDATE dealerships SET fb_page_id = :pid WHERE id = :id")->execute(['pid' => $dealershipResult['page_id'], 'id' => $id]);
}

// Own-vs-reshare breakdown for the dealership's own page in this range — overwritten
// each check so it always reflects the most recently checked range.
$db->prepare("
    INSERT INTO reshare_own_post_stats (dealership_id, range_from, range_to, own_post_count, reshare_post_count, checked_at)
    VALUES (:id, :from, :to, :own, :reshare, NOW())
    ON DUPLICATE KEY UPDATE range_from = VALUES(range_from), range_to = VALUES(range_to),
        own_post_count = VALUES(own_post_count), reshare_post_count = VALUES(reshare_post_count), checked_at = VALUES(checked_at)
")->execute([
    'id' => $id,
    'from' => $from,
    'to' => $to,
    'own' => $match['own_count'],
    'reshare' => $match['total_dealership_posts'] - $match['own_count'],
]);

RefreshStatus::finish('reshare_check', $jobKey, [
    'tracked' => count($sourcePosts),
    'reshared' => count(array_filter($match['matches'])),
    'own_posts' => $match['own_count'],
]);
