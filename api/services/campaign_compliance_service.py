import re
import io
import json
import base64
import requests
from datetime import datetime, timedelta
from PIL import Image
from api.config import Config
from api.database import db_session
from api.models import (
    Dealership, AppSetting, PostSubmission, PostLog,
    CampaignSourcePost, PostImageScanCache, CampaignComplianceRecord
)
from api.services.lookups import FacebookPostsLookup

class CampaignComplianceService:
    def __init__(self):
        self.models = ['gemini-2.5-flash', 'gemini-2.0-flash', 'gemini-1.5-flash', 'gemini-pro']
        
    def get_gemini_key(self):
        return Config.get_key('gemini_api_key', 'GEMINI_API_KEY')

    EXCHANGE_KEYWORDS = [
        'exchange', 'trade-in', 'trade in', 'tradein', 'exchange program', 'exchange plan',
        'car exchange', 'exchange offer', 'old car exchange', 'exchange gala',
        'exchange department', 'exchange scheme', 'exchange bonus', 'upgrade your car',
        'upgrade car', 'bring your old car', 'get new car', 'old car',
        'تبادلہ', 'ایکسچینج', 'پرانی کار', 'نئی کار'
    ]

    USEDCAR_KEYWORDS = [
        'certified used car', 'certified used cars', 'used car gala', 'used car',
        'used cars', 'certified pre-owned', 'certified car', 'used car certified',
        'certified vehicle', 'used car festival', 'used car warranty',
        'certified used', '130 check points', '130 checkpoints', '130 points',
        'certified by suzuki', 'quality tested',
        'سرٹیفائیڈ', 'یوزڈ کار'
    ]

    def has_exchange_keywords(self, text: str) -> bool:
        if not text:
            return False
        text_lower = text.lower()
        return any(k in text_lower for k in self.EXCHANGE_KEYWORDS)

    def has_usedcar_keywords(self, text: str) -> bool:
        if not text:
            return False
        text_lower = text.lower()
        return any(k in text_lower for k in self.USEDCAR_KEYWORDS)

    def scan_post_image_and_text(self, post_id: str, text: str = '', image_url: str = None) -> dict:
        """
        Reads post text and social media graphic image using Gemini Multimodal Vision.
        Extracts embedded headlines/text from the image graphic and identifies whether the post
        is promoting 'Exchange Program' or 'Certified Used Car'.
        Uses PostImageScanCache to prevent duplicate calls.
        """
        combined_text = (text or '').strip()
        text_is_exchange = self.has_exchange_keywords(combined_text)
        text_is_usedcar = self.has_usedcar_keywords(combined_text)

        # 1. Check database cache
        if post_id:
            cached = db_session.query(PostImageScanCache).filter(PostImageScanCache.post_id == str(post_id)).first()
            if cached:
                # If cached was positive, or if cached had already scanned an image, return cached
                if cached.is_exchange or cached.is_certified_used_car or (cached.image_url and not image_url):
                    return {
                        'is_exchange': bool(cached.is_exchange) or text_is_exchange,
                        'is_certified_used_car': bool(cached.is_certified_used_car) or text_is_usedcar,
                        'extracted_text': cached.extracted_text or '',
                        'reason': cached.classification_reason or ''
                    }

        extracted_text = ''
        vision_is_exchange = False
        vision_is_usedcar = False
        reason = ''

        # 2. If image_url is available, fetch image and scan with Gemini Vision
        gemini_key = self.get_gemini_key()
        if image_url and gemini_key:
            try:
                # Fetch image bytes (timeout 10s)
                img_res = requests.get(image_url, timeout=10, headers={'User-Agent': 'Mozilla/5.0'})
                if img_res.status_code == 200 and len(img_res.content) > 500:
                    raw_bytes = img_res.content
                    
                    # Resize and re-encode to compact JPEG
                    with Image.open(io.BytesIO(raw_bytes)) as img:
                        w, h = img.size
                        max_dim = 720
                        if w > max_dim or h > max_dim:
                            if w >= h:
                                new_w = max_dim
                                new_h = int(round((h / w) * max_dim))
                            else:
                                new_h = max_dim
                                new_w = int(round((w / h) * max_dim))
                            img = img.resize((new_w, new_h), Image.Resampling.LANCZOS)
                        if img.mode in ('RGBA', 'P'):
                            img = img.convert('RGB')
                        buf = io.BytesIO()
                        img.save(buf, format='JPEG', quality=80)
                        b64_data = base64.b64encode(buf.getvalue()).decode('utf-8')

                    prompt = f"""You are an AI Auditor for Suzuki Dealership social media marketing campaigns in Pakistan.
Carefully inspect this social media banner / graphic image and its caption:
Caption: "{combined_text}"

TASKS:
1. Read ALL visible headline text and banner labels written inside the image (both English and Urdu).
2. Check if this graphic specifically promotes:
   a) "Exchange" / "Trade-in" (e.g. Pak Suzuki Exchange Program, trade old car for a new one, exchange offer, exchange gala, تبادلہ).
   b) "Certified Used Car" (e.g. Suzuki Certified Used Cars, Used Car Gala, Certified pre-owned with warranty, سرٹیفائیڈ یوزڈ کار).

Reply STRICTLY in JSON:
{{
  "extracted_text": "all key headlines and text read from the graphic",
  "is_exchange": true or false,
  "is_certified_used_car": true or false,
  "reason": "1 short explanation sentence"
}}
"""
                    payload = {
                        "contents": [{
                            "parts": [
                                {"text": prompt},
                                {
                                    "inline_data": {
                                        "mime_type": "image/jpeg",
                                        "data": b64_data
                                    }
                                }
                            ]
                        }],
                        "generationConfig": {
                            "temperature": 0.1,
                            "maxOutputTokens": 300,
                            "response_mime_type": "application/json"
                        }
                    }

                    for model in self.models:
                        url = f"https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent?key={gemini_key}"
                        try:
                            g_res = requests.post(url, json=payload, timeout=20)
                            if g_res.status_code == 200:
                                res_json = g_res.json()
                                part_text = res_json['candidates'][0]['content']['parts'][0]['text']
                                clean_json = re.search(r'\{.*\}', part_text, re.DOTALL)
                                if clean_json:
                                    parsed = json.loads(clean_json.group(0))
                                    extracted_text = parsed.get('extracted_text', '')
                                    vision_is_exchange = bool(parsed.get('is_exchange', False))
                                    vision_is_usedcar = bool(parsed.get('is_certified_used_car', False))
                                    reason = parsed.get('reason', '')
                                    break
                        except Exception:
                            continue
            except Exception as e:
                reason = f"Image read error: {str(e)}"

        # Combine text keyword matching and vision classification
        is_exchange = text_is_exchange or vision_is_exchange
        is_usedcar = text_is_usedcar or vision_is_usedcar

        if not reason:
            reasons = []
            if is_exchange:
                reasons.append("Exchange keywords/graphic detected")
            if is_usedcar:
                reasons.append("Certified Used Car keywords/graphic detected")
            reason = " | ".join(reasons) if reasons else "No campaign keywords found"

        # Cache result in database
        if post_id:
            try:
                cached_rec = db_session.query(PostImageScanCache).filter(PostImageScanCache.post_id == str(post_id)).first()
                if not cached_rec:
                    cached_rec = PostImageScanCache(
                        post_id=str(post_id),
                        image_url=image_url[:1000] if image_url else None,
                        extracted_text=extracted_text[:1000] if extracted_text else None,
                        is_exchange=is_exchange,
                        is_certified_used_car=is_usedcar,
                        classification_reason=reason[:500] if reason else None
                    )
                    db_session.add(cached_rec)
                else:
                    cached_rec.is_exchange = is_exchange
                    cached_rec.is_certified_used_car = is_usedcar
                    if image_url:
                        cached_rec.image_url = image_url[:1000]
                    if extracted_text:
                        cached_rec.extracted_text = extracted_text[:1000]
                    if reason:
                        cached_rec.classification_reason = reason[:500]
                db_session.commit()
            except Exception:
                db_session.rollback()

        return {
            'is_exchange': is_exchange,
            'is_certified_used_car': is_usedcar,
            'extracted_text': extracted_text,
            'reason': reason
        }

    def refresh_source_posts_for_range(self, from_date: str, to_date: str) -> dict:
        """
        Fetches official Pak Suzuki source posts in range using Bright Data scraper
        and also scans existing ProcessedSourcePost records in range.
        Extracts image + text content to identify Exchange and Certified Used Car posts.
        """
        source_url = db_session.query(AppSetting.setting_value).filter(AppSetting.setting_key == 'source_page_url').scalar() or "https://www.facebook.com/SuzukiPakistan"
        source_id = db_session.query(AppSetting.setting_value).filter(AppSetting.setting_key == 'source_page_id').scalar() or "100069181887026"

        from_dt = datetime.strptime(from_date, '%Y-%m-%d')
        to_dt = datetime.strptime(to_date, '%Y-%m-%d') + timedelta(days=1)

        source_posts_to_process = []

        # 1. Fetch live from Bright Data scraper
        lookup = FacebookPostsLookup()
        res = lookup.get_posts_in_range(source_url, from_date, to_date, source_id, max_posts=100)
        if res.get('success') and res.get('posts'):
            source_posts_to_process.extend(res['posts'])

        # 2. ALSO check existing ProcessedSourcePost records in database for this range
        try:
            from api.models import ProcessedSourcePost
            existing_psp = db_session.query(ProcessedSourcePost).filter(
                ProcessedSourcePost.published_at >= from_dt,
                ProcessedSourcePost.published_at < to_dt,
                ~ProcessedSourcePost.source_post_id.like('suzuki_pk_official_%')
            ).all()
            for ep in existing_psp:
                source_posts_to_process.append({
                    'id': ep.source_post_id,
                    'message': ep.message_snippet or '',
                    'image_url': None,
                    'created_time': ep.published_at.isoformat() if ep.published_at else None,
                    'source_url': None
                })
        except Exception:
            pass

        exchange_count = 0
        usedcar_count = 0

        for post in source_posts_to_process:
            p_id = str(post.get('id') or '')
            if not p_id:
                continue
            message = post.get('message') or ''
            image_url = post.get('image_url')

            scan_res = self.scan_post_image_and_text(p_id, message, image_url)
            
            dt = None
            if post.get('created_time'):
                try:
                    dt = datetime.fromisoformat(post['created_time'].replace('Z', '+00:00'))
                except Exception:
                    pass
            if not dt:
                dt = datetime.utcnow()

            if scan_res['is_exchange']:
                sp = db_session.query(CampaignSourcePost).filter(
                    CampaignSourcePost.source_post_id == p_id,
                    CampaignSourcePost.category == 'exchange'
                ).first()
                if not sp:
                    sp = CampaignSourcePost(
                        source_post_id=p_id,
                        category='exchange',
                        message_snippet=message[:500],
                        image_url=image_url,
                        post_url=post.get('source_url'),
                        published_at=dt,
                        detected_keywords=scan_res.get('reason')
                    )
                    db_session.add(sp)
                    exchange_count += 1

            if scan_res['is_certified_used_car']:
                sp = db_session.query(CampaignSourcePost).filter(
                    CampaignSourcePost.source_post_id == p_id,
                    CampaignSourcePost.category == 'certified_used_car'
                ).first()
                if not sp:
                    sp = CampaignSourcePost(
                        source_post_id=p_id,
                        category='certified_used_car',
                        message_snippet=message[:500],
                        image_url=image_url,
                        post_url=post.get('source_url'),
                        published_at=dt,
                        detected_keywords=scan_res.get('reason')
                    )
                    db_session.add(sp)
                    usedcar_count += 1

        try:
            db_session.commit()
        except Exception:
            db_session.rollback()

        # Count total in range from DB
        tot_exchange = db_session.query(CampaignSourcePost).filter(
            CampaignSourcePost.category == 'exchange',
            CampaignSourcePost.published_at >= from_dt,
            CampaignSourcePost.published_at < to_dt
        ).count()
        tot_usedcar = db_session.query(CampaignSourcePost).filter(
            CampaignSourcePost.category == 'certified_used_car',
            CampaignSourcePost.published_at >= from_dt,
            CampaignSourcePost.published_at < to_dt
        ).count()

        return {
            'success': True,
            'exchange_tracked': tot_exchange,
            'usedcar_tracked': tot_usedcar,
            'new_exchange': exchange_count,
            'new_usedcar': usedcar_count
        }

    def evaluate_dealership(self, dealership_id: int, from_date: str, to_date: str) -> dict:
        """
        Scrapes dealership page posts in range with Bright Data, runs text + image OCR/vision,
        and determines:
        1. Dealership Own Content: Exchange (Uploaded / Not Uploaded) & Used Car (Uploaded / Not Uploaded)
        2. PSMCL Source Content: Exchange (Reshared / Missed) & Used Car (Reshared / Missed)
        """
        d = db_session.query(Dealership).filter(Dealership.id == dealership_id).first()
        if not d:
            return {'success': False, 'message': 'Dealership not found'}

        from_dt = datetime.strptime(from_date, '%Y-%m-%d')
        to_dt = datetime.strptime(to_date, '%Y-%m-%d') + timedelta(days=1)

        # 1. Fetch PSMCL source posts for this date range
        source_posts = db_session.query(CampaignSourcePost).filter(
            CampaignSourcePost.published_at >= from_dt,
            CampaignSourcePost.published_at < to_dt
        ).all()
        
        # If no source posts tracked yet for this date range, auto-refresh now
        if not source_posts:
            try:
                self.refresh_source_posts_for_range(from_date, to_date)
                source_posts = db_session.query(CampaignSourcePost).filter(
                    CampaignSourcePost.published_at >= from_dt,
                    CampaignSourcePost.published_at < to_dt
                ).all()
            except Exception as e:
                print(f"[CampaignCompliance] Auto-refresh source posts failed: {e}")

        exchange_sources = [sp for sp in source_posts if sp.category == 'exchange']
        usedcar_sources = [sp for sp in source_posts if sp.category == 'certified_used_car']

        # 2. Fetch dealership posts using Bright Data scraper
        lookup = FacebookPostsLookup()
        dealership_posts = []
        if d.fb_input:
            posts_res = lookup.get_posts_in_range(d.fb_input, from_date, to_date, d.fb_page_id, max_posts=100)
            if posts_res.get('success') and posts_res.get('posts'):
                dealership_posts.extend(posts_res['posts'])

        # Include approved PostSubmissions
        submissions = db_session.query(PostSubmission).filter(
            PostSubmission.dealership_id == dealership_id,
            PostSubmission.submitted_at >= from_dt,
            PostSubmission.submitted_at < to_dt
        ).all()
        for sub in submissions:
            dealership_posts.append({
                'id': f"sub_{sub.id}",
                'message': sub.caption or '',
                'image_url': sub.image_path if sub.image_path and sub.image_path.startswith('http') else None,
                'created_time': sub.submitted_at.isoformat() if sub.submitted_at else None,
                'source_url': None
            })

        # Include PostLog
        logs = db_session.query(PostLog).filter(
            PostLog.dealership_name == d.name,
            PostLog.posted_at >= from_dt,
            PostLog.posted_at < to_dt
        ).all()
        for log in logs:
            dealership_posts.append({
                'id': f"log_{log.id}",
                'message': log.message or '',
                'image_url': None,
                'created_time': log.posted_at.isoformat() if log.posted_at else None,
                'source_url': log.source_url
            })

        # Matching & Reshare classification helpers
        def normalize_str(s):
            if not s:
                return ''
            return re.sub(r'[^a-zA-Z0-9\u0600-\u06FF]+', ' ', s.lower()).strip()

        def is_match(d_post, s_post):
            d_msg = normalize_str(d_post.get('message') or '')
            d_orig = normalize_str(d_post.get('original_post_message') or '')
            s_msg = normalize_str(s_post.message_snippet or '')

            # 1. Text snippet overlap
            if s_msg and len(s_msg) >= 15:
                s_slice = s_msg[:35]
                if s_slice in d_msg or s_slice in d_orig:
                    return True
                s_words = {w for w in s_msg.split() if len(w) > 4}
                d_words = {w for w in (d_msg + ' ' + d_orig).split() if len(w) > 4}
                if len(s_words & d_words) >= 3:
                    return True

            # 2. Source post ID match
            s_id = str(s_post.source_post_id or '').split('_')[-1]
            if s_id and len(s_id) >= 6:
                d_orig_id = str(d_post.get('original_post_id') or '').split('_')[-1]
                if s_id == d_orig_id:
                    return True
                if s_id in str(d_post.get('original_post_url') or '') or s_id in str(d_post.get('source_url') or ''):
                    return True

            # 3. Direct URL match
            if s_post.post_url and (s_post.post_url in str(d_post.get('original_post_url') or '') or s_post.post_url in str(d_post.get('source_url') or '')):
                return True

            return False

        def is_psmcl_reshare(d_post):
            author = (d_post.get('original_post_author') or '').lower()
            orig_url = (d_post.get('original_post_url') or '').lower()
            orig_msg = (d_post.get('original_post_message') or '').lower()
            msg = (d_post.get('message') or '').lower()
            if any(term in author for term in ['suzuki pakistan', 'pak suzuki', 'suzukipakistan']):
                return True
            if 'suzukipakistan' in orig_url or 'suzuki.pakistan' in orig_url or 'suzukipakistanofficial' in orig_url:
                return True
            if any(term in orig_msg for term in ['pak suzuki', 'suzuki pakistan']):
                return True
            if 'shared a post' in msg or 'shared suzuki pakistan' in msg:
                return True
            if d_post.get('original_post_id') or d_post.get('original_post_message'):
                return True
            return False

        psmcl_exchange_posts = []
        psmcl_usedcar_posts = []
        own_exchange_posts = []
        own_usedcar_posts = []

        for dp in dealership_posts:
            # Combine post caption AND original post message for scanning
            msg = (dp.get('message') or '').strip()
            orig_msg = (dp.get('original_post_message') or '').strip()
            combined_text = f"{msg} {orig_msg}".strip()

            # Scan with keyword matcher + Gemini Multimodal Vision
            scan = self.scan_post_image_and_text(
                dp.get('id'),
                combined_text,
                dp.get('image_url')
            )

            is_ex = scan['is_exchange']
            is_uc = scan['is_certified_used_car']

            if not is_ex and not is_uc:
                continue

            matches_source_ex = any(is_match(dp, sp) for sp in exchange_sources)
            matches_source_uc = any(is_match(dp, sp) for sp in usedcar_sources)
            is_reshared = is_psmcl_reshare(dp) or matches_source_ex or matches_source_uc

            if is_ex:
                if is_reshared:
                    psmcl_exchange_posts.append(dp)
                else:
                    own_exchange_posts.append(dp)

            if is_uc:
                if is_reshared:
                    psmcl_usedcar_posts.append(dp)
                else:
                    own_usedcar_posts.append(dp)

        # Status determination: Own Content
        own_ex_status = 'uploaded' if len(own_exchange_posts) > 0 else 'not_uploaded'
        own_ex_url = own_exchange_posts[0].get('source_url') or own_exchange_posts[0].get('image_url') if own_exchange_posts else None

        own_uc_status = 'uploaded' if len(own_usedcar_posts) > 0 else 'not_uploaded'
        own_uc_url = own_usedcar_posts[0].get('source_url') or own_usedcar_posts[0].get('image_url') if own_usedcar_posts else None

        # Status determination: PSMCL Reshares
        exchange_reshared_count = len(psmcl_exchange_posts)
        exchange_tracked_count = max(len(exchange_sources), exchange_reshared_count)
        psmcl_ex_url = psmcl_exchange_posts[0].get('source_url') or psmcl_exchange_posts[0].get('image_url') if psmcl_exchange_posts else None

        if exchange_reshared_count > 0:
            psmcl_ex_status = 'reshared'
        elif exchange_tracked_count == 0:
            psmcl_ex_status = 'no_source_post'
        else:
            psmcl_ex_status = 'missed'

        usedcar_reshared_count = len(psmcl_usedcar_posts)
        usedcar_tracked_count = max(len(usedcar_sources), usedcar_reshared_count)
        psmcl_uc_url = psmcl_usedcar_posts[0].get('source_url') or psmcl_usedcar_posts[0].get('image_url') if psmcl_usedcar_posts else None

        if usedcar_reshared_count > 0:
            psmcl_uc_status = 'reshared'
        elif usedcar_tracked_count == 0:
            psmcl_uc_status = 'no_source_post'
        else:
            psmcl_uc_status = 'missed'

        # Score calculation: Each applicable criterion contributes equally
        total_criteria = 0
        achieved_criteria = 0

        # Own Exchange is always expected
        total_criteria += 1
        if own_ex_status == 'uploaded':
            achieved_criteria += 1

        # Own Used Car is always expected
        total_criteria += 1
        if own_uc_status == 'uploaded':
            achieved_criteria += 1

        # PSMCL Exchange (if PSMCL posted or dealership reshared)
        if psmcl_ex_status != 'no_source_post':
            total_criteria += 1
            if psmcl_ex_status == 'reshared':
                achieved_criteria += 1

        # PSMCL Used Car (if PSMCL posted or dealership reshared)
        if psmcl_uc_status != 'no_source_post':
            total_criteria += 1
            if psmcl_uc_status == 'reshared':
                achieved_criteria += 1

        score = int(round((achieved_criteria / total_criteria) * 100)) if total_criteria > 0 else 0

        # Save to database
        from_date_obj = datetime.strptime(from_date, '%Y-%m-%d').date()
        to_date_obj = datetime.strptime(to_date, '%Y-%m-%d').date()

        rec = db_session.query(CampaignComplianceRecord).filter(
            CampaignComplianceRecord.dealership_id == dealership_id,
            CampaignComplianceRecord.date_from == from_date_obj,
            CampaignComplianceRecord.date_to == to_date_obj
        ).first()

        if not rec:
            rec = CampaignComplianceRecord(
                dealership_id=dealership_id,
                date_from=from_date_obj,
                date_to=to_date_obj,
                own_exchange_count=len(own_exchange_posts),
                own_exchange_url=own_ex_url,
                own_exchange_status=own_ex_status,
                own_usedcar_count=len(own_usedcar_posts),
                own_usedcar_url=own_uc_url,
                own_usedcar_status=own_uc_status,
                psmcl_exchange_tracked=exchange_tracked_count,
                psmcl_exchange_reshared=exchange_reshared_count,
                psmcl_exchange_url=psmcl_ex_url,
                psmcl_exchange_status=psmcl_ex_status,
                psmcl_usedcar_tracked=usedcar_tracked_count,
                psmcl_usedcar_reshared=usedcar_reshared_count,
                psmcl_usedcar_url=psmcl_uc_url,
                psmcl_usedcar_status=psmcl_uc_status,
                compliance_score=score,
                checked_at=datetime.utcnow()
            )
            db_session.add(rec)
        else:
            rec.own_exchange_count = len(own_exchange_posts)
            rec.own_exchange_url = own_ex_url
            rec.own_exchange_status = own_ex_status
            rec.own_usedcar_count = len(own_usedcar_posts)
            rec.own_usedcar_url = own_uc_url
            rec.own_usedcar_status = own_uc_status
            rec.psmcl_exchange_tracked = exchange_tracked_count
            rec.psmcl_exchange_reshared = exchange_reshared_count
            rec.psmcl_exchange_url = psmcl_ex_url
            rec.psmcl_exchange_status = psmcl_ex_status
            rec.psmcl_usedcar_tracked = usedcar_tracked_count
            rec.psmcl_usedcar_reshared = usedcar_reshared_count
            rec.psmcl_usedcar_url = psmcl_uc_url
            rec.psmcl_usedcar_status = psmcl_uc_status
            rec.compliance_score = score
            rec.checked_at = datetime.utcnow()

        try:
            db_session.commit()
        except Exception:
            db_session.rollback()

        return {
            'success': True,
            'dealership_id': dealership_id,
            'dealership_name': d.name,
            'own_exchange': {
                'status': own_ex_status,
                'count': len(own_exchange_posts),
                'url': own_ex_url
            },
            'own_usedcar': {
                'status': own_uc_status,
                'count': len(own_usedcar_posts),
                'url': own_uc_url
            },
            'psmcl_exchange': {
                'status': psmcl_ex_status,
                'tracked': exchange_tracked_count,
                'reshared': exchange_reshared_count,
                'url': psmcl_ex_url
            },
            'psmcl_usedcar': {
                'status': psmcl_uc_status,
                'tracked': usedcar_tracked_count,
                'reshared': usedcar_reshared_count,
                'url': psmcl_uc_url
            },
            'compliance_score': score,
            'checked_at': rec.checked_at.strftime('%d %b, %H:%M') if rec.checked_at else None
        }
