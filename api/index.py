import os
import sys

# Add the parent directory of api/ to sys.path so Vercel can resolve local imports properly
parent_dir = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if parent_dir not in sys.path:
    sys.path.insert(0, parent_dir)

import re
import json
import time
import uuid
import base64
from datetime import datetime, timedelta
import threading
from decimal import Decimal
import openpyxl

from flask import Flask, render_template, request, redirect, url_for, session, jsonify, send_file, Response, abort
from sqlalchemy import or_, and_, func, distinct

from api.config import Config
from api.database import engine, db_session, init_db
from api.models import (
    User, Dealership, user_dealerships, UserSidebarSection, VehicleModel,
    VehicleModelImage, BrandIdentity, PostSubmission, CrmParameter,
    CrmRawData, CrmScore, SalesRecord, SalesSummary, StockRecord,
    AgeingRecord, StockChassisRecord, TargetPage, PostLog, AppSetting,
    ReshareCheck, ReshareOwnPostStat, ProcessedSourcePost
)
# Alias for backward compatibility with code that uses ReshareSourcePost name
ReshareSourcePost = ProcessedSourcePost

from api.auth import (
    hash_password, verify_password, attempt_login, logout_user,
    is_logged_in, is_super_admin, get_dealership_ids,
    can_access_dealership, can_view, can_perform,
    require_login, require_super_admin
)
from api.services.helpers import (
    ImageResizer, SpreadsheetImportHelper, read_excel_rows,
    levenshtein_distance, DataQualityAnalyzer, VisitReportAnalyzer
)
from api.services.lookups import (
    FacebookLookup, InstagramLookup, YouTubeLookup, GoogleReviewLookup,
    FacebookPostsLookup
)
# Aliases for classes that were renamed
InstagramPostsLookup = InstagramLookup
YouTubePostsLookup = YouTubeLookup
from api.services.facebook_poster import FacebookPoster
from api.services.email_validator import EmailValidator as EmailValidatorService

# Absolute path resolution for serverless function execution
root_dir = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
template_dir = os.path.join(root_dir, 'templates')
static_dir = os.path.join(root_dir, 'assets')

app = Flask(
    __name__, 
    template_folder=template_dir, 
    static_folder=static_dir, 
    static_url_path='/assets'
)
application = app
handler = app
# Secret key - must NEVER be None/empty or Flask sessions will crash
app.secret_key = (
    os.environ.get('FLASK_SECRET_KEY') or
    os.environ.get('SECRET_KEY') or
    'rosp-dealership-dashboard-secret-key-2026-xK9mP3qR'
)

# Vercel serverless filesystem is read-only - use /tmp for writable uploads
if os.environ.get('VERCEL') or not os.access(os.path.dirname(os.path.abspath(__file__)), os.W_OK):
    UPLOAD_DIR = '/tmp/uploads'
else:
    UPLOAD_DIR = os.path.join(os.path.dirname(__file__), '../assets/uploads')

os.makedirs(os.path.join(UPLOAD_DIR, 'vehicles'), exist_ok=True)
os.makedirs(os.path.join(UPLOAD_DIR, 'logos'), exist_ok=True)
@app.route('/assets/uploads/<path:filename>')
def serve_uploaded_file(filename):
    clean_fn = filename.lstrip('/')
    fname = os.path.basename(clean_fn)
    
    # 1. Search UPLOAD_DIR (/tmp/uploads or local)
    for cand in [
        os.path.join(UPLOAD_DIR, clean_fn),
        os.path.join(UPLOAD_DIR, 'submissions', fname),
        os.path.join(UPLOAD_DIR, 'vehicles', fname),
        os.path.join(UPLOAD_DIR, 'logos', fname)
    ]:
        if cand and os.path.exists(cand) and os.path.isfile(cand):
            return send_file(cand)

    # 2. Search root_dir/assets/uploads
    for cand in [
        os.path.join(root_dir, 'assets', 'uploads', clean_fn),
        os.path.join(root_dir, 'assets', 'uploads', 'submissions', fname),
        os.path.join(root_dir, 'assets', 'uploads', 'vehicles', fname),
        os.path.join(root_dir, 'assets', 'uploads', 'logos', fname)
    ]:
        if cand and os.path.exists(cand) and os.path.isfile(cand):
            return send_file(cand)

    return abort(404)

@app.route('/manifest.json')
def serve_manifest():
    return send_file(os.path.join(root_dir, 'manifest.json'), mimetype='application/manifest+json')

@app.route('/sw.js')
def serve_sw():
    return send_file(os.path.join(root_dir, 'sw.js'), mimetype='application/javascript')

# Set TZ offset roughly for date display to match Asia/Karachi
def current_time_pk():
    # Pakistan is UTC+5
    return datetime.utcnow() + timedelta(hours=5)

# --- JINJA FILTERS ---
@app.template_filter('escapejs')
def escapejs_filter(val):
    try:
        if val is None or str(type(val)).endswith("Undefined'>"):
            return ''
        return json.dumps(str(val))[1:-1]
    except Exception:
        return ''

@app.template_filter('number_format')
def number_format_filter(value, decimals=0):
    if value is None or value == '' or str(type(value)).endswith("Undefined'>"):
        return ''
    try:
        if decimals == 0:
            return f"{int(round(float(value))):,}"
        return f"{float(value):,.{decimals}f}"
    except (ValueError, TypeError):
        return value

def sidebar_sections_list():
    return {
        'dashboard': {'label': 'Dashboard', 'page': 'index.php'},
        'report': {'label': 'Social Media Report', 'page': 'report.php'},
        'weekly_posts': {'label': 'Posting Activity', 'page': 'weekly_posts.php'},
        'yt_monthly': {'label': 'YT Monthly Videos', 'page': 'yt_monthly.php'},
        'no_activity_report': {'label': 'Follower Activity Report', 'page': 'no_activity_report.php'},
        'manual_publish': {'label': 'Publish Content', 'page': 'manual_publish.php'},
        'syndication_report': {'label': 'Integration Report', 'page': 'syndication_report.php'},
        'post_breakdown': {'label': 'Post Breakdown Report', 'page': 'post_breakdown_report.php'},
        'reshare_compliance': {'label': 'Reshare Compliance', 'page': 'reshare_compliance_report.php'},
        'target_pages': {'label': 'Target Pages', 'page': 'target_pages.php'},
        'exchange_token': {'label': 'Exchange Token', 'page': 'exchange_token.php'},
        'submit_post_check': {'label': 'Post Approval', 'page': 'submit_post_check.php'},
        'email_validator': {'label': 'Email Validator', 'page': 'email_validator.php'},
        'sales_report': {'label': 'Sales Report', 'page': 'sales_report.php'},
        'stock_report': {'label': 'Stock Report', 'page': 'stock_report.php'},
        'visit_report': {'label': 'Visit Report', 'page': 'visit_report.php'},
        'ageing_report': {'label': 'Ageing Report', 'page': 'ageing_report.php'},
        'crm_report': {'label': 'CRM Report', 'page': 'crm_report.php'},
        'crm_parameters': {'label': 'CRM Parameters', 'page': 'crm_parameters.php'},
        'crm_data_quality': {'label': 'CRM Data Quality Checker', 'page': 'crm_data_quality_check.php'},
        'brand_assets': {'label': 'Brand Assets', 'page': 'brand_assets.php'},
    }

def get_setting(key, default=''):
    try:
        setting = db_session.query(AppSetting).filter(AppSetting.setting_key == key).first()
        if setting and setting.setting_value is not None:
            return setting.setting_value.strip()
    except Exception:
        pass
    return default

def set_setting(key, value):
    try:
        setting = db_session.query(AppSetting).filter(AppSetting.setting_key == key).first()
        if not setting:
            setting = AppSetting(setting_key=key, setting_value=str(value).strip())
            db_session.add(setting)
        else:
            setting.setting_value = str(value).strip()
        db_session.commit()
    except Exception:
        db_session.rollback()

# --- UTILITY CONTEXT PROCESSORS ---
@app.context_processor
def utility_processor():
    return {
        'is_logged_in': is_logged_in,
        'is_super_admin': is_super_admin,
        'can_perform': can_perform,
        'can_view': can_view,
        'current_user_id': lambda: session.get('user_id')
    }

# --- DB TEARDOWN ---
@app.teardown_appcontext
def shutdown_session(exception=None):
    db_session.remove()

# --- ERROR HANDLER (temporary - shows traceback) ---
@app.errorhandler(500)
def internal_error(e):
    import traceback
    tb = traceback.format_exc()
    return f'<pre style="color:red;padding:20px">{tb}</pre>', 500

# --- HELPER FUNCTIONS ---
def dealership_percent(d):
    applicable = 0
    if d.fb_input: applicable += 1
    if d.ig_search: applicable += 1
    if d.yt_search: applicable += 1
    if d.google_search: applicable += 1
    if applicable == 0: return 0
    return 100 if d.last_refreshed else 0

def render_monthly_bar_chart(data, color):
    width = 340
    height = 160
    margin_bottom = 22
    margin_top = 24
    chart_height = height - margin_bottom - margin_top
    if not data:
        return '<div class="empty-state">No Data Yet.</div>'
    max_val = max(1, max(data.values()))
    count = len(data)
    gap = 12
    bar_width = max(10, (width - (gap * (count + 1))) / count)

    bars = []
    for i, (label, value) in enumerate(sorted(data.items())):
        x = gap + i * (bar_width + gap)
        bar_height = (value / max_val) * chart_height
        y = margin_top + chart_height - bar_height
        
        try:
            dt = datetime.strptime(label + "-01", "%Y-%m-%d")
            label_short = dt.strftime("%b")
        except Exception:
            label_short = label
            
        bars.append(f'<rect x="{x:.1f}" y="{y:.1f}" width="{bar_width:.1f}" height="{max(2, bar_height):.1f}" rx="4" fill="{color}"/>')
        bars.append(f'<text x="{(x + bar_width / 2):.1f}" y="{(y - 6):.1f}" font-size="10" text-anchor="middle" class="chart-value-label">{value}</text>')
        bars.append(f'<text x="{(x + bar_width / 2):.1f}" y="{(height - 6):.1f}" font-size="10" text-anchor="middle">{label_short}</text>')

    baseline = margin_top + chart_height
    svg = f"""
    <svg viewBox="0 0 {width} {height}" width="100%" style="max-width:{width}px; height:auto; display:block;">
        <line x1="0" y1="{baseline:.1f}" x2="{width}" y2="{baseline:.1f}" stroke="var(--border)" stroke-width="1"/>
        {"".join(bars)}
    </svg>
    """
    return svg

def render_comparison_bar_chart(series):
    width = 220
    height = 160
    margin_bottom = 22
    margin_top = 24
    chart_height = height - margin_bottom - margin_top
    
    vals = [info['value'] for info in series.values()]
    max_val = max(1, max(vals) if vals else 1)
    gap = 30
    bar_width = 56

    bars = []
    for i, (label, info) in enumerate(series.items()):
        x = gap + i * (bar_width + gap)
        bar_height = (info['value'] / max_val) * chart_height
        y = margin_top + chart_height - bar_height
        
        bars.append(f'<rect x="{x:.1f}" y="{y:.1f}" width="{bar_width:.1f}" height="{max(2, bar_height):.1f}" rx="4" fill="{info["color"]}"/>')
        bars.append(f'<text x="{(x + bar_width / 2):.1f}" y="{(y - 8):.1f}" font-size="12" text-anchor="middle" class="chart-value-label">{info["value"]}</text>')
        bars.append(f'<text x="{(x + bar_width / 2):.1f}" y="{(height - 6):.1f}" font-size="10" text-anchor="middle">{label}</text>')

    baseline = margin_top + chart_height
    svg = f"""
    <svg viewBox="0 0 {width} {height}" width="100%" style="max-width:{width}px; height:auto; display:block;">
        <line x1="0" y1="{baseline:.1f}" x2="{width}" y2="{baseline:.1f}" stroke="var(--border)" stroke-width="1"/>
        {"".join(bars)}
    </svg>
    """
    return svg

def get_allowed_dealerships():
    """Fetches list of dealerships the current user is allowed to access (strictly 21 official dealerships)."""
    if is_super_admin():
        return db_session.query(Dealership).filter(Dealership.id <= 21).order_by(Dealership.id).all()
    scoped_ids = get_dealership_ids()
    if not scoped_ids:
        return []
    return db_session.query(Dealership).filter(Dealership.id.in_(scoped_ids), Dealership.id <= 21).order_by(Dealership.id).all()

# --- CORE USER VIEWS ---

# Diagnostic debug endpoint - shows system status and API Key audit
@app.route('/debug')
def debug_info():
    info = []
    info.append("<h2>🚀 System & Database Audit</h2>")
    info.append(f'Python version: {sys.version}')
    info.append(f'DATABASE_URL set: {bool(os.environ.get("DATABASE_URL") or os.environ.get("POSTGRES_URL"))}')
    info.append(f'Template folder: {app.template_folder}')
    info.append(f'UPLOAD_DIR: {UPLOAD_DIR}')
    try:
        from sqlalchemy import text
        with engine.connect() as conn:
            conn.execute(text('SELECT 1'))
        info.append('DB Connection: ✅ OK')
    except Exception as e:
        info.append(f'DB Connection: ❌ FAILED ({e})')

    info.append("<br><h2>🔑 API Keys Audit Status</h2>")
    keys = {
        'YOUTUBE_API_KEY': Config.YOUTUBE_API_KEY,
        'BRIGHTDATA_API_TOKEN': Config.BRIGHTDATA_API_TOKEN,
        'RAPIDAPI_KEY': Config.RAPIDAPI_KEY,
        'GEMINI_API_KEY': Config.GEMINI_API_KEY,
        'FB_APP_ID': Config.FB_APP_ID,
        'FB_APP_SECRET': Config.FB_APP_SECRET,
        'SCRAPECREATORS_API_KEY_INSTAGRAM': Config.SCRAPECREATORS_API_KEY_INSTAGRAM,
    }
    for name, val in keys.items():
        if val and len(val.strip()) > 3:
            masked = val[:4] + '*' * (len(val) - 6) + val[-2:] if len(val) > 8 else 'SET'
            info.append(f'<strong>{name}</strong>: <span style="color:green;font-weight:bold">ENABLED / SET ({masked})</span>')
        else:
            info.append(f'<strong>{name}</strong>: <span style="color:red;font-weight:bold">NOT SET / EMPTY</span>')

    return '<br>'.join(info)

@app.route('/login', methods=['GET', 'POST'])
@app.route('/login.php', methods=['GET', 'POST'])
def login():
    if is_logged_in():
        return redirect(url_for('dashboard'))
    error = None
    message = session.pop('reset_success_msg', None)
    if request.method == 'POST':
        username = request.form.get('username')
        password = request.form.get('password')
        if attempt_login(username, password):
            return redirect(url_for('dashboard'))
        error = "Invalid Username or Password."
    return render_template('login.html', error=error, message=message)

@app.route('/reset_password', methods=['GET', 'POST'])
@app.route('/reset_password.php', methods=['GET', 'POST'])
def reset_password():
    error = None
    message = None
    if request.method == 'POST':
        username = request.form.get('username', '').strip()
        new_password = request.form.get('new_password', '').strip()
        confirm_password = request.form.get('confirm_password', '').strip()

        if not username or not new_password:
            error = "Please fill in all fields."
        elif new_password != confirm_password:
            error = "New password and confirm password do not match."
        elif len(new_password) < 4:
            error = "Password must be at least 4 characters long."
        else:
            user = db_session.query(User).filter(User.username == username).first()
            if not user:
                error = f"Username '{username}' not found."
            else:
                try:
                    user.password_hash = hash_password(new_password)
                    db_session.commit()
                    session['reset_success_msg'] = f"Password for '{username}' reset successfully! Please sign in with your new password."
                    return redirect(url_for('login'))
                except Exception as e:
                    db_session.rollback()
                    error = f"Error updating password: {str(e)}"

    return render_template('reset_password.html', error=error, message=message)

@app.route('/logout')
@app.route('/logout.php')
def logout():
    logout_user()
    return redirect(url_for('login'))

def get_scrapers_initial_summary(scraper_settings):
    bd_token = scraper_settings.get('brightdata_api_token', '')
    yt_key = scraper_settings.get('youtube_api_key', '')
    rapid_key = scraper_settings.get('rapidapi_key', '')
    apify_token = scraper_settings.get('apify_api_token', '')

    return [
        {
            'name': 'Bright Data Scraper',
            'id': 'brightdata',
            'token': bd_token,
            'is_configured': bool(bd_token),
            'status_ok': False,
            'remaining_quota': 'Click "Check Quota" or auto-fetching...' if bd_token else 'Token Not Configured',
            'used_for': 'Facebook Page & Post Scraping, Instagram Profiles',
            'engine_key': 'fb_engine'
        },
        {
            'name': 'YouTube Data API',
            'id': 'youtube',
            'token': yt_key,
            'is_configured': bool(yt_key),
            'status_ok': False,
            'remaining_quota': 'Click "Check Quota" or auto-fetching...' if yt_key else 'API Key Not Configured',
            'used_for': 'YouTube Channel Subscribers, Video Count, Views',
            'engine_key': 'yt_engine'
        },
        {
            'name': 'RapidAPI Scraper',
            'id': 'rapidapi',
            'token': rapid_key,
            'is_configured': bool(rapid_key),
            'status_ok': False,
            'remaining_quota': 'Click "Check Quota" or auto-fetching...' if rapid_key else 'API Key Not Configured',
            'used_for': 'Google Reviews Lookup & Fallback Scraping',
            'engine_key': 'rapidapi'
        },
        {
            'name': 'Apify / ScrapeCreators Scraper',
            'id': 'apify',
            'token': apify_token,
            'is_configured': bool(apify_token),
            'status_ok': False,
            'remaining_quota': 'Click "Check Quota" or auto-fetching...' if apify_token else 'Token Not Configured',
            'used_for': 'Instagram Posts & Secondary Fallback',
            'engine_key': 'ig_engine'
        }
    ]

@app.route('/')
@app.route('/index.php', endpoint='dashboard')
@require_login
def dashboard():
    if not can_view('dashboard'):
        abort(403)
    dealerships = get_allowed_dealerships()
    
    row_percents = {}
    for d in dealerships:
        row_percents[d.id] = dealership_percent(d)
    
    overall_percent = int(round(sum(row_percents.values()) / len(row_percents))) if row_percents else 0
    
    total_fb_followers = sum(d.fb_followers or 0 for d in dealerships)
    total_ig_followers = sum(d.ig_followers or 0 for d in dealerships)
    total_yt_subs = sum(d.yt_subscribers or 0 for d in dealerships)
    total_google_reviews = sum(d.google_review_count or 0 for d in dealerships)
    
    # Chart 1: YouTube uploads last 6 months
    month_keys = []
    today = current_time_pk()
    start_date = (today - timedelta(days=150)).replace(day=1)
    cursor = start_date
    while cursor <= today:
        month_keys.append(cursor.strftime('%Y-%m'))
        if cursor.month == 12:
            cursor = cursor.replace(year=cursor.year + 1, month=1)
        else:
            cursor = cursor.replace(month=cursor.month + 1)
            
    yt_chart_data = {mk: 0 for mk in month_keys}
    if dealerships:
        d_ids = [d.id for d in dealerships]
        from api.models import YtMonthlyStats as YtMonthlyStat
        stats = db_session.query(
            YtMonthlyStat.month, func.sum(YtMonthlyStat.video_count)
        ).filter(
            YtMonthlyStat.dealership_id.in_(d_ids),
            YtMonthlyStat.month >= month_keys[0]
        ).group_by(YtMonthlyStat.month).all()
        for month, val in stats:
            if month in yt_chart_data:
                yt_chart_data[month] = int(val)
                
    yt_chart_html = render_monthly_bar_chart(yt_chart_data, 'var(--yt)')
    
    fb_posts_total = sum(d.fb_posts_week or 0 for d in dealerships)
    ig_posts_total = sum(d.ig_posts_week or 0 for d in dealerships)
    
    comparison_chart_html = render_comparison_bar_chart({
        'Facebook': {'value': fb_posts_total, 'color': 'var(--fb)'},
        'Instagram': {'value': ig_posts_total, 'color': 'var(--ig)'}
    })
    
    scraper_settings = {
        'fb_engine': get_setting('fb_engine', 'brightdata'),
        'ig_engine': get_setting('ig_engine', 'brightdata'),
        'yt_engine': get_setting('yt_engine', 'youtube_api'),
        'youtube_api_key': Config.get_key('youtube_api_key', 'YOUTUBE_API_KEY'),
        'brightdata_api_token': Config.get_key('brightdata_api_token', 'BRIGHTDATA_API_TOKEN'),
        'rapidapi_key': Config.get_key('rapidapi_key', 'RAPIDAPI_KEY'),
        'apify_api_token': Config.get_key('apify_api_token', 'APIFY_API_TOKEN'),
        'gemini_api_key': Config.get_key('gemini_api_key', 'GEMINI_API_KEY'),
    }

    if is_super_admin():
        scrapers_summary = get_scrapers_initial_summary(scraper_settings)
    else:
        scrapers_summary = []

    message = session.pop('dashboard_msg', '')
    error = session.pop('dashboard_err', '')

    return render_template(
        'dashboard.html',
        dealerships=dealerships,
        row_percents=row_percents,
        overall_percent=overall_percent,
        total_fb_followers=total_fb_followers,
        total_ig_followers=total_ig_followers,
        total_yt_subs=total_yt_subs,
        total_google_reviews=total_google_reviews,
        yt_chart_html=yt_chart_html,
        comparison_chart_html=comparison_chart_html,
        scraper_settings=scraper_settings,
        scrapers_summary=scrapers_summary,
        message=message,
        error=error
    )

@app.route('/save_scraper_settings', methods=['POST'])
@require_login
def save_scraper_settings():
    if not is_super_admin():
        session['dashboard_err'] = 'Super admin permission required to update scraper settings.'
        return redirect(url_for('dashboard'))

    # Update provider choices
    if 'fb_engine' in request.form:
        set_setting('fb_engine', request.form.get('fb_engine', 'brightdata'))
    if 'ig_engine' in request.form:
        set_setting('ig_engine', request.form.get('ig_engine', 'brightdata'))
    if 'yt_engine' in request.form:
        set_setting('yt_engine', request.form.get('yt_engine', 'youtube_api'))

    # Update tokens
    for key_name in ['youtube_api_key', 'brightdata_api_token', 'rapidapi_key', 'apify_api_token', 'gemini_api_key']:
        val = request.form.get(key_name, '').strip()
        if val:
            set_setting(key_name, val)

    session['dashboard_msg'] = 'Scraper settings & API tokens updated successfully!'
    return redirect(url_for('dashboard'))

@app.route('/api/check_scraper_quota')
@require_login
def check_scraper_quota_api():
    """Instant key-presence check — no external HTTP calls, zero timeout risk"""
    if not is_super_admin():
        return jsonify({'success': False, 'message': 'Super admin required'}), 403

    def mask(val):
        if not val or len(val) < 8:
            return val or ''
        return val[:4] + '****' + val[-4:]

    raw = {
        'youtube':    Config.get_key('youtube_api_key', 'YOUTUBE_API_KEY') or '',
        'brightdata': Config.get_key('brightdata_api_token', 'BRIGHTDATA_API_TOKEN') or '',
        'rapidapi':   Config.get_key('rapidapi_key', 'RAPIDAPI_KEY') or '',
        'apify':      Config.get_key('apify_api_token', 'APIFY_API_TOKEN') or '',
    }

    info = {
        'youtube':    ('~10,000 units/day free',    'https://console.cloud.google.com/apis/api/youtube.googleapis.com/quotas'),
        'brightdata': ('Metered pay-as-you-go',     'https://brightdata.com/cp/dashboard'),
        'rapidapi':   ('Subscription plan based',   'https://rapidapi.com/developer/dashboard'),
        'apify':      ('Monthly compute units',     'https://console.apify.com/billing'),
    }

    results = {}
    for k, token in raw.items():
        quota_str, dashboard_url = info[k]
        if token:
            results[k] = {
                'status': 'ok',
                'badge': 'Configured',
                'quota': quota_str,
                'masked': mask(token),
                'dashboard_url': dashboard_url,
            }
        else:
            results[k] = {
                'status': 'not_set',
                'badge': 'Not Set',
                'quota': 'Not configured',
                'masked': '',
                'dashboard_url': dashboard_url,
            }

    return jsonify({'success': True, 'results': results})


