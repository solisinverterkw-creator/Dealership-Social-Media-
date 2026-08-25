import os
from dotenv import load_dotenv

# Resolve root path of the project and load .env file
root_dir = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
load_dotenv(os.path.join(root_dir, '.env'))

class Config:
    # Automatically read DATABASE_URL or POSTGRES_URL (injected by Vercel Neon Storage integration)
    _db_url = os.environ.get('DATABASE_URL') or os.environ.get('POSTGRES_URL') or 'mysql+pymysql://root:@localhost/dealership_dashboard'
    
    # pg8000 does NOT support sslmode or channel_binding as URL query params - must be stripped
    # SSL is handled separately via connect_args in database.py
    if 'postgresql' in _db_url or _db_url.startswith('postgres://'):
        from urllib.parse import urlparse, urlencode, parse_qs, urlunparse
        _parsed = urlparse(_db_url)
        # Strip incompatible query params
        _query = {k: v for k, v in parse_qs(_parsed.query).items()
                  if k not in ('sslmode', 'channel_binding', 'connect_timeout')}
        _db_url = urlunparse((
            'postgresql+pg8000',
            _parsed.netloc,
            _parsed.path,
            _parsed.params,
            urlencode(_query, doseq=True),
            _parsed.fragment
        ))
    DATABASE_URL = _db_url


    SECRET_KEY = os.environ.get('FLASK_SECRET_KEY', 'default-dev-secret-key-change-in-prod')
    
    RAPIDAPI_KEY = os.environ.get('RAPIDAPI_KEY', '')
    SCRAPECREATORS_API_KEY_INSTAGRAM = os.environ.get('SCRAPECREATORS_API_KEY_INSTAGRAM', '')
    
    BRIGHTDATA_API_TOKEN = os.environ.get('BRIGHTDATA_API_TOKEN', '')
    BRIGHTDATA_DATASET_PAGE_INFO = os.environ.get('BRIGHTDATA_DATASET_PAGE_INFO', 'gd_mf124a0511bauquyow')
    BRIGHTDATA_DATASET_PAGE_POSTS = os.environ.get('BRIGHTDATA_DATASET_PAGE_POSTS', 'gd_lkaxegm826bjpoo9m5')
    BRIGHTDATA_DATASET_INSTAGRAM_PROFILE = os.environ.get('BRIGHTDATA_DATASET_INSTAGRAM_PROFILE', 'gd_l1vikfch901nx3by4')
    
    YOUTUBE_API_KEY = os.environ.get('YOUTUBE_API_KEY', '')
    GEMINI_API_KEY = os.environ.get('GEMINI_API_KEY', '')
    
    FB_APP_ID = os.environ.get('FB_APP_ID', '')
    FB_APP_SECRET = os.environ.get('FB_APP_SECRET', '')
    FB_GRAPH_API_VERSION = os.environ.get('FB_GRAPH_API_VERSION', 'v20.0')
    SOURCE_PAGE_URL = os.environ.get('SOURCE_PAGE_URL', 'https://www.facebook.com/profile.php?id=61591770663883')
    SOURCE_PAGE_ID = os.environ.get('SOURCE_PAGE_ID', '61591770663883')
    
    CRON_SECRET_KEY = os.environ.get('CRON_SECRET_KEY', 'fc4dbed312f85b641e150b03d859ba64297bbd7c')
    TIMEZONE = 'Asia/Karachi'
    SYNC_RUN = os.environ.get('SYNC_RUN', '0') == '1'

    @classmethod
    def get_key(cls, key_name, default_env_var=None):
        try:
            from api.database import db_session
            from api.models import AppSetting
            setting = db_session.query(AppSetting).filter(AppSetting.setting_key == key_name.lower()).first()
            if setting and setting.setting_value and setting.setting_value.strip():
                return setting.setting_value.strip()
        except Exception:
            pass
        if default_env_var:
            return os.environ.get(default_env_var, '')
        return os.environ.get(key_name.upper(), '')

