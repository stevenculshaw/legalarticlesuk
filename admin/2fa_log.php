<?php
// ============================================================
// admin/2fa_log.php — View recent 2FA codes (admin only)
// For when email isn't working. Codes expire after tfa_expiry seconds.
// ============================================================
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
requireRole('admin');

$pageTitle = '2FA Code Log';

$logFile = BASE_PATH . '/logs/2fa_codes.log';
$lines   = [];

if (file_exists($logFile)) {
    $raw   = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $lines = array_reverse(array_slice($raw, -50)); // last 50, newest first
}

// Also pull live unexpired tokens from DB with user info
$live = dbAll(
    'SELECT t.*, u.username, u.email, u.full_name
     FROM tfa_tokens t
     JOIN users u ON u.id = t.user_id
     WHERE t.used = 0 AND t.expires_at > NOW()
     ORDER BY t.id DESC'
);

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <h1>2FA Code Log</h1>
    <p>Use this to retrieve verification codes when email delivery is not working</p>
  </div>
</div>

<div class="alert alert-warning">
  <strong>Security note:</strong> This page shows live 2FA codes. Only admins can access it.
  Codes are single-use and short-lived. Once email is working correctly, codes will not need to be retrieved here.
</div>

<!-- Live unexpired tokens -->
<div class="card">
  <div class="card-header">
    <span class="card-title">🟢 Live Codes (not yet used, not yet expired)</span>
    <form method="POST" style="display:inline;">
      <?= csrfField() ?>
      <button name="clear_used" class="btn btn-sm btn-ghost"
              onclick="return confirm('Clear all expired/used tokens from database?')">
        Clear expired
      </button>
    </form>
  </div>
  <?php if (empty($live)): ?>
    <p class="text-muted">No active codes right now. User must log in first to generate one.</p>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>User</th>
          <th>Email</th>
          <th>Code (SHA-256 stored — see log below for plaintext)</th>
          <th>Expires</th>
          <th>IP</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($live as $t): ?>
        <tr>
          <td><strong><?= e($t['username']) ?></strong><br>
              <span style="font-size:.75rem;color:var(--muted);"><?= e($t['full_name'] ?? '') ?></span></td>
          <td style="font-size:.82rem;"><?= e($t['email']) ?></td>
          <td style="font-size:.72rem;font-family:monospace;color:var(--muted);">
            (hashed — see log file below for plaintext code)
          </td>
          <td style="font-size:.8rem;">
            <?= e(date('H:i:s', strtotime($t['expires_at']))) ?>
            <br><span style="font-size:.72rem;color:var(--muted);">
              <?php
                $secs = strtotime($t['expires_at']) - time();
                echo $secs > 0 ? "{$secs}s remaining" : 'EXPIRED';
              ?>
            </span>
          </td>
          <td style="font-size:.75rem;color:var(--muted);"><?= e($t['ip_address'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php
// Handle clear
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_used'])) {
    csrfCheck();
    dbRun('DELETE FROM tfa_tokens WHERE used = 1 OR expires_at <= NOW()');
    flashSet('success', 'Expired/used tokens cleared.');
    redirect(BASE_URL . '/admin/2fa_log.php');
}
?>

<!-- Log file output -->
<div class="card">
  <div class="card-header">
    <span class="card-title">📄 Log File — Plaintext Codes</span>
    <span style="font-size:.78rem;color:var(--muted);">
      <?= file_exists($logFile) ? 'Last 50 entries, newest first' : 'Log file not found' ?>
    </span>
  </div>

  <?php if (!file_exists($logFile)): ?>
    <div class="alert alert-info" style="margin:0;">
      Log file does not exist yet at <code><?= e($logFile) ?></code>.
      It will be created automatically when the next login attempt is made.
    </div>
  <?php elseif (empty($lines)): ?>
    <p class="text-muted">Log file is empty.</p>
  <?php else: ?>
    <div style="background:#0f172a;border-radius:8px;padding:1rem;overflow-x:auto;max-height:420px;overflow-y:auto;">
      <?php foreach ($lines as $line): ?>
        <?php
          $isError = str_contains($line, 'EMAIL FAILED');
          $color   = $isError ? '#f87171' : '#86efac';
        ?>
        <div style="font-family:monospace;font-size:.8rem;color:<?= $color ?>;white-space:pre;line-height:1.8;">
          <?= e($line) ?>
        </div>
      <?php endforeach; ?>
    </div>
    <div style="margin-top:.75rem;display:flex;justify-content:flex-end;">
      <form method="POST">
        <?= csrfField() ?>
        <button name="clear_log" class="btn btn-sm btn-danger"
                data-confirm="Clear the 2FA log file? This cannot be undone.">
          Clear log file
        </button>
      </form>
    </div>
  <?php endif; ?>
</div>

<?php
// Handle log clear
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_log'])) {
    csrfCheck();
    @file_put_contents($logFile, '');
    logActivity('2fa_log_cleared', 'Admin cleared the 2FA code log');
    flashSet('success', 'Log file cleared.');
    redirect(BASE_URL . '/admin/2fa_log.php');
}
?>

<div class="card" style="background:#f8fafc;">
  <div class="card-title" style="margin-bottom:.75rem;">📋 How to fix email delivery</div>
  <div style="font-size:.85rem;line-height:1.8;color:var(--text);">
    <p>If codes are appearing in the log but not arriving by email, the most common causes are:</p>
    <ol style="padding-left:1.25rem;margin-top:.5rem;">
      <li><strong>PHP mail() not configured</strong> — edit <code>php.ini</code> and set <code>SMTP</code>, <code>smtp_port</code>, and <code>sendmail_from</code>, then restart PHP.</li>
      <li><strong>No local MTA installed</strong> — install <em>sendmail</em> or <em>postfix</em> on the server: <code>apt install postfix</code>.</li>
      <li><strong>Spam filter blocking it</strong> — check the recipient's spam/junk folder. Add SPF/DKIM records to your domain's DNS.</li>
      <li><strong>Use PHPMailer instead</strong> — replace <code>send2FAEmail()</code> in <code>includes/auth.php</code> with PHPMailer + your SMTP credentials (Gmail, Mailgun, SendGrid, etc.).</li>
    </ol>
  </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
