<?php
// ============================================================
// admin/users.php — User management (admin only)
// ============================================================
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
requireRole('admin');

$pageTitle = 'User Management';
$action    = $_POST['action'] ?? $_GET['action'] ?? '';
$uid       = (int)($_POST['uid'] ?? $_GET['uid'] ?? 0);

// ── Handle POST actions ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();

    if ($action === 'create') {
        $un   = trim($_POST['username']  ?? '');
        $em   = trim($_POST['email']     ?? '');
        $pass = $_POST['password']       ?? '';
        $role = $_POST['role']           ?? 'subscriber';
        $name = trim($_POST['full_name'] ?? '');
        $firm = trim($_POST['firm_name'] ?? '');

        if (!$un || !$em || !$pass || !$role) {
            flashSet('error', 'Please fill all required fields.');
        } elseif (!filter_var($em, FILTER_VALIDATE_EMAIL)) {
            flashSet('error', 'Invalid email address.');
        } elseif (strlen($pass) < 10) {
            flashSet('error', 'Password must be at least 10 characters.');
        } elseif (!in_array($role, ['admin','manager','subscriber'])) {
            flashSet('error', 'Invalid role.');
        } else {
            try {
                $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
                dbRun(
                    'INSERT INTO users (username,email,password_hash,role,full_name,firm_name,status,created_by)
                     VALUES (?,?,?,?,?,?,"active",?)',
                    [$un, $em, $hash, $role, $name, $firm, $_SESSION['user_id']]
                );
                logActivity('user_created', "Created user: {$un} ({$role})");
                flashSet('success', "User '{$un}' created successfully.");
            } catch (PDOException $e) {
                if (str_contains($e->getMessage(), 'Duplicate')) {
                    flashSet('error', 'Username or email already exists.');
                } else {
                    flashSet('error', 'Database error: ' . $e->getMessage());
                }
            }
        }
    }

    elseif ($action === 'edit' && $uid) {
        $em   = trim($_POST['email']     ?? '');
        $name = trim($_POST['full_name'] ?? '');
        $firm = trim($_POST['firm_name'] ?? '');
        $role = $_POST['role']           ?? '';
        $stat = $_POST['status']         ?? '';

        if (!filter_var($em, FILTER_VALIDATE_EMAIL)) {
            flashSet('error', 'Invalid email address.');
        } elseif ($uid === (int)$_SESSION['user_id'] && $stat === 'suspended') {
            flashSet('error', 'You cannot suspend your own account.');
        } else {
            $params = [$em, $name, $firm, $role, $stat, $uid];
            dbRun('UPDATE users SET email=?,full_name=?,firm_name=?,role=?,status=? WHERE id=?', $params);

            if (!empty($_POST['password'])) {
                if (strlen($_POST['password']) < 10) {
                    flashSet('warning', 'User updated but password was too short and not changed.');
                    redirect(BASE_URL . '/admin/users.php');
                }
                $hash = password_hash($_POST['password'], PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
                dbRun('UPDATE users SET password_hash=? WHERE id=?', [$hash, $uid]);
            }

            logActivity('user_edited', "Edited user ID {$uid}");
            flashSet('success', 'User updated successfully.');
        }
    }

    elseif ($action === 'delete' && $uid) {
        if ($uid === (int)$_SESSION['user_id']) {
            flashSet('error', 'You cannot delete your own account.');
        } else {
            $target = dbGet('SELECT username FROM users WHERE id=?', [$uid]);
            dbRun('DELETE FROM users WHERE id=?', [$uid]);
            logActivity('user_deleted', "Deleted user: " . ($target['username'] ?? $uid));
            flashSet('success', 'User deleted.');
        }
    }

    elseif ($action === 'suspend' && $uid) {
        if ($uid === (int)$_SESSION['user_id']) {
            flashSet('error', 'Cannot suspend own account.');
        } else {
            dbRun('UPDATE users SET status="suspended" WHERE id=?', [$uid]);
            logActivity('user_suspended', "Suspended user ID {$uid}");
            flashSet('success', 'User suspended.');
        }
    }

    elseif ($action === 'activate' && $uid) {
        dbRun('UPDATE users SET status="active" WHERE id=?', [$uid]);
        logActivity('user_activated', "Activated user ID {$uid}");
        flashSet('success', 'User activated.');
    }

    redirect(BASE_URL . '/admin/users.php');
}

// ── Fetch users ───────────────────────────────────────────────
$search  = trim($_GET['q'] ?? '');
$filter  = $_GET['role'] ?? '';
$params  = [];
$where   = 'WHERE 1=1';

if ($search) {
    $where .= ' AND (username LIKE ? OR email LIKE ? OR full_name LIKE ?)';
    $params = array_merge($params, ["%$search%","%$search%","%$search%"]);
}
if ($filter) {
    $where .= ' AND role = ?';
    $params[] = $filter;
}

$users = dbAll("SELECT * FROM users $where ORDER BY created_at DESC", $params);

// Edit target
$editUser = $uid ? dbGet('SELECT * FROM users WHERE id=?', [$uid]) : null;

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <h1>User Management</h1>
    <p>Create, edit and manage portal users</p>
  </div>
  <button class="btn btn-primary" data-modal-open="modal-create">+ Add User</button>
</div>

