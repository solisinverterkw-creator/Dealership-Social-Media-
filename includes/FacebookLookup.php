<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/BrightDataClient.php';

class FacebookLookup
{
    public function extractPageUrl(string $input): string
    {
        $input = trim($input);
        if (str_contains($input, 'facebook.com')) {
            // profile.php?id=... links store the real ID in the query string,
            // not the path — keep the URL as-is instead of extracting a path segment.
            if (str_contains($input, 'profile.php')) {
                return $input;
            }
            $path = trim(parse_url($input, PHP_URL_PATH), '/');
            $parts = explode('/', $path);
            return "https://www.facebook.com/" . $parts[0];
        }
        return "https://www.facebook.com/" . ltrim($input, '@');
    }

    public function getFollowerCount(string $pageInput): array
    {
        if (empty(trim($pageInput))) {
            return ['success' => false, 'message' => 'Facebook Input Is Empty.'];
        }

        $pageUrl = $this->extractPageUrl($pageInput);
        $client = new BrightDataClient();
        $result = $client->scrape(BRIGHTDATA_DATASET_PAGE_INFO, [['url' => $pageUrl]]);

        if (!$result['success']) {
            return ['success' => false, 'message' => $result['message']];
        }

        $row = $result['data'][0] ?? null;
        if (!$row) {
            return ['success' => false, 'message' => 'Bright Data Returned No Data For This Page.'];
        }

        return [
            'success' => true,
            'followers' => (int)($row['followers'] ?? 0),
            'page_id' => $row['id'] ?? null,
            'name' => $row['page_name'] ?? null,
        ];
    }
}