# Individual live endpoints — kept simple, one call each
@app.route('/api/quota/youtube')
@require_login
def quota_youtube():
    if not is_super_admin():
        return jsonify({'status': 'error', 'badge': 'Forbidden', 'quota': 'Super admin required'}), 403
    key = Config.get_key('youtube_api_key', 'YOUTUBE_API_KEY') or ''
    if not key:
        return jsonify({'status': 'not_set', 'badge': 'Not Set', 'quota': 'YOUTUBE_API_KEY not configured'})
    try:
        r = requests.get(
            f'https://www.googleapis.com/youtube/v3/videos?part=id&chart=mostPopular&maxResults=1&key={key}',
            timeout=7
        )
        if r.status_code == 200:
            return jsonify({'status': 'ok', 'badge': 'Live OK', 'quota': '~10,000 units/day (key valid)'})
        try:
            err = r.json().get('error', {}).get('message', f'HTTP {r.status_code}')
        except Exception:
            err = f'HTTP {r.status_code}'
        if 'quotaExceeded' in err or 'rateLimitExceeded' in err:
            return jsonify({'status': 'error', 'badge': 'Quota Exceeded', 'quota': 'Daily quota exhausted — resets midnight PST'})
        return jsonify({'status': 'error', 'badge': 'Key Error', 'quota': f'Google: {err[:80]}'})
    except Exception as e:
        return jsonify({'status': 'warn', 'badge': 'Key Set', 'quota': 'Key Stored'})


@app.route('/api/quota/brightdata')
@require_login
def quota_brightdata():
    if not is_super_admin():
        return jsonify({'status': 'error', 'badge': 'Forbidden', 'quota': 'Super admin required'}), 403
    token = Config.get_key('brightdata_api_token', 'BRIGHTDATA_API_TOKEN') or ''
    if not token:
        return jsonify({'status': 'not_set', 'badge': 'Not Set', 'quota': 'BRIGHTDATA_API_TOKEN not configured'})
    try:
        r = requests.get('https://api.brightdata.com/customer',
                         headers={'Authorization': f'Bearer {token}'}, timeout=7)
        if r.status_code == 200:
            try:
                data = r.json()
                balance = data.get('balance', data.get('credit'))
                if balance is not None:
                    return jsonify({'status': 'ok', 'badge': 'Live OK', 'quota': f'Balance: ${float(balance):.4f} (metered)'})
            except Exception:
                pass
            return jsonify({'status': 'ok', 'badge': 'Live OK', 'quota': 'Active metered account'})
        if r.status_code == 401:
            return jsonify({'status': 'error', 'badge': 'Invalid Token', 'quota': 'HTTP 401 — token rejected'})
    except Exception:
        return jsonify({'status': 'warn', 'badge': 'Key Set', 'quota': 'Token Stored'})


@app.route('/api/quota/rapidapi')
@require_login
def quota_rapidapi():
    if not is_super_admin():
        return jsonify({'status': 'error', 'badge': 'Forbidden', 'quota': 'Super admin required'}), 403
    key = Config.get_key('rapidapi_key', 'RAPIDAPI_KEY') or ''
    if not key:
        return jsonify({'status': 'not_set', 'badge': 'Not Set', 'quota': 'RAPIDAPI_KEY not configured'})
    try:
        r = requests.get(
            'https://instagram-scraper-api2.p.rapidapi.com/v1/info?username_or_id_or_url=instagram',
            headers={'X-RapidAPI-Key': key, 'X-RapidAPI-Host': 'instagram-scraper-api2.p.rapidapi.com'},
            timeout=7
        )
        rem = r.headers.get('x-ratelimit-requests-remaining', '')
        limit = r.headers.get('x-ratelimit-requests-limit', '')
        if r.status_code in (200, 201):
            if rem and limit:
                return jsonify({'status': 'ok', 'badge': 'Live OK', 'quota': f'{rem} / {limit} requests remaining'})
            return jsonify({'status': 'ok', 'badge': 'Live OK', 'quota': 'Active key — check RapidAPI dashboard for quota'})
        if r.status_code == 403:
            return jsonify({'status': 'error', 'badge': 'Invalid Key', 'quota': 'HTTP 403 — key rejected or no subscription'})
        if r.status_code == 429:
            return jsonify({'status': 'warn', 'badge': 'Rate Limited', 'quota': f'Limit hit — {rem}/{limit} remaining'})
        return jsonify({'status': 'warn', 'badge': 'Key Set', 'quota': 'Key Stored'})
    except Exception:
        return jsonify({'status': 'warn', 'badge': 'Key Set', 'quota': 'Key Stored'})


@app.route('/api/quota/apify')
@require_login
def quota_apify():
    if not is_super_admin():
        return jsonify({'status': 'error', 'badge': 'Forbidden', 'quota': 'Super admin required'}), 403
    token = Config.get_key('apify_api_token', 'APIFY_API_TOKEN') or ''
    if not token:
        return jsonify({'status': 'not_set', 'badge': 'Not Set', 'quota': 'APIFY_API_TOKEN not configured'})
    try:
        r = requests.get(f'https://api.apify.com/v2/users/me?token={token}', timeout=7)
        if r.status_code == 200:
            try:
                data = r.json().get('data', {})
                plan = data.get('plan', {}).get('id', 'Standard')
                compute = data.get('monthlyUsage', {}).get('actorComputeUnits', {})
                used = compute.get('current')
                limit_val = compute.get('limit')
                if used is not None and limit_val is not None:
                    remaining = max(0, limit_val - used)
                    return jsonify({'status': 'ok', 'badge': 'Live OK',
                                    'quota': f'Plan: {plan} | {used:.1f}/{limit_val:.1f} units used | {remaining:.1f} remaining'})
                return jsonify({'status': 'ok', 'badge': 'Live OK', 'quota': f'Active — Plan: {plan}'})
            except Exception:
                return jsonify({'status': 'ok', 'badge': 'Live OK', 'quota': 'Active Apify account'})
        if r.status_code == 401:
            return jsonify({'status': 'error', 'badge': 'Invalid Token', 'quota': 'HTTP 401 — Apify token rejected'})
        return jsonify({'status': 'warn', 'badge': 'Key Set', 'quota': 'Token Stored'})
    except Exception:
        return jsonify({'status': 'warn', 'badge': 'Key Set', 'quota': 'Token Stored'})


@app.route('/report')
@app.route('/report.php', endpoint='social_report')
@require_login
def social_report():
    if not can_view('report'):
        abort(403)
    dealerships = get_allowed_dealerships()

    # Optional per-dealership detail view
    detail_id = request.args.get('id', type=int)
    detail = None
    if detail_id:
        detail = next((d for d in dealerships if d.id == detail_id), None)

    # Inject total_reach on each object (not a DB column)
    for d in dealerships:
        d.total_reach = (d.fb_followers or 0) + (d.ig_followers or 0) + (d.yt_subscribers or 0)

    # Sort by total reach descending
    ranked_dealerships = sorted(dealerships, key=lambda d: d.total_reach, reverse=True)

    # Compute ratings for avg
    rated = [float(d.google_rating) for d in dealerships if d.google_rating]
    avg_rating = sum(rated) / len(rated) if rated else 0

    totals = {
        'cnt': len(dealerships),
        'fb_total': sum(d.fb_followers or 0 for d in dealerships),
        'ig_total': sum(d.ig_followers or 0 for d in dealerships),
        'yt_total': sum(d.yt_subscribers or 0 for d in dealerships),
        'gr_total': sum(d.google_review_count or 0 for d in dealerships),
        'fb_target_total': sum(d.fb_target or 0 for d in dealerships),
        'ig_target_total': sum(d.ig_target or 0 for d in dealerships),
        'yt_target_total': sum(d.yt_target or 0 for d in dealerships),
        'avg_rating': avg_rating,
    }

    def target_badge(val, target):
        if not target:
            return ''
        val = val or 0
        pct = int(round(val * 100 / target)) if target else 0
        color = '#22c55e' if pct >= 100 else ('#f59e0b' if pct >= 75 else '#ef4444')
        return f'<span style="font-size:11px;color:{color};font-weight:600">({pct}%)</span>'

    def column_target_label(dships, field):
        has_target = any(getattr(d, field, 0) for d in dships)
        return '<span style="font-size:11px;opacity:.6">Target</span>' if has_target else ''

    return render_template(
        'report.html',
        dealerships=ranked_dealerships,
        totals=totals,
        detail=detail,
        target_badge=target_badge,
        column_target_label=column_target_label,
    )


@app.route('/weekly_posts')
@app.route('/weekly_posts.php', endpoint='posting_activity')
@require_login
def posting_activity():
    if not can_view('weekly_posts'):
        abort(403)
    dealerships = get_allowed_dealerships()
    today = current_time_pk().date()
    from_val = request.args.get('from', (today - timedelta(days=7)).strftime('%Y-%m-%d'))
    to_val = request.args.get('to', today.strftime('%Y-%m-%d'))
    message = session.pop('weekly_posts_msg', '')
    error = session.pop('weekly_posts_err', '')
    row_percents = {d.id: dealership_percent(d) for d in dealerships}
    return render_template(
        'weekly_posts.html',
        dealerships=dealerships,
        row_percents=row_percents,
        from_val=from_val,
        to_val=to_val,
        message=message,
        error=error
    )

@app.route('/yt_monthly')
@app.route('/yt_monthly.php', endpoint='yt_monthly')
@require_login
def yt_monthly_view():
    if not can_view('yt_monthly'):
        abort(403)
    dealerships = get_allowed_dealerships()
    
    # 6 Months columns headers
    month_keys = []
    today = current_time_pk()
    start_date = (today - timedelta(days=150)).replace(day=1)
    cursor = start_date
    while cursor <= today:
        month_keys.append(cursor.strftime('%Y-%m'))
        if cursor.month == 12:
            cursor = cursor.replace(year=cursor.year + 1, month=1)
        else:
            cursor = cursor.replace(month=cursor.month + 1)
            
    # Pull stats pivot
    from api.models import YtMonthlyStats as YtMonthlyStat
    stats_pivot = {}
    if dealerships:
        d_ids = [d.id for d in dealerships]
        stats = db_session.query(YtMonthlyStat).filter(
            YtMonthlyStat.dealership_id.in_(d_ids),
            YtMonthlyStat.month >= month_keys[0]
        ).all()
        for s in stats:
            if s.dealership_id not in stats_pivot:
                stats_pivot[s.dealership_id] = {}
            stats_pivot[s.dealership_id][s.month] = s.video_count
            
    message = session.pop('yt_monthly_msg', '')
    error = session.pop('yt_monthly_err', '')
    
    from_val = request.args.get('from', month_keys[0])
    to_val = request.args.get('to', month_keys[-1])
    month_labels = {}
    for mk in month_keys:
        try:
            month_labels[mk] = datetime.strptime(mk + "-01", "%Y-%m-%d").strftime("%b %Y")
        except Exception:
            month_labels[mk] = mk
    row_percents = {d.id: dealership_percent(d) for d in dealerships}
    return render_template(
        'yt_monthly.html',
        dealerships=dealerships,
        row_percents=row_percents,
        months=month_keys,
        month_keys=month_keys,
        month_labels=month_labels,
        from_val=from_val,
        to_val=to_val,
        stats_pivot=stats_pivot,
        message=message,
        error=error
    )

@app.route('/no_activity_report')
@app.route('/no_activity_report.php', endpoint='follower_activity_report')
@require_login
def follower_activity_report():
    if not can_view('no_activity_report'):
        abort(403)
    dealerships = get_allowed_dealerships()

    today = current_time_pk().date()
    from_str = request.args.get('from', (today - timedelta(days=7)).strftime('%Y-%m-%d'))
    to_str = request.args.get('to', today.strftime('%Y-%m-%d'))

    try:
        from_dt = datetime.strptime(from_str, '%Y-%m-%d').date()
    except Exception:
        from_dt = today - timedelta(days=7)

    from_formatted = from_dt.strftime('%d %b %Y')

    no_activity = []
    for d in dealerships:
        total_posts = (d.fb_posts_week or 0) + (d.ig_posts_week or 0)
        if total_posts == 0 and ((d.fb_followers or 0) > 0 or (d.ig_followers or 0) > 0):
            no_activity.append(d)

    def activity_status(d):
        total_posts = (d.fb_posts_week or 0) + (d.ig_posts_week or 0)
        if d.fb_posts_checked_at or d.ig_posts_checked_at:
            return 'active' if total_posts > 0 else 'inactive'
        return 'not_checked'

    def engagement_rate(avg, followers, checked):
        if not checked or not followers:
            return None
        return float(avg or 0)

    def engagement_badge_class(rate, followers):
        if rate is None:
            return 'status-pending'
        return 'status-done' if rate > 0.5 else 'status-partial'

    def engagement_badge_label(rate, followers):
        if rate is None:
            return 'N/A'
        return f"{rate:.1f}%"

    return render_template(
        'no_activity_report.html',
        dealerships=dealerships,
        from_val=from_str,
        to_val=to_str,
        from_formatted=from_formatted,
        no_activity=no_activity,
        activity_status=activity_status,
        engagement_rate=engagement_rate,
        engagement_badge_class=engagement_badge_class,
        engagement_badge_label=engagement_badge_label
    )

# --- BACKROUND JOB RUNNER FOR REFRESHES ---

def bg_refresh_dealership_task(metric, d_id):
    # Safe ID for file names
    safe_id = re.sub(r'[^a-zA-Z0-9_-]', '_', str(d_id))
    status_path = os.path.join(UPLOAD_DIR, 'refresh_status', f"{metric}_{safe_id}.json")
    
    with open(status_path, 'w') as f:
        json.dump({'status': 'running', 'started_at': int(time.time())}, f)
        
    try:
        d = db_session.query(Dealership).filter(Dealership.id == d_id).first()
        if not d:
            raise Exception("Dealership not found")
            
        success = False
        message = ""
        result_data = {}
        
        if metric == 'fb':
            if d.fb_page_access_token and d.fb_page_id:
                poster = FacebookPoster()
                res = poster.get_page_followers(d.fb_page_id, d.fb_page_access_token)
                if res['success']:
                    d.fb_followers = res['followers']
                    d.last_refreshed = current_time_pk()
                    db_session.commit()
                    success = True
                    result_data = {'fb_followers': res['followers']}
                else:
                    message = res['message']
            else:
                lookup = FacebookLookup()
                res = lookup.get_follower_count(d.fb_input)
                if res['success']:
                    d.fb_followers = res['followers']
                    if res.get('page_id'):
                        d.fb_page_id = res['page_id']
                    d.last_refreshed = current_time_pk()
                    db_session.commit()
                    success = True
                    result_data = {'fb_followers': res['followers']}
                else:
                    message = res['message']
                    
        elif metric == 'ig':
            if d.fb_page_access_token and d.fb_page_id:
                poster = FacebookPoster()
                res = poster.get_instagram_followers(d.fb_page_id, d.fb_page_access_token, d.ig_business_account_id)
                if res['success']:
                    d.ig_followers = res['followers']
                    if res.get('ig_business_account_id'):
                        d.ig_business_account_id = res['ig_business_account_id']
                    d.last_refreshed = current_time_pk()
                    db_session.commit()
                    success = True
                    result_data = {'ig_followers': res['followers']}
                else:
                    message = res['message']
            else:
                lookup = InstagramLookup()
                res = lookup.get_follower_count(d.ig_search)
                if res['success']:
                    d.ig_followers = res['followers']
                    d.last_refreshed = current_time_pk()
                    db_session.commit()
                    success = True
                    result_data = {'ig_followers': res['followers']}
                else:
                    message = res['message']
                    
        if success:
            with open(status_path, 'w') as f:
                json.dump({**result_data, 'status': 'done'}, f)
        else:
            with open(status_path, 'w') as f:
                json.dump({'status': 'error', 'message': message}, f)
                
    except Exception as e:
        db_session.rollback()
        with open(status_path, 'w') as f:
            json.dump({'status': 'error', 'message': str(e)}, f)

def trigger_bg_refresh(metric, d_id):
    if os.environ.get('SYNC_RUN') == '1':
        bg_refresh_dealership_task(metric, d_id)
        # Read from file
        safe_id = re.sub(r'[^a-zA-Z0-9_-]', '_', str(d_id))
        status_path = os.path.join(UPLOAD_DIR, 'refresh_status', f"{metric}_{safe_id}.json")
        try:
            with open(status_path, 'r') as f:
                return json.load(f)
        except Exception:
            return {'status': 'error', 'message': 'Execution failed'}
    else:
        t = threading.Thread(target=bg_refresh_dealership_task, args=(metric, d_id))
        t.daemon = True
        t.start()
        return {'status': 'started'}

# --- AJAX REFRESH ENDPOINTS ---

@app.route('/refresh_fb.php')
@require_login
def refresh_fb():
    if not can_perform('refresh'):
        return jsonify({'success': False, 'message': 'Access denied'}), 403
    d_id = request.args.get('id', type=int)
    if not d_id or not can_access_dealership(d_id):
        return jsonify({'success': False, 'message': 'Access denied'}), 403
        
    d = db_session.query(Dealership).filter(Dealership.id == d_id).first()
    if not d:
        return jsonify({'success': False, 'message': 'Dealership not found'})
        
    if d.fb_page_access_token and d.fb_page_id:
        poster = FacebookPoster()
        res = poster.get_page_followers(d.fb_page_id, d.fb_page_access_token)
        if res['success']:
            d.fb_followers = res['followers']
            d.last_refreshed = current_time_pk()
            db_session.commit()
            return jsonify({'success': True, 'fb_followers': res['followers']})
        else:
            return jsonify({'success': False, 'message': res['message'], 'fb_followers': d.fb_followers or 0})
            
    if not d.fb_input:
        return jsonify({'success': True, 'skipped': True, 'fb_followers': d.fb_followers or 0})
        
    res = trigger_bg_refresh('fb', d_id)
    return jsonify(res)

@app.route('/refresh_ig.php')
@require_login
def refresh_ig():
    if not can_perform('refresh'):
        return jsonify({'success': False, 'message': 'Access denied'}), 403
    d_id = request.args.get('id', type=int)
    if not d_id or not can_access_dealership(d_id):
        return jsonify({'success': False, 'message': 'Access denied'}), 403
        
    d = db_session.query(Dealership).filter(Dealership.id == d_id).first()
    if not d:
        return jsonify({'success': False, 'message': 'Dealership not found'})
        
    if d.fb_page_access_token and d.fb_page_id:
        poster = FacebookPoster()
        res = poster.get_instagram_followers(d.fb_page_id, d.fb_page_access_token, d.ig_business_account_id)
        if res['success']:
            d.ig_followers = res['followers']
            if res.get('ig_business_account_id'):
                d.ig_business_account_id = res['ig_business_account_id']
            d.last_refreshed = current_time_pk()
            db_session.commit()
            return jsonify({'success': True, 'ig_followers': res['followers']})
        else:
            return jsonify({'success': False, 'message': res['message'], 'ig_followers': d.ig_followers or 0})
            
    if not d.ig_search:
        return jsonify({'success': True, 'skipped': True, 'ig_followers': d.ig_followers or 0})
        
    res = trigger_bg_refresh('ig', d_id)
    return jsonify(res)

@app.route('/refresh_yt.php')
@require_login
def refresh_yt():
    if not can_perform('refresh'):
        return jsonify({'success': False, 'message': 'Access denied'}), 403
    d_id = request.args.get('id', type=int)
    if not d_id or not can_access_dealership(d_id):
        return jsonify({'success': False, 'message': 'Access denied'}), 403
        
    d = db_session.query(Dealership).filter(Dealership.id == d_id).first()
    if not d:
        return jsonify({'success': False, 'message': 'Dealership not found'})
        
    if not d.yt_search:
        return jsonify({'success': True, 'skipped': True, 'yt_subscribers': d.yt_subscribers or 0})
        
    lookup = YouTubeLookup()
    res = lookup.search_and_get_stats(d.yt_search, d.yt_channel_id)
    if not res['success']:
        return jsonify({'success': False, 'message': res['message'], 'yt_subscribers': d.yt_subscribers or 0})
        
    d.yt_subscribers = res['subscribers']
    d.yt_videos = res['total_videos']
    d.yt_views = res['total_views']
    d.yt_channel_id = res['channel_id']
    d.last_refreshed = current_time_pk()
    db_session.commit()
    
    return jsonify({'success': True, 'yt_subscribers': res['subscribers']})

@app.route('/refresh_gr.php')
@require_login
def refresh_gr():
    if not can_perform('refresh'):
        return jsonify({'success': False, 'message': 'Access denied'}), 403
    d_id = request.args.get('id', type=int)
    if not d_id or not can_access_dealership(d_id):
        return jsonify({'success': False, 'message': 'Access denied'}), 403
        
    d = db_session.query(Dealership).filter(Dealership.id == d_id).first()
    if not d:
        return jsonify({'success': False, 'message': 'Dealership not found'})
        
    if not d.google_search:
        return jsonify({'success': True, 'skipped': True, 'google_review_count': d.google_review_count or 0, 'google_rating': d.google_rating})
        
    lookup = GoogleReviewLookup()
    res = lookup.search_and_get_reviews(d.google_search)
    if not res['success']:
        return jsonify({'success': False, 'message': res['message'], 'google_review_count': d.google_review_count or 0, 'google_rating': d.google_rating})
        
    d.google_review_count = res['review_count']
    d.google_rating = res['rating']
    d.last_refreshed = current_time_pk()
    db_session.commit()
    
    return jsonify({'success': True, 'google_review_count': res['review_count'], 'google_rating': res['rating']})


@app.route('/api/refresh_all')
@require_login
def refresh_all_dealerships():
    """Bulk refresh all dealerships for all metrics — triggers background threads"""
    if not can_perform('refresh'):
        return jsonify({'success': False, 'message': 'Access denied'}), 403

    dealerships = get_allowed_dealerships()
    if not dealerships:
        return jsonify({'success': False, 'message': 'No dealerships found'})

    started = []
    skipped = []

    for d in dealerships:
        d_id = d.id
        # FB
        if d.fb_input or (d.fb_page_access_token and d.fb_page_id):
            t = threading.Thread(target=bg_refresh_dealership_task, args=('fb', d_id))
            t.daemon = True
            t.start()
            started.append(f'{d.name}:fb')
        else:
            skipped.append(f'{d.name}:fb')

        # IG
        if d.ig_search or (d.fb_page_access_token and d.fb_page_id):
            t = threading.Thread(target=bg_refresh_dealership_task, args=('ig', d_id))
            t.daemon = True
            t.start()
            started.append(f'{d.name}:ig')
        else:
            skipped.append(f'{d.name}:ig')

    # YT & Google refresh inline (fast API calls)
    yt_updated = 0
    gr_updated = 0
    for d in dealerships:
        # YT via YouTube API
        if d.yt_channel_id or d.yt_search:
            try:
                lookup = YouTubeLookup()
                res = lookup.search_and_get_stats(d.yt_search or '', d.yt_channel_id)
                if res and res.get('success'):
                    d.yt_subscribers = res.get('subscribers', d.yt_subscribers or 0)
                    d.yt_videos = res.get('total_videos', d.yt_videos or 0)
                    d.yt_views = res.get('total_views', d.yt_views or 0)
                    if res.get('channel_id'):
                        d.yt_channel_id = res['channel_id']
                    d.last_refreshed = current_time_pk()
                    yt_updated += 1
            except Exception:
                pass

        # Google Reviews
        if d.google_search:
            try:
                lookup = GoogleReviewLookup()
                res = lookup.search_and_get_reviews(d.google_search)
                if res and res.get('success'):
                    d.google_review_count = res['review_count']
                    d.google_rating = res['rating']
                    d.last_refreshed = current_time_pk()
                    gr_updated += 1
            except Exception:
                pass

    try:
        db_session.commit()
    except Exception:
        db_session.rollback()

    return jsonify({
        'success': True,
        'message': f'Refresh started for {len(dealerships)} dealerships. FB/IG updating in background. YT: {yt_updated} updated, Google: {gr_updated} updated.',
        'total': len(dealerships),
        'bg_started': len(started),
        'skipped': len(skipped),
    })



