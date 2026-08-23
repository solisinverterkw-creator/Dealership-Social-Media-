<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/BrightDataClient.php';

class FacebookPostsLookup
{
    public function extractPageUrl(string $input): string
    {
        $input = trim($input);
        if (str_contains($input, 'facebook.com')) {
            if (str_contains($input, 'profile.php')) {
                return $input;
            }
            $path = trim(parse_url($input, PHP_URL_PATH), '/');
            $parts = explode('/', $path);
            return "https://www.facebook.com/" . $parts[0] . "/";
        }
        return "https://www.facebook.com/" . ltrim($input, '@') . "/";
    }

    /** Bright Data expects start_date/end_date as MM-DD-YYYY, we use Y-m-d everywhere else. */
    private function toBrightDataDate(string $ymd): string
    {
        return date('m-d-Y', strtotime($ymd));
    }

    /**
     * One Bright Data request per call — either resolves synchronously (~most
     * single-page jobs finish inside their own internal timeout) or transparently
     * falls back to poll-until-ready via BrightDataClient. Returns the raw list
     * of post records on success.
     *
     * Bright Data intermittently comes back with zero records for a page/range
     * that demonstrably has posts (observed directly: the exact same query,
     * unchanged input, flip-flopping between a real count and 0 across
     * back-to-back calls — and the flakiness can span several consecutive
     * empty results before returning real data on a later try). A real "0
     * posts in range" is comparatively rare, so retrying an empty result a
     * few times, with a short pause between tries so a bad window has a
     * chance to clear, catches this without meaningfully increasing cost on
     * the common (non-empty) case.
     */
    private function fetchPosts(string $pageUrl, array $extra = []): array
    {
        $client = new BrightDataClient();
        $input = [array_merge(['url' => $pageUrl], $extra)];
        $result = $client->scrape(BRIGHTDATA_DATASET_PAGE_POSTS, $input);

        if (!$result['success']) {
            return ['success' => false, 'message' => $result['message']];
        }

        for ($attempt = 0; $attempt < 4 && empty($result['data']); $attempt++) {
            sleep(3);
            $retry = $client->scrape(BRIGHTDATA_DATASET_PAGE_POSTS, $input);
            if ($retry['success'] && !empty($retry['data'])) {
                $result = $retry;
            }
        }

        return ['success' => true, 'posts' => $result['data']];
    }

    /**
     * Bright Data returns direct, ready-to-use attachment URLs and a rich set
     * of post + page fields in one record (no separate profile lookup needed).
     *
     * When a dealership does a native Facebook "Share" WITH their own caption
     * on top (not a bare, textless share), Bright Data exposes the original
     * shared post's own text separately under 'original_post.content' — 'content'
     * at the top level is the dealer's own caption, not the source post's text.
     * Surfacing this lets matching check both, or a captioned reshare looks
     * exactly like fresh organic content and gets wrongly reported as missed.
     */
    private function mapPost(array $post): array
    {
        $imageUrl = null;
        $videoUrl = null;
        foreach ($post['attachments'] ?? [] as $att) {
            if (($att['type'] ?? '') === 'Video' && !$videoUrl) {
                $videoUrl = $att['video_url'] ?? $att['url'] ?? null;
            } elseif (!$imageUrl && !empty($att['url'])) {
                $imageUrl = $att['thumbnail_url'] ?? $att['url'] ?? null;
            }
        }
        return [
            'id' => (string)($post['post_id'] ?? ''),
            'message' => $post['content'] ?? '',
            'image_url' => $imageUrl,
            'video_url' => $videoUrl,
            'created_time' => !empty($post['date_posted']) ? date('c', strtotime($post['date_posted'])) : null,
            'source_url' => $post['url'] ?? null,
            'original_post_message' => $post['original_post']['content'] ?? null,
        ];
    }

