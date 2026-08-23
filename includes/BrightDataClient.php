<?php
require_once __DIR__ . '/../config.php';

/**
 * Thin client for Bright Data's Datasets API (Facebook scrapers). Tries the
 * synchronous /scrape endpoint first (fast path, resolves within its own
 * ~60s internal timeout for most single-page requests); if Bright Data can't
 * finish in time it returns HTTP 202 + a snapshot_id instead, and this falls
 * back to trigger-and-poll (checked every 5s) until the job is ready.
 */
class BrightDataClient
{
    private string $baseUrl = 'https://api.brightdata.com/datasets/v3';

    private function headers(): array
    {
        return [
            'Authorization: Bearer ' . BRIGHTDATA_API_TOKEN,
            'Content-Type: application/json',
        ];
    }

    /**
     * Bright Data returns a bare JSON object (not wrapped in an array) when
     * exactly one record matches (e.g. a date-filtered range with one post) —
     * normalize so callers always get a list of records, never a single
     * record's fields misread as a list of one-field-each "rows".
     */
    private function normalizeRecords($data): array
    {
        if (!is_array($data)) {
            return [];
        }
        return array_is_list($data) ? $data : [$data];
    }

    /**
     * $inputs: array of per-URL input objects, e.g. [['url' => '...', 'num_of_posts' => 10]].
     * Returns ['success' => bool, 'data' => array] on success (data = list of result records),
     * or ['success' => false, 'message' => string] on failure.
     */
    public function scrape(string $datasetId, array $inputs, int $maxWaitSeconds = 150): array
    {
        $ch = curl_init("{$this->baseUrl}/scrape?dataset_id=" . urlencode($datasetId) . "&notify=false&include_errors=true");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers());
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['input' => $inputs]));
        // Bright Data's own internal switch-to-async threshold is ~60s — this
        // needs real headroom above that, or a slightly-slow ack races our
        // timeout and the request aborts with no response at all (curl error,
        // HTTP code 0) instead of landing on the 202 + snapshot_id fallback below.
        curl_setopt($ch, CURLOPT_TIMEOUT, 100);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            return ['success' => true, 'data' => $this->normalizeRecords($data)];
        }

        if ($httpCode === 202) {
            $decoded = json_decode($response, true);
            $snapshotId = $decoded['snapshot_id'] ?? null;
            if (!$snapshotId) {
                return ['success' => false, 'message' => 'Bright Data Queued This Job But Returned No snapshot_id.'];
            }
            return $this->pollAndDownload($snapshotId, $maxWaitSeconds);
        }

        $detail = $curlError !== '' ? $curlError : mb_substr((string)$response, 0, 300);
        return ['success' => false, 'message' => "Bright Data HTTP {$httpCode}: {$detail}"];
    }

    private function pollAndDownload(string $snapshotId, int $maxWaitSeconds): array
    {
        $waited = 0;
        $interval = 5;

        while ($waited < $maxWaitSeconds) {
            sleep($interval);
            $waited += $interval;

            $ch = curl_init("{$this->baseUrl}/progress/{$snapshotId}");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers());
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $response = curl_exec($ch);
            curl_close($ch);
            $status = json_decode($response, true)['status'] ?? null;

            if ($status === 'ready') {
                $ch = curl_init("{$this->baseUrl}/snapshot/{$snapshotId}?format=json");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers());
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                $snapshotResponse = curl_exec($ch);
                curl_close($ch);
                $data = json_decode($snapshotResponse, true);
                return ['success' => true, 'data' => $this->normalizeRecords($data)];
            }
            if ($status === 'failed') {
                return ['success' => false, 'message' => 'Bright Data Job Failed.'];
            }
            // "collecting" / "running" — keep polling.
        }

        return ['success' => false, 'message' => "Timed Out Waiting For Bright Data After {$maxWaitSeconds}s."];
    }
}
