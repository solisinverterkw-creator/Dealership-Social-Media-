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
        self.models = ['gemini-3.8-flash', 'gemini-2.5-flash', 'gemini-2.0-flash', 'gemini-1.5-flash', 'gemini-pro']
        
    def get_gemini_key(self):
        return Config.get_key('gemini_api_key', 'GEMINI_API_KEY')

    EXCHANGE_KEYWORDS = [
        'exchange', 'trade-in', 'trade in', 'exchange program', 'exchange plan',
        'car exchange', 'exchange offer', 'old car exchange', 'exchange gala',
        'exchange department', 'exchange scheme', 'تبادلہ', 'ایکسچینج'
    ]

    USEDCAR_KEYWORDS = [
        'certified used car', 'certified used cars', 'used car gala', 'used car',
        'used cars', 'certified pre-owned', 'certified car', 'used car certified',
        'certified vehicle', 'used car festival', 'used car warranty',
        'certified used', 'سرٹیفائیڈ', 'یوزڈ کار'
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
        # 1. Check database cache
        if post_id:
            cached = db_session.query(PostImageScanCache).filter(PostImageScanCache.post_id == str(post_id)).first()
            if cached:
                return {
                    'is_exchange': bool(cached.is_exchange),
                    'is_certified_used_car': bool(cached.is_certified_used_car),
                    'extracted_text': cached.extracted_text or '',
                    'reason': cached.classification_reason or ''
                }

        combined_text = (text or '').strip()
        text_is_exchange = self.has_exchange_keywords(combined_text)
        text_is_usedcar = self.has_usedcar_keywords(combined_text)

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
                cache_entry = PostImageScanCache(
                    post_id=str(post_id),
                    image_url=image_url[:1000] if image_url else None,
                    extracted_text=extracted_text[:1000] if extracted_text else None,
                    is_exchange=is_exchange,
                    is_certified_used_car=is_usedcar,
                    classification_reason=reason[:500] if reason else None
                )
                db_session.add(cache_entry)
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
        Fetches official Pak Suzuki source posts in range using Bright Data scraper.
        Extracts image + text content to identify Exchange and Certified Used Car posts.
        """
        source_url = db_session.query(AppSetting.setting_value).filter(AppSetting.setting_key == 'source_page_url').scalar() or "https://www.facebook.com/SuzukiPakistan"
        source_id = db_session.query(AppSetting.setting_value).filter(AppSetting.setting_key == 'source_page_id').scalar() or "100069181887026"

        lookup = FacebookPostsLookup()
        res = lookup.get_posts_in_range(source_url, from_date, to_date, source_id, max_posts=100)
        
        exchange_count = 0
        usedcar_count = 0

        if res.get('success') and res.get('posts'):
            for post in res['posts']:
                p_id = post.get('id')
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

            db_session.commit()

        # Count total in range from DB
        from_dt = datetime.strptime(from_date, '%Y-%m-%d')
        to_dt = datetime.strptime(to_date, '%Y-%m-%d') + timedelta(days=1)
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

        # Helper for matching source posts
        def is_match(d_post, s_post):
            d_msg = (d_post.get('message') or '').lower().strip()
            d_orig = (d_post.get('original_post_message') or '').lower().strip()
            s_msg = (s_post.message_snippet or '').lower().strip()

            if s_msg and len(s_msg) > 15:
                s_core = s_msg[:50]
                if s_core in d_msg or s_core in d_orig:
                    return True
            if s_post.source_post_id and s_post.source_post_id in str(d_post.get('id', '')):
                return True
            return False

        # Evaluate PSMCL Exchange reshares
        exchange_reshared_count = 0
        for sp in exchange_sources:
            if any(is_match(dp, sp) for dp in dealership_posts):
                exchange_reshared_count += 1

        # Evaluate PSMCL Used Car reshares
        usedcar_reshared_count = 0
        for sp in usedcar_sources:
            if any(is_match(dp, sp) for dp in dealership_posts):
                usedcar_reshared_count += 1

        # Evaluate Dealership Own Content
        own_exchange_posts = []
        own_usedcar_posts = []

        for dp in dealership_posts:
            # Check if this post is a reshare of any tracked source post
            is_any_reshare = any(is_match(dp, sp) for sp in source_posts)
            if is_any_reshare:
                continue

            # Run text + image scan on this post
            scan = self.scan_post_image_and_text(
                dp.get('id'),
                dp.get('message') or '',
                dp.get('image_url')
            )
            if scan['is_exchange']:
                own_exchange_posts.append(dp)
            if scan['is_certified_used_car']:
                own_usedcar_posts.append(dp)

        # Status determination
        own_ex_status = 'uploaded' if len(own_exchange_posts) > 0 else 'not_uploaded'
        own_ex_url = own_exchange_posts[0].get('source_url') or own_exchange_posts[0].get('image_url') if own_exchange_posts else None

        own_uc_status = 'uploaded' if len(own_usedcar_posts) > 0 else 'not_uploaded'
        own_uc_url = own_usedcar_posts[0].get('source_url') or own_usedcar_posts[0].get('image_url') if own_usedcar_posts else None

        # PSMCL Exchange status
        if not exchange_sources:
            psmcl_ex_status = 'no_source_post'
        elif exchange_reshared_count > 0:
            psmcl_ex_status = 'reshared'
        else:
            psmcl_ex_status = 'missed'

        # PSMCL Used Car status
        if not usedcar_sources:
            psmcl_uc_status = 'no_source_post'
        elif usedcar_reshared_count > 0:
            psmcl_uc_status = 'reshared'
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

        # PSMCL Exchange (if PSMCL posted)
        if psmcl_ex_status != 'no_source_post':
            total_criteria += 1
            if psmcl_ex_status == 'reshared':
                achieved_criteria += 1

        # PSMCL Used Car (if PSMCL posted)
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
                psmcl_exchange_tracked=len(exchange_sources),
                psmcl_exchange_reshared=exchange_reshared_count,
                psmcl_exchange_status=psmcl_ex_status,
                psmcl_usedcar_tracked=len(usedcar_sources),
                psmcl_usedcar_reshared=usedcar_reshared_count,
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
            rec.psmcl_exchange_tracked = len(exchange_sources)
            rec.psmcl_exchange_reshared = exchange_reshared_count
            rec.psmcl_exchange_status = psmcl_ex_status
            rec.psmcl_usedcar_tracked = len(usedcar_sources)
            rec.psmcl_usedcar_reshared = usedcar_reshared_count
            rec.psmcl_usedcar_status = psmcl_uc_status
            rec.compliance_score = score
            rec.checked_at = datetime.utcnow()

        db_session.commit()

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
                'tracked': len(exchange_sources),
                'reshared': exchange_reshared_count
            },
            'psmcl_usedcar': {
                'status': psmcl_uc_status,
                'tracked': len(usedcar_sources),
                'reshared': usedcar_reshared_count
            },
            'compliance_score': score,
            'checked_at': rec.checked_at.strftime('%d %b, %H:%M') if rec.checked_at else None
        }