    /**
     * Fetches raw recent posts (message + image + timestamp + id), newest first.
     * Used for source-page monitoring (Content Syndication), not engagement math.
     * Budget: 1 Bright Data request.
     */
    public function getRecentPosts(string $pageInput, int $limit = 10, ?string $cachedPageId = null): array
    {
        if (empty(trim($pageInput))) {
            return ['success' => false, 'message' => 'Facebook Input Is Empty.'];
        }

        $pageUrl = $this->extractPageUrl($pageInput);
        $result = $this->fetchPosts($pageUrl, ['num_of_posts' => $limit]);
        if (!$result['success']) {
            return $result;
        }

        $posts = $result['posts'];
        $pageId = $posts[0]['profile_id'] ?? $cachedPageId;
        $formatted = array_map([$this, 'mapPost'], array_slice($posts, 0, $limit));

        return ['success' => true, 'posts' => $formatted, 'page_id' => $pageId];
    }

    /**
     * Graph API post IDs look like "{page-id}_{post-id}" (or bare video IDs);
     * ScrapeCreators' post id is always the bare numeric ID — normalize by
     * stripping any "pageid_" prefix so the two can be compared.
     */
    private function normalizePostId(string $id): string
    {
        $parts = explode('_', $id);
        return end($parts);
    }

    /**
     * Lowercase, whitespace-collapsed, first-60-chars fingerprint — used to spot
     * posts whose text matches a known Suzuki Pakistan source post, even when the
     * dealership copied/reshared it themselves outside our own publish pipeline
     * (so there's no post_log row to match by ID).
     */
    private function normalizeTextForMatch(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        return mb_substr($text, 0, 60);
    }

    /**
     * Checks a dealership's own (scraped) page for reshares of known source posts,
     * by the same text-fingerprint used elsewhere to spot reshares posted outside
     * our publish pipeline. Used for daily reshare-compliance tracking, not the
     * Content Syndication publish pipeline — works whether or not that pipeline
     * is even in use for this dealership.
     * $sourcePosts: list of ['id' => source_post_id, 'snippet' => message text/snippet].
     * Budget: 1 request per dealership per run.
     */
    public function checkReshares(string $pageInput, array $sourcePosts, ?string $cachedPageId = null): array
    {
        if (empty(trim($pageInput))) {
            return ['success' => false, 'message' => 'Facebook Input Is Empty.'];
        }

        $pageUrl = $this->extractPageUrl($pageInput);
        $result = $this->fetchPosts($pageUrl, ['num_of_posts' => 15]);
        if (!$result['success']) {
            return $result;
        }

        $posts = $result['posts'];
        $pageId = $posts[0]['profile_id'] ?? $cachedPageId;

        $dealershipFingerprints = [];
        foreach ($posts as $p) {
            $dealershipFingerprints[] = $this->normalizeTextForMatch($p['content'] ?? '');
            // Share WITH the dealer's own caption: 'content' is their caption,
            // not the source text — the shared post's own text lives separately
            // under 'original_post.content' on the raw Bright Data record.
            $dealershipFingerprints[] = $this->normalizeTextForMatch($p['original_post']['content'] ?? '');
        }
        $dealershipFingerprints = array_filter($dealershipFingerprints);

        $matches = [];
        foreach ($sourcePosts as $sourcePost) {
            $fingerprint = $this->normalizeTextForMatch($sourcePost['snippet'] ?? '');
            $matches[$sourcePost['id']] = $fingerprint !== '' && in_array($fingerprint, $dealershipFingerprints, true);
        }

        return ['success' => true, 'matches' => $matches, 'page_id' => $pageId];
    }

