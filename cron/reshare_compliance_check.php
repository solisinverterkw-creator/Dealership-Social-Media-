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
// timeout (not PHP's) can kill a slow HTTP-triggered request before Bright
// Data responds, so the response is sent immediately and the rest of this
// script keeps running server-side after the connection closes.
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
require_once __DIR__ . '/../includes/FacebookPostsLookup.php';

$db = Database::getConnection();
$lookup = new FacebookPostsLookup();

$settings = $db->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('source_page_url', 'source_page_id')")
    ->fetchAll(PDO::FETCH_KEY_PAIR);
$sourceUrl = $settings['source_page_url'] ?? null;
$sourcePageId = $settings['source_page_id'] ?? null;

if (empty($sourceUrl)) {
    echo "Source page not set. Set it from the Publish Content page first.\n";
    exit(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Reshare compliance check — fetching source page posts...\n";

$sourceResult = $lookup->getRecentPosts($sourceUrl, 15, $sourcePageId);
if (!$sourceResult['success']) {
    echo "FAILED to fetch source posts: {$sourceResult['message']}\n";
    exit(1);
}

// Reuses the same table Content Syndication marks posts in — it's just "source
// posts we know about," independent of whether they were ever auto-published.
$markKnown = $db->prepare("INSERT IGNORE INTO processed_source_posts (source_post_id, message_snippet) VALUES (:id, :snippet)");
$sourcePosts = [];
foreach ($sourceResult['posts'] as $post) {
    $snippet = mb_substr($post['message'] ?? '', 0, 255);
    if ($snippet === '') {
        continue; // no text to fingerprint — can't detect a reshare of this one
    }
    $markKnown->execute(['id' => $post['id'], 'snippet' => $snippet]);
    $sourcePosts[] = ['id' => $post['id'], 'snippet' => $snippet];
}

echo "  " . count($sourcePosts) . " source post(s) with text to track.\n";

$dealerships = $db->query("SELECT id, name, fb_input, fb_page_id FROM dealerships WHERE fb_input IS NOT NULL AND fb_input != '' ORDER BY id")->fetchAll();

$findRow = $db->prepare("SELECT id, reshared FROM reshare_checks WHERE dealership_id = :did AND source_post_id = :pid");
$insertRow = $db->prepare("
    INSERT INTO reshare_checks (dealership_id, source_post_id, message_snippet, first_seen_at, reshared, reshared_detected_at, last_checked_at)
    VALUES (:did, :pid, :snippet, NOW(), :reshared, :detected_at, NOW())
");
$markReshared = $db->prepare("
    UPDATE reshare_checks SET reshared = 1, reshared_detected_at = NOW(), last_checked_at = NOW()
    WHERE id = :id
");
$touchChecked = $db->prepare("UPDATE reshare_checks SET last_checked_at = NOW() WHERE id = :id");

foreach ($dealerships as $d) {
    echo "  [{$d['id']}] {$d['name']}\n";

    $result = $lookup->checkReshares($d['fb_input'], $sourcePosts, $d['fb_page_id']);
    if (!$result['success']) {
        echo "    FAILED: {$result['message']}\n";
        continue;
    }

    foreach ($sourcePosts as $sourcePost) {
        $matched = $result['matches'][$sourcePost['id']] ?? false;

        $findRow->execute(['did' => $d['id'], 'pid' => $sourcePost['id']]);
        $existing = $findRow->fetch();

        if (!$existing) {
            $insertRow->execute([
                'did' => $d['id'],
                'pid' => $sourcePost['id'],
                'snippet' => $sourcePost['snippet'],
                'reshared' => $matched ? 1 : 0,
                'detected_at' => $matched ? date('Y-m-d H:i:s') : null,
            ]);
        } elseif (!$existing['reshared'] && $matched) {
            // Only ever flips 0 -> 1 — once reshared, always counts as reshared,
            // even if the dealership later deletes the post.
            $markReshared->execute(['id' => $existing['id']]);
        } else {
            $touchChecked->execute(['id' => $existing['id']]);
        }
    }

    echo "    Reshared: " . count(array_filter($result['matches'])) . "/" . count($sourcePosts) . "\n";
    sleep(2); // this host rate-limits (HTTP 429) faster than the older crons' 0.5s delay assumed
}

echo "[" . date('Y-m-d H:i:s') . "] Reshare compliance check done.\n";
