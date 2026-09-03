import re
import urllib.parse
from datetime import datetime, timedelta
import requests
from api.config import Config
from api.services.bright_data import BrightDataClient

def parse_scraped_number(val):
    if val is None:
        return 0
    if isinstance(val, (int, float)):
        return int(val)
    s = str(val).strip().replace(',', '').upper()
    if not s:
        return 0
    try:
        if 'K' in s:
            return int(float(s.replace('K', '')) * 1000)
        if 'M' in s:
            return int(float(s.replace('M', '')) * 1000000)
        return int(float(s))
    except Exception:
        return 0

class FacebookLookup:
    def extract_page_url(self, input_str: str) -> str:
        input_str = input_str.strip()
        if not input_str:
            return ""
        if 'facebook.com' in input_str.lower():
            if not input_str.startswith('http://') and not input_str.startswith('https://'):
                input_str = 'https://' + input_str
            return input_str.rstrip('/') + '/'
        clean = input_str.lstrip('@').strip('/')
        return f"https://www.facebook.com/{clean}/"

    def get_follower_count(self, page_input: str) -> dict:
        if not page_input or not page_input.strip():
            return {'success': False, 'message': 'Facebook Input Is Empty.'}

        page_url = self.extract_page_url(page_input)
        client = BrightDataClient()
        result = client.scrape(Config.BRIGHTDATA_DATASET_PAGE_INFO, [{'url': page_url}])

        if not result['success']:
            return {'success': False, 'message': result['message']}

        if result.get('queued'):
            return {'success': True, 'queued': True, 'followers': 0, 'message': 'Scraping job queued on Bright Data.'}

        row = result['data'][0] if (result.get('data') and isinstance(result['data'], list) and len(result['data']) > 0) else None
        if not row or not isinstance(row, dict):
            return {'success': False, 'message': 'Bright Data Returned No Data For This Page.'}

        followers_raw = row.get('followers') or row.get('followers_count') or row.get('likes') or row.get('fan_count') or row.get('likes_count') or 0
        followers = parse_scraped_number(followers_raw)

        return {
            'success': True,
            'followers': followers,
            'page_id': row.get('id') or row.get('page_id'),
            'name': row.get('page_name') or row.get('name')
        }