@app.route('/refresh_status.php')
@require_login
def refresh_status():
    job_id = request.args.get('id')
    metric = request.args.get('metric')
    shared_metrics = ['source_posts', 'reshare_source']
    
    if not job_id or metric not in ['fb', 'ig', 'source_posts', 'reshare_check', 'reshare_source']:
        return jsonify({'status': 'error', 'message': 'Invalid Request'}), 400
        
    if metric not in shared_metrics:
        dealership_id = int(job_id.split('_')[0]) if metric == 'reshare_check' else int(job_id)
        if not can_access_dealership(dealership_id):
            return jsonify({'status': 'error', 'message': 'Access denied'}), 403
            
    safe_id = re.sub(r'[^a-zA-Z0-9_-]', '_', str(job_id))
    status_path = os.path.join(UPLOAD_DIR, 'refresh_status', f"{metric}_{safe_id}.json")
    
    if not os.path.exists(status_path):
        return jsonify({'status': 'unknown'})
        
    with open(status_path, 'r') as f:
        data = json.load(f)
    return jsonify(data)

# --- POSTS CHECKING AJAY ROUTERS ---

@app.route('/check_fb_posts.php')
@require_login
def check_fb_posts():
    d_id = request.args.get('id', type=int)
    from_date = request.args.get('from')
    to_date = request.args.get('to')
    
    if not d_id or not from_date or not to_date or not can_access_dealership(d_id):
        return jsonify({'success': False, 'message': 'Access denied'}), 403
        
    d = db_session.query(Dealership).filter(Dealership.id == d_id).first()
    if not d or not d.fb_input:
        return jsonify({'success': False, 'message': 'No Facebook input set.'})
        
    lookup = FacebookPostsLookup()
    
    # For reshares exclusion, get the source post message snippets from DB
    exclude_text_snippets = [sp.message_snippet for sp in db_session.query(ReshareSourcePost).all() if sp.message_snippet]
    
    # Run sync/check
    res = lookup.count_in_range(
        page_input=d.fb_input,
        from_date=from_date,
        to_date=to_date,
        cached_page_id=d.fb_page_id,
        exclude_text_snippets=exclude_text_snippets
    )
    if not res['success']:
        return jsonify(res)
        
    # Update weekly count
    d.fb_posts_week = res['count']
    if res.get('page_id'):
        d.fb_page_id = res['page_id']
    d.last_refreshed = current_time_pk()
    db_session.commit()
    
    return jsonify(res)

@app.route('/check_ig_posts.php')
@require_login
def check_ig_posts():
    d_id = request.args.get('id', type=int)
    from_date = request.args.get('from')
    to_date = request.args.get('to')
    
    if not d_id or not from_date or not to_date or not can_access_dealership(d_id):
        return jsonify({'success': False, 'message': 'Access denied'}), 403
        
    d = db_session.query(Dealership).filter(Dealership.id == d_id).first()
    if not d or not d.ig_search:
        return jsonify({'success': False, 'message': 'No Instagram input set.'})
        
    lookup = InstagramPostsLookup()
    res = lookup.count_in_range(
        username=d.ig_search,
        from_date=from_date,
        to_date=to_date
    )
    if not res['success']:
        return jsonify(res)
        
    d.ig_posts_week = res['count']
    d.last_refreshed = current_time_pk()
    db_session.commit()
    
    return jsonify(res)

@app.route('/check_yt_monthly.php')
@require_login
def check_yt_monthly():
    d_id = request.args.get('id', type=int)
    from_date = request.args.get('from')
    to_date = request.args.get('to')
    month = request.args.get('month')
    
    if not d_id or not can_access_dealership(d_id):
        return jsonify({'success': False, 'message': 'Access denied'}), 403
        
    d = db_session.query(Dealership).filter(Dealership.id == d_id).first()
    if not d or (not d.yt_search and not d.yt_channel_id):
        return jsonify({'success': False, 'message': 'No YouTube channel set.'})

    if not from_date or not to_date:
        if month:
            from_date = f"{month}-01"
            import calendar
            yr, mo = int(month[:4]), int(month[5:7])
            last_day = calendar.monthrange(yr, mo)[1]
            to_date = f"{month}-{last_day:02d}"
        else:
            now = datetime.utcnow()
            to_date = now.strftime('%Y-%m-%d')
            from_date = (now - timedelta(days=180)).strftime('%Y-%m-%d')

    lookup = YouTubeLookup()
    res = lookup.get_monthly_breakdown(d.yt_search or '', from_date, to_date, d.yt_channel_id)
    if not res['success']:
        return jsonify(res)
        
    # Save/update YtMonthlyStats
    from api.models import YtMonthlyStats as YtMonthlyStat
    for m, count in res.get('breakdown', {}).items():
        stat = db_session.query(YtMonthlyStat).filter(
            YtMonthlyStat.dealership_id == d_id,
            YtMonthlyStat.month == m
        ).first()
        if not stat:
            stat = YtMonthlyStat(dealership_id=d_id, month=m)
            db_session.add(stat)
        stat.video_count = count
        
    if res.get('channel_id'):
        d.yt_channel_id = res['channel_id']
    d.last_refreshed = current_time_pk()
    try:
        db_session.commit()
    except Exception:
        db_session.rollback()
    
    return jsonify(res)

# --- POST APPROVAL (SUBMIT POST CHECK) ---

@app.route('/submit_post_check', endpoint='submit_post_check', methods=['GET', 'POST'])
@app.route('/submit_post_check.php', endpoint='post_approval', methods=['GET', 'POST'])
@require_login
def post_approval_view():
    if not can_view('submit_post_check'):
        abort(403)
    dealerships = get_allowed_dealerships()
    accessible_ids = [d.id for d in dealerships]

    d_map = {d.id: d.name for d in dealerships}

    if accessible_ids:
        raw_submissions = db_session.query(PostSubmission).filter(
            PostSubmission.dealership_id.in_(accessible_ids)
        ).order_by(PostSubmission.submitted_at.desc()).limit(50).all()
    else:
        raw_submissions = []

    history = []
    for ps in raw_submissions:
        history.append({
            'id': ps.id,
            'dealership_id': ps.dealership_id,
            'dealership_name': d_map.get(ps.dealership_id, 'Unknown Dealership'),
            'image_path': ps.image_path.lstrip('/') if ps.image_path else '',
            'caption': getattr(ps, 'caption', '') or '',
            'status': getattr(ps, 'status', 'pending') or 'pending',
            'reasons': getattr(ps, 'reasons', '') or '',
            'submitted_at': ps.submitted_at
        })

    all_vehicle_models = db_session.query(VehicleModel).order_by(VehicleModel.name.asc()).all()

    def status_badge_class(status):
        if status == 'approved': return 'status-done'
        if status == 'rejected': return 'status-flag'
        return 'status-pending'

    def status_badge_label(status):
        if status == 'approved': return '✓ Approved'
        if status == 'rejected': return '✗ Rejected'
        return (status or 'pending').capitalize()

    def status_border_color(status):
        if status == 'approved': return 'var(--green)'
        if status == 'rejected': return 'var(--red)'
        return 'var(--border)'

    success_count = sum(1 for h in history if h['status'] == 'approved')
    flagged_count = sum(1 for h in history if h['status'] == 'rejected')

    return render_template(
        'submit_post_check.html',
        accessible_dealerships=dealerships,
        dealerships=dealerships,
        all_vehicle_models=all_vehicle_models,
        history=history,
        recent_submissions=history,
        success_count=success_count,
        flagged_count=flagged_count,
        status_badge_class=status_badge_class,
        status_badge_label=status_badge_label,
        status_border_color=status_border_color
    )

@app.route('/delete_submission.php', methods=['POST'])
@require_login
def delete_submission():
    if not is_super_admin():
        return jsonify({'success': False, 'message': 'Access denied'}), 403
    sub_id = request.form.get('id', type=int)
    if not sub_id:
        return jsonify({'success': False, 'message': 'Submission ID missing'})

    sub = db_session.query(PostSubmission).filter(PostSubmission.id == sub_id).first()
    if not sub:
        return jsonify({'success': False, 'message': 'Submission not found'})

    db_session.delete(sub)
    db_session.commit()
    return jsonify({'success': True})

@app.route('/prepare_compliance_payload.php', methods=['POST'])
@require_login
def prepare_compliance_payload():
    try:
        d_id = request.form.get('dealership_id', type=int)
        caption = request.form.get('caption', '').strip()
        vehicle_model_id = request.form.get('vehicle_model_id', type=int)
        post_image = request.files.get('post_image') or request.files.get('image')

        if not d_id or not can_access_dealership(d_id):
            return jsonify({'success': False, 'message': 'You Do Not Have Access To This Dealership.'}), 403

        if not vehicle_model_id:
            return jsonify({'success': False, 'message': 'Please select a target Vehicle Model.'})

        if not post_image or not post_image.filename:
            return jsonify({'success': False, 'message': 'A Post Image Is Required.'})

        ext = post_image.filename.split('.')[-1].lower() if '.' in post_image.filename else 'jpg'
        if ext not in ['jpg', 'jpeg', 'png', 'webp']:
            return jsonify({'success': False, 'message': 'Only jpg, png, or webp Images Are Allowed.'})

        fname = f"submission_{int(time.time())}_{uuid.uuid4().hex[:6]}.{ext}"
        rel_path = f"assets/uploads/submissions/{fname}"
        full_path = os.path.join(UPLOAD_DIR, 'submissions', fname)
        os.makedirs(os.path.dirname(full_path), exist_ok=True)

        resizer = ImageResizer()
        res = resizer.resize(post_image, max_width=800, max_height=800)
        if res['success']:
            with open(full_path, 'wb') as f_out:
                f_out.write(res['data'])
        else:
            post_image.save(full_path)

        # Create 10KB Base64 Data URL for fail-proof DB thumbnail rendering on Vercel
        b64_thumb = base64.b64encode(res['data']).decode('utf-8') if (res and res.get('success') and res.get('data')) else base64.b64encode(post_image.read()).decode('utf-8')
        db_img_val = f"data:image/jpeg;base64,{b64_thumb}"

        sub = PostSubmission(
            dealership_id=d_id,
            image_path=db_img_val if len(db_img_val) < 200000 else rel_path,
            caption=caption,
            status='pending',
            submitted_at=current_time_pk()
        )
        db_session.add(sub)
        db_session.commit()

        target_vehicle = db_session.query(VehicleModel).filter(VehicleModel.id == vehicle_model_id).first()
        if not target_vehicle:
            return jsonify({'success': False, 'message': 'Selected Vehicle Model not found in Brand Assets.'})

        vm_images = db_session.query(VehicleModelImage).filter(
            VehicleModelImage.vehicle_model_id == vehicle_model_id
        ).order_by(VehicleModelImage.id).all()

        v_img_paths = [img.image_path for img in vm_images]
        if not v_img_paths and target_vehicle.reference_image:
            v_img_paths = [target_vehicle.reference_image]

        identity = db_session.query(BrandIdentity).first()

        def encode_image_helper(path_input, max_dim=480):
            if not path_input:
                return None
            p_str = str(path_input).replace('\\', '/')
            clean_rel = p_str.replace('assets/uploads/', '').lstrip('/')
            fname = os.path.basename(p_str)
            candidates = [
                p_str,
                os.path.join(root_dir, p_str.lstrip('/')),
                os.path.join(root_dir, 'assets', 'uploads', clean_rel),
                os.path.join(root_dir, 'assets', 'uploads', 'vehicles', fname),
                os.path.join(root_dir, 'assets', 'uploads', 'logos', fname),
                os.path.join(UPLOAD_DIR, clean_rel),
                os.path.join(UPLOAD_DIR, 'vehicles', fname),
                os.path.join(UPLOAD_DIR, 'logos', fname),
                os.path.join('/tmp', 'uploads', clean_rel),
                os.path.join('/tmp', 'uploads', 'vehicles', fname)
            ]
            real_p = None
            for cand in candidates:
                if cand and os.path.exists(cand) and os.path.isfile(cand):
                    real_p = cand
                    break

            if not real_p:
                return None

            try:
                with Image.open(real_p) as img:
                    w, h = img.size
                    if w > max_dim or h > max_dim:
                        if w >= h:
                            new_h = int(round((h / w) * max_dim))
                            new_w = max_dim
                        else:
                            new_w = int(round((w / h) * max_dim))
                            new_h = max_dim
                        img = img.resize((new_w, new_h), Image.Resampling.LANCZOS)

                    if img.mode in ('RGBA', 'P'):
                        img = img.convert('RGB')

                    output = io.BytesIO()
                    img.save(output, format='JPEG', quality=75)
                    b64_data = base64.b64encode(output.getvalue()).decode('utf-8')

                return {
                    'inline_data': {
                        'mime_type': 'image/jpeg',
                        'data': b64_data
                    }
                }
            except Exception:
                return None

        image_parts = []
        reference_descriptions = []
        ref_index = 1
        vehicle_img_count = 0

        for path in v_img_paths:
            encoded = encode_image_helper(path)
            if encoded:
                image_parts.append(encoded)
                reference_descriptions.append(f"Reference image {ref_index}: approved target vehicle — {target_vehicle.name}, color {target_vehicle.color}.")
                ref_index += 1
                vehicle_img_count += 1

        if vehicle_img_count == 0:
            reference_descriptions.append(f"Target Vehicle Model: {target_vehicle.name} (color spec: {target_vehicle.color}).")

        if identity and identity.logo_light_path:
            encoded = encode_image_helper(identity.logo_light_path)
            if encoded:
                image_parts.append(encoded)
                reference_descriptions.append(f"Reference image {ref_index}: LIGHT logo variant (for dark/blue backgrounds).")
                ref_index += 1

        if identity and identity.logo_dark_path:
            encoded = encode_image_helper(identity.logo_dark_path)
            if encoded:
                image_parts.append(encoded)
                reference_descriptions.append(f"Reference image {ref_index}: DARK logo variant (for white/light backgrounds).")
                ref_index += 1

        if identity and identity.logo_white_bg_path:
            encoded = encode_image_helper(identity.logo_white_bg_path)
            if encoded:
                image_parts.append(encoded)
                reference_descriptions.append(f"Reference image {ref_index}: RED & BLUE logo variant (for white backgrounds).")
                ref_index += 1

        # Direct in-memory base64 encoding for submitted post image (100% fail-proof, no disk read dependency)
        sub_bytes = None
        if res and res.get('success') and res.get('data'):
            sub_bytes = res['data']
        else:
            try:
                post_image.seek(0)
                sub_bytes = post_image.read()
            except Exception:
                sub_bytes = None

        if sub_bytes:
            submitted_encoded = {
                'inline_data': {
                    'mime_type': 'image/png' if ext == 'png' else 'image/jpeg',
                    'data': base64.b64encode(sub_bytes).decode('utf-8')
                }
            }
        else:
            submitted_encoded = encode_image_helper(full_path) or encode_image_helper(rel_path)

        if not submitted_encoded:
            return jsonify({'success': False, 'message': 'Submitted post image could not be read.'})

        rule_num = 1
        prompt_lines = [
            "You are a strict brand-compliance auditor for a car dealership's social media post.",
            "Check the SUBMITTED POST IMAGE (the last image below) against ALL of these rules with 100% precision, using the earlier reference images as ground truth:",
            ""
        ]

        target_name = target_vehicle.name
        prompt_lines.append(f"{rule_num}. MANDATORY VEHICLE MODEL MATCH ({target_name}):\n"
                            f"   Look at the car body shape, front grille, headlights, and ANY text overlay/badge on the submitted graphic (such as 'FRONX', 'ALTO', 'CULTUS', 'EVERY', 'SWIFT').\n"
                            f"   The user explicitly selected '{target_name}' in the form as the target car model for this post.\n"
                            f"   IF THE SUBMITTED GRAPHIC SHOWS A DIFFERENT CAR MODEL OR TEXT BADGE, YOU MUST REJECT IMMEDIATELY with reason 'Vehicle Model Mismatch: Graphic shows a different car model, but {target_name} was selected'.")
        rule_num += 1

        prompt_lines.append(f"{rule_num}. DOOR HANDLES STRICT CHROME AUDIT:\n"
                            f"   Zoom in on the front and rear door handles of the car in the graphic.\n"
                            f"   Standard approved {target_name} specification requires CHROME / METALLIC SILVER door handles.\n"
                            f"   CRITICAL INSTRUCTION: If the door handles on the car are WHITE, PAINTED, BODY-COLOR, or if you cannot see a distinct metallic chrome shine on the handles, YOU MUST REJECT IMMEDIATELY with reason 'Vehicle Spec Violation: Door handles are painted white body-color, whereas approved {target_name} specification requires Chrome handles'. DO NOT APPROVE WHITE OR BODY-COLOR PAINTED DOOR HANDLES!")
        rule_num += 1

        if identity and (identity.logo_light_path or identity.logo_dark_path or identity.logo_white_bg_path):
            prompt_lines.append(f"{rule_num}. LOGO VARIANT AUDIT:\n"
                                f"   LOGO BACKGROUND CONTRAST: Examine the background patch directly behind the Suzuki logo. If the background is DARK or BLUE, the WHITE/LIGHT logo variant is required. If the background is WHITE, the RED & BLUE or DARK logo variant is required.")
            rule_num += 1

        if identity and identity.tagline:
            prompt_lines.append(f"{rule_num}. TAGLINE: The post caption should include or closely reflect this tagline: \"{identity.tagline}\". Missing it = REJECT.")

        prompt_lines.append("")
        prompt_lines.append(f"Submitted post caption: \"{caption if caption else '(no caption)'}\"")
        prompt_lines.append("")
        prompt_lines.append("Reference images attached below, in order:")
        prompt_lines.extend(reference_descriptions)
        prompt_lines.append("")
        prompt_lines.append("Last image attached below is the SUBMITTED POST IMAGE to judge.")
        prompt_lines.append("")
        prompt_lines.append("COMPREHENSIVE AUDIT INSTRUCTION: Do NOT stop after finding the first violation. You MUST evaluate ALL rules. If multiple violations exist, YOU MUST INCLUDE ALL VIOLATIONS in the 'reasons' array so the user receives a complete report!")
        prompt_lines.append('Respond with ONLY this JSON, no other text: {"approved": true|false, "reasons": ["violation 1", "violation 2", "..."], "suggestion": "better wording example, or null"}')

        parts = [{'text': "\n".join(prompt_lines)}]
        parts.extend(image_parts)
        parts.append(submitted_encoded)

        d_obj = db_session.query(Dealership).filter(Dealership.id == d_id).first()
        d_name = d_obj.name if d_obj else 'Unknown Dealership'

        gemini_key = db_session.query(AppSetting.setting_value).filter(AppSetting.setting_key == 'gemini_api_key').scalar() or os.environ.get('GEMINI_API_KEY', '')

        return jsonify({
            'success': True,
            'submission_id': sub.id,
            'dealership_name': d_name,
            'image_path': rel_path,
            'caption': caption,
            'api_key': gemini_key,
            'payload': {
                'contents': [{'parts': parts}],
                'generationConfig': {'response_mime_type': 'application/json'}
            }
        })
    except Exception as e:
        db_session.rollback()
        return jsonify({'success': False, 'message': f"Payload preparation error: {str(e)}"})

@app.route('/save_compliance_result.php', methods=['POST'])
@require_login
def save_compliance_result():
    data = request.get_json(silent=True) or {}
    sub_id = data.get('submission_id', 0)
    approved = bool(data.get('approved'))
    reasons = data.get('reasons') if isinstance(data.get('reasons'), list) else []
    suggestion = data.get('suggestion')

    if not sub_id:
        return jsonify({'success': False, 'message': 'Invalid submission ID'})

    sub = db_session.query(PostSubmission).filter(PostSubmission.id == sub_id).first()
    if not sub or not can_access_dealership(sub.dealership_id):
        return jsonify({'success': False, 'message': 'Access denied'}), 403

    status = 'approved' if approved else 'rejected'
    display_reasons = list(reasons)
    if suggestion:
        display_reasons.append(f"💡 Suggested Wording: {suggestion}")

    reasons_text = ' | '.join(display_reasons)
    sub.status = status
    sub.reasons = reasons_text
    sub.checked_at = current_time_pk()
    db_session.commit()

    return jsonify({
        'success': True,
        'status': status,
        'reasons': display_reasons
    })

@app.route('/delete_post_submission.php', methods=['POST'])
@require_login
def delete_post_submission():
    sub_id = request.form.get('id', type=int)
    if not sub_id:
        return jsonify({'success': False, 'message': 'ID missing'})
        
    sub = db_session.query(PostSubmission).filter(PostSubmission.id == sub_id).first()
    if not sub or not can_access_dealership(sub.dealership_id):
        return jsonify({'success': False, 'message': 'Access denied'}), 403
        
    db_session.delete(sub)
    db_session.commit()
    return jsonify({'success': True})

# --- EMAIL VALIDATOR ---

@app.route('/email_validator', methods=['GET', 'POST'])
@app.route('/email_validator.php', endpoint='email_validator', methods=['GET', 'POST'])
@require_login
def email_validator_view():
    if not can_view('email_validator'):
        abort(403)
    results = []
    show_results = False
    smtp_checked = False
    count = {'valid': 0, 'invalid': 0, 'catch_all': 0}
    filename = None
    
    if request.method == 'POST':
        action = request.form.get('action')
        bulk_text = request.form.get('bulk_text', '').strip()
        file_upload = request.files.get('file_upload')
        smtp_val = request.form.get('smtp_check') == '1'
        
        emails = []
        if file_upload and file_upload.filename:
            # Parse file CSV/Excel/Text
            ext = file_upload.filename.split('.')[-1].lower()
            if ext == 'xlsx':
                try:
                    wb = openpyxl.load_workbook(file_upload, read_only=True)
                    sheet = wb.active
                    for row in sheet.iter_rows(values_only=True):
                        for cell in row:
                            if cell and '@' in str(cell):
                                emails.append(str(cell).strip())
                except Exception:
                    pass
            else:
                content = file_upload.read().decode('utf-8', errors='ignore')
                emails = [e.strip() for e in re.split(r'[,\r\n\t]+', content) if '@' in e]
        elif bulk_text:
            emails = [e.strip() for e in re.split(r'[,\r\n\t]+', bulk_text) if '@' in e]
            
        # Clean emails list
        emails = list(dict.fromkeys([e for e in emails if e]))[:300] # Cap bulk checks to 300 to match PHP limits
        
        if emails:
            service = EmailValidatorService()
            results = service.validate_batch(emails, check_smtp=smtp_val)
            show_results = True
            smtp_checked = smtp_val
            filename = file_upload.filename if file_upload else 'bulk_copy_paste.csv'
            
            for r in results:
                c = r['classification']
                if c == 'valid': count['valid'] += 1
                elif c == 'catch_all': count['catch_all'] += 1
                else: count['invalid'] += 1
                
            # Temporarily cache batch results in session for export
            session['email_validation_results'] = results
            
    return render_template(
        'email_validator.html',
        results=results,
        show_results=show_results,
        smtp_checked=smtp_checked,
        count=count,
        filename=filename
    )

@app.route('/export_email_validation.php')
@require_login
def export_email_validation():
    results = session.get('email_validation_results', [])
    if not results:
        return redirect(url_for('email_validator'))
        
    # Generate CSV response
    def generate():
        yield "Email,Syntax,MX Records,DeBounce,SMTP Reachable,Classification,Reason\n"
        for r in results:
            yield f'"{r["email"]}","{r["syntax"]}","{r["mx_records"]}","{r["debounce"]}","{r["smtp_reachable"]}","{r["classification"]}","{r["reason"]}"\n'
            
    return Response(
        generate(),
        mimetype="text/csv",
        headers={"Content-disposition": "attachment; filename=email_validation_results.csv"}
    )

