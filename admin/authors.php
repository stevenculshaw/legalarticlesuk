<?php
// ============================================================
// admin/authors.php — Author profile management (admin/manager)
// ============================================================
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
requireRole('admin', 'manager');

$pageTitle  = 'Author Profiles';
$uploadDir  = BASE_PATH . '/uploads/author-photos/';
$uploadUrl  = BASE_URL  . '/uploads/author-photos/';

$allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$maxBytes     = 2 * 1024 * 1024; // 2 MB

/**
 * Handle an uploaded photo. Returns saved filename or null.
 * Sets a flash error message on failure.
 */
function handlePhotoUpload(array $file, string $uploadDir): ?string {
    if ($file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) {
        flashSet('error', 'Photo upload error code: ' . $file['error']);
        return null;
    }
    global $allowedMimes, $maxBytes;
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowedMimes, true)) {
        flashSet('error', 'Photo must be a JPEG, PNG, GIF, or WebP image.');
        return null;
    }
    if ($file['size'] > $maxBytes) {
        flashSet('error', 'Photo must be smaller than 2 MB.');
        return null;
    }
    $ext      = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        default      => 'jpg',
    };
    $filename = 'author_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
        flashSet('error', 'Failed to save photo. Check directory permissions for uploads/author-photos/');
        return null;
    }
    return $filename;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action = $_POST['action'] ?? '';
    $aid    = (int)($_POST['aid'] ?? 0);

    if ($action === 'create') {
        $sid   = (int)$_POST['subscriber_id'];
        $name  = trim($_POST['name']                 ?? '');
        $email = trim($_POST['email']                ?? '');
        $pos   = trim($_POST['position']             ?? '');
        $bio   = trim($_POST['bio']                  ?? '');
        $li    = trim($_POST['linkedin_url']         ?? '');
        $prof  = trim($_POST['official_profile_url'] ?? '');

        if (!$name || !$sid) {
            flashSet('error', 'Name and subscriber firm are required.');
        } elseif ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flashSet('error', 'Please enter a valid email address.');
        } else {
            if ($email) {
                $clash = dbGet('SELECT id FROM author_profiles WHERE subscriber_id=? AND email=?', [$sid, $email]);
                if ($clash) {
                    flashSet('error', "A profile for this firm already uses the email '{$email}'.");
                    redirect(BASE_URL . '/admin/authors.php');
                }
            }
            // Handle photo upload
            $photo = null;
            if (!empty($_FILES['photo']['name'])) {
                $photo = handlePhotoUpload($_FILES['photo'], $uploadDir);
            }

            dbRun(
                'INSERT INTO author_profiles
                    (subscriber_id, name, email, photo_path, position, bio, linkedin_url, official_profile_url)
                 VALUES (?,?,?,?,?,?,?,?)',
                [$sid, $name, $email ?: null, $photo, $pos, $bio, $li, $prof]
            );
            logActivity('author_created', "Created author: {$name}" . ($email ? " <{$email}>" : ''));
            flashSet('success', "Author '{$name}' created.");
        }
    }

    elseif ($action === 'edit' && $aid) {
        $sid   = (int)$_POST['subscriber_id'];
        $name  = trim($_POST['name']                 ?? '');
        $email = trim($_POST['email']                ?? '');
        $pos   = trim($_POST['position']             ?? '');
        $bio   = trim($_POST['bio']                  ?? '');
        $li    = trim($_POST['linkedin_url']         ?? '');
        $prof  = trim($_POST['official_profile_url'] ?? '');

        if (!$name || !$sid) {
            flashSet('error', 'Name and subscriber firm are required.');
        } elseif ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flashSet('error', 'Please enter a valid email address.');
        } else {
            if ($email) {
                $clash = dbGet('SELECT id FROM author_profiles WHERE subscriber_id=? AND email=? AND id!=?', [$sid, $email, $aid]);
                if ($clash) {
                    flashSet('error', "Another profile for this firm already uses '{$email}'.");
                    redirect(BASE_URL . '/admin/authors.php');
                }
            }

            $existing = dbGet('SELECT photo_path FROM author_profiles WHERE id=?', [$aid]);
            $photo    = $existing['photo_path'] ?? null;

            // Handle new photo upload
            if (!empty($_FILES['photo']['name'])) {
                $newPhoto = handlePhotoUpload($_FILES['photo'], $uploadDir);
                if ($newPhoto) {
                    // Delete old photo file
                    if ($photo && file_exists($uploadDir . $photo)) {
                        @unlink($uploadDir . $photo);
                    }
                    $photo = $newPhoto;
                }
            }

            // Allow removing the photo
            if (!empty($_POST['remove_photo'])) {
                if ($photo && file_exists($uploadDir . $photo)) @unlink($uploadDir . $photo);
                $photo = null;
            }

            dbRun(
                'UPDATE author_profiles
                 SET subscriber_id=?, name=?, email=?, photo_path=?, position=?, bio=?,
                     linkedin_url=?, official_profile_url=?,
                     ghost_author_id=NULL, ghost_author_slug=NULL
                 WHERE id=?',
                [$sid, $name, $email ?: null, $photo, $pos, $bio, $li, $prof, $aid]
            );
            logActivity('author_edited', "Edited author ID {$aid}: {$name}");
            flashSet('success', 'Author profile updated. Ghost author will be re-synced on next push.');
        }
    }

    elseif ($action === 'delete' && $aid) {
        $existing = dbGet('SELECT photo_path FROM author_profiles WHERE id=?', [$aid]);
        if ($existing['photo_path'] ?? null) {
            @unlink($uploadDir . $existing['photo_path']);
        }
        dbRun('DELETE FROM author_profiles WHERE id=?', [$aid]);
        logActivity('author_deleted', "Deleted author ID {$aid}");
        flashSet('success', 'Author profile deleted.');
    }

    redirect(BASE_URL . '/admin/authors.php');
}

