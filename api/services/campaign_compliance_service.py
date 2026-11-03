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

        # 2. If image_url is available and keywords not already both met, scan image with Gemini Vision
        gemini_key = self.get_gemini_key()
        if image_url and gemini_key and not (text_is_exchange and text_is_usedcar):
            try:
                # Fetch image bytes (timeout 5s)
                img_res = requests.get(image_url, timeout=5, headers={'User-Agent': 'Mozilla/5.0'})
                if img_res.status_code == 200 and len(img_res.content) > 500:
                    raw_bytes = img_res.content
                    
                    # Resize and re-encode to compact JPEG
                    with Image.open(io.BytesIO(raw_bytes)) as img:
                        w, h = img.size
                        max_dim = 600
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
                        img.save(buf, format='JPEG', quality=75)
                        b64_data = base64.b64encode(buf.getvalue()).decode('utf-8')

                    prompt = f"""You are an AI Auditor for Suzuki Dealership social media marketing campaigns in Pakistan.
Inspect this social media graphic banner and caption:
Caption: "{combined_text}"

Check if this graphic specifically promotes:
a) "Exchange" / "Trade-in" (Pak Suzuki Exchange Program, trade old car, exchange offer, exchange gala, تبادلہ).
b) "Certified Used Car" (Suzuki Certified Used Cars, Used Car Gala, Certified pre-owned with warranty, سرٹیفائیڈ یوزڈ کار).

Reply STRICTLY in JSON:
{{
  "extracted_text": "key headlines read from graphic",
  "is_exchange": true or false,
  "is_certified_used_car": true or false,
  "reason": "1 short explanation"
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
                            "maxOutputTokens": 200,
                            "response_mime_type": "application/json"
                        }
                    }

                    for model in ['gemini-2.5-flash', 'gemini-2.0-flash', 'gemini-1.5-flash']:
                        url = f"https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent?key={gemini_key}"
                        try:
                            g_res = requests.post(url, json=payload, timeout=8)
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

    def evaluate_dealership(self, dealership_id: int, from_date: str, to_date: str, snapshot_id: str = None) -> dict:
        """
        Asynchronous Bright Data scraping + AI Caption & Graphic inspection:
        - If snapshot_id is None, triggers Bright Data async job and returns immediately ({status: 'building', snapshot_id: ...})
        - If snapshot_id is provided, checks status:
          * if still building, returns {status: 'building'}
          * if ready, processes posts, runs AI inspection, saves compliance record and returns final counts.
        """
        d = db_session.query(Dealership).filter(Dealership.id == dealership_id).first()
        if not d:
            return {'success': False, 'message': 'Dealership not found'}

        from_dt = datetime.strptime(from_date, '%Y-%m-%d')
        to_dt = datetime.strptime(to_date, '%Y-%m-%d') + timedelta(days=1)

        dealership_posts = []

        # 1. Asynchronous Bright Data Facebook Page Scraper
        from api.services.bright_data import BrightDataClient
        client = BrightDataClient()
        lookup = FacebookPostsLookup()

        if d.fb_input:
            if not snapshot_id:
                # Trigger Bright Data asynchronously (~0.5s)
                page_url = lookup.extract_page_url(d.fb_input)
                start_date = lookup.to_bright_data_date(from_date)
                end_date = lookup.to_bright_data_date(to_date)
                inputs = [{'url': page_url, 'start_date': start_date, 'end_date': end_date, 'num_of_posts': 40}]
                trig = client.trigger_dataset(Config.BRIGHTDATA_DATASET_PAGE_POSTS, inputs)

                if trig.get('success') and trig.get('snapshot_id'):
                    return {
                        'success': True,
                        'status': 'building',
                        'snapshot_id': trig['snapshot_id'],
                        'dealership_id': dealership_id
                    }
                # If trigger failed (e.g. token error or offline), fall back to DB submissions
                print(f"[CampaignCompliance] Trigger dataset failed for {d.name}: {trig.get('message')}", flush=True)
            else:
                # Check existing snapshot progress (~0.3s)
                snap_res = client.check_snapshot(snapshot_id)
                if snap_res.get('status') == 'building':
                    return {
                        'success': True,
                        'status': 'building',
                        'snapshot_id': snapshot_id,
                        'dealership_id': dealership_id
                    }
                if snap_res.get('status') == 'done' and snap_res.get('data'):
                    dealership_posts = [lookup.map_post(p) for p in snap_res['data']]

        # 2. Include approved PostSubmissions
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
                'created_time': sub.submitted_at.isoformat() if sub.submitted_at else None
            })

        # 3. Include PostLog
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
                'created_time': log.posted_at.isoformat() if log.posted_at else None
            })

        exchange_count = 0
        usedcar_count = 0
        image_scans_done = 0
        max_image_scans = 5

        for dp in dealership_posts:
            # Step 1: Check Caption / Text
            msg = (dp.get('message') or '').strip()
            orig_msg = (dp.get('original_post_message') or '').strip()
            combined_text = f"{msg} {orig_msg}".strip()

            is_ex = self.has_exchange_keywords(combined_text)
            is_uc = self.has_usedcar_keywords(combined_text)

            # Step 2: Check image graphic with AI only if not both detected from caption
            if not (is_ex and is_uc) and dp.get('image_url') and image_scans_done < max_image_scans:
                scan = self.scan_post_image_and_text(
                    dp.get('id'),
                    combined_text,
                    dp.get('image_url')
                )
                if scan.get('is_exchange'):
                    is_ex = True
                if scan.get('is_certified_used_car'):
                    is_uc = True
                image_scans_done += 1

            if is_ex:
                exchange_count += 1
            if is_uc:
                usedcar_count += 1

        ex_status = 'uploaded' if exchange_count > 0 else 'not_uploaded'
        uc_status = 'uploaded' if usedcar_count > 0 else 'not_uploaded'

        # Overall compliance score: 100% (both), 50% (one), 0% (none)
        if exchange_count > 0 and usedcar_count > 0:
            score = 100
        elif exchange_count > 0 or usedcar_count > 0:
            score = 50
        else:
            score = 0

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
                own_exchange_count=exchange_count,
                own_exchange_status=ex_status,
                own_usedcar_count=usedcar_count,
                own_usedcar_status=uc_status,
                compliance_score=score,
                checked_at=datetime.utcnow()
            )
            db_session.add(rec)
        else:
            rec.own_exchange_count = exchange_count
            rec.own_exchange_status = ex_status
            rec.own_usedcar_count = usedcar_count
            rec.own_usedcar_status = uc_status
            rec.compliance_score = score
            rec.checked_at = datetime.utcnow()

        try:
            db_session.commit()
        except Exception:
            db_session.rollback()

        return {
            'success': True,
            'status': 'done',
            'dealership_id': dealership_id,
            'dealership_name': d.name,
            'exchange_count': exchange_count,
            'exchange_status': ex_status,
            'usedcar_count': usedcar_count,
            'usedcar_status': uc_status,
            'total_posts': exchange_count + usedcar_count,
            'compliance_score': score,
            'checked_at': rec.checked_at.strftime('%d %b, %H:%M') if rec.checked_at else None
        }
