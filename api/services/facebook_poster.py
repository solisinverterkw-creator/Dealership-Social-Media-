import re
import urllib.parse
import requests
from api.config import Config

class FacebookPoster:
    def __init__(self):
        # Default FB Graph API version from config, e.g. "v19.0"
        self.base_url = f"https://graph.facebook.com/{Config.FB_GRAPH_API_VERSION}"

    def _request(self, method: str, path: str, params: dict) -> dict:
        url = f"{self.base_url}{path}"
        try:
            if method == 'GET':
                res = requests.get(url, params=params, timeout=30)
            else:
                res = requests.post(url, data=params, timeout=30)
            return {'httpCode': res.status_code, 'data': res.json()}
        except Exception as e:
            return {'httpCode': 500, 'data': {'error': {'message': str(e)}}}

    def get_page_followers(self, page_id: str, token: str) -> dict:
        result = self._request('GET', f"/{page_id}", {
            'fields': 'followers_count,fan_count,name',
            'access_token': token
        })

        if result['httpCode'] != 200 or 'id' not in result['data']:
            msg = result['data'].get('error', {}).get('message') or f"HTTP {result['httpCode']}"
            return {'success': False, 'message': msg}

        followers = int(result['data'].get('followers_count') or result['data'].get('fan_count') or 0)
        return {
            'success': True,
            'followers': followers,
            'name': result['data'].get('name')
        }

    def get_instagram_followers(self, page_id: str, token: str, cached_ig_id: str = None) -> dict:
        ig_id = cached_ig_id
        if not ig_id:
            link_result = self._request('GET', f"/{page_id}", {
                'fields': 'instagram_business_account',
                'access_token': token
            })
            ig_id = link_result.get('data', {}).get('instagram_business_account', {}).get('id')
            if not ig_id:
                return {'success': False, 'message': 'No Instagram Business Account Linked To This Facebook Page.'}

        result = self._request('GET', f"/{ig_id}", {
            'fields': 'followers_count,username',
            'access_token': token
        })

        if result['httpCode'] != 200 or 'id' not in result['data']:
            msg = result['data'].get('error', {}).get('message') or f"HTTP {result['httpCode']}"
            return {'success': False, 'message': msg}

        return {
            'success': True,
            'followers': int(result['data'].get('followers_count') or 0),
            'ig_business_account_id': ig_id,
            'username': result['data'].get('username')
        }

    def get_recent_posts(self, page_id: str, token: str, limit: int = 10) -> dict:
        result = self._request('GET', f"/{page_id}/posts", {
            'fields': 'id,message,full_picture,created_time',
            'limit': limit,
            'access_token': token
        })

        if result['httpCode'] != 200 or 'data' not in result['data']:
            msg = result['data'].get('error', {}).get('message') or f"HTTP {result['httpCode']}"
            return {'success': False, 'message': msg}

        return {'success': True, 'posts': result['data']['data']}

    def get_post(self, post_id: str, token: str) -> dict:
        result = self._request('GET', f"/{post_id}", {
            'fields': 'message,full_picture,created_time',
            'access_token': token
        })

        if result['httpCode'] != 200 or 'error' in result['data']:
            msg = result['data'].get('error', {}).get('message') or f"HTTP {result['httpCode']}"
            return {'success': False, 'message': msg}

        return {
            'success': True,
            'message': result['data'].get('message', ''),
            'image_url': result['data'].get('full_picture')
        }

    def publish_to_page(self, page_id: str, token: str, message: str, image_url: str = None, video_url: str = None) -> dict:
        if video_url:
            result = self._request('POST', f"/{page_id}/videos", {
                'file_url': video_url,
                'description': message,
                'access_token': token
            })
            post_id_field = 'id'
        elif image_url:
            result = self._request('POST', f"/{page_id}/photos", {
                'url': image_url,
                'caption': message,
                'access_token': token
            })
            post_id_field = 'post_id'
        else:
            result = self._request('POST', f"/{page_id}/feed", {
                'message': message,
                'access_token': token
            })
            post_id_field = 'id'

        if result['httpCode'] != 200 or not result['data'].get(post_id_field):
            msg = result['data'].get('error', {}).get('message') or f"HTTP {result['httpCode']}"
            return {'success': False, 'message': msg}

        return {'success': True, 'fb_post_id': result['data'][post_id_field]}

    def exchange_for_long_lived_token(self, short_lived_token: str) -> dict:
        result = self._request('GET', '/oauth/access_token', {
            'grant_type': 'fb_exchange_token',
            'client_id': Config.FB_APP_ID,
            'client_secret': Config.FB_APP_SECRET,
            'fb_exchange_token': short_lived_token
        })

        if result['httpCode'] != 200 or not result['data'].get('access_token'):
            msg = result['data'].get('error', {}).get('message') or f"HTTP {result['httpCode']}"
            return {'success': False, 'message': msg}

        return {'success': True, 'long_lived_token': result['data']['access_token']}

    def get_page_access_token(self, page_id: str, long_lived_user_token: str) -> dict:
        result = self._request('GET', f"/{page_id}", {
            'fields': 'access_token,name',
            'access_token': long_lived_user_token
        })

        if result['httpCode'] != 200 or not result['data'].get('access_token'):
            msg = result['data'].get('error', {}).get('message') or f"HTTP {result['httpCode']}"
            return {'success': False, 'message': msg}

        return {
            'success': True,
            'page_access_token': result['data']['access_token'],
            'name': result['data'].get('name')
        }

    def extract_post_id(self, url: str) -> str:
        url = url.strip()

        # https://www.facebook.com/{page}/posts/{post_id}
        m = re.search(r'/posts/([a-zA-Z0-9]+)', url)
        if m:
            return m.group(1)

        # https://www.facebook.com/{page}/videos/{video_id}
        m = re.search(r'/videos/(\d+)', url)
        if m:
            return m.group(1)

        # https://www.facebook.com/reel/{reel_id}
        m = re.search(r'/reel/(\d+)', url)
        if m:
            return m.group(1)

        # story / permalink
        parsed = urllib.parse.urlparse(url)
        params = urllib.parse.parse_qs(parsed.query)
        if 'story_fbid' in params and 'id' in params:
            return f"{params['id'][0]}_{params['story_fbid'][0]}"

        return None