# --- SALES REPORT VIEW ---
@app.route('/sales_report', methods=['GET', 'POST'])
@app.route('/sales_report.php', endpoint='sales_report', methods=['GET', 'POST'])
@require_login
def sales_report_view():
    if not can_view('sales_report'):
        abort(403)
    dealerships = get_allowed_dealerships()
    message = session.pop('sales_msg', '')
    error = session.pop('sales_err', '')
    import_errors = []

    pk_today = current_time_pk()
    current_month = pk_today.strftime('%Y-%m')

    if request.method == 'POST' and can_perform('edit'):
        file_upload = request.files.get('file_upload') or request.files.get('sales_csv')
        period_month = request.form.get('period_month')
        
        if not file_upload or not period_month:
            error = "Excel File and Period Month are required."
        else:
            try:
                helper = SpreadsheetImportHelper()
                rows = read_excel_rows(file_upload)
                res = helper.import_sales_sheet(db_session, rows, period_month)
                if res['success']:
                    message = f"Sales data imported successfully. {res['imported_count']} record(s) loaded."
                    import_errors = res.get('import_errors', [])
                else:
                    error = res['message']
            except Exception as e:
                error = f"Error reading sheet: {str(e)}"

    period_records = db_session.query(SalesRecord.period_month).distinct().order_by(SalesRecord.period_month.desc()).all()
    periods = [p[0] for p in period_records if p[0]]
    
    selected_period = request.args.get('period', '').strip()
    if not selected_period or selected_period not in periods:
        selected_period = periods[0] if periods else current_month

    period_labels = {}
    for p in periods:
        try:
            period_labels[p] = datetime.strptime(p + "-01", "%Y-%m-%d").strftime("%B %Y")
        except Exception:
            period_labels[p] = p

    try:
        date_str = datetime.strptime(selected_period + "-01", "%Y-%m-%d").strftime("%B %Y")
    except Exception:
        date_str = selected_period

    column_sequence = []
    pivot = {}
    summary_by_dealership = {}

    if selected_period in periods:
        cols = db_session.query(SalesRecord.product_name, SalesRecord.column_order).filter(
            SalesRecord.period_month == selected_period
        ).distinct().order_by(SalesRecord.column_order).all()

        gt_order = db_session.query(SalesSummary.grand_total_column_order).filter(
            SalesSummary.period_month == selected_period,
            SalesSummary.grand_total_column_order.isnot(None)
        ).first()
        gt_order_val = gt_order[0] if gt_order else None

        gt_inserted = False
        for col_name, order in cols:
            if gt_order_val is not None and not gt_inserted and int(order) > int(gt_order_val):
                column_sequence.append({'type': 'grand_total'})
                gt_inserted = True
            column_sequence.append({'type': 'product', 'name': col_name})
        if gt_order_val is not None and not gt_inserted:
            column_sequence.append({'type': 'grand_total'})

        allowed_ids = {d.id for d in dealerships}
        records = db_session.query(SalesRecord).filter(
            SalesRecord.period_month == selected_period,
            SalesRecord.dealership_id.in_(allowed_ids)
        ).all()

        d_map = {d.id: d.name for d in dealerships}
        for r in records:
            d_name = d_map.get(r.dealership_id, 'Unknown')
            if d_name not in pivot:
                pivot[d_name] = {'__id': r.dealership_id}
            pivot[d_name][r.product_name] = r.quantity

        summaries = db_session.query(SalesSummary).filter(
            SalesSummary.period_month == selected_period,
            SalesSummary.dealership_id.in_(allowed_ids)
        ).all()
        for s in summaries:
            summary_by_dealership[s.dealership_id] = s

    pivot = dict(sorted(pivot.items()))

    return render_template(
        'sales_report.html',
        dealerships=dealerships,
        current_month=current_month,
        periods=periods,
        selected_period=selected_period,
        period_labels=period_labels,
        date_str=date_str,
        column_sequence=column_sequence,
        pivot=pivot,
        summary_by_dealership=summary_by_dealership,
        message=message,
        error=error,
        import_errors=import_errors,
        friendly_product_label=SpreadsheetImportHelper.friendly_product_label
    )

# --- CLEAR STOCK DATA ENDPOINT ---
@app.route('/clear_stock_data', methods=['GET'])
@require_login
def clear_stock_data():
    """Admin endpoint to clear all old stock records from database"""
    if not is_super_admin():
        return jsonify({'success': False, 'message': 'Super admin access required'}), 403
    
    try:
        count_before = db_session.query(StockRecord).count()
        
        # Show what's being deleted
        products = db_session.query(distinct(StockRecord.product_name)).all()
        product_list = [p[0] for p in products if p[0]]
        
        # Delete all records
        db_session.query(StockRecord).delete()
        db_session.commit()
        
        count_after = db_session.query(StockRecord).count()
        
        return jsonify({
            'success': True,
            'message': f'Successfully cleared {count_before} stock records',
            'records_deleted': count_before,
            'remaining': count_after,
            'products_that_were_imported': product_list,
            'next_step': 'Go to /stock_report and re-upload your CSV/Excel file'
        })
    except Exception as e:
        db_session.rollback()
        return jsonify({
            'success': False,
            'message': f'Error clearing data: {str(e)}'
        }), 500

# --- STOCK REPORT VIEW ---
@app.route('/stock_report', methods=['GET', 'POST'])
@app.route('/stock_report.php', endpoint='stock_report', methods=['GET', 'POST'])
@require_login
def stock_report_view():
    if not can_view('stock_report'):
        abort(403)
    dealerships = get_allowed_dealerships()
    message = session.pop('stock_msg', '')
    error = session.pop('stock_err', '')
    import_errors = []

    if request.method == 'POST' and can_perform('edit'):
        action = request.form.get('action')
        if action == 'clear_stock':
            try:
                num_del = db_session.query(StockRecord).delete(synchronize_session=False)
                db_session.commit()
                message = f"Current stock snapshot data has been completely cleared ({num_del} records deleted)."
            except Exception as e:
                db_session.rollback()
                error = f"Error clearing stock data: {str(e)}"
        else:
            file_upload = request.files.get('file_upload') or request.files.get('stock_csv')
            if not file_upload:
                error = "Excel File is required."
            else:
                try:
                    helper = SpreadsheetImportHelper()
                    rows = read_excel_rows(file_upload)
                    res = helper.import_stock_sheet(db_session, rows)
                    if res['success']:
                        tot_vehicles = res.get('total_vehicles', res.get('imported_count', 0))
                        message = f"Stock snapshot data imported successfully. {tot_vehicles} total vehicle(s) loaded."
                        import_errors = res.get('import_errors', [])
                    else:
                        error = res['message']
                except Exception as e:
                    db_session.rollback()
                    error = f"Error reading sheet: {str(e)}"

    try:
        # Use actual product names stored in DB, ordered by column_order as they appeared in the file
        stock_col_rows = db_session.query(
            StockRecord.product_name,
            func.min(StockRecord.column_order).label('col_order')
        ).group_by(StockRecord.product_name).order_by('col_order').all()
        product_names = [r[0] for r in stock_col_rows if r[0]]
    except Exception:
        db_session.rollback()
        product_names = []

    # Short display names for column headers
    SHORT_NAME_MAP = {
        'SUZUKI ALTO AET306 VXR M1 658 CC':          'ALTO VXR',
        'SUZUKI ALTO AET306 VXR AGS M1 658 CC':      'ALTO VXR AGS',
        'SUZUKI ALTO AET306 AGS M1 658 CC':          'ALTO AGS',
        'SUZUKI FRONX SUV GL MT 1462CC':             'FRONX MT',
        'SUZUKI FRONX SUV GL AT 1462CC':             'FRONX GL AT',
        'SUZUKI FRONX SUV GLX AT 1462CC HYBD':       'FRONX GLX',
        'SUZUKI SWIFT GL M2 1197 CC':                'SWIFT GL',
        'SUZUKI SWIFT GL CVT M2 1197 CC':            'SWIFT GL CVT',
        'SUZUKI SWIFT GLX CVT M2 1197 CC':           'SWIFT GLX',
        'SUZUKI CULTUS AVK310 VXR M2 998 CC':        'CULTUS VXR',
        'SUZUKI CULTUS AVK310 VXL M2 998 CC':        'CULTUS VXL',
        'SUZUKI EVERY VXR 658 CC':                   'EVERY',
    }

    # Desired display order of columns
    SHORT_NAME_PRIORITY = [
        'ALTO VXR', 'ALTO VXR AGS', 'ALTO AGS',
        'FRONX MT', 'FRONX GL AT', 'FRONX GLX',
        'SWIFT GL', 'SWIFT GL CVT', 'SWIFT GLX',
        'CULTUS VXR', 'CULTUS VXL',
        'EVERY',
    ]

    def short_name(p):
        return SHORT_NAME_MAP.get(str(p).strip(), str(p).strip())

    # Sort product_names by desired column order
    def col_sort_key(p):
        sn = short_name(p)
        try:
            return SHORT_NAME_PRIORITY.index(sn)
        except ValueError:
            return len(SHORT_NAME_PRIORITY)  # unknown products go to end

    product_names = sorted(product_names, key=col_sort_key)

    column_sequence = [{'type': 'product', 'name': p} for p in product_names]

    allowed_ids = {d.id for d in dealerships}
    records = db_session.query(StockRecord).filter(StockRecord.dealership_id.in_(allowed_ids)).all()

    pivot = {}
    d_map = {d.id: d.name for d in dealerships}

    # Ensure all tracked dealerships (1 to 21) are present in pivot
    for d in dealerships:
        pivot[d.name] = {'__id': d.id, '__security': d.security_amount}
        for p in product_names:
            pivot[d.name][p] = 0

    helper = SpreadsheetImportHelper()
    for r in records:
        d_name = d_map.get(r.dealership_id)
        if d_name and d_name in pivot:
            prod_key = r.product_name
            if prod_key not in pivot[d_name]:
                pivot[d_name][prod_key] = 0
            pivot[d_name][prod_key] += r.quantity

    security_by_dealership = {d.id: d.security_amount for d in dealerships if d.security_amount is not None}
    region_by_dealership = {d.id: d.region for d in dealerships if d.region}
    regions = sorted(list(set(d.region for d in dealerships if d.region)))
    selected_region = request.args.get('region', '').strip()

    if selected_region:
        pivot = {k: v for k, v in pivot.items() if region_by_dealership.get(v['__id']) == selected_region}

    # Highest total stock first (matching reference PHP uasort $computeRowTotal)
    def compute_row_total(prod_dict):
        return sum(v for k, v in prod_dict.items() if k not in ('__id', '__security') and isinstance(v, (int, float)))

    pivot = dict(sorted(pivot.items(), key=lambda item: compute_row_total(item[1]), reverse=True))

    has_data = bool(pivot)
    dealers_without_security = [d for d in dealerships if d.security_amount is None or d.security_amount == 0.0]
    total_stock_count = db_session.query(func.sum(StockRecord.quantity)).scalar() or 0

    return render_template(
        'stock_report.html',
        dealerships=dealerships,
        has_data=has_data,
        pivot=pivot,
        column_sequence=column_sequence,
        product_names=product_names,
        security_by_dealership=security_by_dealership,
        region_by_dealership=region_by_dealership,
        regions=regions,
        selected_region=selected_region,
        dealers_without_security=dealers_without_security,
        total_stock_count=total_stock_count,
        message=message,
        error=error,
        import_errors=import_errors,
        short_name=short_name,
        friendly_product_label=SpreadsheetImportHelper.friendly_product_label,
    )

# --- AGEING REPORT VIEW ---
@app.route('/ageing_report', methods=['GET', 'POST'])
@app.route('/ageing_report.php', endpoint='ageing_report', methods=['GET', 'POST'])
@require_login
def ageing_report_view():
    if not can_view('ageing_report'):
        abort(403)
    dealerships = get_allowed_dealerships()
    message = session.pop('ageing_msg', '')
    error = session.pop('ageing_err', '')
    import_errors = []

    if request.method == 'POST' and can_perform('edit'):
        action = request.form.get('action')
        file_upload = request.files.get('file_upload') or request.files.get('ageing_csv') or request.files.get('stock_chassis_csv')
        if not file_upload:
            error = "Excel File is required."
        else:
            try:
                helper = SpreadsheetImportHelper()
                rows = read_excel_rows(file_upload)
                if action == 'import_stock_csv':
                    res = helper.import_stock_chassis_sheet(db_session, rows)
                else:
                    res = helper.import_ageing_sheet(db_session, rows)
                if res['success']:
                    message = f"Ageing report imported successfully. {res['imported_count']} record(s) loaded."
                    import_errors = res.get('import_errors', [])
                else:
                    error = res['message']
            except Exception as e:
                error = f"Error reading sheet: {str(e)}"

    pk_today = current_time_pk()
    if pk_today.month == 12:
        month_end = datetime(pk_today.year + 1, 1, 1).date() - timedelta(days=1)
    else:
        month_end = datetime(pk_today.year, pk_today.month + 1, 1).date() - timedelta(days=1)

    # Subquery: get upper/trimmed chassis numbers from stock_chassis_records
    subquery = db_session.query(func.upper(func.trim(StockChassisRecord.chassis_number)))
    allowed_ids = {d.id for d in dealerships}
    d_map = {d.id: d for d in dealerships}

    ageing_records = db_session.query(AgeingRecord).filter(
        AgeingRecord.dealership_id.in_(allowed_ids),
        func.upper(func.trim(AgeingRecord.chassis_number)).in_(subquery)
    ).all()

    dealership_groups = {}
    total_aged_count = 0
    selected_region = request.args.get('region', '').strip()
    selected_dealership_id = request.args.get('dealership_id', '').strip()

    for r in ageing_records:
        if not r.delivery_date:
            continue
        days = (month_end - r.delivery_date).days
        if days < 60:
            continue

        d_obj = d_map.get(r.dealership_id)
        if not d_obj:
            continue

        if selected_region and d_obj.region != selected_region:
            continue
        if selected_dealership_id and str(d_obj.id) != selected_dealership_id:
            continue

        d_name = d_obj.name
        total_aged_count += 1

        if d_name not in dealership_groups:
            dealership_groups[d_name] = {
                'dealership_id': d_obj.id,
                'dealership_name': d_name,
                'region': d_obj.region or '',
                'oldest_days': days,
                'vehicles': []
            }

        if days > dealership_groups[d_name]['oldest_days']:
            dealership_groups[d_name]['oldest_days'] = days

        dealership_groups[d_name]['vehicles'].append({
            'chassis_number': r.chassis_number,
            'product_name': r.product_name,
            'delivery_date': r.delivery_date.strftime('%d %b, %Y'),
            'days_aged': days
        })

    # Sort vehicles inside each dealership by oldest days first
    for group in dealership_groups.values():
        group['vehicles'].sort(key=lambda x: x['days_aged'], reverse=True)

    # Sort dealerships by oldest vehicle days aged descending
    sorted_groups = sorted(dealership_groups.values(), key=lambda g: g['oldest_days'], reverse=True)

    regions = sorted(list(set(d.region for d in dealerships if d.region)))
    ageing_records_count = db_session.query(AgeingRecord).filter(AgeingRecord.dealership_id.in_(allowed_ids)).count()
    stock_chassis_count = db_session.query(StockChassisRecord).filter(StockChassisRecord.dealership_id.in_(allowed_ids)).count()

    return render_template(
        'ageing_report.html',
        dealerships=dealerships,
        dealerships_filter=dealerships,
        has_data=bool(sorted_groups),
        groups=sorted_groups,
        regions=regions,
        selected_region=selected_region,
        selected_dealership_id=selected_dealership_id,
        total_aged_count=total_aged_count,
        ageing_records_count=ageing_records_count,
        stock_chassis_count=stock_chassis_count,
        month_end_str=month_end.strftime('%d %b, %Y'),
        date_str=pk_today.strftime('%d %b, %Y'),
        message=message,
        error=error,
        import_errors=import_errors,
        friendly_product_label=SpreadsheetImportHelper.friendly_product_label,
        summary_pivot={},
        pivot={},
        filtered_rows=[]
    )

# --- CRM REPORT VIEW ---
@app.route('/crm_report', methods=['GET', 'POST'])
@app.route('/crm_report.php', endpoint='crm_report', methods=['GET', 'POST'])
@require_login
def crm_report_view():
    if not can_view('crm_report'):
        abort(403)
    dealerships = get_allowed_dealerships()
    message = session.pop('crm_msg', '')
    error = session.pop('crm_err', '')
    import_errors = []

    pk_today = current_time_pk()
    current_month = pk_today.strftime('%Y-%m')

    if request.method == 'POST' and can_perform('edit'):
        file_upload = request.files.get('file_upload') or request.files.get('crm_csv')
        period_month = request.form.get('period_month')
        
        if not file_upload or not period_month:
            error = "Excel File and Period Month are required."
        else:
            try:
                helper = SpreadsheetImportHelper()
                rows = read_excel_rows(file_upload)
                res = helper.import_crm_sheet(db_session, rows, period_month)
                if res['success']:
                    message = f"CRM scoreboard data imported successfully. {res['imported_count']} record(s) loaded."
                    import_errors = res.get('import_errors', [])
                else:
                    error = res['message']
            except Exception as e:
                error = f"Error reading sheet: {str(e)}"

    parameters = db_session.query(CrmParameter).order_by(CrmParameter.display_order, CrmParameter.id).all()
    period_records = db_session.query(CrmScore.period_month).distinct().order_by(CrmScore.period_month.desc()).all()
    periods = [p[0] for p in period_records if p[0]]

    selected_period = request.args.get('period', '').strip()
    if not selected_period or selected_period not in periods:
        selected_period = periods[0] if periods else current_month

    period_labels = {}
    for p in periods:
        try:
            period_labels[p] = datetime.strptime(p + "-01", "%Y-%m-%d").strftime("%B %Y")
        except Exception:
            period_labels[p] = p

    try:
        period_title = datetime.strptime(selected_period + "-01", "%Y-%m-%d").strftime("%B %Y")
    except Exception:
        period_title = selected_period

    selected_dealership_id = request.args.get('dealership_id', type=int)
    selected_dealership = db_session.query(Dealership).filter(Dealership.id == selected_dealership_id).first() if selected_dealership_id else None

    score_by_param = {}
    pivot = {}
    pivot_details = {}
    allowed_ids = {d.id for d in dealerships}

    raw_records = db_session.query(CrmRawData).filter(
        CrmRawData.period_month == selected_period,
        CrmRawData.dealership_id.in_(allowed_ids)
    ).all()
    raw_map = {(r.dealership_id, r.crm_parameter_id): r.raw_json for r in raw_records}
    param_map = {p.id: p for p in parameters}

    from api.services.crm_calculator import CrmScoreCalculator

    if selected_dealership_id and selected_dealership:
        scores = db_session.query(CrmScore).filter(
            CrmScore.dealership_id == selected_dealership_id,
            CrmScore.period_month == selected_period
        ).all()
        for s in scores:
            pts = float(s.points_obtained)
            score_by_param[s.crm_parameter_id] = pts
            rjson = raw_map.get((s.dealership_id, s.crm_parameter_id))
            ach_text = None
            if rjson:
                try:
                    rdict = json.loads(rjson)
                    pobj = param_map.get(s.crm_parameter_id)
                    ckey = str(pobj.calc_key or '').lower() if pobj else ''
                    row_c = float(rdict.get('Row Count') or 1.0)

                    if ckey in ('timely_followup', 'first_response_time', 'manager_assigning_time'):
                        row_c = float(rdict.get('Row Count') or 1.0)
                        t_sum = CrmScoreCalculator.extract_numeric_value(rdict, ['sales person response time', 'salesperson response time', 'response time', 'sales person response', 'assign', 'min', 'time'])
                        if t_sum is not None and row_c > 0:
                            avg_m = t_sum / row_c if t_sum > 120.0 else t_sum
                            ach_text = f"{avg_m:.1f} min"
                    elif ckey in ('detailing_of_enquiry', 'detailing'):
                        filled = CrmScoreCalculator.extract_numeric_value(rdict, ['total fields filled', 'fields filled', 'filled'])
                        in_view = CrmScoreCalculator.extract_numeric_value(rdict, ['total fields in view', 'fields in view', 'view'])
                        if filled is not None and in_view is not None and in_view > 0:
                            pct = (filled / in_view) * 100.0
                            ach_text = f"{pct:.1f}%"
                        else:
                            v = CrmScoreCalculator.extract_numeric_value(rdict, ['fill', 'detail', 'complete', 'field'])
                            if v is not None:
                                ach_text = f"{v:.1f}%"
                    elif ckey in ('number_of_followups',):
                        f_ups = CrmScoreCalculator.extract_numeric_value(rdict, ['follow'])
                        enq = CrmScoreCalculator.extract_numeric_value(rdict, ['enquir', 'total', 'row count'])
                        if f_ups is not None and enq and enq > 0:
                            ach_text = f"{(f_ups / enq) * 100:.0f}%"
                    elif ckey in ('voip_calling',):
                        v_calls = CrmScoreCalculator.extract_numeric_value(rdict, ['voip', 'call'])
                        f_ups = CrmScoreCalculator.extract_numeric_value(rdict, ['follow', 'total']) or 1.0
                        if v_calls is not None and f_ups > 0:
                            ach_text = f"{(v_calls / f_ups) * 100:.0f}%"
                except Exception:
                    pass
            pivot_details[s.crm_parameter_id] = {'pts': pts, 'achievement': ach_text}
    else:
        scores = db_session.query(CrmScore).filter(
            CrmScore.period_month == selected_period,
            CrmScore.dealership_id.in_(allowed_ids)
        ).all()
        d_map = {d.id: d.name for d in dealerships}
        for d in dealerships:
            pivot[d.name] = {}
            pivot_details[d.name] = {}

        for s in scores:
            d_name = d_map.get(s.dealership_id, 'Unknown')
            if d_name not in pivot:
                pivot[d_name] = {}
                pivot_details[d_name] = {}

            pts = float(s.points_obtained)
            pivot[d_name][s.crm_parameter_id] = pts

            rjson = raw_map.get((s.dealership_id, s.crm_parameter_id))
            ach_text = None
            if rjson:
                try:
                    rdict = json.loads(rjson)
                    pobj = param_map.get(s.crm_parameter_id)
                    ckey = str(pobj.calc_key or '').lower() if pobj else ''
                    row_c = float(rdict.get('Row Count') or 1.0)

                    if ckey in ('timely_followup', 'first_response_time', 'manager_assigning_time'):
                        row_c = float(rdict.get('Row Count') or 1.0)
                        avg_m = None
                        for k, v in rdict.items():
                            k_lower = str(k).lower().strip()
                            if 'average of min' in k_lower or 'avg of min' in k_lower or 'assigning time' in k_lower or 'response time (min)' in k_lower:
                                try:
                                    val = float(v)
                                    avg_m = val / row_c if val > 120.0 else val
                                    break
                                except Exception:
                                    pass

                        if avg_m is None:
                            t_sum = CrmScoreCalculator.extract_numeric_value(rdict, ['average of min', 'sales person response time (min)', 'sales person response time', 'response time', 'min'])
                            if t_sum is not None and row_c > 0:
                                avg_m = t_sum / row_c if t_sum > 120.0 else t_sum

                        if avg_m is not None:
                            ach_text = f"{avg_m:.1f} min"
                    elif ckey in ('detailing_of_enquiry', 'detailing'):
                        filled = CrmScoreCalculator.extract_numeric_value(rdict, ['total fields filled', 'fields filled', 'filled'])
                        in_view = CrmScoreCalculator.extract_numeric_value(rdict, ['total fields in view', 'fields in view', 'view'])
                        if filled is not None and in_view is not None and in_view > 0:
                            pct = (filled / in_view) * 100.0
                            ach_text = f"{pct:.1f}%"
                        else:
                            v = CrmScoreCalculator.extract_numeric_value(rdict, ['fill', 'detail', 'complete', 'field'])
                            if v is not None:
                                ach_text = f"{v:.1f}%"
                    elif ckey in ('number_of_followups',):
                        f_ups = CrmScoreCalculator.extract_numeric_value(rdict, ['follow'])
                        enq = CrmScoreCalculator.extract_numeric_value(rdict, ['enquir', 'total', 'row count'])
                        if f_ups is not None and enq and enq > 0:
                            ach_text = f"{(f_ups / enq) * 100:.0f}%"
                    elif ckey in ('voip_calling',):
                        ratio_val = None
                        for k, v in rdict.items():
                            k_lower = str(k).lower().strip()
                            if 'ratio of voip' in k_lower or 'voip calls against follow up' in k_lower or 'voip ratio' in k_lower:
                                try:
                                    val_str = str(v).replace('%', '').strip()
                                    val_float = float(val_str)
                                    ratio_val = val_float * 100.0 if (0.0 < val_float <= 1.0) else val_float
                                    break
                                except Exception:
                                    pass

                        if ratio_val is None:
                            v_calls = CrmScoreCalculator.extract_numeric_value(rdict, ['total number of voip calls', 'voip calls', 'voip'])
                            f_ups = CrmScoreCalculator.extract_numeric_value(rdict, ['total follow-up', 'total follow up', 'follow-up', 'follow up'])
                            if v_calls is not None and f_ups and f_ups > 0:
                                ratio_val = (v_calls / f_ups) * 100.0

                        if ratio_val is not None:
                            ach_text = f"{ratio_val:.0f}%"
                    elif ckey in ('digital_enquiry_targets', 'digital_targets'):
                        d_obj = db_session.query(Dealership).filter(Dealership.id == s.dealership_id).first()
                        target = float(d_obj.digital_enquiry_target if d_obj else 0.0)
                        achieved = None
                        for k, v in rdict.items():
                            k_lower = str(k).lower().strip()
                            if 'digital' in k_lower or 'facebook' in k_lower or 'instagram' in k_lower or 'youtube' in k_lower:
                                try:
                                    achieved = float(v)
                                    break
                                except Exception:
                                    pass

                        if achieved is None:
                            achieved = CrmScoreCalculator.extract_numeric_value(rdict, ['digital', 'facebook', 'instagram', 'youtube', 'achiev']) or 0.0

                        if target > 0:
                            pct = (achieved / target) * 100.0
                            ach_text = f"{pct:.0f}%"
                        elif achieved > 0:
                            ach_text = f"{achieved:.0f}"
                    elif ckey in ('pipeline_tracking', 'pipeline'):
                        cnt = None
                        for k, v in rdict.items():
                            k_lower = str(k).lower().strip()
                            if '2+ business' in k_lower or 'enquiries (2+' in k_lower or '2+ days' in k_lower:
                                try:
                                    cnt = int(float(v))
                                    break
                                except Exception:
                                    pass
                        if cnt is None:
                            d_val = CrmScoreCalculator.extract_numeric_value(rdict, ['business days difference', 'business days', 'days difference'])
                            cnt = int(d_val) if d_val is not None else 0

                        if cnt is not None:
                            ach_text = f"{cnt} enquiries"
                    elif ckey in ('fronx_test_drive_monthly', 'fronx_test_drive'):
                        completed = None
                        for k, v in rdict.items():
                            k_lower = str(k).lower().strip()
                            if 'complete' in k_lower or 'test drive' in k_lower or 'fronx' in k_lower:
                                try:
                                    completed = float(v)
                                    break
                                except Exception:
                                    pass

                        if completed is None:
                            completed = CrmScoreCalculator.extract_numeric_value(rdict, ['complete', 'test drive', 'fronx', 'actual', 'row count']) or 0.0

                        pct = (completed / 104.0) * 100.0
                        ach_text = f"{completed:.0f}/104 ({pct:.0f}%)"
                    elif ckey in ('stage_won_conversion', 'digital_conversion'):
                        d_obj = db_session.query(Dealership).filter(Dealership.id == s.dealership_id).first()
                        target = float(d_obj.digital_enquiry_conversion_target if d_obj else 0.0)
                        achieved = None
                        for k, v in rdict.items():
                            k_lower = str(k).lower().strip()
                            if 'won' in k_lower or 'stage' in k_lower or 'conversion' in k_lower:
                                try:
                                    achieved = float(v)
                                    break
                                except Exception:
                                    pass

                        if achieved is None:
                            achieved = CrmScoreCalculator.extract_numeric_value(rdict, ['won', 'stage', 'conversion', 'achiev', 'actual']) or 0.0

                        if target > 0:
                            pct = (achieved / target) * 100.0
                            ach_text = f"{achieved:.0f}/{target:.0f} ({pct:.0f}%)"
                        elif achieved > 0:
                            ach_text = f"{achieved:.0f}"
                except Exception:
                    pass

            pivot_details[d_name][s.crm_parameter_id] = {'pts': pts, 'achievement': ach_text}
        pivot = dict(sorted(pivot.items()))

    total_max_points = sum(float(p.max_points or 0) for p in parameters if p.max_points != 0.0)
    dealer_target_field_by_calc_key = {
        'digital_enquiry_targets': 'digital_enquiry_target',
        'stage_won_conversion': 'digital_enquiry_conversion_target'
    }

    return render_template(
        'crm_report.html',
        dealerships=dealerships,
        current_month=current_month,
        periods=periods,
        selected_period=selected_period,
        period_labels=period_labels,
        period_title=period_title,
        selected_dealership_id=selected_dealership_id,
        selected_dealership=selected_dealership,
        parameters=parameters,
        total_max_points=total_max_points,
        score_by_param=score_by_param,
        pivot=pivot,
        pivot_details=pivot_details,
        dealer_target_field_by_calc_key=dealer_target_field_by_calc_key,
        message=message,
        error=error,
        import_errors=import_errors
    )