class FacebookPostsLookup:
    def extract_page_url(self, input_str: str) -> str:
        input_str = input_str.strip()
        if 'facebook.com' in input_str:
            if 'profile.php' in input_str:
                return input_str
            parsed = urllib.parse.urlparse(input_str)
            path = parsed.path.strip('/')
            parts = [p for p in path.split('/') if p]
            if parts:
                return f"https://www.facebook.com/{parts[0]}/"
            return input_str
        return f"https://www.facebook.com/{input_str.lstrip('@')}/"

    def to_bright_data_date(self, ymd: str) -> str:
        try:
            dt = datetime.strptime(ymd, '%Y-%m-%d')
            return dt.strftime('%m-%d-%Y')
        except Exception:
            return ymd

    def fetch_posts(self, page_url: str, extra: dict = None) -> dict:
        if extra is None:
            extra = {}
        client = BrightDataClient()
        inp = {'url': page_url}
        inp.update(extra)
        inputs = [inp]

        result = client.scrape(Config.BRIGHTDATA_DATASET_PAGE_POSTS, inputs)
        if not result['success']:
            return {'success': False, 'message': result['message']}

        # Bright Data flakiness retry
        for attempt in range(4):
            if result['data']:
                break
            time.sleep(3)
            retry = client.scrape(Config.BRIGHTDATA_DATASET_PAGE_POSTS, inputs)
            if retry['success'] and retry['data']:
                result = retry
                break

        return {'success': True, 'posts': result['data']}

    def map_post(self, post: dict) -> dict:
        image_url = None
        video_url = None
        for att in post.get('attachments', []):
            att_type = att.get('type')
            if att_type == 'Video' and not video_url:
                video_url = att.get('video_url') or att.get('url')
            elif not image_url and att.get('url'):
                image_url = att.get('thumbnail_url') or att.get('url')

        created_time = None
        date_posted = post.get('date_posted')
        if date_posted:
            try:
                # Basic ISO format or string datetime parse
                dt = datetime.fromisoformat(date_posted.replace('Z', '+00:00'))
                created_time = dt.isoformat()
            except Exception:
                created_time = date_posted

        return {
            'id': str(post.get('post_id') or ''),
            'message': post.get('content') or '',
            'image_url': image_url,
            'video_url': video_url,
            'created_time': created_time,
            'source_url': post.get('url'),
            'original_post_message': post.get('original_post', {}).get('content') if post.get('original_post') else None
        }

    def get_recent_posts(self, page_input: str, limit: int = 10, cached_page_id: str = None) -> dict:
        if not page_input or not page_input.strip():
            return {'success': False, 'message': 'Facebook Input Is Empty.'}

        page_url = self.extract_page_url(page_input)
        result = self.fetch_posts(page_url, {'num_of_posts': limit})
        if not result['success']:
            return result

        posts = result['posts']
        page_id = posts[0].get('profile_id') if posts else cached_page_id
        formatted = [self.map_post(p) for p in posts[:limit]]

        return {'success': True, 'posts': formatted, 'page_id': page_id}

    def normalize_post_id(self, post_id: str) -> str:
        if not post_id:
            return ''
        parts = post_id.split('_')
        return parts[-1]

    def normalize_text_for_match(self, text: str) -> str:
        if not text:
            return ''
        text = text.strip().lower()
        text = re.sub(r'\s+', ' ', text)
        return text[:60]

    def check_reshares(self, page_input: str, source_posts: list, cached_page_id: str = None) -> dict:
        if not page_input or not page_input.strip():
            return {'success': False, 'message': 'Facebook Input Is Empty.'}

        page_url = self.extract_page_url(page_input)
        result = self.fetch_posts(page_url, {'num_of_posts': 15})
        if not result['success']:
            return result

        posts = result['posts']
        page_id = posts[0].get('profile_id') if posts else cached_page_id

        dealership_fingerprints = []
        for p in posts:
            content = p.get('content') or ''
            orig_content = p.get('original_post', {}).get('content') if p.get('original_post') else ''
            if content:
                dealership_fingerprints.append(self.normalize_text_for_match(content))
            if orig_content:
                dealership_fingerprints.append(self.normalize_text_for_match(orig_content))
        dealership_fingerprints = [fp for fp in dealership_fingerprints if fp]

        matches = {}
        for sp in source_posts:
            fingerprint = self.normalize_text_for_match(sp.get('snippet') or '')
            matches[sp['id']] = (fingerprint != '' and fingerprint in dealership_fingerprints)

        return {'success': True, 'matches': matches, 'page_id': page_id}

    def match_posts_against_source_posts(self, dealership_posts: list, source_posts: list, share_window_hours: int = 48) -> dict:
        def fingerprints_for(p):
            res = []
            m = p.get('message') or ''
            om = p.get('original_post_message') or ''
            if m:
                res.append(self.normalize_text_for_match(m))
            if om:
                res.append(self.normalize_text_for_match(om))
            return [fp for fp in res if fp]

        dealership_fingerprints = []
        for p in dealership_posts:
            dealership_fingerprints.extend(fingerprints_for(p))

        empty_text_post_timestamps = []
        for p in dealership_posts:
            if self.normalize_text_for_match(p.get('message') or '') == '' and p.get('created_time'):
                try:
                    dt = datetime.fromisoformat(p['created_time'].replace('Z', '+00:00'))
                    empty_text_post_timestamps.append(dt.timestamp())
                except Exception:
                    pass

        def is_timing_match(published_at):
            if not published_at:
                return False
            try:
                source_dt = datetime.fromisoformat(published_at.replace('Z', '+00:00'))
                source_ts = source_dt.timestamp()
                for t in empty_text_post_timestamps:
                    if source_ts <= t <= source_ts + share_window_hours * 3600:
                        return True
            except Exception:
                pass
            return False

        matches = {}
        for sp in source_posts:
            fingerprint = self.normalize_text_for_match(sp.get('snippet') or '')
            text_match = fingerprint != '' and fingerprint in dealership_fingerprints
            timing_match = is_timing_match(sp.get('published_at'))
            matches[sp['id']] = text_match or timing_match

        source_fingerprints = []
        for sp in source_posts:
            fp = self.normalize_text_for_match(sp.get('snippet') or '')
            if fp:
                source_fingerprints.append(fp)

        own_count = 0
        for p in dealership_posts:
            p_fps = fingerprints_for(p)
            text_match = bool(set(p_fps) & set(source_fingerprints))
            timing_match = False
            if self.normalize_text_for_match(p.get('message') or '') == '' and p.get('created_time'):
                try:
                    post_dt = datetime.fromisoformat(p['created_time'].replace('Z', '+00:00'))
                    post_ts = post_dt.timestamp()
                    for sp in source_posts:
                        if not sp.get('published_at'):
                            continue
                        src_dt = datetime.fromisoformat(sp['published_at'].replace('Z', '+00:00'))
                        src_ts = src_dt.timestamp()
                        if src_ts <= post_ts <= src_ts + share_window_hours * 3600:
                            timing_match = True
                            break
                except Exception:
                    pass
            if not text_match and not timing_match:
                own_count += 1

        return {
            'matches': matches,
            'own_count': own_count,
            'total_dealership_posts': len(dealership_posts)
        }

    def get_posts_in_range(self, page_input: str, from_date: str, to_date: str, cached_page_id: str = None, max_posts: int = 200) -> dict:
        if not page_input or not page_input.strip():
            return {'success': False, 'message': 'Facebook Input Is Empty.'}

        page_url = self.extract_page_url(page_input)
        start_date = self.to_bright_data_date(from_date)
        end_date = self.to_bright_data_date(to_date)

        result = self.fetch_posts(page_url, {
            'start_date': start_date,
            'end_date': end_date,
            'num_of_posts': max_posts
        })
        if not result['success']:
            return result

        posts = result['posts']
        page_id = posts[0].get('profile_id') if posts else cached_page_id
        formatted = [self.map_post(p) for p in posts]
        return {'success': True, 'posts': formatted, 'page_id': page_id}

    def count_in_range(self, page_input: str, from_date: str, to_date: str, cached_page_id: str = None, exclude_post_ids: list = None, max_posts: int = 200, exclude_text_snippets: list = None) -> dict:
        if exclude_post_ids is None:
            exclude_post_ids = []
        if exclude_text_snippets is None:
            exclude_text_snippets = []

        if not page_input or not page_input.strip():
            return {'success': False, 'message': 'Facebook Input Is Empty.'}

        page_url = self.extract_page_url(page_input)
        
        try:
            from_dt = datetime.strptime(from_date, '%Y-%m-%d')
            to_dt = datetime.strptime(to_date, '%Y-%m-%d') + timedelta(days=1)
            from_ts = from_dt.timestamp()
            to_ts = to_dt.timestamp()
        except Exception:
            return {'success': False, 'message': 'Invalid Date Format.'}

        result = self.fetch_posts(page_url, {
            'num_of_posts': max_posts,
            'start_date': self.to_bright_data_date(from_date),
            'end_date': self.to_bright_data_date(to_date)
        })
        if not result['success']:
            return result

        all_posts = result['posts']
        page_id = all_posts[0].get('profile_id') if all_posts else cached_page_id

        exclude_normalized = [self.normalize_post_id(pid) for pid in exclude_post_ids]
        exclude_text_normalized = [self.normalize_text_for_match(txt) for txt in exclude_text_snippets if txt]

        in_range = []
        for post in all_posts:
            date_posted = post.get('date_posted')
            if not date_posted:
                continue
            try:
                dt = datetime.fromisoformat(date_posted.replace('Z', '+00:00'))
                ts = dt.timestamp()
            except Exception:
                try:
                    dt = datetime.strptime(date_posted, '%Y-%m-%d %H:%M:%S')
                    ts = dt.timestamp()
                except Exception:
                    continue

            if not (from_ts <= ts < to_ts):
                continue

            post_id = str(post.get('post_id') or '')
            if post_id and self.normalize_post_id(post_id) in exclude_normalized:
                continue

            post_text = self.normalize_text_for_match(post.get('content') or '')
            if post_text and post_text in exclude_text_normalized:
                continue

            orig_post_text = self.normalize_text_for_match(post.get('original_post', {}).get('content') or '')
            if orig_post_text and orig_post_text in exclude_text_normalized:
                continue

            in_range.append(post)

        post_count = len(in_range)
        total_engagement = 0
        for post in in_range:
            likes = int(post.get('likes') or 0)
            comments = int(post.get('num_comments') or 0)
            total_engagement += (likes + comments)

        avg_engagement = round(total_engagement / post_count, 2) if post_count > 0 else 0.0

        return {
            'success': True,
            'count': post_count,
            'avg_engagement': avg_engagement,
            'page_id': page_id
        }

