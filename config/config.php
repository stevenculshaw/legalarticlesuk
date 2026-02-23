<?php
// ============================================================
// config/config.php — Main application configuration
// ============================================================
define('APP_NAME', 'UK Legal Articles Portal');
define('APP_VERSION', '1.0.0');
define('BASE_URL', 'https://example.com');  // ← Change for production
define('BASE_PATH', dirname(__DIR__));

// ── Database ─────────────────────────────────────────────────
define('DB_HOST',    'localhost');
define('DB_NAME',    '');  // ← Change in production
define('DB_USER',    '');     // ← Change in production
define('DB_PASS',    '');         // ← Change in production
define('DB_CHARSET', 'utf8mb4');

// ── Security ─────────────────────────────────────────────────
define('BCRYPT_COST',       12);
define('SESSION_NAME',      'legalportal_sess');
define('SESSION_LIFETIME',  3600);
define('CSRF_TOKEN_NAME',   '_csrf_token');

// ── Email ─────────────────────────────────────────────────────
define('MAIL_FROM',      'you@yourdomain.whatever');      // ← Change in production
define('MAIL_FROM_NAME', APP_NAME);

// ── Timezone ──────────────────────────────────────────────────
date_default_timezone_set('Europe/London');

// ── Error handling ────────────────────────────────────────────
ini_set('display_errors', 0);
ini_set('log_errors',     1);
error_reporting(E_ALL);

// ── Session bootstrap ─────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => false,   // true in production (HTTPS)
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