$authors     = dbAll(
    'SELECT ap.*, u.full_name, u.firm_name
     FROM author_profiles ap
     JOIN users u ON u.id = ap.subscriber_id
     ORDER BY u.firm_name, ap.name'
);
$subscribers = dbAll(
    'SELECT id, full_name, firm_name
     FROM users WHERE role="subscriber" AND status="active"
     ORDER BY firm_name, full_name'
);

$unmatchedByFirm = dbAll(
    'SELECT u.firm_name, u.full_name, COUNT(rc.id) AS cnt
     FROM rss_cache rc
     JOIN rss_feeds rf ON rf.id = rc.feed_id
     JOIN users u ON u.id = rf.subscriber_id
     WHERE rc.author_profile_id IS NULL AND rc.pushed_to_ghost = 0
     GROUP BY u.id ORDER BY cnt DESC'
);
$totalUnmatched = array_sum(array_column($unmatchedByFirm, 'cnt'));

include dirname(__DIR__) . '/includes/header.php';
?>

<style>
.author-card { position:relative; }
.author-avatar {
  width:64px; height:64px; border-radius:50%; object-fit:cover;
  border:2px solid var(--border); flex-shrink:0;
}
.author-avatar-placeholder {
  width:64px; height:64px; border-radius:50%; background:var(--navy);
  display:flex; align-items:center; justify-content:center;
  font-size:1.4rem; font-weight:700; color:#fff; flex-shrink:0;
}
.photo-preview {
  width:80px; height:80px; border-radius:50%; object-fit:cover;
  border:2px solid var(--border); display:block; margin-bottom:.5rem;
}
.photo-upload-area {
  border:2px dashed var(--border); border-radius:8px;
  padding:.75rem 1rem; text-align:center; cursor:pointer;
  transition:border-color .15s; font-size:.82rem; color:var(--muted);
}
.photo-upload-area:hover { border-color:var(--navy); }
.firm-pill { display:inline-flex; align-items:center; gap:.3rem; font-size:.74rem; background:#f1f5f9; border-radius:20px; padding:.15rem .55rem; color:var(--navy); white-space:nowrap; }
</style>

<div class="page-header">
  <div>
    <h1>Author Profiles</h1>
    <p>Manage author profiles — email and photo are used for RSS matching and Ghost author creation.</p>
  </div>
  <button class="btn btn-primary" data-modal-open="modal-create">+ Add Author</button>
</div>

<?php if ($totalUnmatched > 0): ?>
<div class="alert alert-warning">
  <strong>⚠ <?= number_format($totalUnmatched) ?> queued article<?= $totalUnmatched !== 1 ? 's' : '' ?> have no matched author.</strong>
  Add profiles below, then re-fetch or assign manually in the
  <a href="<?= BASE_URL ?>/feeds/cache.php?status=unpushed">Moderation Queue</a>.
  <ul style="margin:.5rem 0 0 1.25rem;font-size:.85rem;">
    <?php foreach ($unmatchedByFirm as $f): ?>
    <li><?= e($f['firm_name'] ?: $f['full_name']) ?> — <?= $f['cnt'] ?> article<?= $f['cnt']!==1?'s':'' ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<div class="card" style="background:#f0f4ff;border:1px solid #c7d2fe;padding:1rem 1.25rem;margin-bottom:1.25rem;">
  <strong style="color:var(--navy);">🔗 How author matching works</strong>
  <p style="font-size:.84rem;margin-top:.4rem;color:#374151;line-height:1.7;">
    When feeds are fetched, each article's author field is matched against profiles for that firm —
    first by <strong>email</strong>, then by <strong>name</strong>. When pushed to Ghost, the author
    is created/matched on Ghost using the email, profile photo is uploaded, and each article is
    attributed to the correct author.
  </p>
</div>

<!-- Profile cards grid -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(360px,1fr));gap:1.25rem;">
<?php foreach ($authors as $a):
  $photoUrl = $a['photo_path'] ? $uploadUrl . e($a['photo_path']) : null;
  $initials = implode('', array_map(fn($w) => mb_strtoupper(mb_substr($w, 0, 1)), array_slice(explode(' ', $a['name']), 0, 2)));
  $matchCount = dbGet('SELECT COUNT(*) c FROM rss_cache WHERE author_profile_id=?', [$a['id']])['c'] ?? 0;
  $unmCount   = dbGet('SELECT COUNT(*) c FROM rss_cache rc JOIN rss_feeds rf ON rf.id=rc.feed_id WHERE rf.subscriber_id=? AND rc.author_profile_id IS NULL AND rc.pushed_to_ghost=0', [$a['subscriber_id']])['c'] ?? 0;
