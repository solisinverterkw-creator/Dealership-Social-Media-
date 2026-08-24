from sqlalchemy import Column, Integer, String, Text, DECIMAL, BigInteger, DateTime, Date, ForeignKey, Table, UniqueConstraint, func
from sqlalchemy.orm import relationship
from api.database import Base

# Association table for User-to-Dealership many-to-many relationship
user_dealerships = Table(
    'user_dealerships',
    Base.metadata,
    Column('user_id', Integer, ForeignKey('users.id', ondelete='CASCADE'), primary_key=True),
    Column('dealership_id', Integer, ForeignKey('dealerships.id', ondelete='CASCADE'), primary_key=True)
)

class User(Base):
    __tablename__ = 'users'

    id = Column(Integer, primary_key=True, autoincrement=True)
    username = Column(String(50), nullable=False, unique=True)
    password_hash = Column(String(255), nullable=False)
    is_super_admin = Column(Integer, nullable=False, default=0) # Using Integer to map tinyint(1)
    can_refresh = Column(Integer, nullable=False, default=1)
    can_edit = Column(Integer, nullable=False, default=0)
    can_delete = Column(Integer, nullable=False, default=0)
    created_at = Column(DateTime, default=func.now())

    # Relationships
    dealerships = relationship('Dealership', secondary=user_dealerships, back_populates='users')
    sidebar_sections = relationship('UserSidebarSection', back_populates='user', cascade='all, delete-orphan')

class UserSidebarSection(Base):
    __tablename__ = 'user_sidebar_sections'

    user_id = Column(Integer, ForeignKey('users.id', ondelete='CASCADE'), primary_key=True)
    section_key = Column(String(50), primary_key=True)

    # Relationships
    user = relationship('User', back_populates='sidebar_sections')

class Dealership(Base):
    __tablename__ = 'dealerships'

    id = Column(Integer, primary_key=True, autoincrement=True)
    name = Column(String(150), nullable=False)

    fb_input = Column(String(255), nullable=True)
    ig_search = Column(String(255), nullable=True)
    yt_search = Column(String(255), nullable=True)
    google_search = Column(String(255), nullable=True)

    fb_page_id = Column(String(50), nullable=True)
    fb_followers = Column(Integer, default=0)
    fb_target = Column(Integer, default=0)
    fb_page_access_token = Column(Text, nullable=True)
    ig_business_account_id = Column(String(50), nullable=True)

    ig_followers = Column(Integer, default=0)
    ig_target = Column(Integer, default=0)
    ig_updated_at = Column(DateTime, nullable=True)

    yt_channel_id = Column(String(50), nullable=True)
    yt_subscribers = Column(Integer, default=0)
    yt_target = Column(Integer, default=0)
    yt_videos = Column(Integer, default=0)
    yt_views = Column(BigInteger, default=0)

    google_review_count = Column(Integer, default=0)
    google_review_target = Column(Integer, default=0)
    google_rating = Column(DECIMAL(2, 1), default=0.0)

    fb_posts_week = Column(Integer, default=0)
    fb_engagement_avg = Column(DECIMAL(10, 2), default=0.0)
    fb_posts_checked_at = Column(DateTime, nullable=True)

    ig_posts_week = Column(Integer, default=0)
    ig_engagement_avg = Column(DECIMAL(10, 2), default=0.0)
    ig_posts_checked_at = Column(DateTime, nullable=True)

    yt_videos_month = Column(Integer, default=0)
    yt_videos_checked_at = Column(DateTime, nullable=True)

    security_amount = Column(DECIMAL(12, 2), nullable=True)
    region = Column(String(50), nullable=True)

    digital_enquiry_target = Column(Integer, nullable=True)
    digital_enquiry_conversion_target = Column(Integer, nullable=True)

    last_refreshed = Column(DateTime, nullable=True)
    created_at = Column(DateTime, default=func.now())

    # Relationships
    users = relationship('User', secondary=user_dealerships, back_populates='dealerships')
    yt_monthly_stats = relationship('YtMonthlyStats', back_populates='dealership', cascade='all, delete-orphan')
    reshare_checks = relationship('ReshareCheck', back_populates='dealership', cascade='all, delete-orphan')
    reshare_own_post_stats = relationship('ReshareOwnPostStat', back_populates='dealership', uselist=False, cascade='all, delete-orphan')
    post_submissions = relationship('PostSubmission', back_populates='dealership', cascade='all, delete-orphan')
    sales_records = relationship('SalesRecord', back_populates='dealership', cascade='all, delete-orphan')
    sales_summaries = relationship('SalesSummary', back_populates='dealership', cascade='all, delete-orphan')
    stock_records = relationship('StockRecord', back_populates='dealership', cascade='all, delete-orphan')
    ageing_records = relationship('AgeingRecord', back_populates='dealership', cascade='all, delete-orphan')
    stock_chassis_records = relationship('StockChassisRecord', back_populates='dealership', cascade='all, delete-orphan')
    crm_scores = relationship('CrmScore', back_populates='dealership', cascade='all, delete-orphan')
    crm_raw_data = relationship('CrmRawData', back_populates='dealership', cascade='all, delete-orphan')