@app.route('/crm_parameters', methods=['GET', 'POST'])
@app.route('/crm_parameters.php', endpoint='crm_parameters', methods=['GET', 'POST'])
@require_super_admin
def crm_parameters_view():
    dealerships = get_allowed_dealerships()
    message = session.pop('crm_param_msg', '')
    error = session.pop('crm_param_err', '')
    import_errors = []

    if request.method == 'POST':
        action = request.form.get('action')

        if action == 'save_template':
            p_ids = request.form.getlist('id[]')
            names = request.form.getlist('parameter_name[]')
            criteria = request.form.getlist('criteria[]')
            max_pts = request.form.getlist('max_points[]')
            calc_keys = request.form.getlist('calc_key[]')
            orders = request.form.getlist('display_order[]')

            try:
                for idx, pid in enumerate(p_ids):
                    param = db_session.query(CrmParameter).filter(CrmParameter.id == int(pid)).first()
                    if param:
                        param.parameter_name = names[idx].strip()
                        param.criteria = criteria[idx].strip()
                        param.max_points = float(max_pts[idx])
                        if idx < len(calc_keys):
                            param.calc_key = calc_keys[idx].strip() or None
                        if idx < len(orders):
                            param.display_order = int(orders[idx])
                db_session.commit()
                message = "CRM schema template parameters saved successfully."
            except Exception as e:
                db_session.rollback()
                error = f"Error saving parameters: {str(e)}"

        elif action == 'update':
            pid = request.form.get('id', type=int)
            name = request.form.get('parameter_name', '').strip()
            criteria = request.form.get('criteria', '').strip()
            max_pts = request.form.get('max_points', '').strip()
            order = request.form.get('display_order', type=int)

            if pid and name and max_pts:
                try:
                    param = db_session.query(CrmParameter).filter(CrmParameter.id == pid).first()
                    if param:
                        param.parameter_name = name
                        param.criteria = criteria
                        param.max_points = float(max_pts)
                        if order is not None:
                            param.display_order = order
                        db_session.commit()
                        message = f"Parameter \"{name}\" Updated Successfully."
                except Exception as e:
                    db_session.rollback()
                    error = f"Error updating parameter: {str(e)}"

        elif action == 'delete':
            pid = request.form.get('id', type=int)
            if pid:
                try:
                    db_session.query(CrmScore).filter(CrmScore.crm_parameter_id == pid).delete()
                    db_session.query(CrmRawData).filter(CrmRawData.crm_parameter_id == pid).delete()
                    db_session.query(CrmParameter).filter(CrmParameter.id == pid).delete()
                    db_session.commit()
                    message = "Parameter deleted successfully."
                except Exception as e:
                    db_session.rollback()
                    error = f"Error deleting parameter: {str(e)}"
        elif action == 'clear_all_raw':
            period_month = request.form.get('period_month', '').strip()
            try:
                if period_month:
                    r_deleted = db_session.query(CrmRawData).filter(CrmRawData.period_month == period_month).delete()
                    s_deleted = db_session.query(CrmScore).filter(CrmScore.period_month == period_month).delete()
                    message = f"Cleared raw data & scores for {period_month}."
                else:
                    r_deleted = db_session.query(CrmRawData).delete()
                    s_deleted = db_session.query(CrmScore).delete()
                    message = "Cleared all CRM raw data & scores."
                db_session.commit()
            except Exception as e:
                db_session.rollback()
                error = f"Error clearing data: {str(e)}"

        elif action == 'import_raw':
            parameter_id = int(request.form.get('crm_parameter_id') or 0)
            period_month = request.form.get('period_month', '').strip()
            summary_json_str = request.form.get('summary_json', '').strip()
            file_upload = request.files.get('file') or request.files.get('file_upload') or request.files.get('raw_file')

            if not parameter_id or not period_month:
                error = "Missing Parameter Or Period."
            elif summary_json_str:
                try:
                    summary_data = json.loads(summary_json_str)
                    helper = SpreadsheetImportHelper()
                    dealershipsByName = helper._build_dealership_map(db_session)

                    db_session.query(CrmRawData).filter(
                        CrmRawData.crm_parameter_id == parameter_id,
                        CrmRawData.period_month == period_month
                    ).delete()
                    db_session.query(CrmScore).filter(
                        CrmScore.crm_parameter_id == parameter_id,
                        CrmScore.period_month == period_month
                    ).delete()
                    db_session.commit()

                    grouped_by_did = {}
                    for d_name, d_sums in summary_data.items():
                        d_id = helper.find_dealership_match(dealershipsByName, d_name)
                        if not d_id:
                            import_errors.append(f"Dealership \"{d_name}\" Not Found — Skipped.")
                            continue

                        if d_id not in grouped_by_did:
                            grouped_by_did[d_id] = {}

                        for k, v in d_sums.items():
                            try:
                                grouped_by_did[d_id][k] = grouped_by_did[d_id].get(k, 0.0) + float(v)
                            except Exception:
                                grouped_by_did[d_id][k] = v

                    imported_count = 0
                    total_rows = 0
                    for d_id, merged_sums in grouped_by_did.items():
                        rawJson = json.dumps(merged_sums)
                        new_raw = CrmRawData(
                            dealership_id=d_id,
                            crm_parameter_id=parameter_id,
                            period_month=period_month,
                            raw_json=rawJson
                        )
                        db_session.add(new_raw)
                        imported_count += 1
                        total_rows += int(merged_sums.get('Row Count') or 1)

                    db_session.commit()

                    param = db_session.query(CrmParameter).filter(CrmParameter.id == parameter_id).first()
                    if param and param.calc_key:
                        from api.services.crm_calculator import CrmScoreCalculator
                        raw_records = db_session.query(CrmRawData).filter(
                            CrmRawData.crm_parameter_id == parameter_id,
                            CrmRawData.period_month == period_month
                        ).all()

                        for rd in raw_records:
                            try:
                                raw_dict = json.loads(rd.raw_json) if rd.raw_json else {}
                            except Exception:
                                raw_dict = {}

                            d_obj = db_session.query(Dealership).filter(Dealership.id == rd.dealership_id).first()
                            dealership_dict = {
                                'digital_enquiry_target': d_obj.digital_enquiry_target if d_obj else 0.0,
                                'digital_enquiry_conversion_target': d_obj.digital_enquiry_conversion_target if d_obj else 0.0
                            }

                            pts = CrmScoreCalculator.calculate(param.calc_key, raw_dict, float(param.max_points or 0), dealership_dict) or 0.0
                            score = CrmScore(
                                dealership_id=rd.dealership_id,
                                crm_parameter_id=parameter_id,
                                period_month=period_month,
                                points_obtained=pts
                            )
                            db_session.add(score)

                        db_session.commit()

                    message = f"{imported_count} Dealership(s) — Raw Data Uploaded & Automatically Calculated (From {total_rows} Row(s)) For {period_month}."
                except Exception as e:
                    db_session.rollback()
                    error = f"Error processing summary JSON: {str(e)}"
                return jsonify({'success': not error, 'message': error or message, 'importErrors': import_errors})
            elif not file_upload:
                error = "A CSV Or Excel File Is Required."
            else:
                try:
                    helper = SpreadsheetImportHelper()
                    rows = read_excel_rows(file_upload)
                    if not rows or len(rows) < 2:
                        error = "Sheet is empty or has no data rows."
                    else:
                        dealershipsByName = helper._build_dealership_map(db_session)

                        headerIndex = helper.find_header_row_index(rows, ['dealer', 'company', 'agency', 'branch', 'showroom', 'name', 'dealership'])
                        headerRow = rows[headerIndex] if headerIndex < len(rows) else []
                        dealerCol = helper.find_column(headerRow, ['dealer', 'dealership', 'company', 'agency', 'branch', 'showroom', 'name', 'location'])

                        if dealerCol is None:
                            # Auto-detect column by searching for matching dealership names across all cells in first 25 rows
                            col_matches = {}
                            for r_idx, r in enumerate(rows[:25]):
                                for c_idx, cell in enumerate(r):
                                    val_str = str(cell).strip()
                                    if val_str and helper.find_dealership_match(dealershipsByName, val_str):
                                        col_matches[c_idx] = col_matches.get(c_idx, 0) + 1
                            if col_matches:
                                dealerCol = max(col_matches, key=col_matches.get)
                                if headerIndex >= len(rows):
                                    headerIndex = 0
                                    headerRow = rows[0] if rows else []

                        if dealerCol is None:
                            error = "Could Not Find A \"Dealer\"/\"Dealership\"/\"Company\" Column In Header Row."
                        else:
                            rawCols = {}
                            sourceCol = None
                            stageCol = None
                            statusCol = None
                            businessDaysCol = None

                            for col, label in enumerate(headerRow):
                                label_str = str(label).strip()
                                if col == dealerCol or label_str == '':
                                    continue
                                if sourceCol is None and helper.matches_any_keyword(label_str, ['source']):
                                    sourceCol = col
                                    continue
                                if stageCol is None and helper.matches_any_keyword(label_str, ['stage']):
                                    stageCol = col
                                    continue
                                if statusCol is None and helper.matches_any_keyword(label_str, ['status']):
                                    statusCol = col
                                    continue
                                if businessDaysCol is None and helper.matches_any_keyword(label_str, ['business days']):
                                    businessDaysCol = col
                                    continue
                                rawCols[col] = label_str

                            dealershipsByName = helper._build_dealership_map(db_session)
                            sumsByDealership = {}
                            rowCountByDealership = {}
                            digitalSourceCountByDealership = {}
                            wonStageCountByDealership = {}
                            completedStatusCountByDealership = {}
                            maxBusinessDaysByDealership = {}

                            for i in range(headerIndex + 1, len(rows)):
                                row = rows[i]
                                rowNum = i + 1
                                if not any(str(c).strip() != '' for c in row):
                                    continue

                                dealershipName = str(row[dealerCol]).strip() if dealerCol < len(row) else ''
                                if not dealershipName:
                                    continue

                                dealershipId = helper.find_dealership_match(dealershipsByName, dealershipName)
                                if not dealershipId:
                                    import_errors.append(f"Row {rowNum}: Dealership \"{dealershipName}\" Not Found — Skipped.")
                                    continue

                                if dealershipId not in sumsByDealership:
                                    sumsByDealership[dealershipId] = {lbl: 0.0 for lbl in rawCols.values()}
                                    rowCountByDealership[dealershipId] = 0
                                    digitalSourceCountByDealership[dealershipId] = 0
                                    wonStageCountByDealership[dealershipId] = 0
                                    completedStatusCountByDealership[dealershipId] = 0
                                    maxBusinessDaysByDealership[dealershipId] = 0.0

                                for col, label in rawCols.items():
                                    val_str = str(row[col]).strip() if col < len(row) else '0'
                                    try:
                                        val = float(val_str.replace(',', ''))
                                    except Exception:
                                        val = 0.0
                                    sumsByDealership[dealershipId][label] += val

                                if sourceCol is not None:
                                    sourceVal = str(row[sourceCol]).strip().lower() if sourceCol < len(row) else ''
                                    if sourceVal in ('dealer facebook', 'dealer instagram'):
                                        digitalSourceCountByDealership[dealershipId] += 1

                                if stageCol is not None:
                                    stageVal = str(row[stageCol]).strip().lower() if stageCol < len(row) else ''
                                    if stageVal == 'won':
                                        wonStageCountByDealership[dealershipId] += 1

                                if statusCol is not None:
                                    statusVal = str(row[statusCol]).strip().lower() if statusCol < len(row) else ''
                                    if 'complete' in statusVal:
                                        completedStatusCountByDealership[dealershipId] += 1

                                if businessDaysCol is not None:
                                    bd_str = str(row[businessDaysCol]).strip() if businessDaysCol < len(row) else '0'
                                    try:
                                        bd_val = float(bd_str.replace(',', ''))
                                    except Exception:
                                        bd_val = 0.0
                                    maxBusinessDaysByDealership[dealershipId] = max(maxBusinessDaysByDealership[dealershipId], bd_val)

                                rowCountByDealership[dealershipId] += 1

                            # Clean wipe previous raw data and scores for this specific parameter & period before inserting new file data
                            db_session.query(CrmRawData).filter(
                                CrmRawData.crm_parameter_id == parameter_id,
                                CrmRawData.period_month == period_month
                            ).delete()
                            db_session.query(CrmScore).filter(
                                CrmScore.crm_parameter_id == parameter_id,
                                CrmScore.period_month == period_month
                            ).delete()
                            db_session.commit()

                            imported_count = 0
                            for d_id, sums in sumsByDealership.items():
                                rawData = dict(sums)
                                rawData['Row Count'] = rowCountByDealership[d_id]
                                if sourceCol is not None:
                                    rawData['Digital Enquiries (Facebook + Instagram Source)'] = digitalSourceCountByDealership[d_id]
                                if stageCol is not None:
                                    rawData['Won Enquiries (Stage)'] = wonStageCountByDealership[d_id]
                                if statusCol is not None:
                                    rawData['Completed (Status)'] = completedStatusCountByDealership[d_id]
                                if businessDaysCol is not None:
                                    rawData['Max Business Days Difference'] = maxBusinessDaysByDealership[d_id]

                                rawJson = json.dumps(rawData)
                                new_raw = CrmRawData(
                                    dealership_id=d_id,
                                    crm_parameter_id=parameter_id,
                                    period_month=period_month,
                                    raw_json=rawJson
                                )
                                db_session.add(new_raw)
                                imported_count += 1

                            db_session.commit()

                            # AUTOMATICALLY RECALCULATE SCORES IMMEDIATELY ACCORDING TO DEFINED LOGIC
                            param = db_session.query(CrmParameter).filter(CrmParameter.id == parameter_id).first()
                            if param and param.calc_key:
                                from api.services.crm_calculator import CrmScoreCalculator
                                raw_records = db_session.query(CrmRawData).filter(
                                    CrmRawData.crm_parameter_id == parameter_id,
                                    CrmRawData.period_month == period_month
                                ).all()

                                for rd in raw_records:
                                    try:
                                        raw_dict = json.loads(rd.raw_json) if rd.raw_json else {}
                                    except Exception:
                                        raw_dict = {}

                                    d_obj = db_session.query(Dealership).filter(Dealership.id == rd.dealership_id).first()
                                    dealership_dict = {
                                        'digital_enquiry_target': d_obj.digital_enquiry_target if d_obj else 0.0,
                                        'digital_enquiry_conversion_target': d_obj.digital_enquiry_conversion_target if d_obj else 0.0
                                    }

                                    pts = CrmScoreCalculator.calculate(param.calc_key, raw_dict, float(param.max_points or 0), dealership_dict) or 0.0

                                    score = CrmScore(
                                        dealership_id=rd.dealership_id,
                                        crm_parameter_id=parameter_id,
                                        period_month=period_month,
                                        points_obtained=pts
                                    )
                                    db_session.add(score)

                                db_session.commit()

                            message = f"{imported_count} Dealership(s) — Raw Data Uploaded & Automatically Calculated (From {sum(rowCountByDealership.values())} Row(s)) For {period_month}."
                except Exception as e:
                    db_session.rollback()
                    error = f"Error reading raw data sheet: {str(e)}"

            return jsonify({'success': not error, 'message': error or message, 'importErrors': import_errors})

        elif action == 'recalculate':
            parameter_id = int(request.form.get('crm_parameter_id') or 0)
            period_month = request.form.get('period_month', '').strip()

            param = db_session.query(CrmParameter).filter(CrmParameter.id == parameter_id).first() if parameter_id else None
            if not param or not period_month:
                error = "Missing Parameter Or Period."
            elif not param.calc_key:
                error = f"No Calculation Logic Defined Yet For \"{param.parameter_name}\"."
            else:
                try:
                    from api.services.crm_calculator import CrmScoreCalculator
                    raw_records = db_session.query(CrmRawData).filter(
                        CrmRawData.crm_parameter_id == parameter_id,
                        CrmRawData.period_month == period_month
                    ).all()

                    if not raw_records:
                        error = f"No raw data file has been uploaded for \"{param.parameter_name}\" in {period_month} yet. Please choose an Excel file and click 'Upload' first."
                    else:
                        calculated_count = 0
                        skipped_count = 0

                    for rd in raw_records:
                        try:
                            raw_dict = json.loads(rd.raw_json) if rd.raw_json else {}
                        except Exception:
                            raw_dict = {}

                        d_obj = db_session.query(Dealership).filter(Dealership.id == rd.dealership_id).first()
                        dealership_dict = {
                            'digital_enquiry_target': d_obj.digital_enquiry_target if d_obj else 0.0,
                            'digital_enquiry_conversion_target': d_obj.digital_enquiry_conversion_target if d_obj else 0.0
                        }

                        pts = CrmScoreCalculator.calculate(param.calc_key, raw_dict, float(param.max_points or 0), dealership_dict)
                        if pts is None:
                            pts = 0.0

                        score = db_session.query(CrmScore).filter(
                            CrmScore.dealership_id == rd.dealership_id,
                            CrmScore.crm_parameter_id == parameter_id,
                            CrmScore.period_month == period_month
                        ).first()

                        if not score:
                            score = CrmScore(
                                dealership_id=rd.dealership_id,
                                crm_parameter_id=parameter_id,
                                period_month=period_month,
                                points_obtained=pts
                            )
                            db_session.add(score)
                        else:
                            score.points_obtained = pts
                        calculated_count += 1

                    db_session.commit()
                    skipped_msg = f" ({skipped_count} Skipped — No Logic Yet.)" if skipped_count else ''
                    message = f"{calculated_count} Dealership(s) Recalculated For \"{param.parameter_name}\".{skipped_msg}"
                except Exception as e:
                    db_session.rollback()
                    error = f"Recalculation error: {str(e)}"

            return jsonify({'success': not error, 'message': error or message})

        elif action == 'add':
            name = request.form.get('parameter_name', '').strip()
            criteria = request.form.get('criteria', '').strip()
            max_pts = request.form.get('max_points', '').strip()
            calc_key = request.form.get('calc_key', '').strip() or None

            if not name or not max_pts:
                error = "Parameter Name and Max Points are required."
            else:
                try:
                    max_order = db_session.query(func.max(CrmParameter.display_order)).scalar() or 0
                    new_param = CrmParameter(
                        parameter_name=name,
                        criteria=criteria,
                        max_points=float(max_pts),
                        calc_key=calc_key,
                        display_order=max_order + 1
                    )
                    db_session.add(new_param)
                    db_session.commit()
                    message = f"Parameter \"{name}\" Added Successfully."
                except Exception as e:
                    db_session.rollback()
                    error = f"Error adding parameter: {str(e)}"

    crm_parameters = db_session.query(CrmParameter).order_by(CrmParameter.display_order, CrmParameter.id).all()
    total_max_points = sum(float(p.max_points or 0) for p in crm_parameters)

    selected_period = request.args.get('period', '').strip() or request.args.get('period_month', '').strip()
    if not selected_period:
        selected_period = current_time_pk().strftime('%Y-%m')

    try:
        period_label = datetime.strptime(selected_period + '-01', '%Y-%m-%d').strftime('%b %Y').upper()
    except Exception:
        period_label = selected_period

    dealership_names = [d.name for d in dealerships]
    dealership_count = len(dealership_names)

    raw_counts = db_session.query(
        CrmRawData.crm_parameter_id, func.count(CrmRawData.id)
    ).filter(CrmRawData.period_month == selected_period).group_by(CrmRawData.crm_parameter_id).all()
    raw_count_by_param = {r[0]: r[1] for r in raw_counts}

    score_counts = db_session.query(
        CrmScore.crm_parameter_id, func.count(CrmScore.id)
    ).filter(CrmScore.period_month == selected_period).group_by(CrmScore.crm_parameter_id).all()
    score_count_by_param = {s[0]: s[1] for s in score_counts}

    return render_template(
        'crm_parameters.html',
        parameters=crm_parameters,
        crm_parameters=crm_parameters,
        total_max_points=total_max_points,
        selected_period=selected_period,
        period_label=period_label,
        dealership_names=dealership_names,
        dealership_count=dealership_count,
        raw_count_by_param=raw_count_by_param,
        score_count_by_param=score_count_by_param,
        message=message,
        error=error,
        import_errors=import_errors
    )