    /**
     * Pure text-fingerprint + timing matching over already-fetched post lists —
     * no API calls. Used with getPostsInRange() results (fully paginated over a
     * date range) instead of checkReshares()'s single-page snapshot, which
     * misses reshares that have scrolled past the dealership's most recent
     * ~10-15 posts.
     *
     * Text match alone misses native Facebook "Share" (the Share button without
     * adding your own caption) — that produces a post with an EMPTY text field,
     * since Facebook doesn't copy the original caption into the sharer's post.
     * So an empty-text dealership post published within $shareWindowHours of a
     * tracked source post is also treated as a probable reshare of it (a timing
     * heuristic, not a certainty — but far better than assuming "no text = own
     * content", which is backwards for native shares).
     *
     * It also misses a Share WITH the dealer's own caption on top — that has a
     * non-empty 'message' (the dealer's caption, not the source text), so
     * neither the text match nor the empty-text timing heuristic catches it.
     * mapPost() surfaces the source post's actual text separately under
     * 'original_post_message' for exactly this case — check that too.
     *
     * $dealershipPosts: getPostsInRange()-shaped posts (keys 'message', 'created_time', 'original_post_message').
     * $sourcePosts: list of ['id' => source_post_id, 'snippet' => message text, 'published_at' => datetime].
     */
    public function matchPostsAgainstSourcePosts(array $dealershipPosts, array $sourcePosts, int $shareWindowHours = 48): array
    {
        $fingerprintsFor = fn(array $p): array => array_filter([
            $this->normalizeTextForMatch($p['message'] ?? ''),
            $this->normalizeTextForMatch($p['original_post_message'] ?? ''),
        ], fn($fp) => $fp !== '');

        $dealershipFingerprints = [];
        foreach ($dealershipPosts as $p) {
            array_push($dealershipFingerprints, ...$fingerprintsFor($p));
        }

        $emptyTextPostTimestamps = array_filter(array_map(
            fn($p) => ($this->normalizeTextForMatch($p['message'] ?? '') === '' && !empty($p['created_time']))
                ? strtotime($p['created_time'])
                : null,
            $dealershipPosts
        ));

        $isTimingMatch = function (?string $publishedAt) use ($emptyTextPostTimestamps, $shareWindowHours): bool {
            if (empty($publishedAt)) {
                return false;
            }
            $sourceTs = strtotime($publishedAt);
            foreach ($emptyTextPostTimestamps as $t) {
                if ($t >= $sourceTs && $t <= $sourceTs + $shareWindowHours * 3600) {
                    return true;
                }
            }
            return false;
        };

        $matches = [];
        foreach ($sourcePosts as $sourcePost) {
            $fingerprint = $this->normalizeTextForMatch($sourcePost['snippet'] ?? '');
            $textMatch = $fingerprint !== '' && in_array($fingerprint, $dealershipFingerprints, true);
            $matches[$sourcePost['id']] = $textMatch || $isTimingMatch($sourcePost['published_at'] ?? null);
        }

        // "Own" posts = dealership-page posts that don't text-match (as own
        // caption OR as the source text behind a captioned share) AND aren't
        // an empty-text post that timing-matches some tracked source post.
        $sourceFingerprints = array_filter(array_map(
            fn($p) => $this->normalizeTextForMatch($p['snippet'] ?? ''),
            $sourcePosts
        ));
        $ownCount = 0;
        foreach ($dealershipPosts as $p) {
            $textMatch = !empty(array_intersect($fingerprintsFor($p), $sourceFingerprints));
            $timingMatch = false;
            if ($this->normalizeTextForMatch($p['message'] ?? '') === '' && !empty($p['created_time'])) {
                $postTs = strtotime($p['created_time']);
                foreach ($sourcePosts as $sp) {
                    if (empty($sp['published_at'])) {
                        continue;
                    }
                    $sourceTs = strtotime($sp['published_at']);
                    if ($postTs >= $sourceTs && $postTs <= $sourceTs + $shareWindowHours * 3600) {
                        $timingMatch = true;
                        break;
                    }
                }
            }
            if (!$textMatch && !$timingMatch) {
                $ownCount++;
            }
        }

        return [
            'matches' => $matches,
            'own_count' => $ownCount,
            'total_dealership_posts' => count($dealershipPosts),
        ];
    }

    /**
     * Returns the full formatted post objects (message/image/video/etc.) for a
     * date range — used to browse the source page by date range rather than
     * just "most recent N posts." Bright Data filters server-side via
     * start_date/end_date, so this is a single request (no manual pagination);
     * $maxPosts is just a generous cap in case the range covers an unusually
     * active page.
     */
    public function getPostsInRange(string $pageInput, string $fromDate, string $toDate, ?string $cachedPageId = null, int $maxPosts = 200): array
    {
        if (empty(trim($pageInput))) {
            return ['success' => false, 'message' => 'Facebook Input Is Empty.'];
        }

        $pageUrl = $this->extractPageUrl($pageInput);
        $fromTs = strtotime($fromDate);
        $toTs = strtotime($toDate . ' +1 day');

        $result = $this->fetchPosts($pageUrl, [
            'num_of_posts' => $maxPosts,
            'start_date' => $this->toBrightDataDate($fromDate),
            'end_date' => $this->toBrightDataDate($toDate),
        ]);
        if (!$result['success']) {
            return $result;
        }

        $allPosts = $result['posts'];
        $pageId = $allPosts[0]['profile_id'] ?? $cachedPageId;

        // Bright Data's date filter is a courtesy, not a hard guarantee — keep a
        // local safety filter so a slightly-off boundary can't leak an out-of-range post in.
        $inRange = array_filter($allPosts, function ($post) use ($fromTs, $toTs) {
            $ts = !empty($post['date_posted']) ? strtotime($post['date_posted']) : 0;
            return $ts && $ts >= $fromTs && $ts < $toTs;
        });

        $formatted = array_map([$this, 'mapPost'], array_values($inRange));

        return ['success' => true, 'posts' => $formatted, 'page_id' => $pageId];
    }

