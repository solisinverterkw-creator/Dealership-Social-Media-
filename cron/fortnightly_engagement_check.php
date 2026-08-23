<?php
require_once __DIR__ . '/../config.php';
$isCli = php_sapi_name() === 'cli';
$hasValidKey = isset($_GET['key']) && hash_equals(CRON_SECRET_KEY, $_GET['key']);
if (!$isCli && !$hasValidKey) {
    http_response_code(403);
    exit('CLI only, or provide a valid ?key=.');
}

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/FacebookPostsLookup.php';
require_once __DIR__ . '/../includes/InstagramLookup.php';
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

$db = Database::getConnection();
$fbPosts = new FacebookPostsLookup();
$igPosts = new InstagramLookup();

$from = date('Y-m-d', strtotime('-7 days'));
$to = date('Y-m-d');

$dealerships = $db->query("SELECT id, name, fb_input, fb_page_id, ig_search FROM dealerships ORDER BY id")->fetchAll();
$excludeTextSnippets = $db->query("SELECT message_snippet FROM processed_source_posts WHERE message_snippet IS NOT NULL AND message_snippet != ''")->fetchAll(PDO::FETCH_COLUMN);

echo "[" . date('Y-m-d H:i:s') . "] Fortnightly engagement check ({$from} to {$to}) — " . count($dealerships) . " dealership(s).\n";

foreach ($dealerships as $d) {
    echo "  [{$d['id']}] {$d['name']}\n";

    if (!empty($d['fb_input'])) {
        $excludeIds = [];
        $targetName = $db->prepare("SELECT name FROM target_pages WHERE dealership_id = :id LIMIT 1");
        $targetName->execute(['id' => $d['id']]);
        $linkedName = $targetName->fetchColumn();
        if ($linkedName) {
            $logStmt = $db->prepare("SELECT fb_post_id FROM post_log WHERE dealership_name = :name AND status = 'success' AND fb_post_id IS NOT NULL");
            $logStmt->execute(['name' => $linkedName]);
            $excludeIds = $logStmt->fetchAll(PDO::FETCH_COLUMN);
        }

        $result = $fbPosts->countInRange($d['fb_input'], $from, $to, $d['fb_page_id'], $excludeIds, 100, $excludeTextSnippets);
        if ($result['success']) {
            $db->prepare("UPDATE dealerships SET fb_posts_week = :count, fb_engagement_avg = :engagement, fb_page_id = :page_id, fb_posts_checked_at = NOW() WHERE id = :id")
               ->execute(['count' => $result['count'], 'engagement' => $result['avg_engagement'], 'page_id' => $result['page_id'], 'id' => $d['id']]);
            echo "    FB posts: {$result['count']}, avg engagement: {$result['avg_engagement']}\n";
        } else {
            echo "    FB FAILED: {$result['message']}\n";
        }
        usleep(500000);
    }

    if (!empty($d['ig_search'])) {
        $result = $igPosts->countInRange($d['ig_search'], $from, $to);
        if ($result['success']) {
            $db->prepare("UPDATE dealerships SET ig_posts_week = :count, ig_engagement_avg = :engagement, ig_posts_checked_at = NOW() WHERE id = :id")
               ->execute(['count' => $result['count'], 'engagement' => $result['avg_engagement'], 'id' => $d['id']]);
            echo "    IG posts: {$result['count']}, avg engagement: {$result['avg_engagement']}\n";
        } else {
            echo "    IG FAILED: {$result['message']}\n";
        }
        usleep(500000);
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Fortnightly engagement check done.\n";
