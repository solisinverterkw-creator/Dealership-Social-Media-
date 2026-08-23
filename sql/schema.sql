CREATE DATABASE IF NOT EXISTS dealership_dashboard CHARACTER SET utf8mb4;
USE dealership_dashboard;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    is_super_admin TINYINT(1) NOT NULL DEFAULT 0,  -- super admin bypasses user_dealerships + all can_* flags entirely
    can_refresh TINYINT(1) NOT NULL DEFAULT 1,     -- trigger FB/IG/YT/Google refresh + reshare compliance checks
    can_edit TINYINT(1) NOT NULL DEFAULT 0,        -- edit dealership search inputs (edit_dealership.php)
    can_delete TINYINT(1) NOT NULL DEFAULT 0,      -- delete a dealership they're assigned to
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE dealerships (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,

    -- search inputs (aap ek baar set karein)
    fb_input VARCHAR(255) NULL,        -- FB page ka exact URL ya username
    ig_search VARCHAR(255) NULL,       -- IG profile ka URL ya @username
    yt_search VARCHAR(255) NULL,       -- YouTube channel ka naam
    google_search VARCHAR(255) NULL,   -- Google business ka naam + city

    -- Facebook (auto via RapidAPI)
    fb_page_id VARCHAR(50) NULL,       -- resolve once, reuse (saves a request on every future check)
    fb_followers INT DEFAULT 0,
    fb_target INT DEFAULT 0,           -- super-admin-set goal; used for % achieved in reports

    -- Optional official Graph API access, set once a dealership grants Page admin
    -- access (Business Manager partner/System User token). When present, refresh_fb.php
    -- and refresh_ig.php use this instead of the Bright Data/ScrapeCreators scrapers —
    -- gradual, per-dealership migration, not all-or-nothing.
    fb_page_access_token TEXT NULL,
    ig_business_account_id VARCHAR(50) NULL, -- resolved once from the linked IG Business/Creator account, reused

    -- Instagram (auto via Apify)
    ig_followers INT DEFAULT 0,
    ig_target INT DEFAULT 0,
    ig_updated_at DATETIME NULL,

    -- YouTube (auto)
    yt_channel_id VARCHAR(50) NULL,    -- resolve once, reuse (naam-search har baar consistent nahi hoti)
    yt_subscribers INT DEFAULT 0,
    yt_target INT DEFAULT 0,
    yt_videos INT DEFAULT 0,
    yt_views BIGINT DEFAULT 0,

    -- Google Reviews (auto)
    google_review_count INT DEFAULT 0,
    google_review_target INT DEFAULT 0,
    google_rating DECIMAL(2,1) DEFAULT 0,

    -- Weekly Facebook post count + avg engagement per post (auto via Apify)
    fb_posts_week INT DEFAULT 0,
    fb_engagement_avg DECIMAL(10,2) DEFAULT 0,
    fb_posts_checked_at DATETIME NULL,

    -- Weekly Instagram post count + avg engagement per post (auto via Apify)
    ig_posts_week INT DEFAULT 0,
    ig_engagement_avg DECIMAL(10,2) DEFAULT 0,
    ig_posts_checked_at DATETIME NULL,

    -- Monthly YouTube video uploads (auto via YouTube Data API)
    yt_videos_month INT DEFAULT 0,
    yt_videos_checked_at DATETIME NULL,

    -- Security deposit the dealer holds with the company — one value per
    -- dealership, set via the Stock Report CSV import (or edited manually).
    security_amount DECIMAL(12,2) NULL,

    -- Region/zone code (e.g. "ROSP") — captured from raw transactional Stock
    -- Report imports, used for the Region filter there.
    region VARCHAR(50) NULL,

    -- CRM "Digital Enquiry Targets" scorecard parameter — unlike the other
    -- CRM raw-data parameters, these targets are per-dealership and only
    -- reviewed every ~6 months (not monthly), so they're set here once via
    -- Edit Dealership instead of being re-uploaded in every month's raw
    -- Excel. Two separate targets: raw digital enquiry count, and how many
    -- of those enquiries are expected to convert.
    digital_enquiry_target INT NULL,
    digital_enquiry_conversion_target INT NULL,

    last_refreshed DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- A scoped (non-super-admin) user can be assigned to more than one dealership.
CREATE TABLE user_dealerships (
    user_id INT NOT NULL,
    dealership_id INT NOT NULL,
    PRIMARY KEY (user_id, dealership_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (dealership_id) REFERENCES dealerships(id) ON DELETE CASCADE
);

-- Which sidebar sections a scoped (non-super-admin) user can see, set per user
-- from Edit User. Keys match includes/SidebarSections.php. A super admin
-- bypasses this entirely and always sees every section.
CREATE TABLE user_sidebar_sections (
    user_id INT NOT NULL,
    section_key VARCHAR(50) NOT NULL,
    PRIMARY KEY (user_id, section_key),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE yt_monthly_stats (
    dealership_id INT NOT NULL,
    month CHAR(7) NOT NULL,           -- e.g. '2026-07'
    video_count INT NOT NULL DEFAULT 0,
    PRIMARY KEY (dealership_id, month),
    FOREIGN KEY (dealership_id) REFERENCES dealerships(id) ON DELETE CASCADE
);

-- ---------- Facebook Content Syndication (official Graph API, admin-owned pages only) ----------

CREATE TABLE target_pages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    page_id VARCHAR(50) NOT NULL,
    page_access_token TEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    dealership_id INT NULL,     -- links this target page to a dealerships.id for scoped-user access
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE processed_source_posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    source_post_id VARCHAR(50) NOT NULL UNIQUE,
    message_snippet VARCHAR(255) NULL,
    published_at DATETIME NULL,   -- the post's actual publish date — used for date-range filtering + grace period
    processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE app_settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value TEXT NULL
);

-- Tracks, per dealership per known source post, whether that dealership has
-- reshared it on their own (RapidAPI-scraped) page — independent of whether
-- Content Syndication (target_pages/Zapier) actually published it there.
CREATE TABLE reshare_checks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dealership_id INT NOT NULL,
    source_post_id VARCHAR(50) NOT NULL,
    message_snippet VARCHAR(255) NULL,
    first_seen_at DATETIME NOT NULL,     -- when our system first checked this pairing (diagnostic only)
    published_at DATETIME NULL,          -- the source post's real publish date — used for range filtering + grace period
    reshared TINYINT(1) NOT NULL DEFAULT 0,
    reshared_detected_at DATETIME NULL,
    last_checked_at DATETIME NULL,
    UNIQUE KEY dealership_post (dealership_id, source_post_id),
    FOREIGN KEY (dealership_id) REFERENCES dealerships(id) ON DELETE CASCADE
);

-- Own-vs-reshare post counts for a dealership's page, computed fresh (fully
-- paginated over the selected date range) each time "Check" runs — overwritten
-- on every check, always reflecting the most recently checked range.
CREATE TABLE reshare_own_post_stats (
    dealership_id INT PRIMARY KEY,
    range_from DATE NOT NULL,
    range_to DATE NOT NULL,
    own_post_count INT NOT NULL DEFAULT 0,
    reshare_post_count INT NOT NULL DEFAULT 0,
    checked_at DATETIME NOT NULL,
    FOREIGN KEY (dealership_id) REFERENCES dealerships(id) ON DELETE CASCADE
);

