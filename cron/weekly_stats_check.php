<?php
require_once __DIR__ . '/../config.php';
$isCli = php_sapi_name() === 'cli';
$hasValidKey = isset($_GET['key']) && hash_equals(CRON_SECRET_KEY, $_GET['key']);
if (!$isCli && !$hasValidKey) {
    http_response_code(403);
    exit('CLI only, or provide a valid ?key=.');
}
set_time_limit(0); // Bright Data can take longer than PHP's default execution limit if it falls back to polling

// See sync_posts.php for why this exists — the web server's own connection
// timeout (not PHP's) can kill a slow HTTP-triggered request before all 21
// dealerships are checked, so the response is sent immediately and the rest
// of this script keeps running server-side after the connection closes.
if (!$isCli) {
    ignore_user_abort(true);
    header('Connection: close');
    ob_start();
    echo "Check started — continuing in the background.\n";
    $responseSize = ob_get_length();
    header("Content-Length: {$responseSize}");
    ob_end_flush();
    flush();
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    }
}

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/FacebookLookup.php';
require_once __DIR__ . '/../includes/InstagramLookup.php';
require_once __DIR__ . '/../includes/GoogleReviewLookup.php';

$db = Database::getConnection();
$fb = new FacebookLookup();
$ig = new InstagramLookup();
$gr = new GoogleReviewLookup();

$dealerships = $db->query("SELECT id, name, fb_input, ig_search, google_search FROM dealerships ORDER BY id")->fetchAll();

echo "[" . date('Y-m-d H:i:s') . "] Weekly stats check — " . count($dealerships) . " dealership(s).\n";

foreach ($dealerships as $d) {
    echo "  [{$d['id']}] {$d['name']}\n";

    if (!empty($d['fb_input'])) {
        $result = $fb->getFollowerCount($d['fb_input']);
        if ($result['success']) {
            $db->prepare("UPDATE dealerships SET fb_followers = :v, fb_page_id = COALESCE(fb_page_id, :page_id), last_refreshed = NOW() WHERE id = :id")
               ->execute(['v' => $result['followers'], 'page_id' => $result['page_id'], 'id' => $d['id']]);
            echo "    FB followers: {$result['followers']}\n";
        } else {
            echo "    FB FAILED: {$result['message']}\n";
        }
        usleep(500000);
    }

    if (!empty($d['ig_search'])) {
        $result = $ig->getFollowerCount($d['ig_search']);
        if ($result['success']) {
            $db->prepare("UPDATE dealerships SET ig_followers = :v, last_refreshed = NOW() WHERE id = :id")
               ->execute(['v' => $result['followers'], 'id' => $d['id']]);
            echo "    IG followers: {$result['followers']}\n";
        } else {
            echo "    IG FAILED: {$result['message']}\n";
        }
        usleep(500000);
    }

    if (!empty($d['google_search'])) {
        $result = $gr->searchAndGetReviews($d['google_search']);
        if ($result['success']) {
            $db->prepare("UPDATE dealerships SET google_review_count = :count, google_rating = :rating, last_refreshed = NOW() WHERE id = :id")
               ->execute(['count' => $result['review_count'], 'rating' => $result['rating'], 'id' => $d['id']]);
            echo "    Google reviews: {$result['review_count']} ({$result['rating']}★)\n";
        } else {
            echo "    Google FAILED: {$result['message']}\n";
        }
        usleep(500000);
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Weekly stats check done.\n";
