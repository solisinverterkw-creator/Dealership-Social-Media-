<?php
require_once __DIR__ . '/../config.php';

class YouTubeLookup
{
    private string $baseUrl = "https://www.googleapis.com/youtube/v3";

    public function resolveChannelId(string $channelName): ?string
    {
        $searchParams = [
            'part' => 'snippet',
            'q' => $channelName,
            'type' => 'channel',
            'maxResults' => 1,
            'key' => YOUTUBE_API_KEY,
        ];
        $searchUrl = $this->baseUrl . "/search?" . http_build_query($searchParams);
        $searchResponse = @file_get_contents($searchUrl);
        $searchData = json_decode($searchResponse, true) ?? [];

        if (empty($searchData['items'])) {
            return null;
        }

        return $searchData['items'][0]['snippet']['channelId'];
    }

    /**
     * If $channelId is already known (cached in the DB), it's reused directly —
     * searching by name again can match a slightly different channel each time.
     */
    private function resolveChannel(string $channelName, ?string $channelId): ?string
    {
        return $channelId ?: $this->resolveChannelId($channelName);
    }

    public function searchAndGetStats(string $channelName, ?string $channelId = null): array
    {
        if (empty(trim($channelName)) && empty($channelId)) {
            return ['success' => false, 'message' => 'YouTube Search Name Is Empty.'];
        }

        $resolvedId = $this->resolveChannel($channelName, $channelId);
        if (!$resolvedId) {
            return ['success' => false, 'message' => 'Channel Not Found.'];
        }

        $statsParams = [
            'part' => 'statistics',
            'id' => $resolvedId,
            'key' => YOUTUBE_API_KEY,
        ];
        $statsUrl = $this->baseUrl . "/channels?" . http_build_query($statsParams);
        $statsResponse = @file_get_contents($statsUrl);
        $statsData = json_decode($statsResponse, true) ?? [];
        $stats = $statsData['items'][0]['statistics'] ?? [];

        return [
            'success' => true,
            'channel_id' => $resolvedId,
            'subscribers' => (int)($stats['subscriberCount'] ?? 0),
            'total_views' => (int)($stats['viewCount'] ?? 0),
            'total_videos' => (int)($stats['videoCount'] ?? 0),
        ];
    }

    public function countThisMonth(string $channelName, ?string $channelId = null): array
    {
        if (empty(trim($channelName)) && empty($channelId)) {
            return ['success' => false, 'message' => 'YouTube Search Name Is Empty.'];
        }

        $resolvedId = $this->resolveChannel($channelName, $channelId);
        if (!$resolvedId) {
            return ['success' => false, 'message' => 'Channel Not Found.'];
        }

        // Current calendar month (local date), not a rolling 30-day window.
        $publishedAfter = date('Y-m-01') . 'T00:00:00Z';

        $params = [
            'part' => 'id',
            'channelId' => $resolvedId,
            'type' => 'video',
            'order' => 'date',
            'publishedAfter' => $publishedAfter,
            'maxResults' => 50,
            'key' => YOUTUBE_API_KEY,
        ];
        $url = $this->baseUrl . "/search?" . http_build_query($params);
        $response = @file_get_contents($url);
        $data = json_decode($response, true) ?? [];

        if (isset($data['error'])) {
            return ['success' => false, 'message' => $data['error']['message'] ?? 'YouTube API error.'];
        }

        return [
            'success' => true,
            'channel_id' => $resolvedId,
            'count' => count($data['items'] ?? []),
        ];
    }

    public function getMonthlyBreakdown(string $channelName, string $fromDate, string $toDate, ?string $channelId = null): array
    {
        if (empty(trim($channelName)) && empty($channelId)) {
            return ['success' => false, 'message' => 'YouTube Search Name Is Empty.'];
        }

        $resolvedId = $this->resolveChannel($channelName, $channelId);
        if (!$resolvedId) {
            return ['success' => false, 'message' => 'Channel Not Found.'];
        }

        $publishedAfter = gmdate('Y-m-d\T00:00:00\Z', strtotime($fromDate));
        $publishedBefore = gmdate('Y-m-d\T23:59:59\Z', strtotime($toDate));

        $publishedDates = [];
        $pageToken = null;
        $pagesFetched = 0;
        $maxPages = 5; // cost cap: 5 * 100 quota units per breakdown

        do {
            $params = [
                'part' => 'snippet',
                'channelId' => $resolvedId,
                'type' => 'video',
                'order' => 'date',
                'publishedAfter' => $publishedAfter,
                'publishedBefore' => $publishedBefore,
                'maxResults' => 50,
                'key' => YOUTUBE_API_KEY,
            ];
            if ($pageToken) {
                $params['pageToken'] = $pageToken;
            }
            $url = $this->baseUrl . "/search?" . http_build_query($params);
            $response = @file_get_contents($url);
            $data = json_decode($response, true) ?? [];

            if (isset($data['error'])) {
                return ['success' => false, 'message' => $data['error']['message'] ?? 'YouTube API error.'];
            }

            foreach (($data['items'] ?? []) as $item) {
                $publishedDates[] = $item['snippet']['publishedAt'] ?? null;
            }

            $pageToken = $data['nextPageToken'] ?? null;
            $pagesFetched++;
        } while ($pageToken && $pagesFetched < $maxPages);

        $months = [];
        $cursor = new DateTime(substr($fromDate, 0, 7) . '-01');
        $end = new DateTime(substr($toDate, 0, 7) . '-01');
        while ($cursor <= $end) {
            $months[$cursor->format('Y-m')] = 0;
            $cursor->modify('+1 month');
        }

        foreach ($publishedDates as $date) {
            if (!$date) continue;
            $key = substr($date, 0, 7);
            if (isset($months[$key])) {
                $months[$key]++;
            }
        }

        return [
            'success' => true,
            'channel_id' => $resolvedId,
            'breakdown' => $months,
            'truncated' => $pageToken !== null,
        ];
    }
}