-- ---------- Brand Compliance Checker (Gemini vision) ----------

-- Reference photos per approved vehicle model + color, used as the "ground
-- truth" a dealership's submitted post photo is compared against (rims,
-- lights, and every other visible part must match one of these).
CREATE TABLE vehicle_models (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    color VARCHAR(50) NOT NULL,
    reference_image VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Multiple reference photos per vehicle model (up to 10) — different
-- angles/parts (rim, bumper, side mirror, fuel tank cap, etc.) so the
-- compliance checker has enough ground truth to catch a mismatched part.
CREATE TABLE vehicle_model_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_model_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (vehicle_model_id) REFERENCES vehicle_models(id) ON DELETE CASCADE
);

-- Single-row table: logo variants (for light/dark backgrounds) + tagline.
CREATE TABLE brand_identity (
    id INT PRIMARY KEY DEFAULT 1,
    logo_light_path VARCHAR(255) NULL,
    logo_dark_path VARCHAR(255) NULL,
    logo_white_bg_path VARCHAR(255) NULL, -- full-color (red & blue) variant, used specifically when the post background is white
    tagline VARCHAR(255) NULL,
    primary_color VARCHAR(100) NULL,
    secondary_color VARCHAR(100) NULL,
    website_url VARCHAR(255) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- A dealership-submitted post (image + caption) awaiting/having gone through
-- the Gemini compliance check. Approval only — no auto-publish.
CREATE TABLE post_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dealership_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    caption TEXT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reasons TEXT NULL,
    checked_at DATETIME NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dealership_id) REFERENCES dealerships(id) ON DELETE CASCADE
);

