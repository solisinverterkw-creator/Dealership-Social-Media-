<?php

/**
 * Tiny file-based job status store for the refresh_fb.php/refresh_ig.php
 * background-continuation pattern. LiteSpeed kills any single HTTP request
 * after roughly two minutes, but Bright Data can take several minutes to
 * respond — so those endpoints answer immediately and keep working
 * server-side afterward, writing progress here for refresh_status.php to
 * poll from the frontend.
 */
class RefreshStatus
{
    private static function path(string $metric, $id): string
    {
        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$id);
        return __DIR__ . "/../assets/uploads/refresh_status/{$metric}_{$safeId}.json";
    }

    public static function start(string $metric, $id): void
    {
        $dir = dirname(self::path($metric, $id));
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(self::path($metric, $id), json_encode(['status' => 'running', 'started_at' => time()]));
    }

    public static function finish(string $metric, $id, array $data): void
    {
        file_put_contents(self::path($metric, $id), json_encode(array_merge(['status' => 'done'], $data)));
    }

    public static function fail(string $metric, $id, string $message, array $extra = []): void
    {
        file_put_contents(self::path($metric, $id), json_encode(array_merge(['status' => 'error', 'message' => $message], $extra)));
    }

    public static function read(string $metric, $id): ?array
    {
        $path = self::path($metric, $id);
        if (!file_exists($path)) {
            return null;
        }
        return json_decode(file_get_contents($path), true);
    }
}
