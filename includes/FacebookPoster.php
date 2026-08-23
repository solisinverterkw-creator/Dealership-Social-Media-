<?php
require_once __DIR__ . '/../config.php';

class FacebookPoster
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = "https://graph.facebook.com/" . FB_GRAPH_API_VERSION;
    }

    private function request(string $method, string $path, array $params): array
    {
        $url = "{$this->baseUrl}{$path}";
        $ch = curl_init();

        if ($method === 'GET') {
            curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
        } else {
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);
        return ['httpCode' => $httpCode, 'data' => $data];
    }

    /**
     * Official Graph API follower count — used for dealerships that have granted
     * real Page admin access (see dealerships.fb_page_access_token), instead of
     * scraping. No quota/blocking concerns like Bright Data/RapidAPI/ScrapeCreators.
     */
    public function getPageFollowers(string $pageId, string $token): array
    {
        $result = $this->request('GET', "/{$pageId}", [
            'fields' => 'followers_count,fan_count,name',
            'access_token' => $token,
        ]);

        if ($result['httpCode'] !== 200 || !isset($result['data']['id'])) {
            $message = $result['data']['error']['message'] ?? ('HTTP ' . $result['httpCode']);
            return ['success' => false, 'message' => $message];
        }

        return [
            'success' => true,
            'followers' => (int)($result['data']['followers_count'] ?? $result['data']['fan_count'] ?? 0),
            'name' => $result['data']['name'] ?? null,
        ];
    }

    /**
     * Instagram data goes through the linked Facebook Page's own token — no
     * separate Instagram login/token needed, as long as the IG account is a
     * Business/Creator account connected to this Page. $cachedIgId (dealerships.
     * ig_business_account_id) skips the resolve step once it's known.
     */
    public function getInstagramFollowers(string $pageId, string $token, ?string $cachedIgId = null): array
    {
        $igId = $cachedIgId;
        if (!$igId) {
            $linkResult = $this->request('GET', "/{$pageId}", [
                'fields' => 'instagram_business_account',
                'access_token' => $token,
            ]);
            $igId = $linkResult['data']['instagram_business_account']['id'] ?? null;
            if (!$igId) {
                return ['success' => false, 'message' => 'No Instagram Business Account Linked To This Facebook Page.'];
            }
        }

        $result = $this->request('GET', "/{$igId}", [
            'fields' => 'followers_count,username',
            'access_token' => $token,
        ]);

        if ($result['httpCode'] !== 200 || !isset($result['data']['id'])) {
            $message = $result['data']['error']['message'] ?? ('HTTP ' . $result['httpCode']);
            return ['success' => false, 'message' => $message];
        }

        return [
            'success' => true,
            'followers' => (int)($result['data']['followers_count'] ?? 0),
            'ig_business_account_id' => $igId,
            'username' => $result['data']['username'] ?? null,
        ];
    }

    public function getRecentPosts(string $pageId, string $token, int $limit = 10): array
    {
        $result = $this->request('GET', "/{$pageId}/posts", [
            'fields' => 'id,message,full_picture,created_time',
            'limit' => $limit,
            'access_token' => $token,
        ]);

        if ($result['httpCode'] !== 200 || !isset($result['data']['data'])) {
            $message = $result['data']['error']['message'] ?? ('HTTP ' . $result['httpCode']);
            return ['success' => false, 'message' => $message];
        }

        return ['success' => true, 'posts' => $result['data']['data']];
    }

    public function getPost(string $postId, string $token): array
    {
        $result = $this->request('GET', "/{$postId}", [
            'fields' => 'message,full_picture,created_time',
            'access_token' => $token,
        ]);

        if ($result['httpCode'] !== 200 || isset($result['data']['error'])) {
            $errorMessage = $result['data']['error']['message'] ?? ('HTTP ' . $result['httpCode']);
            return ['success' => false, 'message' => $errorMessage];
        }

        return [
            'success' => true,
            'message' => $result['data']['message'] ?? '',
            'image_url' => $result['data']['full_picture'] ?? null,
        ];
    }

    /**
     * Posting with 'link' set to the source post's own facebook.com permalink
     * (to get Facebook's embedded "shared post" card look) was tried and
     * reverted — Facebook rejects it with "must be granted before impersonating
     * a user's page" unless the Page Access Token carries extra permissions
     * (pages_read_engagement/pages_manage_metadata/etc.) that this token
     * doesn't have. Reshare-look posting goes through Zapier instead (see
     * send_to_zapier.php); this always posts as fresh content.
     */
    public function publishToPage(string $pageId, string $token, string $message, ?string $imageUrl, ?string $videoUrl = null): array
    {
        if (!empty($videoUrl)) {
            $result = $this->request('POST', "/{$pageId}/videos", [
                'file_url' => $videoUrl,
                'description' => $message,
                'access_token' => $token,
            ]);
            $postIdField = 'id';
        } elseif (!empty($imageUrl)) {
            $result = $this->request('POST', "/{$pageId}/photos", [
                'url' => $imageUrl,
                'caption' => $message,
                'access_token' => $token,
            ]);
            $postIdField = 'post_id';
        } else {
            $result = $this->request('POST', "/{$pageId}/feed", [
                'message' => $message,
                'access_token' => $token,
            ]);
            $postIdField = 'id';
        }

        if ($result['httpCode'] !== 200 || empty($result['data'][$postIdField])) {
            $errorMessage = $result['data']['error']['message'] ?? ('HTTP ' . $result['httpCode']);
            return ['success' => false, 'message' => $errorMessage];
        }

        return ['success' => true, 'fb_post_id' => $result['data'][$postIdField]];
    }

    /**
     * Exchanges a short-lived token (the one Graph API Explorer / a dealership's
     * login hands out, ~1-2hr life) for a long-lived one (~60 days). Needs
     * FB_APP_ID/FB_APP_SECRET since only the app that issued the original token
     * can request this exchange.
     */
    public function exchangeForLongLivedToken(string $shortLivedToken): array
    {
        $result = $this->request('GET', '/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => FB_APP_ID,
            'client_secret' => FB_APP_SECRET,
            'fb_exchange_token' => $shortLivedToken,
        ]);

        if ($result['httpCode'] !== 200 || empty($result['data']['access_token'])) {
            $message = $result['data']['error']['message'] ?? ('HTTP ' . $result['httpCode']);
            return ['success' => false, 'message' => $message];
        }

        return ['success' => true, 'long_lived_token' => $result['data']['access_token']];
    }

    /**
     * A Page token derived from a long-lived user token doesn't carry its own
     * expiry — it stays valid until the page role, app connection, or user
     * password changes. This is the "permanent" token target_pages.php expects.
     */
    public function getPageAccessToken(string $pageId, string $longLivedUserToken): array
    {
        $result = $this->request('GET', "/{$pageId}", [
            'fields' => 'access_token,name',
            'access_token' => $longLivedUserToken,
        ]);

        if ($result['httpCode'] !== 200 || empty($result['data']['access_token'])) {
            $message = $result['data']['error']['message'] ?? ('HTTP ' . $result['httpCode']);
            return ['success' => false, 'message' => $message];
        }

        return [
            'success' => true,
            'page_access_token' => $result['data']['access_token'],
            'name' => $result['data']['name'] ?? null,
        ];
    }

    /**
     * Parses common Facebook post URL shapes into a Graph API-usable ID.
     * Returns null if the URL doesn't match a known pattern.
     */
    public function extractPostId(string $url): ?string
    {
        $url = trim($url);

        // https://www.facebook.com/{page}/posts/{post_id}
        if (preg_match('#/posts/([a-zA-Z0-9]+)#', $url, $m)) {
            return $m[1];
        }

        // https://www.facebook.com/{page}/videos/{video_id}
        if (preg_match('#/videos/(\d+)#', $url, $m)) {
            return $m[1];
        }

        // https://www.facebook.com/reel/{reel_id}
        if (preg_match('#/reel/(\d+)#', $url, $m)) {
            return $m[1];
        }

        // https://www.facebook.com/story.php?story_fbid=X&id=Y  or  /permalink.php?story_fbid=X&id=Y
        $query = parse_url($url, PHP_URL_QUERY);
        if ($query) {
            parse_str($query, $params);
            if (!empty($params['story_fbid']) && !empty($params['id'])) {
                return $params['id'] . '_' . $params['story_fbid'];
            }
        }

        return null;
    }
}