class InstagramLookup:

    def extract_username(self, input_str: str) -> str:
        input_str = input_str.strip()
        if 'instagram.com' in input_str:
            parsed = urllib.parse.urlparse(input_str)
            path = parsed.path.strip('/')
            parts = [p for p in path.split('/') if p]
            if parts:
                return parts[0]
            return input_str
        return input_str.lstrip('@')

    def fetch_profile(self, username: str) -> dict:
        url = f"https://api.scrapecreators.com/v1/instagram/profile?handle={urllib.parse.quote(username)}"
        headers = {'x-api-key': Config.SCRAPECREATORS_API_KEY_INSTAGRAM}
        try:
            res = requests.get(url, headers=headers, timeout=30)
            return {'httpCode': res.status_code, 'data': res.json()}
        except Exception as e:
            return {'httpCode': 500, 'data': {'success': False, 'message': str(e)}}

    def error_message(self, result: dict) -> str:
        if result['httpCode'] != 200 or not result.get('data', {}).get('success'):
            api_msg = result.get('data', {}).get('message') or result.get('data', {}).get('error')
            return f"Instagram API Error: {api_msg}" if api_msg else f"Instagram API HTTP {result['httpCode']}."
        return 'Profile Not Found.'

    def get_follower_count(self, profile_input: str) -> dict:
        if not profile_input or not profile_input.strip():
            return {'success': False, 'message': 'Instagram Input Is Empty.'}

        username = self.extract_username(profile_input)
        profile_url = f"https://www.instagram.com/{username}/"

        client = BrightDataClient()
        result = client.scrape(Config.BRIGHTDATA_DATASET_INSTAGRAM_PROFILE, [{'url': profile_url}])

        if not result['success']:
            return {'success': False, 'message': result['message']}

        if result.get('queued'):
            return {'success': True, 'queued': True, 'followers': 0, 'message': 'Scraping job queued on Bright Data.'}

        row = result['data'][0] if result['data'] else None
        if not row:
            return {'success': False, 'message': 'Bright Data Returned No Data For This Profile.'}

        return {
            'success': True,
            'followers': int(row.get('followers') or 0)
        }

    def count_in_range(self, profile_input: str, from_date: str, to_date: str) -> dict:
        if not profile_input or not profile_input.strip():
            return {'success': False, 'message': 'Instagram Input Is Empty.'}

        username = self.extract_username(profile_input)
        result = self.fetch_profile(username)

        if result['httpCode'] != 200 or not result.get('data', {}).get('success') or 'user' not in result.get('data', {}).get('data', {}):
            return {'success': False, 'message': self.error_message(result)}

        edges = result['data']['data']['user'].get('edge_owner_to_timeline_media', {}).get('edges', [])
        
        try:
            from_dt = datetime.strptime(from_date, '%Y-%m-%d')
            to_dt = datetime.strptime(to_date, '%Y-%m-%d') + timedelta(days=1)
            from_ts = from_dt.timestamp()
            to_ts = to_dt.timestamp()
        except Exception:
            return {'success': False, 'message': 'Invalid Date Format.'}

        in_range = []
        for edge in edges:
            ts = edge.get('node', {}).get('taken_at_timestamp')
            if ts and from_ts <= ts < to_ts:
                in_range.append(edge)

        post_count = len(in_range)
        total_engagement = 0
        for edge in in_range:
            node = edge.get('node', {})
            likes = int(node.get('edge_media_preview_like', {}).get('count') or 0)
            comments = int(node.get('edge_media_to_comment', {}).get('count') or 0)
            total_engagement += (likes + comments)

        avg_engagement = round(total_engagement / post_count, 2) if post_count > 0 else 0.0

        return {
            'success': True,
            'count': post_count,
            'avg_engagement': avg_engagement
        }