?>
<div class="card author-card" style="margin-bottom:0;">
  <div style="display:flex;gap:.85rem;align-items:flex-start;margin-bottom:.85rem;">

    <!-- Avatar -->
    <div style="flex-shrink:0;">
      <?php if ($photoUrl): ?>
        <img src="<?= $photoUrl ?>" alt="<?= e($a['name']) ?>" class="author-avatar">
      <?php else: ?>
        <div class="author-avatar-placeholder"><?= e($initials) ?></div>
      <?php endif; ?>
    </div>

    <div style="flex:1;min-width:0;">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:.5rem;">
        <div>
          <h3 style="font-size:.95rem;font-weight:700;color:var(--navy);margin:0;"><?= e($a['name']) ?></h3>
          <?php if ($a['position']): ?>
            <p style="font-size:.78rem;color:var(--gold);font-weight:600;margin:.1rem 0 0;"><?= e($a['position']) ?></p>
          <?php endif; ?>
          <p style="font-size:.73rem;color:var(--muted);margin:.1rem 0 0;"><?= e($a['firm_name'] ?: $a['full_name']) ?></p>
        </div>
        <div style="display:flex;gap:.4rem;flex-shrink:0;">
          <?php if ($a['ghost_author_id']): ?>
            <span class="badge badge-active" title="Ghost ID: <?= e($a['ghost_author_id']) ?>">👻 Synced</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Email row -->
  <div style="background:<?= $a['email'] ? '#f0fdf4' : '#fef9c3' ?>;
              border:1px solid <?= $a['email'] ? '#86efac' : '#fcd34d' ?>;
              border-radius:6px;padding:.4rem .75rem;margin-bottom:.65rem;
              font-size:.82rem;display:flex;align-items:center;gap:.4rem;">
    <?php if ($a['email']): ?>
      <span>✉</span>
      <span style="color:#166534;font-weight:500;"><?= e($a['email']) ?></span>
    <?php else: ?>
      <span>⚠</span>
      <span style="color:#92400e;font-weight:500;">No email — matching will use name only</span>
    <?php endif; ?>
  </div>

  <?php if ($a['bio']): ?>
  <p style="font-size:.8rem;color:var(--text);line-height:1.5;margin-bottom:.75rem;">
    <?= e(mb_strimwidth($a['bio'], 0, 130, '…')) ?>
  </p>
  <?php endif; ?>

  <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-bottom:.65rem;">
    <span class="firm-pill" style="background:#eff6ff;">📰 <?= $matchCount ?> article<?= $matchCount!==1?'s':'' ?> linked</span>
    <?php if ($a['photo_path']): ?>
      <span class="firm-pill" style="background:#f0fdf4;">📷 Photo uploaded</span>
    <?php endif; ?>
    <?php if ($unmCount > 0): ?>
      <a href="<?= BASE_URL ?>/feeds/cache.php?status=unpushed&sid=<?= $a['subscriber_id'] ?>"
         class="firm-pill" style="background:#fef9c3;text-decoration:none;color:#92400e;">
        ⚠ <?= $unmCount ?> unmatched
      </a>
    <?php endif; ?>
  </div>

  <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
    <?php if ($a['linkedin_url']): ?>
      <a href="<?= e($a['linkedin_url']) ?>" target="_blank" rel="noopener"
         class="btn btn-sm btn-ghost" style="font-size:.72rem;">LinkedIn</a>
    <?php endif; ?>
    <?php if ($a['official_profile_url']): ?>
      <a href="<?= e($a['official_profile_url']) ?>" target="_blank" rel="noopener"
         class="btn btn-sm btn-ghost" style="font-size:.72rem;">Profile</a>
    <?php endif; ?>
    <div style="margin-left:auto;display:flex;gap:.4rem;">
      <button class="btn btn-sm btn-ghost"
              onclick="fillAuthorEdit(<?= htmlspecialchars(json_encode($a), ENT_QUOTES) ?>)"
              data-modal-open="modal-edit">Edit</button>
      <form method="POST" style="display:inline;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="aid" value="<?= $a['id'] ?>">
        <button class="btn btn-sm btn-danger"
                data-confirm="Delete '<?= e(addslashes($a['name'])) ?>'? This will unlink them from cached articles.">Del</button>
      </form>
    </div>
  </div>