CREATE TABLE post_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    source_post_id VARCHAR(50) NULL,
    source_url VARCHAR(500) NULL,
    message TEXT NULL,
    dealership_name VARCHAR(150) NOT NULL,
    target_page_id VARCHAR(50) NOT NULL,
    fb_post_id VARCHAR(100) NULL,
    status ENUM('success','failed') NOT NULL,
    error_message VARCHAR(500) NULL,
    posted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ---------- Sales & Stock Reporting (CSV import) ----------

-- One row per product/model per dealership per month, from an imported
-- Sales CSV (Dealership Name, Product/Model, Quantity Sold). A dealership
-- can have many rows across many months — the Sales Report groups/filters by
-- period_month.
CREATE TABLE sales_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dealership_id INT NOT NULL,
    product_name VARCHAR(150) NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    period_month CHAR(7) NOT NULL,   -- e.g. '2026-07' — the month this batch was imported for
    column_order INT NOT NULL DEFAULT 0, -- original left-to-right column position in the imported sheet, so the Sales Report can rebuild the same wide layout
    imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dealership_id) REFERENCES dealerships(id) ON DELETE CASCADE
);

-- Per-dealership, per-month scalar values from the imported sheet that aren't
-- themselves a product column — Target and the sheet's own Grand Total
-- (kept as-is rather than re-summed, since Grand Total in these reports only
-- covers the "Model Wise Sale" group, not every extra column like PA/PB).
CREATE TABLE sales_summary (
    dealership_id INT NOT NULL,
    period_month CHAR(7) NOT NULL,
    target INT NULL,
    grand_total INT NULL,
    grand_total_column_order INT NULL, -- where "Grand Total" sat among the product columns, so the report can reinsert it in the right spot
    PRIMARY KEY (dealership_id, period_month),
    FOREIGN KEY (dealership_id) REFERENCES dealerships(id) ON DELETE CASCADE
);

-- Current stock snapshot per product/model per dealership, from an imported
-- Stock CSV (Dealership Name, Product/Model, Stock Quantity, Security Amount).
-- Security Amount is a single per-dealership value (not per-product) — when
-- present in the CSV it updates dealerships.security_amount directly instead
-- of being stored per row here.
CREATE TABLE stock_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dealership_id INT NOT NULL,
    product_name VARCHAR(150) NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    column_order INT NOT NULL DEFAULT 0, -- original left-to-right column position in the imported sheet, so the Stock Report can rebuild the same wide layout
    imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dealership_id) REFERENCES dealerships(id) ON DELETE CASCADE
);

