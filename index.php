<?php
// ============================================================
// index.php — Login page (step 1 of 2FA)
// ============================================================
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) redirect(BASE_URL . '/dashboard.php');

$error      = '';
$maxAttempts = (int)setting('max_login_attempts', '5');
$lockout     = (int)setting('lockout_duration',   '900');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    csrfCheck();

    $identifier = trim($_POST['identifier'] ?? '');
    $password   = $_POST['password']        ?? '';

    // Lockout check
    $lockUntil = $_SESSION['lockout_until'] ?? 0;
    $attempts  = $_SESSION['login_attempts'] ?? 0;

    if ($lockUntil > time()) {
        $remaining = ceil(($lockUntil - time()) / 60);
        $error = "Too many failed attempts. Try again in {$remaining} minute(s).";
    } elseif (empty($identifier) || empty($password)) {
        $error = 'Please enter your username/email and password.';
    } else {
        $user = dbGet(
            'SELECT * FROM users WHERE (username = ? OR email = ?)',
            [$identifier, $identifier]
        );

        if ($user && $user['status'] === 'suspended') {
            $error = 'This account has been suspended. Please contact your administrator.';
        } elseif ($user && password_verify($password, $user['password_hash'])) {
            // ✅ Credentials correct — generate & send 2FA
            $_SESSION['login_attempts'] = 0;
            unset($_SESSION['lockout_until']);
            $_SESSION['2fa_user_id'] = $user['id'];

            $token  = generate2FAToken($user['id']);
            $result = send2FAEmail($user, $token);

            if (!$result['sent']) {
                // Mail failed — still proceed to 2FA page, but warn the user
                // The code is in logs/2fa_codes.log for admin retrieval
                $_SESSION['2fa_mail_failed'] = true;
                logActivity('2fa_mail_failed', "Mail error: {$result['error']}", $user['id']);
            } else {
                unset($_SESSION['2fa_mail_failed']);
                logActivity('2fa_sent', "Code sent to {$user['email']}", $user['id']);
            }

            redirect(BASE_URL . '/2fa.php');

        } else {
            // ❌ Bad credentials
            $attempts++;
            $_SESSION['login_attempts'] = $attempts;
            if ($attempts >= $maxAttempts) {
                $_SESSION['lockout_until'] = time() + $lockout;
                $error = 'Too many failed attempts. Account temporarily locked.';
            } else {
                $left  = $maxAttempts - $attempts;
                $error = 'Invalid username/email or password. ' . $left . ' attempt(s) remaining.';
            }
            if ($user) logActivity('login_failed', "Bad password for user {$user['id']}", $user['id']);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Sign In — <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/portal.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
<div class="login-wrap">
  <div class="login-box">
    <div class="login-logo">
      <span class="brand-icon">⚖</span>
      <h1><?= e(APP_NAME) ?></h1>
      <p>Secure Legal Content Management</p>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (isset($_GET['reason']) && $_GET['reason'] === 'auth'): ?>
    <div class="alert alert-info">Please sign in to continue.</div>
    <?php endif; ?>

    <form method="POST">
      <?= csrfField() ?>
      <div class="form-group">
        <label class="form-label" for="identifier">Username or Email</label>
        <input type="text" id="identifier" name="identifier" class="form-control"
               value="<?= e($_POST['identifier'] ?? '') ?>"
               required autocomplete="username" autofocus>
      </div>
      <div class="form-group">
        <label class="form-label" for="password">Password</label>
        <input type="password" id="password" name="password" class="form-control"
               required autocomplete="current-password">
      </div>
      <button type="submit" name="login" class="btn btn-primary w-full"
              style="margin-top:.5rem;padding:.75rem;">
        Continue to Verification →
      </button>
    </form>

    <p class="text-center text-muted mt-2" style="font-size:.8rem;">
      Protected by two-factor authentication 🔐
    </p>
  </div>
</div>
<script src="<?= BASE_URL ?>/assets/js/portal.js"></script>
</body>
</html>