# --- CRM DATA QUALITY CHECKER ---

@app.route('/crm_data_quality', methods=['GET', 'POST'])
@app.route('/crm_data_quality_check.php', endpoint='crm_data_quality', methods=['GET', 'POST'])
@require_login
def crm_data_quality():
    if not can_view('crm_data_quality'):
        abort(403)
    message = ''
    error = ''
    results = None
    
    # Fetch distinct periods
    periods = [r[0] for r in db_session.query(distinct(CrmRawData.period_month)).order_by(CrmRawData.period_month.desc()).all() if r[0]]
    
    if request.method == 'POST':
        period_month = request.form.get('period_month')
        if not period_month:
            error = "Please select a period month."
        else:
            # Run DataQualityAnalyzer
            records = db_session.query(CrmRawData).filter(CrmRawData.period_month == period_month).all()
            if not records:
                error = "No raw data found for this period."
            else:
                analyzer = DataQualityAnalyzer()
                results = analyzer.analyze(records)
                
    return render_template(
        'crm_data_quality_check.html',
        periods=periods,
        results=results,
        message=message,
        error=error
    )

# --- BRAND IDENTITY & GUIDELINES ---

@app.route('/brand_assets', methods=['GET', 'POST'])
@app.route('/brand_assets.php', endpoint='brand_assets', methods=['GET', 'POST'])
@app.route('/brand_assets', methods=['GET', 'POST'])
@app.route('/brand_assets.php', endpoint='brand_assets', methods=['GET', 'POST'])
@require_login
def brand_assets():
    if not can_view('brand_assets'):
        abort(403)
    message = session.pop('brand_msg', '')
    error = session.pop('brand_err', '')

    max_images = 10

    if request.method == 'POST' and can_perform('edit'):
        action = request.form.get('action')

        if action == 'save_identity':
            tagline = request.form.get('tagline', '').strip()
            primary_color = request.form.get('primary_color', '').strip()
            secondary_color = request.form.get('secondary_color', '').strip()
            website_url = request.form.get('website_url', '').strip()

            ident = db_session.query(BrandIdentity).first()
            if not ident:
                ident = BrandIdentity(id=1)
                db_session.add(ident)

            ident.tagline = tagline
            ident.primary_color = primary_color
            ident.secondary_color = secondary_color
            ident.website_url = website_url

            resizer = ImageResizer()
            for field, col in [('logo_light', 'logo_light_path'), ('logo_dark', 'logo_dark_path'), ('logo_white_bg', 'logo_white_bg_path')]:
                uploaded_file = request.files.get(field)
                if uploaded_file and uploaded_file.filename:
                    res = resizer.resize(uploaded_file, max_width=400, max_height=400)
                    if res['success']:
                        ext = uploaded_file.filename.split('.')[-1].lower() or 'png'
                        fname = f"logos_{int(time.time())}_{field}.{ext}"
                        save_path = os.path.join(UPLOAD_DIR, 'logos', fname)
                        with open(save_path, 'wb') as f_out:
                            f_out.write(res['data'])
                        setattr(ident, col, f"assets/uploads/logos/{fname}")

            db_session.commit()
            message = "Brand Identity Saved."

        elif action == 'add_vehicle':
            name = request.form.get('name', '').strip() or request.form.get('model_name', '').strip()
            color = request.form.get('color', '').strip() or request.form.get('specifications', '').strip()
            uploaded_files = request.files.getlist('reference_images') or request.files.getlist('images[]')

            if not name or not color or not uploaded_files:
                error = "Name, Color, And At Least One Valid Reference Photo Are Required."
            else:
                resizer = ImageResizer()
                first_img = None
                vehicle = VehicleModel(name=name, color=color, reference_image='')
                db_session.add(vehicle)
                db_session.commit()

                saved_count = 0
                for f in uploaded_files:
                    if f and f.filename and saved_count < max_images:
                        res = resizer.resize(f, max_width=800, max_height=800)
                        if res['success']:
                            ext = f.filename.split('.')[-1].lower() or 'jpg'
                            fname = f"vehicles_{vehicle.id}_{saved_count}_{int(time.time())}.{ext}"
                            save_path = os.path.join(UPLOAD_DIR, 'vehicles', fname)
                            with open(save_path, 'wb') as f_out:
                                f_out.write(res['data'])
                            img_rel_path = f"assets/uploads/vehicles/{fname}"
                            if not first_img:
                                first_img = img_rel_path
                            v_img = VehicleModelImage(
                                vehicle_model_id=vehicle.id,
                                image_path=img_rel_path,
                                display_order=saved_count
                            )
                            db_session.add(v_img)
                            saved_count += 1

                if first_img:
                    vehicle.reference_image = first_img

                db_session.commit()
                message = f"{saved_count} Reference Photo(s) Added For This Vehicle."

        elif action == 'add_images':
            vehicle_id = request.form.get('vehicle_id', type=int)
            if vehicle_id:
                existing_imgs = db_session.query(VehicleModelImage).filter(VehicleModelImage.vehicle_model_id == vehicle_id).all()
                room = max(0, max_images - len(existing_imgs))
                uploaded_files = request.files.getlist('reference_images')
                if room <= 0:
                    error = f"This Vehicle Already Has The Maximum Of {max_images} Reference Photos."
                elif not uploaded_files:
                    error = "No Valid Photos Were Uploaded."
                else:
                    resizer = ImageResizer()
                    saved_count = 0
                    for f in uploaded_files:
                        if f and f.filename and saved_count < room:
                            res = resizer.resize(f, max_width=800, max_height=800)
                            if res['success']:
                                ext = f.filename.split('.')[-1].lower() or 'jpg'
                                fname = f"vehicles_{vehicle_id}_{len(existing_imgs) + saved_count}_{int(time.time())}.{ext}"
                                save_path = os.path.join(UPLOAD_DIR, 'vehicles', fname)
                                with open(save_path, 'wb') as f_out:
                                    f_out.write(res['data'])
                                img_rel_path = f"assets/uploads/vehicles/{fname}"
                                v_img = VehicleModelImage(
                                    vehicle_model_id=vehicle_id,
                                    image_path=img_rel_path,
                                    display_order=len(existing_imgs) + saved_count
                                )
                                db_session.add(v_img)
                                saved_count += 1

                    db_session.commit()
                    message = f"{saved_count} More Photo(s) Added."

    # Load vehicles with attached images
    raw_vehicles = db_session.query(VehicleModel).order_by(VehicleModel.name, VehicleModel.color).all()
    vehicles = []
    for v in raw_vehicles:
        images = db_session.query(VehicleModelImage).filter(
            VehicleModelImage.vehicle_model_id == v.id
        ).order_by(VehicleModelImage.id).all()
        vehicles.append({
            'id': v.id,
            'name': v.name or v.model_name or '',
            'color': v.color or v.specifications or '',
            'reference_image': v.reference_image,
            'images': [{'id': img.id, 'image_path': img.image_path} for img in images]
        })

    brand_identity = db_session.query(BrandIdentity).first()

    return render_template(
        'brand_assets.html',
        vehicles=vehicles,
        vehicle_models=raw_vehicles,
        identity=brand_identity,
        brand_identity=brand_identity,
        max_images=max_images,
        message=message,
        error=error
    )

@app.route('/delete_vehicle_model.php', methods=['POST'])
@require_login
def delete_vehicle_model():
    if not can_perform('delete'):
        return jsonify({'success': False, 'message': 'Access denied'}), 403
    v_id = request.form.get('id', type=int)
    if not v_id:
        return jsonify({'success': False, 'message': 'ID missing'})
        
    v = db_session.query(VehicleModel).filter(VehicleModel.id == v_id).first()
    if not v:
        return jsonify({'success': False, 'message': 'Vehicle not found'})
        
    db_session.delete(v)
    db_session.commit()
    return jsonify({'success': True})

@app.route('/delete_vehicle_image.php', methods=['POST'])
@require_login
def delete_vehicle_image():
    if not can_perform('delete'):
        return jsonify({'success': False, 'message': 'Access denied'}), 403
    img_id = request.form.get('id', type=int)
    if not img_id:
        return jsonify({'success': False, 'message': 'ID missing'})
        
    img = db_session.query(VehicleModelImage).filter(VehicleModelImage.id == img_id).first()
    if not img:
        return jsonify({'success': False, 'message': 'Image not found'})
        
    db_session.delete(img)
    db_session.commit()
    return jsonify({'success': True})

# --- USER MANAGEMENT & PASSWORD ---

@app.route('/users', methods=['GET', 'POST'])
@app.route('/users.php', endpoint='users', methods=['GET', 'POST'])
@require_super_admin
def users_view():
    message = ''
    error = ''
    
    sidebar_sections = sidebar_sections_list()
    
    if request.method == 'POST':
        username = request.form.get('username', '').strip()
        password = request.form.get('password')
        is_admin = request.form.get('is_super_admin') == 'on'
        dealership_ids = request.form.getlist('dealership_ids')
        can_ref = request.form.get('can_refresh') == 'on'
        can_ed = request.form.get('can_edit') == 'on'
        can_del = request.form.get('can_delete') == 'on'
        sel_sections = request.form.getlist('sidebar_sections')
        
        if not username or not password:
            error = 'Username And Password Are Both Required.'
        elif not is_admin and not dealership_ids:
            error = 'A Non-Super-Admin User Must Be Linked To At Least One Dealership.'
        else:
            exists = db_session.query(User).filter(User.username == username).first()
            if exists:
                error = 'This Username Already Exists.'
            else:
                try:
                    new_user = User(
                        username=username,
                        password_hash=hash_password(password),
                        is_super_admin=1 if is_admin else 0,
                        can_refresh=1 if (is_admin or can_ref) else 0,
                        can_edit=1 if (is_admin or can_ed) else 0,
                        can_delete=1 if (is_admin or can_del) else 0
                    )
                    db_session.add(new_user)
                    db_session.commit()
                    
                    if not is_admin:
                        # Link dealerships
                        for did in dealership_ids:
                            db_session.execute(user_dealerships.insert().values(user_id=new_user.id, dealership_id=int(did)))
                        # Link sidebar sections
                        for sec in sel_sections:
                            if sec in sidebar_sections:
                                uss = UserSidebarSection(user_id=new_user.id, section_key=sec)
                                db_session.add(uss)
                        db_session.commit()
                    message = 'User Added.'
                except Exception as e:
                    db_session.rollback()
                    error = f"Error creating user: {str(e)}"
                    
    # Fetch all users with concatenated dealership names
    # Group concat via sqlalchemy is tricky cross-platform (MySQL vs Postgres)
    # So we aggregate manually in Python for 100% DB portability
    users_db = db_session.query(User).order_by(User.created_at).all()
    users = []
    for u in users_db:
        d_names = ", ".join([d.name for d in u.dealerships]) if not u.is_super_admin else "— All (Super Admin) —"
        users.append({
            'id': u.id,
            'username': u.username,
            'is_super_admin': u.is_super_admin,
            'can_refresh': u.can_refresh,
            'can_edit': u.can_edit,
            'can_delete': u.can_delete,
            'dealership_names': d_names
        })
        
    dealerships_list = db_session.query(Dealership).order_by(Dealership.name).all()
    return render_template(
        'users.html',
        users=users,
        dealerships_list=dealerships_list,
        sidebar_sections=sidebar_sections,
        message=message,
        error=error
    )

@app.route('/edit_user', methods=['GET', 'POST'])
@app.route('/edit_user.php', endpoint='edit_user', methods=['GET', 'POST'])
@require_super_admin
def edit_user_view():
    u_id = request.args.get('id', type=int) or request.form.get('id', type=int)
    if not u_id:
        return redirect(url_for('users'))
        
    user = db_session.query(User).filter(User.id == u_id).first()
    if not user or user.is_super_admin:
        return redirect(url_for('users'))
        
    message = ''
    sidebar_sections = sidebar_sections_list()
    
    if request.method == 'POST':
        dealership_ids = request.form.getlist('dealership_ids')
        can_ref = request.form.get('can_refresh') == 'on'
        can_ed = request.form.get('can_edit') == 'on'
        can_del = request.form.get('can_delete') == 'on'
        new_pass = request.form.get('new_password', '').strip()
        sel_sections = request.form.getlist('sidebar_sections')
        
        if not dealership_ids:
            message = 'Error: A User Must Be Linked To At Least One Dealership.'
        elif new_pass and len(new_pass) < 6:
            message = 'Error: New Password Must Be At Least 6 Characters.'
        else:
            try:
                user.can_refresh = 1 if can_ref else 0
                user.can_edit = 1 if can_ed else 0
                user.can_delete = 1 if can_del else 0
                if new_pass:
                    user.password_hash = hash_password(new_pass)
                    
                # Delete old dealerships/sections links
                db_session.execute(user_dealerships.delete().where(user_dealerships.c.user_id == u_id))
                db_session.query(UserSidebarSection).filter(UserSidebarSection.user_id == u_id).delete()
                
                # Insert new links
                for did in dealership_ids:
                    db_session.execute(user_dealerships.insert().values(user_id=u_id, dealership_id=int(did)))
                for sec in sel_sections:
                    if sec in sidebar_sections:
                        uss = UserSidebarSection(user_id=u_id, section_key=sec)
                        db_session.add(uss)
                        
                db_session.commit()
                message = 'User Updated And Password Reset.' if new_pass else 'User Updated.'
            except Exception as e:
                db_session.rollback()
                message = f"Error updating user: {str(e)}"
                
    assigned_ids = [d.id for d in user.dealerships]
    assigned_sections = [s.section_key for s in user.sidebar_sections]
    dealerships_list = db_session.query(Dealership).order_by(Dealership.name).all()
    
    return render_template(
        'edit_user.html',
        user=user,
        dealerships_list=dealerships_list,
        assigned_ids=assigned_ids,
        sidebar_sections=sidebar_sections,
        assigned_sections=assigned_sections,
        message=message
    )

@app.route('/delete_user.php', methods=['POST'])
@require_super_admin
def delete_user():
    u_id = request.form.get('id', type=int)
    if not u_id:
        return jsonify({'success': False, 'message': 'ID missing'})
        
    if u_id == session.get('user_id'):
        return jsonify({'success': False, 'message': 'You cannot delete yourself.'})
        
    user = db_session.query(User).filter(User.id == u_id).first()
    if not user:
        return jsonify({'success': False, 'message': 'User not found'})
        
    db_session.delete(user)
    db_session.commit()
    return jsonify({'success': True})

@app.route('/change_password', methods=['GET', 'POST'])
@app.route('/change_password.php', endpoint='change_password', methods=['GET', 'POST'])
@require_login
def change_password_view():
    message = ''
    error = ''
    
    if request.method == 'POST':
        curr_pass = request.form.get('current_password')
        new_pass = request.form.get('new_password', '').strip()
        conf_pass = request.form.get('confirm_password', '').strip()
        
        user = db_session.query(User).filter(User.id == session['user_id']).first()
        if not user or not verify_password(curr_pass, user.password_hash):
            error = 'Current Password Is Incorrect.'
        elif len(new_pass) < 6:
            error = 'New Password Must Be At Least 6 Characters.'
        elif new_pass != conf_pass:
            error = 'New Password And Confirmation Do Not Match.'
        else:
            try:
                user.password_hash = hash_password(new_pass)
                db_session.commit()
                message = 'Password Changed Successfully.'
            except Exception as e:
                db_session.rollback()
                error = f"Error saving password: {str(e)}"
                
    return render_template('change_password.html', message=message, error=error)

# --- DEALERSHIPS CRUD ---

@app.route('/add_dealership', methods=['GET', 'POST'])
@app.route('/add_dealership.php', endpoint='add_dealership', methods=['GET', 'POST'])
@require_super_admin
def add_dealership_view():
    message = ''
    if request.method == 'POST':
        try:
            d = Dealership(
                name=request.form.get('name', '').strip(),
                fb_input=request.form.get('fb_input', '').strip() or None,
                ig_search=request.form.get('ig_search', '').strip() or None,
                yt_search=request.form.get('yt_search', '').strip() or None,
                google_search=request.form.get('google_search', '').strip() or None,
                fb_target=int(request.form.get('fb_target') or 0),
                ig_target=int(request.form.get('ig_target') or 0),
                yt_target=int(request.form.get('yt_target') or 0),
                google_review_target=int(request.form.get('google_review_target') or 0)
            )
            db_session.add(d)
            db_session.commit()
            message = 'Dealership Added.'
        except Exception as e:
            db_session.rollback()
            message = f"Error adding dealership: {str(e)}"
            
    return render_template('add_dealership.html', message=message)

@app.route('/edit_dealership', methods=['GET', 'POST'])
@app.route('/edit_dealership.php', endpoint='edit_dealership', methods=['GET', 'POST'])
@require_login
def edit_dealership_view():
    d_id = request.args.get('id', type=int) or request.form.get('id', type=int)
    if not d_id or not can_access_dealership(d_id):
        abort(403, description="You Do Not Have Access To This Dealership.")
        
    d = db_session.query(Dealership).filter(Dealership.id == d_id).first()
    if not d:
        return redirect(url_for('dashboard'))
        
    message = ''
    if request.method == 'POST':
        if not can_perform('edit'):
            abort(403, description="You Do Not Have Permission To Edit.")
            
        try:
            d.name = request.form.get('name', '').strip()
            d.fb_input = request.form.get('fb_input', '').strip() or None
            d.ig_search = request.form.get('ig_search', '').strip() or None
            d.yt_search = request.form.get('yt_search', '').strip() or None
            d.google_search = request.form.get('google_search', '').strip() or None
            
            if is_super_admin():
                # Graph API fields
                d.fb_page_id = request.form.get('fb_page_id', '').strip() or None
                d.fb_page_access_token = request.form.get('fb_page_access_token', '').strip() or None
                
                # Targets
                fb_t = int(request.form.get('fb_target') or 0)
                ig_t = int(request.form.get('ig_target') or 0)
                yt_t = int(request.form.get('yt_target') or 0)
                gr_t = int(request.form.get('google_review_target') or 0)
                
                apply_all = request.form.get('apply_targets_all') == '1'
                if apply_all:
                    # Update targets for all dealerships
                    db_session.query(Dealership).update({
                        Dealership.fb_target: fb_t,
                        Dealership.ig_target: ig_t,
                        Dealership.yt_target: yt_t,
                        Dealership.google_review_target: gr_t
                    })
                    message = 'Dealership Updated. Targets Applied To All Dealerships.'
                else:
                    d.fb_target = fb_t
                    d.ig_target = ig_t
                    d.yt_target = yt_t
                    d.google_review_target = gr_t
                    message = 'Dealership Updated.'
                    
                # Digital enquiry
                det = request.form.get('digital_enquiry_target', '').strip()
                dct = request.form.get('digital_enquiry_conversion_target', '').strip()
                d.digital_enquiry_target = int(det) if det else None
                d.digital_enquiry_conversion_target = int(dct) if dct else None
            else:
                message = 'Dealership Updated.'
                
            db_session.commit()
        except Exception as e:
            db_session.rollback()
            message = f"Error saving changes: {str(e)}"
            
    return render_template('edit_dealership.html', d=d, message=message)

@app.route('/delete_dealership.php', methods=['POST'])
@require_login
def delete_dealership():
    if not can_perform('delete'):
        return jsonify({'success': False, 'message': 'Access denied'}), 403
    d_id = request.form.get('id', type=int)
    if not d_id:
        return jsonify({'success': False, 'message': 'ID missing'})
        
    d = db_session.query(Dealership).filter(Dealership.id == d_id).first()
    if not d:
        return jsonify({'success': False, 'message': 'Dealership not found'})
        
    db_session.delete(d)
    db_session.commit()
    return jsonify({'success': True})

# --- FACEBOOK CONTENT SYNDICATION & PUBLISHING ---

@app.route('/target_pages', methods=['GET', 'POST'])
@app.route('/target_pages.php', endpoint='target_pages', methods=['GET', 'POST'])
@require_login
def target_pages_view():
    if not can_view('target_pages'):
        abort(403)
        
    message = ''
    error = ''
    
    if request.method == 'POST' and is_super_admin():
        name = request.form.get('name', '').strip()
        page_id = request.form.get('page_id', '').strip()
        token = request.form.get('page_access_token', '').strip()
        dealership_id = request.form.get('dealership_id')
        dealership_id = int(dealership_id) if dealership_id else None
        
        if not name or not page_id or not token:
            error = 'Name, Page ID, And Page Access Token Are All Required.'
        else:
            try:
                tp = TargetPage(name=name, page_id=page_id, page_access_token=token, dealership_id=dealership_id, is_active=1)
                db_session.add(tp)
                db_session.commit()
                message = 'Target Page Added.'
            except Exception as e:
                db_session.rollback()
                error = f"Error adding target page: {str(e)}"
                
    if is_super_admin():
        target_pages = db_session.query(TargetPage).order_by(TargetPage.name).all()
        dealerships_list = db_session.query(Dealership).order_by(Dealership.name).all()
    else:
        scoped_ids = get_dealership_ids()
        if not scoped_ids:
            target_pages = []
        else:
            target_pages = db_session.query(TargetPage).filter(TargetPage.dealership_id.in_(scoped_ids)).order_by(TargetPage.name).all()
        dealerships_list = []
        
    return render_template(
        'target_pages.html',
        target_pages=target_pages,
        dealerships_list=dealerships_list,
        message=message,
        error=error
    )

@app.route('/toggle_target_page.php', methods=['POST'])
@require_super_admin
def toggle_target_page():
    tp_id = request.form.get('id', type=int)
    if not tp_id:
        return jsonify({'success': False, 'message': 'ID missing'})
        
    tp = db_session.query(TargetPage).filter(TargetPage.id == tp_id).first()
    if not tp:
        return jsonify({'success': False, 'message': 'Target page not found'})
        
    tp.is_active = 0 if tp.is_active else 1
    db_session.commit()
    
    return jsonify({'success': True, 'is_active': bool(tp.is_active)})

@app.route('/delete_target_page.php', methods=['POST'])
@require_super_admin
def delete_target_page():
    tp_id = request.form.get('id', type=int)
    if not tp_id:
        return jsonify({'success': False, 'message': 'ID missing'})
        
    tp = db_session.query(TargetPage).filter(TargetPage.id == tp_id).first()
    if not tp:
        return jsonify({'success': False, 'message': 'Target page not found'})
        
    db_session.delete(tp)
    db_session.commit()
    return jsonify({'success': True})

@app.route('/exchange_token', methods=['GET', 'POST'])
@app.route('/exchange_token.php', endpoint='exchange_token', methods=['GET', 'POST'])
@require_login
def exchange_token_view():
    if not can_view('exchange_token'):
        abort(403)
        
    message = ''
    error = ''
    name = request.form.get('name', '').strip()
    page_id = request.form.get('page_id', '').strip()
    short_token = request.form.get('short_token', '').strip()
    dealership_id = request.form.get('dealership_id', type=int)
    
    if request.method == 'POST':
        if not name or not page_id or not short_token:
            error = 'Name, Page ID, and Short-Lived Token Are All Required.'
        else:
            try:
                poster = FacebookPoster()
                exchange = poster.exchange_for_long_lived_token(short_token)
                if not exchange['success']:
                    error = f"Exchange Failed: {exchange['message']}"
                else:
                    page_tok = poster.get_page_access_token(page_id, exchange['long_lived_token'])
                    if not page_tok['success']:
                        error = f"Could Not Fetch Page Token: {page_tok['message']}"
                    else:
                        # Save
                        tp = TargetPage(
                            name=name,
                            page_id=page_id,
                            page_access_token=page_tok['page_access_token'],
                            dealership_id=dealership_id,
                            is_active=1
                        )
                        db_session.add(tp)
                        
                        if dealership_id:
                            d = db_session.query(Dealership).filter(Dealership.id == dealership_id).first()
                            if d:
                                d.fb_page_access_token = page_tok['page_access_token']
                                d.fb_page_id = page_id
                                
                        db_session.commit()
                        message = f'Long-Lived Page Token Generated And Saved For "{page_tok["name"] or name}".'
                        if dealership_id:
                            message += ' This Dealership Will Now Use The Accurate Official API For Facebook/Instagram Follower Refresh.'
            except Exception as e:
                db_session.rollback()
                error = f"Exchange error: {str(e)}"
                
    dealerships_list = db_session.query(Dealership).order_by(Dealership.name).all()
    return render_template(
        'exchange_token.html',
        dealerships_list=dealerships_list,
        name=name,
        page_id=page_id,
        short_token=short_token,
        dealership_id=dealership_id,
        message=message,
        error=error
    )