<!-- Search / filter bar -->
<div class="card" style="padding:1rem;">
  <form method="GET" style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;">
    <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search name, email, username…"
           class="form-control" style="max-width:320px;margin:0;">
    <select name="role" class="form-control" style="max-width:160px;margin:0;">
      <option value="">All Roles</option>
      <option value="admin"      <?= $filter==='admin'      ?'selected':'' ?>>Admin</option>
      <option value="manager"    <?= $filter==='manager'    ?'selected':'' ?>>Manager</option>
      <option value="subscriber" <?= $filter==='subscriber' ?'selected':'' ?>>Subscriber</option>
    </select>
    <button class="btn btn-ghost" type="submit">Filter</button>
    <?php if ($search || $filter): ?>
    <a href="<?= BASE_URL ?>/admin/users.php" class="btn btn-sm" style="background:#f3f4f6;color:var(--text);">Clear</a>
    <?php endif; ?>
  </form>
</div>

<div class="card">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>ID</th><th>Name</th><th>Username</th><th>Email</th>
          <th>Firm</th><th>Role</th><th>Status</th><th>Last Login</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($users as $row): ?>
        <tr>
          <td style="color:var(--muted);font-size:.78rem;"><?= $row['id'] ?></td>
          <td><?= e($row['full_name'] ?? '—') ?></td>
          <td><code><?= e($row['username']) ?></code></td>
          <td><?= e($row['email']) ?></td>
          <td><?= e($row['firm_name'] ?? '—') ?></td>
          <td><span class="role-badge role-<?= e($row['role']) ?>"><?= e(ucfirst($row['role'])) ?></span></td>
          <td><span class="badge badge-<?= e($row['status']) ?>"><?= e(ucfirst($row['status'])) ?></span></td>
          <td style="font-size:.78rem;color:var(--muted);"><?= $row['last_login'] ? e(date('d M y H:i', strtotime($row['last_login']))) : 'Never' ?></td>
          <td>
            <div class="flex gap-2">
              <button class="btn btn-sm btn-ghost"
                      data-modal-open="modal-edit"
                      onclick="fillEditForm(<?= htmlspecialchars(json_encode($row), ENT_QUOTES) ?>)">Edit</button>
              <?php if ($row['status'] === 'active' && $row['id'] != $_SESSION['user_id']): ?>
              <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="suspend">
                <input type="hidden" name="uid" value="<?= $row['id'] ?>">
                <button class="btn btn-sm btn-warning" type="submit"
                        data-confirm="Suspend this user?">Suspend</button>
              </form>
              <?php elseif ($row['status'] === 'suspended'): ?>
              <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="activate">
                <input type="hidden" name="uid" value="<?= $row['id'] ?>">
                <button class="btn btn-sm btn-success" type="submit">Activate</button>
              </form>
              <?php endif; ?>
              <?php if ($row['id'] != $_SESSION['user_id']): ?>
              <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="uid" value="<?= $row['id'] ?>">
                <button class="btn btn-sm btn-danger" type="submit"
                        data-confirm="Permanently delete this user and all their data?">Delete</button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($users)): ?>
        <tr><td colspan="9" class="text-center text-muted" style="padding:2rem;">No users found</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Create User Modal -->
<div class="modal-overlay" id="modal-create">
  <div class="modal">
    <div class="modal-header">
      <h2>Add New User</h2>
      <button class="modal-close">✕</button>
    </div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="create">
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Username *</label>
          <input type="text" name="username" class="form-control" required>
        </div>
        <div class="form-group">
          <label class="form-label">Full Name</label>
          <input type="text" name="full_name" class="form-control">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Email Address *</label>
        <input type="email" name="email" class="form-control" required>
      </div>
      <div class="form-group">
        <label class="form-label">Firm / Company Name</label>
        <input type="text" name="firm_name" class="form-control">
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Role *</label>
          <select name="role" class="form-control" required>
            <option value="subscriber">Subscriber</option>
            <option value="manager">Manager</option>
            <option value="admin">Admin</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Password * (min 10 chars)</label>
          <input type="password" name="password" class="form-control" required minlength="10">
        </div>
      </div>
      <button type="submit" class="btn btn-primary w-full">Create User</button>
    </form>
  </div>
</div>

<!-- Edit User Modal -->
<div class="modal-overlay" id="modal-edit">
  <div class="modal">
    <div class="modal-header">
      <h2>Edit User</h2>
      <button class="modal-close">✕</button>
    </div>
    <form method="POST" id="edit-form">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="uid" id="edit-uid">
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Full Name</label>
          <input type="text" name="full_name" id="edit-full_name" class="form-control">
        </div>
        <div class="form-group">
          <label class="form-label">Email *</label>
          <input type="email" name="email" id="edit-email" class="form-control" required>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Firm Name</label>
        <input type="text" name="firm_name" id="edit-firm_name" class="form-control">
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Role</label>
          <select name="role" id="edit-role" class="form-control">
            <option value="subscriber">Subscriber</option>
            <option value="manager">Manager</option>
            <option value="admin">Admin</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Status</label>
          <select name="status" id="edit-status" class="form-control">
            <option value="active">Active</option>
            <option value="suspended">Suspended</option>
            <option value="pending">Pending</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">New Password (leave blank to keep current)</label>
        <input type="password" name="password" class="form-control" minlength="10">
        <p class="form-hint">Minimum 10 characters if changing</p>
      </div>
      <button type="submit" class="btn btn-primary w-full">Save Changes</button>
    </form>
  </div>
</div>

<script>
function fillEditForm(user) {
  document.getElementById('edit-uid').value       = user.id;
  document.getElementById('edit-full_name').value = user.full_name || '';
  document.getElementById('edit-email').value     = user.email;
  document.getElementById('edit-firm_name').value = user.firm_name || '';
  document.getElementById('edit-role').value      = user.role;
  document.getElementById('edit-status').value    = user.status;
}
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