class YtMonthlyStats(Base):
    __tablename__ = 'yt_monthly_stats'

    dealership_id = Column(Integer, ForeignKey('dealerships.id', ondelete='CASCADE'), primary_key=True)
    month = Column(String(7), primary_key=True) # e.g. '2026-07'
    video_count = Column(Integer, nullable=False, default=0)

    # Relationships
    dealership = relationship('Dealership', back_populates='yt_monthly_stats')

class TargetPage(Base):
    __tablename__ = 'target_pages'

    id = Column(Integer, primary_key=True, autoincrement=True)
    name = Column(String(150), nullable=False)
    page_id = Column(String(50), nullable=False)
    page_access_token = Column(Text, nullable=False)
    is_active = Column(Integer, nullable=False, default=1)
    dealership_id = Column(Integer, nullable=True) # Weak link, not a hard FK (as in schema.sql line 124)
    created_at = Column(DateTime, default=func.now())

class ProcessedSourcePost(Base):
    __tablename__ = 'processed_source_posts'

    id = Column(Integer, primary_key=True, autoincrement=True)
    source_post_id = Column(String(50), nullable=False, unique=True)
    message_snippet = Column(String(255), nullable=True)
    published_at = Column(DateTime, nullable=True)
    processed_at = Column(DateTime, default=func.now())

class AppSetting(Base):
    __tablename__ = 'app_settings'

    setting_key = Column(String(50), primary_key=True)
    setting_value = Column(Text, nullable=True)

class ReshareCheck(Base):
    __tablename__ = 'reshare_checks'
    __table_args__ = (UniqueConstraint('dealership_id', 'source_post_id', name='dealership_post'),)

    id = Column(Integer, primary_key=True, autoincrement=True)
    dealership_id = Column(Integer, ForeignKey('dealerships.id', ondelete='CASCADE'), nullable=False)
    source_post_id = Column(String(50), nullable=False)
    message_snippet = Column(String(255), nullable=True)
    first_seen_at = Column(DateTime, nullable=False)
    published_at = Column(DateTime, nullable=True)
    reshared = Column(Integer, nullable=False, default=0)
    reshared_detected_at = Column(DateTime, nullable=True)
    last_checked_at = Column(DateTime, nullable=True)

    # Relationships
    dealership = relationship('Dealership', back_populates='reshare_checks')

class ReshareOwnPostStat(Base):
    __tablename__ = 'reshare_own_post_stats'

    dealership_id = Column(Integer, ForeignKey('dealerships.id', ondelete='CASCADE'), primary_key=True)
    range_from = Column(Date, nullable=False)
    range_to = Column(Date, nullable=False)
    own_post_count = Column(Integer, nullable=False, default=0)
    reshare_post_count = Column(Integer, nullable=False, default=0)
    checked_at = Column(DateTime, nullable=False)

    # Relationships
    dealership = relationship('Dealership', back_populates='reshare_own_post_stats')

