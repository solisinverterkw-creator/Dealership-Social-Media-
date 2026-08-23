<?php
require_once __DIR__ . '/../config.php';

class GoogleReviewLookup
{
    private string $host = "local-business-data.p.rapidapi.com";

    public function searchAndGetReviews(string $businessName): array
    {
        if (empty(trim($businessName))) {
            return ['success' => false, 'message' => 'Google Search Name Is Empty.'];
        }

        $params = [
            'query' => $businessName,
            'limit' => 1,
            'country' => 'pk',
            'language' => 'en',
        ];
        $url = "https://{$this->host}/search?" . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'x-rapidapi-key: ' . RAPIDAPI_KEY,
            'x-rapidapi-host: ' . $this->host,
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        if ($httpCode !== 200 || !is_array($data)) {
            return ['success' => false, 'message' => 'No Response From RapidAPI (HTTP ' . $httpCode . ').'];
        }

        $item = $data['data'][0] ?? null;
        if (!$item) {
            return ['success' => false, 'message' => 'Business Not Found.'];
        }

        return [
            'success' => true,
            'rating' => (float)($item['rating'] ?? 0),
            'review_count' => (int)($item['review_count'] ?? 0),
        ];
    }
}