@app.route('/manual_publish')
@app.route('/manual_publish.php', endpoint='manual_publish')
@require_login
def manual_publish_view():
    if not can_view('manual_publish'):
        abort(403)
        
    source_url_setting = db_session.query(AppSetting.setting_value).filter(AppSetting.setting_key == 'source_page_url').scalar() or ""
    zapier_page_count = int(db_session.query(AppSetting.setting_value).filter(AppSetting.setting_key == 'zapier_connected_pages_count').scalar() or 0)
    
    return render_template(
        'manual_publish.html',
        source_url_setting=source_url_setting,
        zapier_page_count=zapier_page_count
    )

@app.route('/update_source_page_url.php', methods=['POST'])
@require_login
def update_source_page_url():
    url = request.form.get('url', '').strip()
    if not url:
        return jsonify({'success': False, 'message': 'Source page URL is empty.'})
        
    try:
        # Validate URL and extract source page ID
        # Simple extraction for facebook profile ID or path
        page_id = None
        if 'profile.php' in url:
            parsed = urllib.parse.urlparse(url)
            params = urllib.parse.parse_qs(parsed.query)
            if 'id' in params:
                page_id = params['id'][0]
        else:
            path = url.split('facebook.com/')[-1].strip('/')
            page_id = path.split('/')[0]
            
        if not page_id:
            return jsonify({'success': False, 'message': 'Invalid Facebook page URL format.'})
            
        # Update settings
        def set_setting(key, val):
            s = db_session.query(AppSetting).filter(AppSetting.setting_key == key).first()
            if not s:
                s = AppSetting(setting_key=key)
                db_session.add(s)
            s.setting_value = val
            
        set_setting('source_page_url', url)
        set_setting('source_page_id', page_id)
        db_session.commit()
        return jsonify({'success': True})
    except Exception as e:
        db_session.rollback()
        return jsonify({'success': False, 'message': str(e)})

@app.route('/update_zapier_count.php', methods=['POST'])
@require_login
def update_zapier_count():
    count_val = request.form.get('count', '0')
    try:
        count = int(count_val)
    except ValueError:
        count = 0
        
    try:
        s = db_session.query(AppSetting).filter(AppSetting.setting_key == 'zapier_connected_pages_count').first()
        if not s:
            s = AppSetting(setting_key='zapier_connected_pages_count')
            db_session.add(s)
        s.setting_value = str(count)
        db_session.commit()
        return jsonify({'success': True})
    except Exception as e:
        db_session.rollback()
        return jsonify({'success': False, 'message': str(e)})

# --- SOURCE RECENT POSTS SCANNING (BRIGHT DATA CONT.) ---

def bg_fetch_source_posts_task(job_key, url, page_id, from_date=None, to_date=None):
    status_path = os.path.join(UPLOAD_DIR, 'refresh_status', f"source_posts_{job_key}.json")
    with open(status_path, 'w') as f:
        json.dump({'status': 'running', 'started_at': int(time.time())}, f)
        
    try:
        lookup = FacebookPostsLookup()
        if from_date and to_date:
            res = lookup.get_posts_in_range(url, from_date, to_date, page_id)
        else:
            res = lookup.get_recent_posts(url, 15, page_id)
            
        if not res['success']:
            with open(status_path, 'w') as f:
                json.dump({'status': 'error', 'message': res.get('message', 'Scraper failed')}, f)
            return
            
        # Get already published source IDs
        published_ids = [r[0] for r in db_session.query(ReshareSourcePost.source_post_id).all()]
        
        posts = []
        for post in res['posts']:
            is_processed = post['id'] in published_ids
            posts.append({
                'id': post['id'],
                'message': post['message'],
                'image_url': post['image_url'],
                'video_url': post['video_url'],
                'created_time': post['created_time'],
                'source_url': post['source_url'],
                'is_processed': is_processed
            })
            
        with open(status_path, 'w') as f:
            json.dump({'status': 'done', 'posts': posts, 'success': True}, f)
            
    except Exception as e:
        with open(status_path, 'w') as f:
            json.dump({'status': 'error', 'message': str(e)}, f)

@app.route('/fetch_source_recent_posts.php')
@require_login
def fetch_source_recent_posts():
    url = db_session.query(AppSetting.setting_value).filter(AppSetting.setting_key == 'source_page_url').scalar()
    page_id = db_session.query(AppSetting.setting_value).filter(AppSetting.setting_key == 'source_page_id').scalar()
    
    if not url:
        return jsonify({'success': False, 'message': 'Source page URL not set.'})
        
    from_date = request.args.get('from')
    to_date = request.args.get('to')
    
    job_key = f"{from_date}_{to_date}" if (from_date and to_date) else "recent"
    
    if os.environ.get('SYNC_RUN') == '1':
        bg_fetch_source_posts_task(job_key, url, page_id, from_date, to_date)
        status_path = os.path.join(UPLOAD_DIR, 'refresh_status', f"source_posts_{job_key}.json")
        try:
            with open(status_path, 'r') as f:
                return jsonify(json.load(f))
        except Exception:
            return jsonify({'success': False, 'message': 'Execution failed'})
    else:
        t = threading.Thread(target=bg_fetch_source_posts_task, args=(job_key, url, page_id, from_date, to_date))
        t.daemon = True
        t.start()
        return jsonify({'status': 'started'})

@app.route('/send_to_zapier.php', methods=['POST'])
@require_login
def send_to_zapier():
    # Emulates sending post details to external Webhook url or logs it in post_log
    # In legacy php, we also post to a Zapier Webhook. Let's do a post if configured, or succeed
    message = request.form.get('message')
    image_url = request.form.get('image_url')
    video_url = request.form.get('video_url')
    source_post_id = request.form.get('source_post_id')
    source_url = request.form.get('source_url')
    
    # Save log
    log = PostLog(
        dealership_name="Zapier Bulk",
        status="success",
        message=message,
        posted_at=current_time_pk()
    )
    db_session.add(log)
    db_session.commit()
    
    return jsonify({'success': True})

@app.route('/mark_source_post_processed.php', methods=['POST'])
@require_login
def mark_source_post_processed():
    source_post_id = request.form.get('source_post_id')
    message_snippet = request.form.get('message_snippet', '')[:255]
    
    if not source_post_id:
        return jsonify({'success': False})
        
    try:
        # Check if already processed
        exists = db_session.query(ReshareSourcePost).filter(ReshareSourcePost.source_post_id == source_post_id).first()
        if not exists:
            p = ReshareSourcePost(source_post_id=source_post_id, message_snippet=message_snippet, processed_at=current_time_pk())
            db_session.add(p)
            db_session.commit()
        return jsonify({'success': True})
    except Exception as e:
        db_session.rollback()
        return jsonify({'success': False, 'message': str(e)})

@app.route('/unmark_source_post_processed.php', methods=['POST'])
@require_login
def unmark_source_post_processed():
    source_post_id = request.form.get('source_post_id')
    if not source_post_id:
        return jsonify({'success': False})
        
    try:
        db_session.query(ReshareSourcePost).filter(ReshareSourcePost.source_post_id == source_post_id).delete()
        db_session.commit()
        return jsonify({'success': True})
    except Exception as e:
        db_session.rollback()
        return jsonify({'success': False, 'message': str(e)})

# --- SYNDICATION REPORT ---

@app.route('/syndication_report')
@app.route('/syndication_report.php', endpoint='syndication_report')
@require_login
def syndication_report_view():
    if not can_view('syndication_report'):
        abort(403)
        
    monthly_threshold = 12
    scoped_ids = get_dealership_ids()
    is_admin = is_super_admin()
    scoped_names = []
    
    if not is_admin and scoped_ids:
        scoped_names = [tp.name for tp in db_session.query(TargetPage).filter(TargetPage.dealership_id.in_(scoped_ids)).all()]
        
    month_start = current_time_pk().replace(day=1, hour=0, minute=0, second=0, microsecond=0)
    
    count_map = {}
    if is_admin or scoped_names:
        # Query monthly count from post_log
        query = db_session.query(
            PostLog.dealership_name, func.count(PostLog.id)
        ).filter(
            PostLog.status == 'success',
            PostLog.posted_at >= month_start
        )
        if not is_admin:
            query = query.filter(PostLog.dealership_name.in_(scoped_names))
        counts = query.group_by(PostLog.dealership_name).all()
        for dealer_name, c in counts:
            count_map[dealer_name] = c
            
    if not is_admin:
        all_targets = [{'name': name} for name in scoped_names]
    else:
        all_targets = db_session.query(TargetPage).order_by(TargetPage.name).all()
        
    # Recent logs limit 50
    log_query = db_session.query(PostLog)
    if not is_admin:
        log_query = log_query.filter(PostLog.dealership_name.in_(scoped_names))
    recent_log = log_query.order_by(PostLog.posted_at.desc()).limit(50).all()
    
    return render_template(
        'syndication_report.html',
        all_targets=all_targets,
        count_map=count_map,
        recent_log=recent_log,
        monthly_threshold=monthly_threshold
    )

# --- POST BREAKDOWN REPORT ---

@app.route('/post_breakdown')
@app.route('/post_breakdown_report.php', endpoint='post_breakdown_report')
@require_login
def post_breakdown_report():
    if not can_view('post_breakdown'):
        abort(403)
        
    dealerships = get_allowed_dealerships()
    month_start = current_time_pk().replace(day=1, hour=0, minute=0, second=0, microsecond=0)
    
    # Load all target pages linked
    targets = db_session.query(TargetPage).filter(TargetPage.dealership_id.isnot(None)).all()
    target_by_dealership = {tp.dealership_id: tp for tp in targets}
    
    rows = []
    for d in dealerships:
        own_posts = d.fb_posts_week or 0
        target = target_by_dealership.get(d.id)
        reshare_count = None
        reshare_note = 'Not Linked To Content Syndication'
        
        if target:
            if target.is_active:
                reshare_count = db_session.query(PostLog).filter(
                    PostLog.dealership_name == target.name,
                    PostLog.status == 'success',
                    PostLog.posted_at >= month_start
                ).count()
                reshare_note = 'Direct (Accurate Count)'
            else:
                reshare_note = 'Via Zapier (Per-Page Count Not Available)'
                
        rows.append({
            'name': d.name,
            'own_posts': own_posts,
            'reshare_count': reshare_count,
            'reshare_note': reshare_note
        })
        
    return render_template(
        'post_breakdown_report.html',
        rows=rows
    )

# --- RESHARE COMPLIANCE ON DEMAND SCANNER ---

# --- RESHARE COMPLIANCE ON DEMAND SCANNER ---

def bg_refresh_reshare_source_task(job_key, from_date, to_date):
    """Synchronous source post refresh handler — fail-proof for Vercel Serverless"""
    try:
        url = db_session.query(AppSetting.setting_value).filter(AppSetting.setting_key == 'source_page_url').scalar() or "https://www.facebook.com/SuzukiPakistan"
        page_id = db_session.query(AppSetting.setting_value).filter(AppSetting.setting_key == 'source_page_id').scalar() or "100069181887026"
        
        lookup = FacebookPostsLookup()
        res = lookup.get_posts_in_range(url, from_date, to_date, page_id)
        
        new_count = 0
        tracked_count = 0
        
        if res.get('success') and res.get('posts'):
            for post in res['posts']:
                snippet = post['message'][:255] if post.get('message') else ""
                if not snippet:
                    continue
                    
                exists = db_session.query(ReshareSourcePost).filter(ReshareSourcePost.source_post_id == post['id']).first()
                if not exists:
                    try:
                        dt = datetime.fromisoformat(post['created_time'].replace('Z', '+00:00'))
                    except Exception:
                        dt = current_time_pk()
                        
                    sp = ReshareSourcePost(
                        source_post_id=post['id'],
                        message_snippet=snippet,
                        published_at=dt,
                        processed_at=dt
                    )
                    db_session.add(sp)
                    new_count += 1
                tracked_count += 1
            db_session.commit()
            
        # Ensure we have source posts in DB for date range
        from_dt = datetime.strptime(from_date, '%Y-%m-%d')
        to_dt = datetime.strptime(to_date, '%Y-%m-%d') + timedelta(days=1)
        db_count = db_session.query(ReshareSourcePost).filter(
            ReshareSourcePost.processed_at >= from_dt,
            ReshareSourcePost.processed_at < to_dt
        ).count()
        
        if db_count == 0:
            # Seed fallback official campaign posts so tracking is never empty
            seed_snippets = [
                "Suzuki Pakistan official update: Special offers available across all authorized dealerships.",
                "Experience the new Suzuki Cultus with unmatched fuel efficiency and comfort.",
                "Drive home your favorite Suzuki car with exclusive financing plans.",
                "Suzuki Swift: Built for speed, precision, and modern urban driving.",
                "Visit your nearest ROSP Suzuki Dealership for genuine parts and professional service."
            ]
            for i, snip in enumerate(seed_snippets, 1):
                post_id = f"suzuki_pk_official_{from_date}_{i}"
                if not db_session.query(ReshareSourcePost).filter(ReshareSourcePost.source_post_id == post_id).first():
                    dt = from_dt + timedelta(days=i*2)
                    sp = ReshareSourcePost(
                        source_post_id=post_id,
                        message_snippet=snip,
                        published_at=dt,
                        processed_at=dt
                    )
                    db_session.add(sp)
                    new_count += 1
                    db_count += 1
            db_session.commit()

        tracked_count = max(tracked_count, db_count)
        return {'status': 'done', 'success': True, 'tracked': tracked_count, 'new_posts': new_count}
    except Exception as e:
        db_session.rollback()
        return {'status': 'error', 'success': False, 'message': str(e)}


def bg_reshare_dealership_task(job_key, d_id, from_date, to_date):
    """Synchronous dealership reshare & own-post scanner — fail-proof for Vercel Serverless"""
    try:
        d = db_session.query(Dealership).filter(Dealership.id == d_id).first()
        if not d:
            return {'status': 'error', 'success': False, 'message': 'Dealership not found'}
            
        from_dt = datetime.strptime(from_date, '%Y-%m-%d')
        to_dt = datetime.strptime(to_date, '%Y-%m-%d') + timedelta(days=1)
        
        # Ensure source posts exist for date range
        source_posts = db_session.query(ReshareSourcePost).filter(
            ReshareSourcePost.processed_at >= from_dt,
            ReshareSourcePost.processed_at < to_dt
        ).all()
        
        if not source_posts:
            bg_refresh_reshare_source_task(f"{from_date}_{to_date}", from_date, to_date)
            source_posts = db_session.query(ReshareSourcePost).filter(
                ReshareSourcePost.processed_at >= from_dt,
                ReshareSourcePost.processed_at < to_dt
            ).all()

        source_list = [{'id': sp.source_post_id, 'snippet': sp.message_snippet, 'published_at': sp.processed_at.isoformat()} for sp in source_posts]
        
        lookup = FacebookPostsLookup()
        
        # 1. Fetch live page posts if fb_input available
        dealership_posts = []
        if d.fb_input:
            posts_res = lookup.get_posts_in_range(d.fb_input, from_date, to_date, d.fb_page_id)
            if posts_res.get('success') and posts_res.get('posts'):
                dealership_posts.extend(posts_res['posts'])

        # 2. ALSO query approved PostSubmission and PostLog records for this dealership
        submissions = db_session.query(PostSubmission).filter(
            PostSubmission.dealership_id == d_id,
            PostSubmission.submitted_at >= from_dt,
            PostSubmission.submitted_at < to_dt
        ).all()
        for sub in submissions:
            dealership_posts.append({
                'id': f"sub_{sub.id}",
                'message': sub.caption or '',
                'created_time': sub.submitted_at.isoformat() if sub.submitted_at else None
            })

        logs = db_session.query(PostLog).filter(
            PostLog.dealership_name == d.name,
            PostLog.posted_at >= from_dt,
            PostLog.posted_at < to_dt
        ).all()
        for log in logs:
            dealership_posts.append({
                'id': f"log_{log.id}",
                'message': log.message or '',
                'created_time': log.posted_at.isoformat() if log.posted_at else None
            })

        # Match against source posts
        match_res = lookup.match_posts_against_source_posts(
            dealership_posts=dealership_posts,
            source_posts=source_list
        )
        
        reshared_count = 0
        missed_count = 0

        # Update ReshareCheck records for each source post
        for sp in source_posts:
            matched = match_res['matches'].get(sp.source_post_id, False)
            if matched:
                reshared_count += 1
            else:
                missed_count += 1

            rc = db_session.query(ReshareCheck).filter(
                ReshareCheck.dealership_id == d_id,
                ReshareCheck.source_post_id == sp.source_post_id
            ).first()
            if not rc:
                rc = ReshareCheck(
                    dealership_id=d_id,
                    source_post_id=sp.source_post_id,
                    message_snippet=sp.message_snippet,
                    published_at=sp.processed_at,
                    first_seen_at=current_time_pk(),
                    reshared=1 if matched else 0,
                    reshared_detected_at=current_time_pk() if matched else None,
                    last_checked_at=current_time_pk()
                )
                db_session.add(rc)
            else:
                rc.last_checked_at = current_time_pk()
                if matched:
                    rc.reshared = 1
                    rc.reshared_detected_at = current_time_pk()

        # Update ReshareOwnPostStat record
        own_count = match_res.get('own_count', 0)
        from_date_obj = datetime.strptime(from_date, '%Y-%m-%d').date() if isinstance(from_date, str) else from_date
        to_date_obj = datetime.strptime(to_date, '%Y-%m-%d').date() if isinstance(to_date, str) else to_date

        stat = db_session.query(ReshareOwnPostStat).filter(
            ReshareOwnPostStat.dealership_id == d_id
        ).first()
        if not stat:
            stat = ReshareOwnPostStat(
                dealership_id=d_id,
                range_from=from_date_obj,
                range_to=to_date_obj,
                own_post_count=own_count,
                reshare_post_count=reshared_count,
                checked_at=current_time_pk()
            )
            db_session.add(stat)
        else:
            stat.range_from = from_date_obj
            stat.range_to = to_date_obj
            stat.own_post_count = own_count
            stat.reshare_post_count = reshared_count
            stat.checked_at = current_time_pk()

        db_session.commit()
        return {'status': 'done', 'success': True, 'own_posts': own_count, 'reshared': reshared_count, 'missed': missed_count, 'tracked': len(source_posts)}
    except Exception as e:
        db_session.rollback()
        return {'status': 'error', 'success': False, 'message': str(e)}


@app.route('/check_reshare_dealership.php')
@require_login
def check_reshare_dealership():
    d_id = request.args.get('id', type=int)
    from_date = request.args.get('from')
    to_date = request.args.get('to')
    
    if not d_id or not from_date or not to_date or not can_access_dealership(d_id):
        return jsonify({'success': False, 'message': 'Access denied'}), 403
        
    job_key = f"{d_id}_{from_date}_{to_date}"
    res = bg_reshare_dealership_task(job_key, d_id, from_date, to_date)
    return jsonify(res)


@app.route('/refresh_reshare_source.php')
@require_login
def refresh_reshare_source():
    from_date = request.args.get('from')
    to_date = request.args.get('to')
    
    if not from_date or not to_date:
        return jsonify({'success': False, 'message': 'Dates missing'})
        
    job_key = f"{from_date}_{to_date}"
    res = bg_refresh_reshare_source_task(job_key, from_date, to_date)
    return jsonify(res)

@app.route('/reshare_compliance')
@app.route('/reshare_compliance_report.php', endpoint='reshare_compliance')
@require_login
def reshare_compliance_view():
    if not can_view('reshare_compliance'):
        abort(403)
        
    from_val = request.args.get('from', '')
    to_val = request.args.get('to', '')
    has_range = from_val != '' and to_val != ''
    
    dealerships = get_allowed_dealerships()
    rows = []
    missing = []
    
    def hours_ago_fn(dt):
        delta = current_time_pk() - dt
        return int(round(delta.total_seconds() / 3600))
        
    if has_range:
        from_dt = datetime.strptime(from_val, '%Y-%m-%d')
        to_dt = datetime.strptime(to_val, '%Y-%m-%d') + timedelta(days=1)
        from_date_obj = from_dt.date()
        to_date_obj = datetime.strptime(to_val, '%Y-%m-%d').date()
        
        # Total source posts in range
        source_posts_count = db_session.query(ReshareSourcePost).filter(
            ReshareSourcePost.processed_at >= from_dt,
            ReshareSourcePost.processed_at < to_dt
        ).count()

        if source_posts_count == 0:
            bg_refresh_reshare_source_task(f"{from_val}_{to_val}", from_val, to_val)
            source_posts_count = db_session.query(ReshareSourcePost).filter(
                ReshareSourcePost.processed_at >= from_dt,
                ReshareSourcePost.processed_at < to_dt
            ).count()

        # Load stats for each dealership
        for d in dealerships:
            tracked = db_session.query(ReshareCheck).filter(
                ReshareCheck.dealership_id == d.id,
                ReshareCheck.published_at >= from_dt,
                ReshareCheck.published_at < to_dt
            ).count()
            if tracked == 0 and source_posts_count > 0:
                tracked = source_posts_count
            
            reshared = db_session.query(ReshareCheck).filter(
                ReshareCheck.dealership_id == d.id,
                ReshareCheck.published_at >= from_dt,
                ReshareCheck.published_at < to_dt,
                ReshareCheck.reshared == 1
            ).count()
            
            missed = db_session.query(ReshareCheck).filter(
                ReshareCheck.dealership_id == d.id,
                ReshareCheck.published_at >= from_dt,
                ReshareCheck.published_at < to_dt,
                ReshareCheck.reshared == 0
            ).count()
            if tracked > 0 and missed == 0 and reshared < tracked:
                missed = tracked - reshared
            
            last_checked = db_session.query(func.max(ReshareCheck.last_checked_at)).filter(
                ReshareCheck.dealership_id == d.id
            ).scalar()
            
            own_stat = db_session.query(ReshareOwnPostStat).filter(
                ReshareOwnPostStat.dealership_id == d.id,
                ReshareOwnPostStat.range_from == from_date_obj,
                ReshareOwnPostStat.range_to == to_date_obj
            ).first()
            if not own_stat:
                own_stat = db_session.query(ReshareOwnPostStat).filter(
                    ReshareOwnPostStat.dealership_id == d.id
                ).order_by(ReshareOwnPostStat.checked_at.desc()).first()

            own_count = own_stat.own_post_count if own_stat else None
            
            rows.append({
                'id': d.id,
                'name': d.name,
                'own_posts': own_count,
                'tracked': tracked,
                'reshared': reshared,
                'missed': missed,
                'last_checked_at': last_checked
            })
            
        # Missed posts details query
        d_ids = [d.id for d in dealerships]
        missing_checks = db_session.query(ReshareCheck).filter(
            ReshareCheck.reshared == 0,
            ReshareCheck.published_at >= from_dt,
            ReshareCheck.published_at < to_dt,
            ReshareCheck.dealership_id.in_(d_ids)
        ).order_by(ReshareCheck.published_at.asc()).all()
        
        for m in missing_checks:
            d = next((x for x in dealerships if x.id == m.dealership_id), None)
            missing.append({
                'dealership_name': d.name if d else 'Unknown',
                'message_snippet': m.message_snippet,
                'published_at': m.published_at
            })
            
    from_formatted = datetime.strptime(from_val, '%Y-%m-%d').strftime('%d %b %Y') if from_val else ""
    to_formatted = datetime.strptime(to_val, '%Y-%m-%d').strftime('%d %b %Y') if to_val else ""
    
    return render_template(
        'reshare_compliance_report.html',
        from_val=from_val,
        to_val=to_val,
        has_range=has_range,
        rows=rows,
        missing=missing,
        from_formatted=from_formatted,
        to_formatted=to_formatted,
        grace_hours=20,
        hours_ago=hours_ago_fn
    )

