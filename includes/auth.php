<?php
// ============================================================
// includes/auth.php — Authentication, CSRF, roles, 2FA
// ============================================================
require_once BASE_PATH . '/includes/db.php';

// ── CSRF ──────────────────────────────────────────────────────
function csrfToken(): string {
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

function csrfCheck(): void {
    $token = $_POST[CSRF_TOKEN_NAME] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals((string)csrfToken(), (string)$token)) {
        http_response_code(403);
        die('CSRF validation failed.');
    }
}

function csrfField(): string {
    return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . e(csrfToken()) . '">';
}

// ── Auth helpers ──────────────────────────────────────────────
function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']) && !empty($_SESSION['2fa_verified']);
}

function currentUser(): ?array {
    if (!isLoggedIn()) return null;
    static $user = null;
    if ($user === null) {
        $user = dbGet('SELECT * FROM users WHERE id = ? AND status = "active"', [$_SESSION['user_id']]);
    }
    return $user;
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        // Clear any stale pre-auth session data to avoid redirect loops
        unset($_SESSION['2fa_user_id'], $_SESSION['2fa_verified'], $_SESSION['user_id']);
        session_write_close();
        header('Location: ' . BASE_URL . '/index.php?reason=auth');
        exit;
    }
}

function requireRole(string ...$roles): void {
    requireLogin();
    $u = currentUser();
    if (!$u || !in_array($u['role'], $roles, true)) {
        header('Location: ' . BASE_URL . '/dashboard.php?error=access');
        exit;
    }
}

function isAdmin(): bool   { $u = currentUser(); return $u && $u['role'] === 'admin'; }
function isManager(): bool { $u = currentUser(); return $u && in_array($u['role'], ['admin','manager'], true); }
function isSubscriber(): bool { $u = currentUser(); return $u && $u['role'] === 'subscriber'; }

// ── 2FA ───────────────────────────────────────────────────────

/**
 * Generate and store a 6-digit 2FA code.
 * Stored as SHA-256 (fast, appropriate for short-lived OTPs).
 * Returns the plaintext token so it can be emailed / logged.
 */
function generate2FAToken(int $userId): string {
    $token  = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expiry = max(60, (int)setting('tfa_expiry', '600'));
    $hash   = hash('sha256', $token);

    // Wipe any previous unused tokens for this user
    dbRun('DELETE FROM tfa_tokens WHERE user_id = ?', [$userId]);

    dbRun(
        'INSERT INTO tfa_tokens (user_id, token, expires_at, ip_address)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?)',
        [$userId, $hash, $expiry, $_SERVER['REMOTE_ADDR'] ?? '']
    );

    // Always write to log so admins can retrieve the code if email fails
    tfa_log($userId, $token);

    return $token;
}

/**
 * Verify a submitted 2FA code against the stored SHA-256 hash.
 */
function verify2FAToken(int $userId, string $submitted): bool {
    // Normalise: strip spaces, ensure 6 digits
    $submitted = preg_replace('/\D/', '', trim($submitted));
    if (strlen($submitted) !== 6) return false;

    $row = dbGet(
        'SELECT * FROM tfa_tokens
         WHERE user_id = ? AND used = 0 AND expires_at > NOW()
         ORDER BY id DESC LIMIT 1',
        [$userId]
    );

    if (!$row) return false;

    // Constant-time hash comparison
    if (!hash_equals($row['token'], hash('sha256', $submitted))) return false;

    dbRun('UPDATE tfa_tokens SET used = 1 WHERE id = ?', [$row['id']]);
    return true;
}

/**
 * Send the 2FA code by email.
 * Returns ['sent' => bool, 'error' => string]
 */
function send2FAEmail(array $user, string $token): array {
    $expMins = max(1, (int)setting('tfa_expiry', '600') / 60);
    $name    = $user['full_name'] ?: $user['username'];
    $subject = APP_NAME . ' — Your Login Code: ' . $token;
    $body    = "Hello {$name},\n\n"
             . "Your one-time login code is:\n\n"
             . "    {$token}\n\n"
             . "This code expires in {$expMins} minute" . ($expMins !== 1 ? 's' : '') . ".\n\n"
             . "If you did not attempt to log in, please contact your administrator immediately.\n\n"
             . "— " . APP_NAME;

    $headers  = "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM . ">\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "X-Mailer: PHP\r\n";

    $sent = @mail($user['email'], $subject, $body, $headers);

    if (!$sent) {
        $err = error_get_last()['message'] ?? 'mail() returned false';
        tfa_log($user['id'], $token, "EMAIL FAILED: {$err}");
        return ['sent' => false, 'error' => $err];
    }

    return ['sent' => true, 'error' => ''];
}

/**
 * Write the 2FA code to a private log file outside the web root (or in logs/).
 * This is the fallback so admins can ALWAYS retrieve a code.
 */
function tfa_log(int $userId, string $token, string $note = ''): void {
    // Try to write outside web root first, fall back to logs/ subfolder
    $logDir = BASE_PATH . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0750, true);
        // Drop an .htaccess to block web access if Apache is used
        @file_put_contents($logDir . '/.htaccess', "Deny from all\n");
    }

    $line = sprintf(
        "[%s] user_id=%d  code=%s  ip=%s  %s\n",
        date('Y-m-d H:i:s'),
        $userId,
        $token,
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        $note
    );

    @file_put_contents($logDir . '/2fa_codes.log', $line, FILE_APPEND | LOCK_EX);
}

// ── Activity log ──────────────────────────────────────────────
function logActivity(string $action, string $details = '', ?int $userId = null): void {
    $uid = $userId ?? ($_SESSION['user_id'] ?? null);
    dbRun(
        'INSERT INTO activity_log (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)',
        [$uid, $action, $details, $_SERVER['REMOTE_ADDR'] ?? '']
    );
}

// ── Utilities ─────────────────────────────────────────────────
function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}

function flashSet(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function flashGet(): ?array {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}