class VehicleModel(Base):
    __tablename__ = 'vehicle_models'

    id = Column(Integer, primary_key=True, autoincrement=True)
    name = Column(String(100), nullable=False)
    color = Column(String(50), nullable=False)
    reference_image = Column(String(255), nullable=False)
    created_at = Column(DateTime, default=func.now())

    # Relationships
    images = relationship('VehicleModelImage', back_populates='vehicle_model', cascade='all, delete-orphan')

class VehicleModelImage(Base):
    __tablename__ = 'vehicle_model_images'

    id = Column(Integer, primary_key=True, autoincrement=True)
    vehicle_model_id = Column(Integer, ForeignKey('vehicle_models.id', ondelete='CASCADE'), nullable=False)
    image_path = Column(String(255), nullable=False)
    created_at = Column(DateTime, default=func.now())

    # Relationships
    vehicle_model = relationship('VehicleModel', back_populates='images')

class BrandIdentity(Base):
    __tablename__ = 'brand_identity'

    id = Column(Integer, primary_key=True, default=1)
    logo_light_path = Column(String(255), nullable=True)
    logo_dark_path = Column(String(255), nullable=True)
    logo_white_bg_path = Column(String(255), nullable=True)
    tagline = Column(String(255), nullable=True)
    primary_color = Column(String(100), nullable=True)
    secondary_color = Column(String(100), nullable=True)
    website_url = Column(String(255), nullable=True)
    updated_at = Column(DateTime, default=func.now(), onupdate=func.now())

class PostSubmission(Base):
    __tablename__ = 'post_submissions'

    id = Column(Integer, primary_key=True, autoincrement=True)
    dealership_id = Column(Integer, ForeignKey('dealerships.id', ondelete='CASCADE'), nullable=False)
    image_path = Column(String(255), nullable=False)
    caption = Column(Text, nullable=True)
    status = Column(String(20), nullable=False, default='pending') # pending, approved, rejected
    reasons = Column(Text, nullable=True)
    checked_at = Column(DateTime, nullable=True)
    submitted_at = Column(DateTime, default=func.now())

    # Relationships
    dealership = relationship('Dealership', back_populates='post_submissions')

class PostLog(Base):
    __tablename__ = 'post_log'

    id = Column(Integer, primary_key=True, autoincrement=True)
    source_post_id = Column(String(50), nullable=True)
    source_url = Column(String(500), nullable=True)
    message = Column(Text, nullable=True)
    dealership_name = Column(String(150), nullable=False)
    target_page_id = Column(String(50), nullable=False)
    fb_post_id = Column(String(100), nullable=True)
    status = Column(String(20), nullable=False) # success, failed
    error_message = Column(String(500), nullable=True)
    posted_at = Column(DateTime, default=func.now())

class SalesRecord(Base):
    __tablename__ = 'sales_records'

    id = Column(Integer, primary_key=True, autoincrement=True)
    dealership_id = Column(Integer, ForeignKey('dealerships.id', ondelete='CASCADE'), nullable=False)
    product_name = Column(String(150), nullable=False)
    quantity = Column(Integer, nullable=False, default=0)
    period_month = Column(String(7), nullable=False) # e.g. '2026-07'
    column_order = Column(Integer, nullable=False, default=0)
    imported_at = Column(DateTime, default=func.now())

    # Relationships
    dealership = relationship('Dealership', back_populates='sales_records')

class SalesSummary(Base):
    __tablename__ = 'sales_summary'

    dealership_id = Column(Integer, ForeignKey('dealerships.id', ondelete='CASCADE'), primary_key=True)
    period_month = Column(String(7), primary_key=True) # e.g. '2026-07'
    target = Column(Integer, nullable=True)
    grand_total = Column(Integer, nullable=True)
    grand_total_column_order = Column(Integer, nullable=True)

    # Relationships
    dealership = relationship('Dealership', back_populates='sales_summaries')