</div>
<?php endforeach; ?>
<?php if (empty($authors)): ?>
<div class="card text-center" style="grid-column:1/-1;padding:3rem;">
  <p class="text-muted">No author profiles yet.</p>
  <button class="btn btn-primary mt-2" data-modal-open="modal-create">Add First Author</button>
</div>
<?php endif; ?>
</div>

<!-- ── Create Modal ────────────────────────────────────────── -->
<div class="modal-overlay" id="modal-create">
  <div class="modal" style="max-width:560px;">
    <div class="modal-header">
      <h2>Add Author Profile</h2>
      <button class="modal-close">✕</button>
    </div>
    <form method="POST" enctype="multipart/form-data">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="create">

      <div class="form-group">
        <label class="form-label">Subscriber (Law Firm) *</label>
        <select name="subscriber_id" class="form-control" required>
          <option value="">— Select firm —</option>
          <?php foreach ($subscribers as $s): ?>
          <option value="<?= $s['id'] ?>"><?= e($s['firm_name'] ?: $s['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Full Name *</label>
          <input type="text" name="name" class="form-control" required placeholder="e.g. Jane Smith">
        </div>
        <div class="form-group">
          <label class="form-label">Position / Role</label>
          <input type="text" name="position" class="form-control" placeholder="e.g. Senior Partner">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">
          Email Address
          <span style="font-weight:400;color:var(--muted);font-size:.78rem;">— used to match RSS articles and create Ghost author</span>
        </label>
        <input type="email" name="email" class="form-control" placeholder="jane.smith@lawfirm.co.uk">
        <p class="form-hint">Should match the email used in the RSS feed's &lt;author&gt; tag. Without this, matching uses name only.</p>
      </div>

      <!-- Photo upload -->
      <div class="form-group">
        <label class="form-label">
          Profile Photo
          <span style="font-weight:400;color:var(--muted);font-size:.78rem;">— JPEG, PNG, GIF or WebP, max 2 MB</span>
        </label>
        <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
          <div id="create-photo-preview" style="display:none;">
            <img id="create-preview-img" class="photo-preview" src="" alt="Preview">
          </div>
          <label class="photo-upload-area" style="flex:1;min-width:140px;" for="create-photo-input">
            📷 Click to select photo
            <input type="file" id="create-photo-input" name="photo" accept="image/*"
                   style="display:none;" onchange="previewPhoto(this, 'create-preview-img', 'create-photo-preview')">
          </label>
        </div>
        <p class="form-hint">This photo will be uploaded to Ghost when the author is first pushed.</p>
      </div>

      <div class="form-group">
        <label class="form-label">Biography</label>
        <textarea name="bio" class="form-control auto-resize" rows="3" placeholder="Brief professional bio…"></textarea>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">LinkedIn URL</label>
          <input type="url" name="linkedin_url" class="form-control" placeholder="https://linkedin.com/in/…">
        </div>
        <div class="form-group">
          <label class="form-label">Official Profile URL</label>
          <input type="url" name="official_profile_url" class="form-control" placeholder="https://lawfirm.co.uk/people/…">
        </div>
      </div>

      <button type="submit" class="btn btn-primary w-full">Create Author Profile</button>
    </form>
  </div>
</div>

<!-- ── Edit Modal ─────────────────────────────────────────── -->
<div class="modal-overlay" id="modal-edit">
  <div class="modal" style="max-width:560px;">
    <div class="modal-header">
      <h2>Edit Author Profile</h2>
      <button class="modal-close">✕</button>
    </div>
    <form method="POST" enctype="multipart/form-data">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="aid"    id="ea-id">

      <div class="form-group">
        <label class="form-label">Subscriber (Law Firm) *</label>
        <select name="subscriber_id" id="ea-sid" class="form-control">
          <?php foreach ($subscribers as $s): ?>
          <option value="<?= $s['id'] ?>"><?= e($s['firm_name'] ?: $s['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Full Name *</label>
          <input type="text" name="name" id="ea-name" class="form-control" required>
        </div>
        <div class="form-group">
          <label class="form-label">Position</label>
          <input type="text" name="position" id="ea-pos" class="form-control">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">
          Email Address
          <span style="font-weight:400;color:var(--muted);font-size:.78rem;">— for RSS matching &amp; Ghost</span>
        </label>
        <input type="email" name="email" id="ea-email" class="form-control">
        <p class="form-hint" id="ea-ghost-note" style="display:none;color:var(--warn);">
          ⚠ Changing name or email resets the Ghost author link — they will be re-synced on next push.
        </p>
      </div>

      <!-- Photo -->
      <div class="form-group">
        <label class="form-label">
          Profile Photo
          <span style="font-weight:400;color:var(--muted);font-size:.78rem;">— JPEG, PNG, GIF or WebP, max 2 MB</span>
        </label>
        <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:.5rem;">
          <div id="ea-current-photo-wrap">
            <img id="ea-current-photo" class="photo-preview" src="" alt="Current photo" style="display:none;">
            <div id="ea-no-photo" style="width:80px;height:80px;border-radius:50%;background:#e5e7eb;
                 display:flex;align-items:center;justify-content:center;font-size:1.5rem;color:#9ca3af;">👤</div>
          </div>
          <div style="flex:1;">
            <label class="photo-upload-area" for="ea-photo-input">
              📷 Upload new photo (replaces current)
              <input type="file" id="ea-photo-input" name="photo" accept="image/*"
                     style="display:none;" onchange="previewPhoto(this, 'ea-current-photo', null, 'ea-no-photo')">
            </label>
            <label style="display:flex;align-items:center;gap:.4rem;margin-top:.5rem;font-size:.82rem;cursor:pointer;">
              <input type="checkbox" name="remove_photo" id="ea-remove-photo" value="1">
              Remove current photo
            </label>
          </div>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Biography</label>
        <textarea name="bio" id="ea-bio" class="form-control auto-resize" rows="3"></textarea>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">LinkedIn URL</label>
          <input type="url" name="linkedin_url" id="ea-li" class="form-control">
        </div>
        <div class="form-group">
          <label class="form-label">Official Profile URL</label>
          <input type="url" name="official_profile_url" id="ea-prof" class="form-control">
        </div>
      </div>

      <button type="submit" class="btn btn-primary w-full">Save Changes</button>
    </form>
  </div>
</div>

<script>
const UPLOAD_URL = <?= json_encode($uploadUrl) ?>;

function fillAuthorEdit(a) {
  document.getElementById('ea-id').value    = a.id;
  document.getElementById('ea-sid').value   = a.subscriber_id;
  document.getElementById('ea-name').value  = a.name        || '';
  document.getElementById('ea-pos').value   = a.position    || '';
  document.getElementById('ea-email').value = a.email       || '';
  document.getElementById('ea-bio').value   = a.bio         || '';
  document.getElementById('ea-li').value    = a.linkedin_url           || '';
  document.getElementById('ea-prof').value  = a.official_profile_url   || '';

  // Current photo
  const img    = document.getElementById('ea-current-photo');
  const noPhoto = document.getElementById('ea-no-photo');
  if (a.photo_path) {
    img.src          = UPLOAD_URL + a.photo_path;
    img.style.display = 'block';
    noPhoto.style.display = 'none';
  } else {
    img.style.display = 'none';
    noPhoto.style.display = 'flex';
  }
  document.getElementById('ea-remove-photo').checked = false;
  document.getElementById('ea-photo-input').value = '';

  // Ghost re-sync warning
  const note = document.getElementById('ea-ghost-note');
  note.style.display = a.ghost_author_id ? 'block' : 'none';
  ['ea-name', 'ea-email'].forEach(id => {
    document.getElementById(id).addEventListener('input', () => {
      note.style.display = 'block';
    }, { once: true });
  });
}

function previewPhoto(input, previewId, wrapId, hiddenId) {
  const file = input.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = e => {
    const img = document.getElementById(previewId);
    img.src          = e.target.result;
    img.style.display = 'block';
    if (wrapId)  document.getElementById(wrapId).style.display  = 'block';
    if (hiddenId) document.getElementById(hiddenId).style.display = 'none';
  };
  reader.readAsDataURL(file);
}
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
