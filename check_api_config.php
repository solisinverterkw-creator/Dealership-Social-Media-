<?php
require_once __DIR__ . '/config.php';

header('Content-Type: text/plain');

function checkDefined(string $name): string {
    if (!defined($name)) return "NOT DEFINED";
    $v = constant($name);
    if ($v === '' || $v === null) return "DEFINED BUT EMPTY";
    if (is_string($v) && strlen($v) < 8) return "DEFINED BUT SUSPICIOUSLY SHORT ('$v')";
    $masked = is_string($v) ? substr($v, 0, 6) . '...' . substr($v, -4) : (string)$v;
    return "OK ($masked)";
}

echo "=== Config Constants ===\n";
foreach ([
    'DB_HOST', 'DB_NAME', 'DB_USER',
    'RAPIDAPI_KEY', 'SCRAPECREATORS_API_KEY_INSTAGRAM', 'APIFY_API_TOKEN',
    'BRIGHTDATA_API_TOKEN', 'BRIGHTDATA_DATASET_PAGE_INFO', 'BRIGHTDATA_DATASET_PAGE_POSTS', 'BRIGHTDATA_DATASET_INSTAGRAM_PROFILE',
    'YOUTUBE_API_KEY', 'GEMINI_API_KEY', 'FB_APP_ID', 'FB_APP_SECRET',
    'FB_GRAPH_API_VERSION', 'SOURCE_PAGE_URL', 'SOURCE_PAGE_ID', 'CRON_SECRET_KEY',
] as $name) {
    echo str_pad($name, 42) . ": " . checkDefined($name) . "\n";
}

echo "\n=== Live Checks (cheap/free calls only) ===\n";

// YouTube Data API - quota cost ~1 unit, generous daily quota.
if (defined('YOUTUBE_API_KEY') && YOUTUBE_API_KEY !== '') {
    $ch = curl_init("https://www.googleapis.com/youtube/v3/videos?part=id&chart=mostPopular&maxResults=1&key=" . YOUTUBE_API_KEY);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "YouTube Data API: HTTP $code " . ($code === 200 ? "- OK" : "- " . mb_substr($resp, 0, 200)) . "\n";
} else {
    echo "YouTube Data API: SKIPPED (key not set)\n";
}

// Gemini - models list endpoint is free (no generation cost).
if (defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '') {
    $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models?key=" . GEMINI_API_KEY);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "Gemini API (list models): HTTP $code " . ($code === 200 ? "- OK" : "- " . mb_substr($resp, 0, 200)) . "\n";
} else {
    echo "Gemini API: SKIPPED (key not set)\n";
}

// Bright Data - account/customer info endpoint (free, no scrape credit used).
if (defined('BRIGHTDATA_API_TOKEN') && BRIGHTDATA_API_TOKEN !== '') {
    $ch = curl_init("https://api.brightdata.com/customer");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . BRIGHTDATA_API_TOKEN]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "Bright Data (account check): HTTP $code " . ($code === 200 ? "- OK (token valid)" : "- " . mb_substr($resp, 0, 200)) . "\n";
} else {
    echo "Bright Data: SKIPPED (token not set)\n";
}

echo "\n(RapidAPI/ScrapeCreators/Apify not live-tested here — testing them costs real request credits. Presence check above is sufficient unless you're seeing actual failures.)\n";
echo "\nDELETE THIS FILE FROM THE SERVER AFTER VIEWING — it reads your live secret keys.\n";
