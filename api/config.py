import os
from dotenv import load_dotenv

# Resolve root path of the project and load .env file
root_dir = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
load_dotenv(os.path.join(root_dir, '.env'))

class Config:
    DATABASE_URL = os.environ.get('DATABASE_URL', 'mysql+pymysql://root:@localhost/dealership_dashboard')
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
