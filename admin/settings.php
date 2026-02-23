<?php
// ============================================================
// admin/settings.php — System settings + Ghost config
// ============================================================
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
requireRole('admin');

$pageTitle = 'System Settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();

    if (isset($_POST['save_settings'])) {
        $keys = ['site_name','mail_from','mail_from_name','max_login_attempts',
                 'lockout_duration','tfa_expiry','rss_cache_hours'];
        foreach ($keys as $k) {
            if (isset($_POST[$k])) {
                dbRun('INSERT INTO system_settings (setting_key, setting_value) VALUES (?,?)
                       ON DUPLICATE KEY UPDATE setting_value=?',
                    [$k, $_POST[$k], $_POST[$k]]);
            }
        }
        logActivity('settings_saved', 'System settings updated');
        flashSet('success', 'Settings saved successfully.');
    }

    elseif (isset($_POST['save_ghost'])) {
        $gid  = (int)($_POST['ghost_id'] ?? 0);
        $name = trim($_POST['ghost_name'] ?? 'Default');
        $url  = rtrim(trim($_POST['ghost_url'] ?? ''), '/');
        $key  = trim($_POST['ghost_key'] ?? '');
        $def  = isset($_POST['is_default']) ? 1 : 0;

        if (!$url || !$key) {
            flashSet('error', 'Ghost URL and Admin API Key are required.');
        } else {
            if ($def) dbRun('UPDATE ghost_config SET is_default=0');
            if ($gid) {
                dbRun('UPDATE ghost_config SET name=?,ghost_url=?,admin_api_key=?,is_default=? WHERE id=?',
                      [$name, $url, $key, $def, $gid]);
            } else {
                dbRun('INSERT INTO ghost_config (name,ghost_url,admin_api_key,is_default) VALUES (?,?,?,?)',
                      [$name, $url, $key, $def]);
            }
            logActivity('ghost_config_saved', "Ghost config saved: {$url}");
            flashSet('success', 'Ghost CMS configuration saved.');
        }
    }

    elseif (isset($_POST['delete_ghost'])) {
        $gid = (int)$_POST['ghost_id'];
        dbRun('DELETE FROM ghost_config WHERE id=?', [$gid]);
        flashSet('success', 'Ghost configuration deleted.');
    }

    redirect(BASE_URL . '/admin/settings.php');
}

$settings    = dbAll('SELECT * FROM system_settings ORDER BY setting_key');
$settingsMap = array_column($settings, 'setting_value', 'setting_key');
$ghostConfs  = dbAll('SELECT * FROM ghost_config ORDER BY id');

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="page-header">
  <div><h1>System Settings</h1><p>Configure portal behaviour and integrations</p></div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;align-items:start;">

<!-- General Settings -->
<div class="card">
  <div class="card-header"><span class="card-title">⚙ General Settings</span></div>
  <form method="POST">
    <?= csrfField() ?>
    <?php
    $fields = [
      'site_name'          => ['Site Name', 'text'],
      'mail_from'          => ['System Email (From)', 'email'],
      'mail_from_name'     => ['Email Sender Name', 'text'],
      'max_login_attempts' => ['Max Login Attempts', 'number'],
      'lockout_duration'   => ['Lockout Duration (seconds)', 'number'],
      'tfa_expiry'         => ['2FA Code Expiry (seconds)', 'number'],
      'rss_cache_hours'    => ['RSS Cache Duration (hours)', 'number'],
    ];
    foreach ($fields as $key => [$label, $type]): ?>
    <div class="form-group">
      <label class="form-label"><?= e($label) ?></label>
      <input type="<?= $type ?>" name="<?= e($key) ?>" class="form-control"
             value="<?= e($settingsMap[$key] ?? '') ?>" required>
    </div>
    <?php endforeach; ?>
    <button type="submit" name="save_settings" class="btn btn-primary">Save Settings</button>
  </form>
</div>

