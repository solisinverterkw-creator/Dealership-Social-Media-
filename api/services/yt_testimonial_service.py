import re
import requests
import xml.etree.ElementTree as ET
from datetime import datetime, timedelta
from api.config import Config

class YtTestimonialService:
    def __init__(self):
        self.base_url = "https://www.googleapis.com/youtube/v3"

    def get_api_key(self):
        return Config.get_key('youtube_api_key', 'YOUTUBE_API_KEY')

    def get_gemini_key(self):
        return Config.get_key('gemini_api_key', 'GEMINI_API_KEY')

    def get_channel_videos_for_month(self, channel_id: str, month: str) -> list:
        """
        Fetches all videos uploaded in the given month (e.g., '2026-10') for a channel.
        Returns a list of dicts: [{'video_id': ..., 'title': ..., 'description': ..., 'published_at': ...}]
        """
        api_key = self.get_api_key()
        if not api_key or not channel_id:
            return []

        try:
            start_dt = datetime.strptime(month + '-01', '%Y-%m-%d')
            # Calculate next month start
            if start_dt.month == 12:
                next_month = start_dt.replace(year=start_dt.year + 1, month=1)
            else:
                next_month = start_dt.replace(month=start_dt.month + 1)
            
            published_after = start_dt.strftime('%Y-%m-%dT00:00:00Z')
            published_before = next_month.strftime('%Y-%m-%dT00:00:00Z')
        except Exception:
            return []

        videos = []
        page_token = None
        max_pages = 3  # cap at 150 videos per month

        for _ in range(max_pages):
            params = {
                'part': 'snippet',
                'channelId': channel_id,
                'type': 'video',
                'order': 'date',
                'publishedAfter': published_after,
                'publishedBefore': published_before,
                'maxResults': 50,
                'key': api_key
            }
            if page_token:
                params['pageToken'] = page_token

            try:
                res = requests.get(f"{self.base_url}/search", params=params, timeout=15)
                if res.status_code != 200:
                    break
                data = res.json()
                items = data.get('items', [])
                for item in items:
                    v_id = item.get('id', {}).get('videoId')
                    snippet = item.get('snippet', {})
                    if v_id:
                        videos.append({
                            'video_id': v_id,
                            'title': snippet.get('title', ''),
                            'description': snippet.get('description', ''),
                            'published_at': snippet.get('publishedAt', '')
                        })
                page_token = data.get('nextPageToken')
                if not page_token:
                    break
            except Exception:
                break

        return videos

    def fetch_video_transcript(self, video_id: str) -> str:
        """
        Attempts to fetch auto-generated or manual subtitles for a YouTube video.
        Uses YouTube's publicly available timedtext API without third-party heavy dependencies.
        """
        try:
            # 1. Fetch video watch page to locate timedtext / captions track
            headers = {
                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
            }
            watch_url = f"https://www.youtube.com/watch?v={video_id}"
            res = requests.get(watch_url, headers=headers, timeout=10)
            if res.status_code != 200:
                return ""

            html = res.text
            # Look for captions json inside ytInitialPlayerResponse
            match = re.search(r'"captionTracks":\s*(\[.*?\])', html)
            if not match:
                return ""

            import json
            caption_tracks = json.loads(match.group(1))
            if not caption_tracks:
                return ""

            # Prefer Urdu or English or the first available
            selected_track_url = caption_tracks[0].get('baseUrl')
            for track in caption_tracks:
                lang = track.get('languageCode', '').lower()
                if lang in ('ur', 'en', 'hi'):
                    selected_track_url = track.get('baseUrl')
                    break

            if not selected_track_url:
                return ""

            # Fetch the XML transcript
            sub_res = requests.get(selected_track_url, headers=headers, timeout=10)
            if sub_res.status_code != 200:
                return ""

            # Parse XML
            root = ET.fromstring(sub_res.text)
            lines = []
            for child in root.iter('text'):
                if child.text:
                    clean_line = child.text.strip().replace('&#39;', "'").replace('&quot;', '"')
                    lines.append(clean_line)

            return " ".join(lines)
        except Exception:
            return ""

    CUSTOMER_REVIEW_INDICATORS = [
        'customer review', 'customer feedback', 'owner review', 'customer remarks',
        'testimonial', 'delivery review', 'customer experience', 'delivery feedback',
        'customer reaction', 'customer interview', 'client review', 'client feedback',
        'client remarks', 'customer point of view', 'customer views', 'customer saying',
        'customer talk', 'customer handover review', 'buyers review', 'buyer review'
    ]

    def verify_customer_remarks_with_gemini(self, title: str, description: str, transcript: str, car_model: str) -> dict:
        """
        Evaluates whether the video contains genuine customer feedback/remarks for the given car model.
        Returns: {'verified': bool, 'reason': str}
        """
        combined_text = (title + " " + description).lower()
        if transcript:
            combined_text += " " + transcript.lower()

        has_direct_indicator = any(ind in combined_text for ind in self.CUSTOMER_REVIEW_INDICATORS)

        gemini_key = self.get_gemini_key()
        if gemini_key:
            # Prompt Gemini Flash
            prompt = f"""
You are an AI Compliance Auditor for Suzuki Dealerships.
We are auditing a YouTube video for Suzuki {car_model}.

CRITERIA:
1. The video must feature a real CUSTOMER giving feedback / review / remarks / testimonial regarding the car (e.g. driving experience, satisfaction, handover remarks, delivery feedback).
2. Videos whose Title or Description explicitly indicate "Customer Review", "Customer Feedback", "Customer Remarks", "Owner Review", or customer interview remarks must be ACCEPTED (verified: true).
3. If the video is purely a sales pitch / walkaround by showroom staff, stock display event, or general ceremony with no customer review/feedback, it must be REJECTED (verified: false).
4. Note: Dealership videos in Pakistan are spoken in Urdu/local languages and YouTube rarely generates automatic subtitles. Do NOT reject solely because auto-subtitles are unavailable if the video title/description indicates a customer review.

VIDEO DATA:
- Title: {title}
- Description: {description}
- Spoken Subtitles/Transcript: {transcript if transcript else '[Audio spoken in Urdu/local language - YouTube subtitles not available]'}

Analyze this video details carefully.
Reply strictly in JSON format:
{{
  "verified": true or false,
  "reason": "1 short sentence in English or Urdu explaining why it passed or failed"
}}
"""
            models = ['gemini-3.8-flash', 'gemini-2.5-flash', 'gemini-2.0-flash', 'gemini-1.5-flash', 'gemini-pro']
            for model in models:
                url = f"https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent?key={gemini_key}"
                payload = {
                    "contents": [{"parts": [{"text": prompt}]}],
                    "generationConfig": {"temperature": 0.1, "maxOutputTokens": 200}
                }
                try:
                    res = requests.post(url, json=payload, timeout=15)
                    if res.status_code == 200:
                        import json
                        text = res.json()['candidates'][0]['content']['parts'][0]['text']
                        clean_json = re.search(r'\{.*\}', text, re.DOTALL)
                        if clean_json:
                            parsed = json.loads(clean_json.group(0))
                            return {
                                'verified': bool(parsed.get('verified', False)),
                                'reason': str(parsed.get('reason', 'Evaluation complete.'))
                            }
                except Exception:
                    continue

        # Rule-based fallback if Gemini API is unreachable or rate-limited
        if has_direct_indicator:
            return {
                'verified': True,
                'reason': f'Verified: Video titled & presented as genuine customer review/feedback for Suzuki {car_model}.'
            }
        else:
            return {
                'verified': False,
                'reason': f'Rejected: Video appears to be a promotional or walkaround showcase without customer remarks.'
            }

    def evaluate_dealership_for_month(self, dealership_id: int, channel_id: str, month: str) -> dict:
        """
        Full evaluation for Fronx and Every videos for the dealership in the target month.
        Prioritizes candidate videos that contain customer review indicators.
        """
        videos = self.get_channel_videos_for_month(channel_id, month)

        fronx_candidates = []
        every_candidates = []

        for v in videos:
            text = (v['title'] + " " + v['description']).lower()
            if 'fronx' in text:
                fronx_candidates.append(v)
            if 'every' in text:
                every_candidates.append(v)

        def score_candidate(v):
            txt = (v['title'] + " " + v['description']).lower()
            score = 0
            if any(k in txt for k in self.CUSTOMER_REVIEW_INDICATORS):
                score += 10
            elif any(k in txt for k in ['customer', 'review', 'feedback', 'remarks', 'interview', 'point of view', 'delivery']):
                score += 5
            return score

        fronx_candidates.sort(key=score_candidate, reverse=True)
        every_candidates.sort(key=score_candidate, reverse=True)

        # Evaluate Fronx
        fronx_res = {'status': 'missing', 'video_id': None, 'video_title': None, 'video_url': None, 'reason': 'No Fronx video uploaded this month.'}
        for v in fronx_candidates:
            v_id = v['video_id']
            transcript = self.fetch_video_transcript(v_id)
            eval_res = self.verify_customer_remarks_with_gemini(v['title'], v['description'], transcript, 'Fronx')
            
            # Record current candidate
            fronx_res['video_id'] = v_id
            fronx_res['video_title'] = v['title']
            fronx_res['video_url'] = f"https://www.youtube.com/watch?v={v_id}"
            fronx_res['status'] = 'verified' if eval_res['verified'] else 'rejected'
            fronx_res['reason'] = eval_res['reason']

            # If verified, stop at best matching verified video
            if eval_res['verified']:
                break

        # Evaluate Every
        every_res = {'status': 'missing', 'video_id': None, 'video_title': None, 'video_url': None, 'reason': 'No Every video uploaded this month.'}
        for v in every_candidates:
            v_id = v['video_id']
            transcript = self.fetch_video_transcript(v_id)
            eval_res = self.verify_customer_remarks_with_gemini(v['title'], v['description'], transcript, 'Every')
            
            # Record current candidate
            every_res['video_id'] = v_id
            every_res['video_title'] = v['title']
            every_res['video_url'] = f"https://www.youtube.com/watch?v={v_id}"
            every_res['status'] = 'verified' if eval_res['verified'] else 'rejected'
            every_res['reason'] = eval_res['reason']

            # If verified, stop at best matching verified video
            if eval_res['verified']:
                break

        # Calculate compliance score
        score = 0
        if fronx_res['status'] == 'verified':
            score += 50
        if every_res['status'] == 'verified':
            score += 50

        return {
            'dealership_id': dealership_id,
            'month': month,
            'fronx': fronx_res,
            'every': every_res,
            'compliance_score': score
        }