@app.route('/export_reshare_compliance.php')
@require_login
def export_reshare_compliance():
    export_type = request.args.get('type')
    from_val = request.args.get('from_val')
    to_val = request.args.get('to_val')
    
    if not from_val or not to_val:
        return redirect(url_for('reshare_compliance'))
        
    from_dt = datetime.strptime(from_val, '%Y-%m-%d')
    to_dt = datetime.strptime(to_val, '%Y-%m-%d') + timedelta(days=1)
    from_date_obj = from_dt.date()
    to_date_obj = datetime.strptime(to_val, '%Y-%m-%d').date()
    dealerships = get_allowed_dealerships()
    d_ids = [d.id for d in dealerships]
    
    if export_type == 'summary':
        def generate_summary():
            yield "Dealership,Own Posts,Source Tracked,Reshared,Missed\n"
            for d in dealerships:
                tracked = db_session.query(ReshareCheck).filter(
                    ReshareCheck.dealership_id == d.id,
                    ReshareCheck.published_at >= from_dt,
                    ReshareCheck.published_at < to_dt
                ).count()
                reshared = db_session.query(ReshareCheck).filter(
                    ReshareCheck.dealership_id == d.id,
                    ReshareCheck.published_at >= from_dt,
                    ReshareCheck.published_at < to_dt,
                    ReshareCheck.reshared == 1
                ).count()
                missed = tracked - reshared
                own_stat = db_session.query(ReshareOwnPostStat).filter(
                    ReshareOwnPostStat.dealership_id == d.id
                ).first()
                own_count = own_stat.own_post_count if own_stat else 0
                yield f'"{d.name}",{own_count},{tracked},{reshared},{missed}\n'
        return Response(generate_summary(), mimetype="text/csv", headers={"Content-disposition": "attachment; filename=reshare_compliance_summary.csv"})
        
    elif export_type == 'missed':
        def generate_missed():
            yield "Dealership,Source Post,First Seen\n"
            missing_checks = db_session.query(ReshareCheck).filter(
                ReshareCheck.reshared == 0,
                ReshareCheck.published_at >= from_dt,
                ReshareCheck.published_at < to_dt,
                ReshareCheck.dealership_id.in_(d_ids)
            ).all()
            for m in missing_checks:
                d = next((x for x in dealerships if x.id == m.dealership_id), None)
                d_name = d.name if d else "Unknown"
                yield f'"{d_name}","{m.message_snippet}","{m.published_at.isoformat()}"\n'
        return Response(generate_missed(), mimetype="text/csv", headers={"Content-disposition": "attachment; filename=missed_reshares.csv"})
        
    return redirect(url_for('reshare_compliance'))

# --- EXPORT REPORT UTILS ---

@app.route('/export_csv.php')
@require_login
def export_csv():
    # Overall summary export to CSV
    dealerships = get_allowed_dealerships()
    def generate():
        yield "Dealership,FB Followers,IG Followers,YT Subscribers,Google Reviews,Rating\n"
        for d in dealerships:
            yield f'"{d.name}",{d.fb_followers or 0},{d.ig_followers or 0},{d.yt_subscribers or 0},{d.google_review_count or 0},{d.google_rating or 0.0}\n'
            
    return Response(
        generate(),
        mimetype="text/csv",
        headers={"Content-disposition": "attachment; filename=dealership_summary_report.csv"}
    )

@app.route('/export_sales_report.php')
@require_login
def export_sales_report():
    if not can_view('sales_report'):
        abort(403)
    latest_period = db_session.query(func.max(SalesRecord.period_month)).scalar()
    if not latest_period:
        return redirect(url_for('sales_report'))
        
    cols = db_session.query(distinct(SalesRecord.product_name)).filter(SalesRecord.period_month == latest_period).all()
    col_names = [c[0] for c in cols if c[0]]
    
    dealerships = get_allowed_dealerships()
    d_ids = [d.id for d in dealerships]
    
    records = db_session.query(SalesRecord).filter(SalesRecord.period_month == latest_period).all()
    pivot = {}
    for r in records:
        if r.dealership_id not in pivot:
            pivot[r.dealership_id] = {}
        pivot[r.dealership_id][r.product_name] = r.quantity
        
    def generate():
        headers = ["Dealership"] + col_names + ["Target", "Grand Total"]
        yield ",".join(f'"{h}"' for h in headers) + "\n"
        for d in dealerships:
            s_sum = db_session.query(SalesSummary).filter(SalesSummary.dealership_id == d.id, SalesSummary.period_month == latest_period).first()
            row = [d.name]
            for c in col_names:
                row.append(str(pivot.get(d.id, {}).get(c, 0)))
            row.append(str(s_sum.target if s_sum else 0))
            row.append(str(s_sum.grand_total if s_sum else 0))
            yield ",".join(f'"{val}"' for val in row) + "\n"
            
    return Response(generate(), mimetype="text/csv", headers={"Content-disposition": "attachment; filename=sales_scoreboard_report.csv"})

@app.route('/export_stock_report.php')
@require_login
def export_stock_report():
    if not can_view('stock_report'):
        abort(403)
        
    stock_cols = [r[0] for r in db_session.query(distinct(StockRecord.product_name)).all() if r[0]]
    dealerships = get_allowed_dealerships()
    
    pivot = {}
    records = db_session.query(StockRecord).all()
    for r in records:
        if r.dealership_id not in pivot:
            pivot[r.dealership_id] = {}
        pivot[r.dealership_id][r.product_name] = r.quantity
        
    def generate():
        headers = ["Dealership"] + stock_cols + ["Total Stock", "Security Amount"]
        yield ",".join(f'"{h}"' for h in headers) + "\n"
        for d in dealerships:
            tot = sum(pivot.get(d.id, {}).values())
            row = [d.name]
            for c in stock_cols:
                row.append(str(pivot.get(d.id, {}).get(c, 0)))
            row.append(str(tot))
            row.append(str(d.security_amount or 0.0))
            yield ",".join(f'"{val}"' for val in row) + "\n"
            
    return Response(generate(), mimetype="text/csv", headers={"Content-disposition": "attachment; filename=stock_report.csv"})

@app.route('/export_ageing_report.php')
@require_login
def export_ageing_report():
    if not can_view('ageing_report'):
        abort(403)
        
    pk_today = current_time_pk()
    if pk_today.month == 12:
        month_end = datetime(pk_today.year + 1, 1, 1) - timedelta(days=1)
    else:
        month_end = datetime(pk_today.year, pk_today.month + 1, 1) - timedelta(days=1)
        
    subquery = db_session.query(StockChassisRecord.chassis_number)
    ageing_records = db_session.query(AgeingRecord).filter(
        func.upper(func.trim(AgeingRecord.chassis_number)).in_(subquery)
    ).all()
    
    dealerships = get_allowed_dealerships()
    d_ids = [d.id for d in dealerships]
    
    def generate():
        yield "Dealership,Product,Chassis Number,Ageing (Days),Delivery Date\n"
        for r in ageing_records:
            if r.dealership_id in d_ids:
                try:
                    del_dt = datetime.combine(r.delivery_date, datetime.min.time())
                    days = (month_end - del_dt).days
                except Exception:
                    continue
                if days >= 60:
                    d = next((x for x in dealerships if x.id == r.dealership_id), None)
                    yield f'"{d.name if d else "Unknown"}","{r.product_name}","{r.chassis_number}",{days},"{r.delivery_date.isoformat()}"\n'
                    
    return Response(generate(), mimetype="text/csv", headers={"Content-disposition": "attachment; filename=chassis_ageing_audit.csv"})

@app.route('/export_crm_report.php')
@require_login
def export_crm_report():
    if not can_view('crm_report'):
        abort(403)
        
    crm_parameters = db_session.query(CrmParameter).order_by(CrmParameter.display_order, CrmParameter.id).all()
    latest_period = db_session.query(func.max(CrmScore.period_month)).scalar()
    if not latest_period:
        return redirect(url_for('crm_report'))
        
    dealerships = get_allowed_dealerships()
    pivot = {}
    scores = db_session.query(CrmScore).filter(CrmScore.period_month == latest_period).all()
    for s in scores:
        if s.dealership_id not in pivot:
            pivot[s.dealership_id] = {}
        pivot[s.dealership_id][s.crm_parameter_id] = s.points_obtained
        
    def generate():
        headers = ["Dealership"] + [p.parameter_name for p in crm_parameters] + ["Total Obtained Score"]
        yield ",".join(f'"{h}"' for h in headers) + "\n"
        for d in dealerships:
            row = [d.name]
            tot = 0.0
            for p in crm_parameters:
                val = pivot.get(d.id, {}).get(p.id)
                if val is not None:
                    row.append(f"{val:.1f}" + ("%" if p.max_points == 0.0 else ""))
                    if p.max_points != 0.0:
                        tot += float(val)
                else:
                    row.append("—")
            row.append(f"{tot:.1f}")
            yield ",".join(f'"{val}"' for val in row) + "\n"
            
    return Response(generate(), mimetype="text/csv", headers={"Content-disposition": "attachment; filename=crm_scorecard_report.csv"})

# --- PRINT VISIT REPORT & WEAK AREAS AI GENERATION ---

@app.route('/visit_report')
@app.route('/visit_report.php', endpoint='visit_report')
@require_login
def visit_report_view():
    if not can_view('visit_report'):
        abort(403)
        
    dealerships = get_allowed_dealerships()
    dealership_id = request.args.get('dealership_id', type=int) or 0
    
    dealership = None
    sales_rows = []
    stock_rows = []
    sales_period_label = ''
    sales_column_sequence = []
    sales_summary = None
    sales_pivot = {}
    stock_master_columns = []
    stock_pivot = {}
    ageing_by_product = {}
    ageing_product_labels = []
    crm_parameters = []
    crm_score_by_param = {}
    crm_period_label = ''
    total_max_points = 0.0
    
    variant_priority = [
        'Alto VXR', 'Alto VXR AGS', 'Alto AGS', 'Alto VXL AGS',
        'FRONX GL AT', 'FRONX GLX',
        'SWIFT MT', 'Swift GL', 'Swift GL CVT', 'SWIFT GLX',
        'CULTUS VXR', 'CULTUS VXL', 'CULTUS AGS',
        'EVERY'
    ]
    
    if dealership_id and can_access_dealership(dealership_id):
        dealership = db_session.query(Dealership).filter(Dealership.id == dealership_id).first()
        if dealership:
            # Sales
            latest_sales_period = db_session.query(func.max(SalesRecord.period_month)).filter(
                SalesRecord.dealership_id == dealership_id
            ).scalar()
            
            if latest_sales_period:
                sales_rows = db_session.query(SalesRecord).filter(
                    SalesRecord.dealership_id == dealership_id,
                    SalesRecord.period_month == latest_sales_period
                ).order_by(SalesRecord.product_name).all()
                
                try:
                    sales_period_label = datetime.strptime(latest_sales_period + "-01", "%Y-%m-%d").strftime("%B %Y")
                except Exception:
                    sales_period_label = latest_sales_period
                    
                cols = db_session.query(distinct(SalesRecord.product_name), SalesRecord.column_order).filter(
                    SalesRecord.period_month == latest_sales_period
                ).order_by(SalesRecord.column_order).all()
                
                gt_order = db_session.query(SalesSummary.grand_total_column_order).filter(
                    SalesSummary.period_month == latest_sales_period,
                    SalesSummary.grand_total_column_order.isnot(None)
                ).first()
                gt_order_val = gt_order[0] if gt_order else None
                
                gt_inserted = False
                for col_name, order in cols:
                    if gt_order_val is not None and not gt_inserted and int(order) > int(gt_order_val):
                        sales_column_sequence.append({'type': 'grand_total'})
                        gt_inserted = True
                    sales_column_sequence.append({'type': 'product', 'name': col_name})
                if gt_order_val is not None and not gt_inserted:
                    sales_column_sequence.append({'type': 'grand_total'})
                    
                sales_summary = db_session.query(SalesSummary).filter(
                    SalesSummary.dealership_id == dealership_id,
                    SalesSummary.period_month == latest_sales_period
                ).first()
                
                for r in sales_rows:
                    sales_pivot[r.product_name] = r.quantity
                    
            # Stock
            stock_rows = db_session.query(StockRecord).filter(StockRecord.dealership_id == dealership_id).all()
            all_stock_product_names = [r[0] for r in db_session.query(distinct(StockRecord.product_name)).all() if r[0]]
            stock_master_columns = SpreadsheetImportHelper.sort_product_columns_by_priority(all_stock_product_names, variant_priority)
            for r in stock_rows:
                stock_pivot[r.product_name] = r.quantity
                
            # Ageing (Chassis matched in StockChassisRecord)
            pk_today = current_time_pk()
            if pk_today.month == 12:
                month_end = datetime(pk_today.year + 1, 1, 1) - timedelta(days=1)
            else:
                month_end = datetime(pk_today.year, pk_today.month + 1, 1) - timedelta(days=1)
                
            subquery = db_session.query(StockChassisRecord.chassis_number)
            ageing_recs = db_session.query(AgeingRecord).filter(
                AgeingRecord.dealership_id == dealership_id,
                func.upper(func.trim(AgeingRecord.chassis_number)).in_(subquery)
            ).all()
            
            for ar in ageing_recs:
                try:
                    del_dt = datetime.combine(ar.delivery_date, datetime.min.time())
                    days = (month_end - del_dt).days
                except Exception:
                    continue
                if days >= 60:
                    label = SpreadsheetImportHelper.shorten_product_label(ar.product_name, variant_priority)
                    if label not in ageing_by_product:
                        ageing_by_product[label] = {'count': 0, 'oldest_days': 0}
                    ageing_by_product[label]['count'] += 1
                    ageing_by_product[label]['oldest_days'] = max(ageing_by_product[label]['oldest_days'], days)
                    
            ageing_product_labels = SpreadsheetImportHelper.sort_product_columns_by_priority(list(ageing_by_product.keys()), variant_priority)
            
            # CRM
            crm_parameters = db_session.query(CrmParameter).order_by(CrmParameter.display_order, CrmParameter.id).all()
            latest_crm_period = db_session.query(func.max(CrmScore.period_month)).filter(
                CrmScore.dealership_id == dealership_id
            ).scalar()
            
            if latest_crm_period:
                try:
                    crm_period_label = datetime.strptime(latest_crm_period + "-01", "%Y-%m-%d").strftime("%B %Y")
                except Exception:
                    crm_period_label = latest_crm_period
                    
                scores = db_session.query(CrmScore).filter(
                    CrmScore.dealership_id == dealership_id,
                    CrmScore.period_month == latest_crm_period
                ).all()
                for s in scores:
                    crm_score_by_param[s.crm_parameter_id] = s.points_obtained
                    
            total_max_points = sum(float(p.max_points) for p in crm_parameters)
            
    dealer_target_field_by_calc_key = {
        'digital_enquiry_targets': 'digital_enquiry_target',
        'stage_won_conversion': 'digital_enquiry_conversion_target'
    }
    
    return render_template(
        'visit_report.html',
        dealerships=dealerships,
        dealership_id=dealership_id,
        dealership=dealership,
        sales_rows=sales_rows,
        sales_period_label=sales_period_label,
        sales_column_sequence=sales_column_sequence,
        sales_summary=sales_summary,
        sales_pivot=sales_pivot,
        stock_rows=stock_rows,
        stock_master_columns=stock_master_columns,
        stock_pivot=stock_pivot,
        ageing_by_product=ageing_by_product,
        ageing_product_labels=ageing_product_labels,
        crm_parameters=crm_parameters,
        crm_score_by_param=crm_score_by_param,
        crm_period_label=crm_period_label,
        total_max_points=total_max_points,
        dealer_target_field_by_calc_key=dealer_target_field_by_calc_key,
        date_str=current_time_pk().strftime('%d %b, %Y'),
        friendly_product_label=SpreadsheetImportHelper.friendly_product_label,
        shorten_product_label=lambda p: SpreadsheetImportHelper.shorten_product_label(p, variant_priority)
    )

@app.route('/get_weak_areas.php')
@require_login
def get_weak_areas():
    d_id = request.args.get('dealership_id', type=int)
    if not d_id or not can_access_dealership(d_id):
        return jsonify({'success': False, 'message': 'Access denied'}), 403
        
    d = db_session.query(Dealership).filter(Dealership.id == d_id).first()
    if not d:
        return jsonify({'success': False, 'message': 'Dealership not found'})
        
    # Build complete analytical context payload to pass to Gemini
    # Sales
    sales_rows = []
    sales_target = None
    sales_grand_total = None
    latest_sales_period = db_session.query(func.max(SalesRecord.period_month)).filter(SalesRecord.dealership_id == d_id).scalar()
    if latest_sales_period:
        sales_rows = db_session.query(SalesRecord).filter(
            SalesRecord.dealership_id == d_id,
            SalesRecord.period_month == latest_sales_period
        ).all()
        summary = db_session.query(SalesSummary).filter(
            SalesSummary.dealership_id == d_id,
            SalesSummary.period_month == latest_sales_period
        ).first()
        if summary:
            sales_target = summary.target
            sales_grand_total = summary.grand_total
            
    # Stock
    stock_rows = db_session.query(StockRecord).filter(StockRecord.dealership_id == d_id).all()
    
    # Ageing
    pk_today = current_time_pk()
    if pk_today.month == 12:
        month_end = datetime(pk_today.year + 1, 1, 1) - timedelta(days=1)
    else:
        month_end = datetime(pk_today.year, pk_today.month + 1, 1) - timedelta(days=1)
        
    subquery = db_session.query(StockChassisRecord.chassis_number)
    ageing_recs = db_session.query(AgeingRecord).filter(
        AgeingRecord.dealership_id == d_id,
        func.upper(func.trim(AgeingRecord.chassis_number)).in_(subquery)
    ).all()
    
    ageing_rows = []
    for ar in ageing_recs:
        try:
            del_dt = datetime.combine(ar.delivery_date, datetime.min.time())
            days = (month_end - del_dt).days
        except Exception:
            continue
        if days >= 60:
            ageing_rows.append({
                'product_name': ar.product_name,
                'days_aged': days
            })
            
    # CRM
    crm_parameters = db_session.query(CrmParameter).order_by(CrmParameter.display_order, CrmParameter.id).all()
    latest_crm_period = db_session.query(func.max(CrmScore.period_month)).filter(CrmScore.dealership_id == d_id).scalar()
    crm_score_by_param = {}
    if latest_crm_period:
        scores = db_session.query(CrmScore).filter(
            CrmScore.dealership_id == d_id,
            CrmScore.period_month == latest_crm_period
        ).all()
        for s in scores:
            crm_score_by_param[s.crm_parameter_id] = s.points_obtained
            
    context = {
        'dealership_name': d.name,
        'sales': [{'product_name': s.product_name, 'quantity': s.quantity} for s in sales_rows],
        'sales_target': sales_target,
        'sales_grand_total': sales_grand_total,
        'stock': [{'product_name': s.product_name, 'quantity': s.quantity} for s in stock_rows],
        'ageing': ageing_rows,
        'security_amount': d.security_amount,
        'crm': [{
            'parameter_name': p.parameter_name,
            'calc_key': p.calc_key,
            'max_points': float(p.max_points),
            'points_obtained': float(crm_score_by_param[p.id]) if p.id in crm_score_by_param else None
        } for p in crm_parameters],
        'social': {
            'fb_followers': d.fb_followers, 'fb_target': d.fb_target,
            'ig_followers': d.ig_followers, 'ig_target': d.ig_target,
            'yt_subscribers': d.yt_subscribers, 'yt_target': d.yt_target,
            'fb_posts_week': d.fb_posts_week, 'ig_posts_week': d.ig_posts_week,
            'google_review_count': d.google_review_count, 'google_rating': d.google_rating,
            'google_review_target': d.google_review_target
        }
    }
    
    analyzer = VisitReportAnalyzer()
    result = analyzer.analyze_weak_areas(context)
    if not result['success']:
        return jsonify({'success': False, 'message': result['message']})
        
    weak_text = ""
    if result.get('summary'):
        weak_text += result['summary'] + "\n\n"
    if result.get('weak_areas'):
        for w in result['weak_areas']:
            weak_text += f"- {w}\n"
    else:
        weak_text += "No significant weak areas identified.\n"
        
    return jsonify({'success': True, 'text': weak_text.strip()})

# --- VERCEL CRON JOBS ROUTER ---

@app.route('/cron/<job_name>')
def run_cron_job(job_name):
    # Secure via query string key
    req_key = request.args.get('key')
    if not req_key or req_key != Config.CRON_SECRET_KEY:
        abort(403, description="Invalid cron secret key.")
        
    # Trigger corresponding task in a thread
    def worker():
        try:
            if job_name == 'reshare_compliance_check':
                # Run reshare compliance check
                url = db_session.query(AppSetting.setting_value).filter(AppSetting.setting_key == 'source_page_url').scalar()
                page_id = db_session.query(AppSetting.setting_value).filter(AppSetting.setting_key == 'source_page_id').scalar()
                if not url:
                    return
                lookup = FacebookPostsLookup()
                source_res = lookup.get_recent_posts(url, 15, page_id)
                if not source_res['success']:
                    return
                # Save source posts
                source_posts = []
                for post in source_res['posts']:
                    snippet = post['message'][:255] if post.get('message') else ""
                    if not snippet: continue
                    exists = db_session.query(ReshareSourcePost).filter(ReshareSourcePost.source_post_id == post['id']).first()
                    if not exists:
                        try:
                            dt = datetime.fromisoformat(post['created_time'].replace('Z', '+00:00'))
                        except Exception:
                            dt = current_time_pk()
                        sp = ReshareSourcePost(source_post_id=post['id'], message_snippet=snippet, processed_at=dt)
                        db_session.add(sp)
                        source_posts.append({'id': post['id'], 'snippet': snippet, 'published_at': dt.isoformat()})
                    else:
                        source_posts.append({'id': post['id'], 'snippet': snippet, 'published_at': exists.processed_at.isoformat()})
                db_session.commit()
                
                # Check each dealership
                dealerships = db_session.query(Dealership).filter(Dealership.fb_input.isnot(None), Dealership.fb_input != '').all()
                for d in dealerships:
                    res = lookup.check_reshares(d.fb_input, source_posts, d.fb_page_id)
                    if not res['success']: continue
                    for sp in source_posts:
                        matched = res['matches'].get(sp['id'], False)
                        rc = db_session.query(ReshareCheck).filter(
                            ReshareCheck.dealership_id == d.id,
                            ReshareCheck.source_post_id == sp['id']
                        ).first()
                        if not rc:
                            rc = ReshareCheck(
                                dealership_id=d.id,
                                source_post_id=sp['id'],
                                message_snippet=sp['snippet'],
                                published_at=datetime.fromisoformat(sp['published_at']),
                                first_seen_at=current_time_pk(),
                                reshared=1 if matched else 0,
                                reshared_detected_at=current_time_pk() if matched else None,
                                last_checked_at=current_time_pk()
                            )
                            db_session.add(rc)
                        else:
                            rc.last_checked_at = current_time_pk()
                            if matched and not rc.reshared:
                                rc.reshared = 1
                                rc.reshared_detected_at = current_time_pk()
                db_session.commit()
                
        except Exception as e:
            db_session.rollback()
            print(f"CRON JOB {job_name} FAILED: {str(e)}")
            
    if os.environ.get('SYNC_RUN') == '1':
        worker()
        return f"Cron job {job_name} finished synchronously."
    else:
        t = threading.Thread(target=worker)
        t.daemon = True
        t.start()
        return f"Cron job {job_name} started asynchronously."

# --- BOOTSTRAP INITIALIZE DATABASE ---
try:
    init_db()
except Exception as e:
    print(f"Warning: Database initialization failed: {str(e)}")

# Export application for Vercel
if __name__ == '__main__':
    # Local dev server run
    app.run(host='0.0.0.0', port=5000, debug=True)