<!-- Ghost CMS Configs -->
<div>
  <div class="card">
    <div class="card-header">
      <span class="card-title">👻 Ghost CMS Configurations</span>
      <button class="btn btn-sm btn-primary" data-modal-open="modal-ghost">+ Add</button>
    </div>
    <?php if (empty($ghostConfs)): ?>
    <p class="text-muted" style="padding:.5rem 0;">No Ghost configurations yet.</p>
    <?php else: ?>
    <?php foreach ($ghostConfs as $gc): ?>
    <div style="border:1px solid var(--border);border-radius:8px;padding:1rem;margin-bottom:.75rem;">
      <div class="flex justify-between" style="align-items:flex-start;">
        <div>
          <strong><?= e($gc['name']) ?></strong>
          <?php if ($gc['is_default']): ?><span class="badge badge-active" style="margin-left:.5rem;">Default</span><?php endif; ?>
          <p style="font-size:.8rem;color:var(--muted);margin-top:.25rem;"><?= e($gc['ghost_url']) ?></p>
          <p style="font-size:.75rem;color:var(--muted);">Key: <?= e(substr($gc['admin_api_key'],0,10)) ?>…</p>
        </div>
        <div class="flex gap-2">
          <button class="btn btn-sm btn-ghost"
                  onclick="fillGhostForm(<?= htmlspecialchars(json_encode($gc), ENT_QUOTES) ?>)"
                  data-modal-open="modal-ghost">Edit</button>
          <form method="POST" style="display:inline;">
            <?= csrfField() ?>
            <input type="hidden" name="ghost_id" value="<?= $gc['id'] ?>">
            <button name="delete_ghost" class="btn btn-sm btn-danger"
                    data-confirm="Delete this Ghost configuration?">Del</button>
          </form>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Activity log summary -->
  <div class="card">
    <div class="card-header"><span class="card-title">📋 Recent Admin Activity</span></div>
    <?php $logs = dbAll('SELECT l.*,u.username FROM activity_log l LEFT JOIN users u ON u.id=l.user_id WHERE l.user_id=? ORDER BY l.created_at DESC LIMIT 8', [$_SESSION['user_id']]); ?>
    <?php foreach ($logs as $l): ?>
    <div style="display:flex;justify-content:space-between;padding:.4rem 0;border-bottom:1px solid var(--border);font-size:.82rem;">
      <code><?= e($l['action']) ?></code>
      <span class="text-muted"><?= e(date('d M H:i', strtotime($l['created_at']))) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
</div>
</div>

<!-- Ghost Config Modal -->
<div class="modal-overlay" id="modal-ghost">
  <div class="modal">
    <div class="modal-header">
      <h2>Ghost CMS Configuration</h2>
      <button class="modal-close">✕</button>
    </div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="ghost_id" id="ghost-id" value="0">
      <div class="form-group">
        <label class="form-label">Configuration Name</label>
        <input type="text" name="ghost_name" id="ghost-name" class="form-control"
               value="Default" required>
      </div>
      <div class="form-group">
        <label class="form-label">Ghost Site URL *</label>
        <input type="url" name="ghost_url" id="ghost-url" class="form-control"
               placeholder="https://your-ghost-site.ghost.io" required>
        <p class="form-hint">The full URL of your Ghost instance (no trailing slash)</p>
      </div>
      <div class="form-group">
        <label class="form-label">Admin API Key *</label>
        <input type="text" name="ghost_key" id="ghost-key" class="form-control"
               placeholder="id:secret (from Ghost Admin → Integrations)" required>
        <p class="form-hint">Found at Ghost Admin → Settings → Integrations → Add custom integration</p>
      </div>
      <div class="form-group" style="display:flex;align-items:center;gap:.5rem;">
        <input type="checkbox" name="is_default" id="ghost-default" value="1">
        <label for="ghost-default" style="margin:0;font-weight:500;">Set as default configuration</label>
      </div>
      <button type="submit" name="save_ghost" class="btn btn-primary w-full">Save Ghost Config</button>
    </form>
  </div>
</div>

<script>
function fillGhostForm(gc) {
  document.getElementById('ghost-id').value      = gc.id;
  document.getElementById('ghost-name').value    = gc.name;
  document.getElementById('ghost-url').value     = gc.ghost_url;
  document.getElementById('ghost-key').value     = gc.admin_api_key;
  document.getElementById('ghost-default').checked = gc.is_default == 1;
}
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
