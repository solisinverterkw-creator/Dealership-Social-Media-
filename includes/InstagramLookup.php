<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/BrightDataClient.php';

class InstagramLookup
{
    private string $host = "api.scrapecreators.com";

    public function extractUsername(string $input): string
    {
        $input = trim($input);
        if (str_contains($input, 'instagram.com')) {
            $path = trim(parse_url($input, PHP_URL_PATH), '/');
            $parts = explode('/', $path);
            return $parts[0];
        }
        return ltrim($input, '@');
    }

    private function fetchProfile(string $username): array
    {
        $url = "https://{$this->host}/v1/instagram/profile?handle=" . urlencode($username);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'x-api-key: ' . SCRAPECREATORS_API_KEY_INSTAGRAM,
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['httpCode' => $httpCode, 'data' => json_decode($response, true)];
    }

    /**
     * A non-200 response (out of credits, rate-limited, auth error, etc.) has
     * its own message from ScrapeCreators — surface that instead of a generic
     * "Profile Not Found", which used to hide the real cause (e.g. a RapidAPI
     * monthly quota cap, back when this used Instagram Looter2) behind a
     * message that looked like a bad username.
     */
    private function errorMessage(array $result): string
    {
        if ($result['httpCode'] !== 200 || empty($result['data']['success'])) {
            $apiMessage = $result['data']['message'] ?? $result['data']['error'] ?? null;
            return $apiMessage ? "Instagram API Error: {$apiMessage}" : "Instagram API HTTP {$result['httpCode']}.";
        }
        return 'Profile Not Found.';
    }

    /**
     * Follower count now goes through Bright Data (re-tested 2026-07-24 — its
     * Instagram dataset works fine now, despite the earlier "both scraper
     * modes fail" finding). Bright Data's free tier (5,000 records/month) is
     * far more generous than ScrapeCreators' small credit-based free tier,
     * which was running out fast across ~21 dealerships.
     *
     * countInRange() below stays on ScrapeCreators — Bright Data's post
     * entries came back with datetime/likes/comments all null in testing, so
     * it can't support the date-range + engagement math that needs those.
     */
    public function getFollowerCount(string $profileInput): array
    {
        if (empty(trim($profileInput))) {
            return ['success' => false, 'message' => 'Instagram Input Is Empty.'];
        }

        $username = $this->extractUsername($profileInput);
        $profileUrl = "https://www.instagram.com/{$username}/";

        $client = new BrightDataClient();
        $result = $client->scrape(BRIGHTDATA_DATASET_INSTAGRAM_PROFILE, [['url' => $profileUrl]]);

        if (!$result['success']) {
            return ['success' => false, 'message' => $result['message']];
        }

        $row = $result['data'][0] ?? null;
        if (!$row) {
            return ['success' => false, 'message' => 'Bright Data Returned No Data For This Profile.'];
        }

        return [
            'success' => true,
            'followers' => (int)($row['followers'] ?? 0),
        ];
    }

    public function countInRange(string $profileInput, string $fromDate, string $toDate): array
    {
        if (empty(trim($profileInput))) {
            return ['success' => false, 'message' => 'Instagram Input Is Empty.'];
        }

        $username = $this->extractUsername($profileInput);
        $result = $this->fetchProfile($username);

        if ($result['httpCode'] !== 200 || empty($result['data']['success']) || !isset($result['data']['data']['user'])) {
            return ['success' => false, 'message' => $this->errorMessage($result)];
        }

        $edges = $result['data']['data']['user']['edge_owner_to_timeline_media']['edges'] ?? [];
        $fromTs = strtotime($fromDate);
        $toTs = strtotime($toDate . ' +1 day');

        $inRange = array_filter($edges, function ($edge) use ($fromTs, $toTs) {
            $ts = $edge['node']['taken_at_timestamp'] ?? null;
            return $ts && $ts >= $fromTs && $ts < $toTs;
        });
        $postCount = count($inRange);

        $totalEngagement = 0;
        foreach ($inRange as $edge) {
            $node = $edge['node'];
            $totalEngagement += (int)($node['edge_media_preview_like']['count'] ?? 0)
                              + (int)($node['edge_media_to_comment']['count'] ?? 0);
        }
        $avgEngagement = $postCount > 0 ? round($totalEngagement / $postCount, 2) : 0;

        return [
            'success' => true,
            'count' => $postCount,
            'avg_engagement' => $avgEngagement,
        ];
    }
}