class YouTubeLookup:
    def __init__(self):
        self.base_url = "https://www.googleapis.com/youtube/v3"

    def resolve_channel_id(self, channel_name: str) -> str:
        if not channel_name:
            return None
        clean = channel_name.strip()
        if clean.startswith('UC') and len(clean) == 24:
            return clean
        search_params = {
            'part': 'snippet',
            'q': clean,
            'type': 'channel',
            'maxResults': 1,
            'key': Config.get_key('youtube_api_key', 'YOUTUBE_API_KEY')
        }
        try:
            res = requests.get(f"{self.base_url}/search", params=search_params, timeout=10)
            if res.status_code == 200:
                items = res.json().get('items', [])
                if items:
                    return items[0]['snippet']['channelId']
        except Exception:
            pass
        return None

    def resolve_channel(self, channel_name: str, channel_id: str = None) -> str:
        return channel_id if channel_id else self.resolve_channel_id(channel_name)

    def search_and_get_stats(self, channel_name: str, channel_id: str = None) -> dict:
        if not channel_name.strip() and not channel_id:
            return {'success': False, 'message': 'YouTube Search Name Is Empty.'}

        resolved_id = self.resolve_channel(channel_name, channel_id)
        if not resolved_id:
            return {'success': False, 'message': 'Channel Not Found.'}

        stats_params = {
            'part': 'statistics',
            'id': resolved_id,
            'key': Config.get_key('youtube_api_key', 'YOUTUBE_API_KEY')
        }
        try:
            res = requests.get(f"{self.base_url}/channels", params=stats_params, timeout=20)
            if res.status_code == 200:
                items = res.json().get('items', [])
                if items:
                    stats = items[0].get('statistics', {})
                    return {
                        'success': True,
                        'channel_id': resolved_id,
                        'subscribers': int(stats.get('subscriberCount') or 0),
                        'total_views': int(stats.get('viewCount') or 0),
                        'total_videos': int(stats.get('videoCount') or 0)
                    }
        except Exception as e:
            return {'success': False, 'message': f"Request error: {str(e)}"}
        
        return {'success': False, 'message': 'Failed to retrieve stats.'}

    def count_this_month(self, channel_name: str, channel_id: str = None) -> dict:
        if not channel_name.strip() and not channel_id:
            return {'success': False, 'message': 'YouTube Search Name Is Empty.'}

        resolved_id = self.resolve_channel(channel_name, channel_id)
        if not resolved_id:
            return {'success': False, 'message': 'Channel Not Found.'}

        published_after = datetime.utcnow().replace(day=1, hour=0, minute=0, second=0, microsecond=0).isoformat() + 'Z'

        params = {
            'part': 'id',
            'channelId': resolved_id,
            'type': 'video',
            'order': 'date',
            'publishedAfter': published_after,
            'maxResults': 50,
            'key': Config.get_key('youtube_api_key', 'YOUTUBE_API_KEY')
        }
        try:
            res = requests.get(f"{self.base_url}/search", params=params, timeout=20)
            data = res.json()
            if 'error' in data:
                return {'success': False, 'message': data['error'].get('message') or 'YouTube API error.'}
            return {
                'success': True,
                'channel_id': resolved_id,
                'count': len(data.get('items', []))
            }
        except Exception as e:
            return {'success': False, 'message': f"Request error: {str(e)}"}

    def get_monthly_breakdown(self, channel_name: str, from_date: str, to_date: str, channel_id: str = None) -> dict:
        if not channel_name.strip() and not channel_id:
            return {'success': False, 'message': 'YouTube Search Name Is Empty.'}

        resolved_id = self.resolve_channel(channel_name, channel_id)
        if not resolved_id:
            return {'success': False, 'message': 'Channel Not Found.'}

        try:
            from_dt = datetime.strptime(from_date, '%Y-%m-%d')
            to_dt = datetime.strptime(to_date, '%Y-%m-%d') + timedelta(days=1)
            published_after = from_dt.strftime('%Y-%m-%dT00:00:00Z')
            published_before = to_dt.strftime('%Y-%m-%dT23:59:59Z')
        except Exception:
            return {'success': False, 'message': 'Invalid Date Format.'}

        published_dates = []
        page_token = None
        pages_fetched = 0
        max_pages = 5

        while True:
            params = {
                'part': 'snippet',
                'channelId': resolved_id,
                'type': 'video',
                'order': 'date',
                'publishedAfter': published_after,
                'publishedBefore': published_before,
                'maxResults': 50,
                'key': Config.get_key('youtube_api_key', 'YOUTUBE_API_KEY')
            }
            if page_token:
                params['pageToken'] = page_token

            try:
                res = requests.get(f"{self.base_url}/search", params=params, timeout=20)
                data = res.json()
                if 'error' in data:
                    return {'success': False, 'message': data['error'].get('message') or 'YouTube API error.'}

                for item in data.get('items', []):
                    pub_at = item.get('snippet', {}).get('publishedAt')
                    if pub_at:
                        published_dates.append(pub_at)

                page_token = data.get('nextPageToken')
                pages_fetched += 1
                if not page_token or pages_fetched >= max_pages:
                    break
            except Exception as e:
                return {'success': False, 'message': f"Request error: {str(e)}"}

        months = {}
        curr = datetime(from_dt.year, from_dt.month, 1)
        end = datetime(to_dt.year, to_dt.month, 1)
        while curr <= end:
            months[curr.strftime('%Y-%m')] = 0
            # Increment month
            if curr.month == 12:
                curr = datetime(curr.year + 1, 1, 1)
            else:
                curr = datetime(curr.year, curr.month + 1, 1)

        for date_str in published_dates:
            key = date_str[:7]
            if key in months:
                months[key] += 1

        return {
            'success': True,
            'channel_id': resolved_id,
            'breakdown': months,
            'truncated': page_token is not None
        }

class GoogleReviewLookup:
    def __init__(self):
        self.host = "local-business-data.p.rapidapi.com"

    def search_and_get_reviews(self, business_name: str) -> dict:
        if not business_name or not business_name.strip():
            return {'success': False, 'message': 'Google Search Name Is Empty.'}

        params = {
            'query': business_name,
            'limit': 1,
            'country': 'pk',
            'language': 'en'
        }
        url = f"https://{self.host}/search"
        headers = {
            'x-rapidapi-key': Config.RAPIDAPI_KEY,
            'x-rapidapi-host': self.host
        }
        try:
            res = requests.get(url, params=params, headers=headers, timeout=30)
            if res.status_code == 200:
                data = res.json()
                items = data.get('data', [])
                if items:
                    item = items[0]
                    return {
                        'success': True,
                        'rating': float(item.get('rating') or 0.0),
                        'review_count': int(item.get('review_count') or 0)
                    }
                return {'success': False, 'message': 'Business Not Found.'}
            return {'success': False, 'message': f"No Response From RapidAPI (HTTP {res.status_code})."}
        except Exception as e:
            return {'success': False, 'message': f"Request error: {str(e)}"}
