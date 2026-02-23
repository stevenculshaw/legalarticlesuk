# UK Legal Articles Portal

A PHP/MySQL web portal for aggregating UK law firm articles via RSS and publishing them to Ghost CMS.

---

## Features

- **Three user roles**: Admin (full access), Manager (content & feeds), Subscriber (own firm only)
- **Two-factor authentication** — 6-digit one-time code emailed on every login; codes also written to `logs/2fa_codes.log` as a fallback
- **2FA log viewer** — admin page to retrieve codes when email delivery fails
- **Firm profiles** — each subscriber firm has a rich profile (logo, tagline, description, specialisms, address, social links) with a profile-completeness score
- **Author profiles** — name, position, bio, photo, LinkedIn URL, official profile URL; matched automatically from RSS `<author>` fields
- **RSS feed management** — add feeds linked to a subscriber and a default author; fetch and cache articles; optional per-feed full-text crawl
- **Content back-fill** — crawl original article URLs to retrieve full HTML content and featured images for cached articles missing them
- **Ghost CMS integration** — push cached articles via Ghost Admin API v5 (JWT auth); auto-creates/syncs Ghost authors; uploads featured images; sets canonical URLs
- **Multiple Ghost instances** — manage more than one Ghost configuration from the admin panel
- **Activity log** — full audit trail of all sensitive actions
- **System settings** — configurable via admin panel (login attempt limits, 2FA expiry, RSS cache TTL, mail settings)

---

## Requirements

- PHP 8.0+ with `simplexml`, `pdo_mysql`, `mbstring` extensions
- MySQL 5.7+ / MariaDB 10.3+
- Web server (Apache/Nginx) with `mod_rewrite`
- PHP `mail()` configured, **or** swap `send2FAEmail()` in `includes/auth.php` for PHPMailer/SMTP

---

## Installation

### 1. Database setup

Import the SQL dump to create the `legalportal` database with all tables and a default admin account:

```bash
mysql -u root -p legalportal < dberwgtie3swso.sql
```

**Default admin credentials:**
- Username: `admin`
- Password: `password` ← **Change immediately after first login!**

### 2. Configure the application

Edit `config/config.php`:

```php
define('BASE_URL', 'https://your-domain.co.uk');
define('DB_HOST',  'localhost');
define('DB_NAME',  'legalportal');
define('DB_USER',  'dbuser');
define('DB_PASS',  'dbpassword');
```

Set `'secure' => true` in the session cookie params for HTTPS.

### 3. Configure Ghost CMS

1. Log into your Ghost Admin panel
2. Go to **Settings → Integrations → Add custom integration**
3. Copy the **Admin API Key** (format: `id:hexsecret`)
4. In the portal: **Admin → Settings → Ghost CMS Configurations → Add**

Multiple Ghost instances are supported.

### 4. Web server

**Apache** — create `.htaccess` in the portal root:

```apache
Options -Indexes
RewriteEngine On
RewriteRule ^config/ - [F,L]
RewriteRule ^includes/ - [F,L]
```

**Nginx:**

```nginx
location ~* ^/(config|includes)/ {
    deny all;
}
```

Ensure `uploads/firm-logos/` and `uploads/author-photos/` are writable by the web server.

### 5. Email (2FA)

The portal uses PHP's `mail()` by default. If codes aren't arriving, check **Admin → 2FA Log** for the plaintext code. For production, replace `send2FAEmail()` in `includes/auth.php` with PHPMailer:

```php
use PHPMailer\PHPMailer\PHPMailer;
// ... configure SMTP settings
```

---

## File Structure