    /**
     * Bright Data filters server-side via start_date/end_date, so this is a
     * single request for an accurate count in the range — no manual pagination.
     * $maxPosts is a generous safety cap, not the normal stop condition.
     * $excludePostIds: fb_post_id values (from post_log) to leave out of the count —
     * used so posts we auto-published via Content Syndication aren't counted as the
     * dealership's own organic posting activity.
     * $excludeTextSnippets: known Suzuki Pakistan source-post text snippets (from
     * processed_source_posts) — catches reshares the dealership posted themselves,
     * outside our publish pipeline, which have no post_log row to match by ID.
     */
    public function countInRange(string $pageInput, string $fromDate, string $toDate, ?string $cachedPageId = null, array $excludePostIds = [], int $maxPosts = 200, array $excludeTextSnippets = []): array
    {
        if (empty(trim($pageInput))) {
            return ['success' => false, 'message' => 'Facebook Input Is Empty.'];
        }

        $pageUrl = $this->extractPageUrl($pageInput);
        $fromTs = strtotime($fromDate);
        $toTs = strtotime($toDate . ' +1 day');

        $result = $this->fetchPosts($pageUrl, [
            'num_of_posts' => $maxPosts,
            'start_date' => $this->toBrightDataDate($fromDate),
            'end_date' => $this->toBrightDataDate($toDate),
        ]);
        if (!$result['success']) {
            return $result;
        }

        $allPosts = $result['posts'];
        $pageId = $allPosts[0]['profile_id'] ?? $cachedPageId;

        $excludeNormalized = array_map([$this, 'normalizePostId'], $excludePostIds);
        $excludeTextNormalized = array_filter(array_map([$this, 'normalizeTextForMatch'], $excludeTextSnippets));

        $inRange = array_filter($allPosts, function ($post) use ($fromTs, $toTs, $excludeNormalized, $excludeTextNormalized) {
            $ts = !empty($post['date_posted']) ? strtotime($post['date_posted']) : 0;
            if (!$ts || $ts < $fromTs || $ts >= $toTs) {
                return false;
            }
            $postId = (string)($post['post_id'] ?? '');
            if ($postId && in_array($this->normalizePostId($postId), $excludeNormalized, true)) {
                return false;
            }
            $postText = $this->normalizeTextForMatch($post['content'] ?? '');
            if ($postText !== '' && in_array($postText, $excludeTextNormalized, true)) {
                return false;
            }
            // A Share WITH the dealer's own caption on top has 'content' equal
            // to their caption, not the source text — check the shared post's
            // own text (Bright Data's 'original_post.content') too, or this
            // counts as organic activity instead of a reshare.
            $originalPostText = $this->normalizeTextForMatch($post['original_post']['content'] ?? '');
            if ($originalPostText !== '' && in_array($originalPostText, $excludeTextNormalized, true)) {
                return false;
            }
            return true;
        });
        $postCount = count($inRange);

        $totalEngagement = 0;
        foreach ($inRange as $post) {
            $totalEngagement += (int)($post['likes'] ?? 0) + (int)($post['num_comments'] ?? 0);
        }
        $avgEngagement = $postCount > 0 ? round($totalEngagement / $postCount, 2) : 0;

        return [
            'success' => true,
            'count' => $postCount,
            'avg_engagement' => $avgEngagement,
            'page_id' => $pageId,
        ];
    }
}
