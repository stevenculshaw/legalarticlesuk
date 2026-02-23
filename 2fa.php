<?php
// ============================================================
// 2fa.php — Two-factor verification (step 2)
// ============================================================
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn())                    redirect(BASE_URL . '/dashboard.php');
if (empty($_SESSION['2fa_user_id'])) redirect(BASE_URL . '/index.php');

$userId     = (int)$_SESSION['2fa_user_id'];
$user       = dbGet('SELECT * FROM users WHERE id = ? AND status = "active"', [$userId]);
$mailFailed = !empty($_SESSION['2fa_mail_failed']);

if (!$user) {
    unset($_SESSION['2fa_user_id']);
    redirect(BASE_URL . '/index.php');
}

// Mask email for display: jo***@example.com
$parts     = explode('@', $user['email'], 2);
$localMask = mb_substr($parts[0], 0, 2) . str_repeat('*', max(1, mb_strlen($parts[0]) - 2));
$emailMask = $localMask . '@' . ($parts[1] ?? '');

$error = '';

// ── Resend ────────────────────────────────────────────────────
if (isset($_GET['resend'])) {
    $token  = generate2FAToken($userId);
    $result = send2FAEmail($user, $token);
    if ($result['sent']) {
        unset($_SESSION['2fa_mail_failed']);
        flashSet('success', 'A new code has been sent to your email.');
    } else {
        $_SESSION['2fa_mail_failed'] = true;
        flashSet('warning', 'Could not send email. Check logs/2fa_codes.log on the server.');
    }
    redirect(BASE_URL . '/2fa.php');
}

// ── Verify ────────────────────────────────────────────────────
// Check for POST regardless of whether the submit button value is present.
// (JS form.submit() does not include button name/value in POST data.)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();

    $submitted = trim($_POST['token'] ?? '');

    if ($submitted === '') {
        $error = 'Please enter the 6-digit code.';
    } elseif (verify2FAToken($userId, $submitted)) {

        // ── Successful verification ───────────────────────────
        // Do NOT call session_regenerate_id() here — on many shared hosts
        // it destroys session data before it can be written, causing an
        // immediate redirect loop back to this page.
        // Instead, manually rotate the session safely below.

        $oldData = $_SESSION; // snapshot everything

        // Write the authenticated state into the current session
        $_SESSION['user_id']      = $userId;
        $_SESSION['2fa_verified'] = true;

        // Clean up pre-auth keys
        unset(
            $_SESSION['2fa_user_id'],
            $_SESSION['2fa_mail_failed'],
            $_SESSION['login_attempts'],
            $_SESSION['lockout_until']
        );

        // Force the session to be written to disk NOW, before the redirect
        session_write_close();

        dbRun('UPDATE users SET last_login = NOW() WHERE id = ?', [$userId]);
        logActivity('login_success', 'Login verified via 2FA', $userId);

        // Reopen session just to write the flash message, then close again
        session_start();
        flashSet('success', 'Welcome back, ' . ($user['full_name'] ?: $user['username']) . '!');
        session_write_close();

        redirect(BASE_URL . '/dashboard.php');

    } else {
        $error = 'That code is incorrect or has expired. Please try again or request a new code.';
        logActivity('2fa_failed', '2FA code rejected for user ' . $userId, $userId);
    }
}

$flash = flashGet();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Verify — <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/portal.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
  .code-input {
    font-size: 2rem;
    letter-spacing: .6rem;
    text-align: center;
    font-weight: 700;
    font-family: 'Courier New', monospace;
    padding: .65rem 1rem;
  }
  .help-box {
    background: #f8fafc;
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 1rem 1.25rem;
    margin-top: 1.25rem;
    font-size: .82rem;
    color: var(--muted);
  }
  .help-box strong { color: var(--text); }
  .help-box ol { padding-left: 1.2rem; margin-top: .4rem; line-height: 2; }
  .mail-fail-box {
    background: #fffbeb;
    border: 1.5px solid #d97706;
    border-radius: 10px;
    padding: 1rem 1.25rem;
    margin-bottom: 1rem;
    font-size: .84rem;
  }
  .mail-fail-box strong { color: #92400e; }
</style>
</head>
<body>
<div class="login-wrap">
  <div class="login-box" style="width:460px;">

    <div class="login-logo">
      <span class="brand-icon" style="font-size:2rem;">📧</span>
      <h1>Two-Factor Verification</h1>
      <p>
        <?php if ($mailFailed): ?>
          Email delivery failed — see instructions below
        <?php else: ?>
          Code sent to <strong><?= e($emailMask) ?></strong>
        <?php endif; ?>
      </p>
    </div>

    <?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" style="margin-bottom:1rem;">
      <?= e($flash['msg']) ?>
    </div>
    <?php endif; ?>

    <?php if ($mailFailed): ?>
    <div class="mail-fail-box">
      <strong>⚠ Email could not be sent</strong><br>
      Your verification code was generated but the email failed to deliver.
      <ol>
        <li>Check your <strong>spam / junk folder</strong> for a message from <?= e(MAIL_FROM) ?></li>
        <li>Ask your server administrator to check <code>logs/2fa_codes.log</code></li>
        <li>Or <a href="?resend=1" style="color:#d97706;">try resending</a> — the old code is cancelled</li>
      </ol>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <!--
      IMPORTANT: The form deliberately has no name/value on the submit button.
      We detect POST by REQUEST_METHOD only, so JS form.submit() works identically
      to clicking the button — both are caught by the same PHP check.
    -->
    <form method="POST" id="tfa-form">
      <?= csrfField() ?>
      <div class="form-group">
        <label class="form-label" for="token">6-Digit Verification Code</label>
        <input type="text"
               id="token"
               name="token"
               class="form-control code-input"
               placeholder="000000"
               maxlength="6"
               inputmode="numeric"
               pattern="[0-9]{6}"
               autocomplete="one-time-code"
               required
               autofocus
               oninput="this.value=this.value.replace(/\D/g,''); if(this.value.length===6) submitCode();">
      </div>
      <button type="submit" class="btn btn-primary w-full" style="padding:.75rem;font-size:1rem;">
        Verify &amp; Sign In
      </button>
    </form>

    <div class="help-box">
      <strong>Didn't receive a code?</strong>
      <ol>
        <li>Wait up to 2 minutes — email can be slow</li>
        <li>Check your spam / junk folder</li>
        <li><a href="?resend=1" style="color:var(--navy);">Request a new code</a> (cancels the old one)</li>
      </ol>
    </div>

    <div class="text-center mt-2">
      <a href="<?= BASE_URL ?>/index.php" style="font-size:.82rem;color:var(--muted);">← Back to login</a>
    </div>

  </div>
</div>
<script src="<?= BASE_URL ?>/assets/js/portal.js"></script>
<script>
  // Auto-submit once all 6 digits are present.
  // Uses a hidden input flag so the form always submits with a consistent payload.
  function submitCode() {
    var form  = document.getElementById('tfa-form');
    var input = document.getElementById('token');
    if (input.value.length === 6) {
      // Small delay so oninput finishes updating the value first
      setTimeout(function() { form.submit(); }, 50);
    }
  }
</script>
</body>
</html>