```
legalportal/
├── config/
│   └── config.php              ← Database, session & app config
├── includes/
│   ├── auth.php                ← Auth, CSRF, 2FA, roles, activity log
│   ├── db.php                  ← PDO singleton + helpers (dbGet/dbAll/dbRun)
│   ├── ghost_helper.php        ← Ghost JWT, image upload, author sync, post push
│   ├── header.php              ← Nav header partial
│   └── footer.php              ← Footer partial
├── admin/
│   ├── users.php               ← User CRUD (admin only)
│   ├── authors.php             ← Author profile management
│   ├── firms.php               ← Firm profiles overview (admin/manager)
│   ├── settings.php            ← System settings + Ghost config
│   └── 2fa_log.php             ← View live/recent 2FA codes (admin only)
├── feeds/
│   ├── index.php               ← Feed management (add/edit/delete)
│   ├── fetch.php               ← Fetch & cache RSS feeds
│   ├── crawl.php               ← Back-fill full content by crawling article URLs
│   └── cache.php               ← Browse cached articles
├── ghost/
│   └── push.php                ← Push cached articles to Ghost CMS
├── firms/
│   └── profile.php             ← Firm profile view (admin, manager, subscriber)
├── subscriber/
│   ├── articles.php            ← Subscriber article view
│   ├── authors.php             ← Subscriber author profiles (read-only)
│   └── firm.php                ← Subscriber firm profile editor
├── assets/
│   ├── css/portal.css
│   └── js/portal.js
├── uploads/
│   ├── firm-logos/             ← Uploaded firm logos
│   └── author-photos/          ← Uploaded author profile photos
├── logs/
│   └── 2fa_codes.log           ← Plaintext 2FA codes (fallback; blocked from web)
├── index.php                   ← Login (step 1: username + password)
├── 2fa.php                     ← 2FA verification (step 2: one-time code)
├── dashboard.php               ← Main dashboard (role-aware)
└── logout.php
```

---

## Ghost API Notes

- Uses **Ghost Admin API v5** with JWT (HS256) authentication
- JWT tokens are short-lived (5 minutes) and generated per request
- Posts are pushed with `status: published` and `source=html` (required query param for HTML content)
- `canonical_url` is set to the original article URL
- Author creation uses the `Contributor` role; authors are matched by Ghost ID → slug → email before creating
- Profile photos are uploaded to Ghost before author creation
- The `UK Legal Articles` tag is automatically applied to every post; RSS categories are appended as additional tags (up to 5)
- All string content is sanitised to valid UTF-8 before JSON encoding

---

## RSS Feed & Crawl Notes

- Feeds support both RSS 2.0 and Atom
- `<media:content>`, `<media:thumbnail>`, and enclosures are checked for featured images
- Each feed has a **default author** that is used when no `<author>` is present in an item; authors are also matched by email or name against existing author profiles
- Per-feed **full-text crawl** option: after caching, `feeds/crawl.php` visits each article URL to extract the full HTML body and a featured image (batches of 200; re-runnable)

---

## Security Notes

- All user input is parameterised (PDO prepared statements)
- CSRF tokens on every form and GET-triggered action
- Passwords hashed with bcrypt (cost 12)
- 2FA tokens stored as SHA-256 hashes; single-use and short-lived
- Session cookies are `httponly` and `samesite=Lax`
- Rate limiting on login attempts (configurable via system settings)
- Activity log tracks all sensitive actions
- `config/` and `includes/` should be blocked at web server level
- `logs/` directory gets an `.htaccess Deny from all` created automatically

---

## Typical Workflow

1. **Admin** creates subscriber accounts (one per law firm) and sets up Ghost CMS config
2. **Subscriber** logs in and fills out their firm profile (`subscriber/firm.php`)
3. **Manager** adds author profiles for each firm's lawyers
4. **Manager** adds RSS feed URLs, linking each to a subscriber and a default author
5. **Manager** clicks "Fetch Feeds" → articles cached in the database
6. *(Optional)* **Manager** runs "Back-fill Content" (`feeds/crawl.php`) to retrieve full article text
7. **Manager** reviews cached articles → "Push to Ghost CMS"
8. Articles appear on the Ghost website with correct authors, tags, and canonical URLs
9. **Subscriber** logs in to view their firm's published articles and author profiles
