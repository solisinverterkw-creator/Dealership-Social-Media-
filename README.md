# Dealership Social Dashboard (30 Dealerships) — Setup Guide

30 dealerships ki Facebook, Instagram, YouTube, Google Reviews stats ek table
mein track karne ke liye.

## Kya automatic hai, aur kaise

| Platform | Status | Zariya |
|---|---|---|
| Facebook Followers | ✅ Automatic | Apify scraper (Graph API ko App Review chahiye hoti, isliye) |
| Instagram Followers | ✅ Automatic | Apify scraper (Meta API sirf khud ke connected accounts deti hai) |
| YouTube Subscribers | ✅ Automatic | YouTube Data API v3 (official, free) |
| Google Reviews | ✅ Automatic | Apify scraper (Places API ko billing card chahiye hoti, isliye) |

Facebook/Instagram/Google Reviews Apify (third-party scraper service) se aate
hain — Meta/Google ke Terms of Service ke hisaab se ye automated collection
hai, lekin competitor-tracking ke liye industry mein aam tareeqa hai; risk
Apify apne upar leta hai, aapke account/IP par nahi.

## Folder Structure
```
dealership-30-dashboard/
├── config.php              → API keys (Apify token, YouTube key)
├── includes/                → Database + 4 lookup classes
├── refresh_fb.php, refresh_ig.php, refresh_yt.php, refresh_gr.php → per-platform refresh
├── add_dealership.php       → nayi dealership add karne ka form
├── index.php                → main dashboard table
├── assets/style.css
└── sql/schema.sql
```

## Setup Steps

### 1. Database banayein
`sql/schema.sql` ko phpMyAdmin/MySQL mein run karein.
`config.php` mein DB_USER, DB_PASS apne XAMPP/WAMP setup ke mutabiq set karein.

### 2. API Keys
- **Apify:** apify.com → free account banayein → Settings → API & Integrations → Personal API token copy karein
- **YouTube:** console.cloud.google.com → project banayein → "YouTube Data API v3" enable karein → Credentials → API Key banayein

Dono `config.php` mein dalein (`APIFY_API_TOKEN`, `YOUTUBE_API_KEY`).

### 3. Chalayein
```
php -S localhost:8000
```
Browser mein `localhost:8000` kholein.

### 4. 30 dealerships add karein
"+ Add Dealership" button se ek ek kar ke add karein — 5 fields chahiye:
naam, FB page URL, Instagram profile URL/@username, YouTube channel naam,
Google business naam+city.

### 5. Data fetch karein
- Har row ke saamne "Refresh" button se ek dealership update hoti hai
- "Refresh All" button se sab 30 ek ke baad ek update ho jati hain
  (API rate-limit se bachne ke liye ek-ek karke chalta hai, ~1-2 minute lagega)

### 6. Weekly routine
Har hafte "Refresh All" dabayein — sab kuch automatic update ho jata hai,
koi manual entry nahi chahiye.

## Cost (roughly)
- YouTube Data API: free (10,000 units/day, ~90 searches/day free)
- Apify: pay-per-result, 30 dealerships weekly ≈ 130 lookups/month — free
  $5/month credit mein aaram se aa jata hai, warna ~$1-2/month