-- One row per individual vehicle unit (by chassis), from a raw transactional
-- Undelivered Stock import — the Ageing Report computes "days aged" as
-- (last day of the current calendar month) minus delivery_date at display
-- time, so it doesn't need to be re-imported for the number to move.
CREATE TABLE ageing_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dealership_id INT NOT NULL,
    product_name VARCHAR(150) NOT NULL,
    chassis_number VARCHAR(100) NOT NULL,
    delivery_date DATE NOT NULL,
    imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dealership_id) REFERENCES dealerships(id) ON DELETE CASCADE
);

-- Current physical stock snapshot, by chassis, from a separately-imported
-- Stock Report (a full company-wide dump, not per-dealer) — the Ageing
-- Report only counts a chassis as "aged" when it's found here too, so a
-- chassis that's already been sold/delivered (and so no longer appears in
-- the latest Stock Report) automatically drops out of the ageing count even
-- though it's still sitting in ageing_records. Wiped and fully reloaded on
-- every import (not per-dealer) since the source file is always a full
-- snapshot of the entire network's current stock.
CREATE TABLE stock_chassis_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dealership_id INT NOT NULL,
    chassis_number VARCHAR(100) NOT NULL,
    imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dealership_id) REFERENCES dealerships(id) ON DELETE CASCADE,
    INDEX idx_stock_chassis_number (chassis_number)
);

-- ---------- CRM & Dealership Infrastructure Scorecard ----------

-- The scorecard template — ONE shared set of parameters/criteria/max points
-- used for every dealership (edited here, not per-dealership). display_order
-- also doubles as the expected column position when importing scores (see
-- crm_report.php), so a parameter's position in this list matters.
CREATE TABLE crm_parameters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    display_order INT NOT NULL DEFAULT 0,
    parameter_name VARCHAR(255) NOT NULL,
    criteria TEXT NULL,
    max_points DECIMAL(6,2) NOT NULL,
    -- Stable slug identifying which hand-written calculator (in
    -- CrmScoreCalculator) turns this parameter's raw data into points —
    -- keyed off this instead of the DB id/name so it survives edits/reorders.
    -- NULL until a calculator has been implemented for this parameter.
    calc_key VARCHAR(64) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Actual "Points Obtained" per dealership, per parameter, per month — either
-- imported directly (already-computed) via crm_report.php, or written by
-- CrmScoreCalculator from crm_raw_data once that parameter's logic exists.
CREATE TABLE crm_scores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dealership_id INT NOT NULL,
    crm_parameter_id INT NOT NULL,
    period_month CHAR(7) NOT NULL,
    points_obtained DECIMAL(6,2) NOT NULL,
    imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY dealership_param_period (dealership_id, crm_parameter_id, period_month),
    FOREIGN KEY (dealership_id) REFERENCES dealerships(id) ON DELETE CASCADE,
    FOREIGN KEY (crm_parameter_id) REFERENCES crm_parameters(id) ON DELETE CASCADE
);

-- Raw source numbers per dealership, per parameter, per month — uploaded via
-- the "Import Raw Data" file input on each row of crm_parameters.php. Each
-- parameter's raw Excel can have completely different columns (e.g. VoIP
-- Calling needs "total calls"/"VoIP calls", Timely Follow-Up needs "total
-- enquiries"/"on-time count"), so whatever columns come in next to "Dealer
-- Name" are kept as-is, as a JSON object of {column_header: value}.
CREATE TABLE crm_raw_data (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dealership_id INT NOT NULL,
    crm_parameter_id INT NOT NULL,
    period_month CHAR(7) NOT NULL,
    raw_json TEXT NOT NULL,
    imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY dealership_param_period (dealership_id, crm_parameter_id, period_month),
    FOREIGN KEY (dealership_id) REFERENCES dealerships(id) ON DELETE CASCADE,
    FOREIGN KEY (crm_parameter_id) REFERENCES crm_parameters(id) ON DELETE CASCADE
);