class StockRecord(Base):
    __tablename__ = 'stock_records'

    id = Column(Integer, primary_key=True, autoincrement=True)
    dealership_id = Column(Integer, ForeignKey('dealerships.id', ondelete='CASCADE'), nullable=False)
    product_name = Column(String(150), nullable=False)
    quantity = Column(Integer, nullable=False, default=0)
    column_order = Column(Integer, nullable=False, default=0)
    imported_at = Column(DateTime, default=func.now())

    # Relationships
    dealership = relationship('Dealership', back_populates='stock_records')

class AgeingRecord(Base):
    __tablename__ = 'ageing_records'

    id = Column(Integer, primary_key=True, autoincrement=True)
    dealership_id = Column(Integer, ForeignKey('dealerships.id', ondelete='CASCADE'), nullable=False)
    product_name = Column(String(150), nullable=False)
    chassis_number = Column(String(100), nullable=False)
    delivery_date = Column(Date, nullable=False)
    imported_at = Column(DateTime, default=func.now())

    # Relationships
    dealership = relationship('Dealership', back_populates='ageing_records')

class StockChassisRecord(Base):
    __tablename__ = 'stock_chassis_records'

    id = Column(Integer, primary_key=True, autoincrement=True)
    dealership_id = Column(Integer, ForeignKey('dealerships.id', ondelete='CASCADE'), nullable=False)
    chassis_number = Column(String(100), nullable=False)
    imported_at = Column(DateTime, default=func.now())

    # Relationships
    dealership = relationship('Dealership', back_populates='stock_chassis_records')

class CrmParameter(Base):
    __tablename__ = 'crm_parameters'

    id = Column(Integer, primary_key=True, autoincrement=True)
    display_order = Column(Integer, nullable=False, default=0)
    parameter_name = Column(String(255), nullable=False)
    criteria = Column(Text, nullable=True)
    max_points = Column(DECIMAL(6, 2), nullable=False)
    calc_key = Column(String(64), nullable=True)
    created_at = Column(DateTime, default=func.now())

    # Relationships
    scores = relationship('CrmScore', back_populates='parameter', cascade='all, delete-orphan')
    raw_data = relationship('CrmRawData', back_populates='parameter', cascade='all, delete-orphan')

class CrmScore(Base):
    __tablename__ = 'crm_scores'
    __table_args__ = (UniqueConstraint('dealership_id', 'crm_parameter_id', 'period_month', name='dealership_param_period_score'),)

    id = Column(Integer, primary_key=True, autoincrement=True)
    dealership_id = Column(Integer, ForeignKey('dealerships.id', ondelete='CASCADE'), nullable=False)
    crm_parameter_id = Column(Integer, ForeignKey('crm_parameters.id', ondelete='CASCADE'), nullable=False)
    period_month = Column(String(7), nullable=False) # e.g. '2026-07'
    points_obtained = Column(DECIMAL(6, 2), nullable=False)
    imported_at = Column(DateTime, default=func.now())

    # Relationships
    dealership = relationship('Dealership', back_populates='crm_scores')
    parameter = relationship('CrmParameter', back_populates='scores')

class CrmRawData(Base):
    __tablename__ = 'crm_raw_data'
    __table_args__ = (UniqueConstraint('dealership_id', 'crm_parameter_id', 'period_month', name='dealership_param_period_raw'),)

    id = Column(Integer, primary_key=True, autoincrement=True)
    dealership_id = Column(Integer, ForeignKey('dealerships.id', ondelete='CASCADE'), nullable=False)
    crm_parameter_id = Column(Integer, ForeignKey('crm_parameters.id', ondelete='CASCADE'), nullable=False)
    period_month = Column(String(7), nullable=False) # e.g. '2026-07'
    raw_json = Column(Text, nullable=False) # JSON encoded data
    imported_at = Column(DateTime, default=func.now())

    # Relationships
    dealership = relationship('Dealership', back_populates='crm_raw_data')
    parameter = relationship('CrmParameter', back_populates='raw_data')
