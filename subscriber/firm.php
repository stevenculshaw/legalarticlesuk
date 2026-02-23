<?php
// ============================================================
// subscriber/firm.php — Firm profile management (subscriber)
// ============================================================
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
requireRole('subscriber');

$pageTitle = 'Firm Profile';
$u         = currentUser();
$sid       = $u['id'];

$uploadDir  = BASE_PATH . '/uploads/firm-logos/';
$uploadUrl  = BASE_URL  . '/uploads/firm-logos/';
$allowedMimes = ['image/jpeg','image/png','image/gif','image/webp','image/svg+xml'];
$maxBytes     = 3 * 1024 * 1024; // 3 MB

// Load existing profile (may be null for new subscribers)
$firm = dbGet('SELECT * FROM firm_profiles WHERE subscriber_id = ?', [$sid]);

// ── Handle POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();

    $firmName   = trim($_POST['firm_name']    ?? '');
    $tagline    = trim($_POST['tagline']      ?? '');
    $desc       = trim($_POST['description']  ?? '');
    $website    = trim($_POST['website']      ?? '');
    $email      = trim($_POST['email']        ?? '');
    $phone      = trim($_POST['phone']        ?? '');
    $addr1      = trim($_POST['address_line1']?? '');
    $addr2      = trim($_POST['address_line2']?? '');
    $city       = trim($_POST['city']         ?? '');
    $postcode   = trim($_POST['postcode']     ?? '');
    $linkedin   = trim($_POST['linkedin_url'] ?? '');
    $twitter    = trim($_POST['twitter_url']  ?? '');

    // Specialisms — textarea, one per line
    $specialismsRaw = trim($_POST['specialisms'] ?? '');
    $specialisms    = array_values(array_filter(
        array_map('trim', explode("\n", str_replace("\r", '', $specialismsRaw)))
    ));

    if (!$firmName) {
        flashSet('error', 'Firm name is required.');
    } else {
        // ── Logo upload ───────────────────────────────────────
        $logoPath = $firm['logo_path'] ?? null;

        if (!empty($_FILES['logo']['name'])) {
            $file = $_FILES['logo'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                flashSet('error', 'Logo upload error: ' . $file['error']);
            } else {
                $mime = mime_content_type($file['tmp_name']);
                if (!in_array($mime, $allowedMimes, true)) {
                    flashSet('error', 'Logo must be JPEG, PNG, GIF, WebP, or SVG.');
                } elseif ($file['size'] > $maxBytes) {
                    flashSet('error', 'Logo must be smaller than 3 MB.');
                } else {
                    $ext  = match(true) {
                        str_contains($mime, 'svg')  => 'svg',
                        $mime === 'image/png'        => 'png',
                        $mime === 'image/gif'        => 'gif',
                        $mime === 'image/webp'       => 'webp',
                        default                     => 'jpg',
                    };
                    $newFile = 'firm_' . $sid . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($file['tmp_name'], $uploadDir . $newFile)) {
                        // Delete old logo
                        if ($logoPath && file_exists($uploadDir . $logoPath)) {
                            @unlink($uploadDir . $logoPath);
                        }
                        $logoPath = $newFile;
                    } else {
                        flashSet('error', 'Failed to save logo. Check directory permissions.');
                    }
                }
            }
        }

        // ── Remove logo ───────────────────────────────────────
        if (!empty($_POST['remove_logo']) && $logoPath) {
            if (file_exists($uploadDir . $logoPath)) @unlink($uploadDir . $logoPath);
            $logoPath = null;
        }

        // ── Save / update ─────────────────────────────────────
        if (!isset($_SESSION['flash']['type']) || $_SESSION['flash']['type'] !== 'error') {
            $specJson = json_encode($specialisms);
            if ($firm) {
                dbRun(
                    'UPDATE firm_profiles
                     SET firm_name=?, tagline=?, description=?, logo_path=?,
                         website=?, email=?, phone=?,
                         address_line1=?, address_line2=?, city=?, postcode=?,
                         specialisms=?, linkedin_url=?, twitter_url=?
                     WHERE subscriber_id=?',
                    [$firmName, $tagline, $desc, $logoPath,
                     $website, $email, $phone,
                     $addr1, $addr2, $city, $postcode,
                     $specJson, $linkedin, $twitter,
                     $sid]
                );
            } else {
                dbRun(
                    'INSERT INTO firm_profiles
                     (subscriber_id, firm_name, tagline, description, logo_path,
                      website, email, phone, address_line1, address_line2,
                      city, postcode, specialisms, linkedin_url, twitter_url)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [$sid, $firmName, $tagline, $desc, $logoPath,
                     $website, $email, $phone,
                     $addr1, $addr2, $city, $postcode,
                     $specJson, $linkedin, $twitter]
                );
            }

            // Keep users.firm_name in sync
            dbRun('UPDATE users SET firm_name=? WHERE id=?', [$firmName, $sid]);
            logActivity('firm_profile_saved', "Subscriber {$sid} saved firm profile");
            flashSet('success', 'Firm profile saved successfully.');

            $firm = dbGet('SELECT * FROM firm_profiles WHERE subscriber_id = ?', [$sid]);
        }
    }

    redirect(BASE_URL . '/subscriber/firm.php');
}

// Parse specialisms JSON for display
$specialismsList = [];
if (!empty($firm['specialisms'])) {
    $dec = json_decode($firm['specialisms'], true);
    if (is_array($dec)) $specialismsList = $dec;
}

// Stats for sidebar
$authorCount  = dbGet('SELECT COUNT(*) c FROM author_profiles WHERE subscriber_id=?', [$sid])['c'] ?? 0;
$articleCount = dbGet('SELECT COUNT(*) c FROM rss_cache rc JOIN rss_feeds rf ON rf.id=rc.feed_id WHERE rf.subscriber_id=?', [$sid])['c'] ?? 0;
$pushedCount  = dbGet('SELECT COUNT(*) c FROM rss_cache rc JOIN rss_feeds rf ON rf.id=rc.feed_id WHERE rf.subscriber_id=? AND rc.pushed_to_ghost=1', [$sid])['c'] ?? 0;

include dirname(__DIR__) . '/includes/header.php';
?>

<style>
.profile-grid { display:grid; grid-template-columns:1fr 320px; gap:1.5rem; align-items:start; }
.logo-wrap { position:relative; display:inline-block; }
.logo-preview {
  width:200px; height:120px; border:2px dashed var(--border); border-radius:10px;
  object-fit:contain; background:#f8fafc; display:flex; align-items:center;
  justify-content:center; cursor:pointer; overflow:hidden;
}
.logo-preview img { max-width:100%; max-height:100%; object-fit:contain; }
.logo-placeholder { color:var(--muted); font-size:.85rem; text-align:center; padding:.5rem; }
.specialism-tag {
  display:inline-block; background:#e0f2fe; color:#0369a1;
  padding:.25rem .75rem; border-radius:20px; font-size:.78rem; font-weight:600;
}
.profile-preview-btn {
  display:inline-flex; align-items:center; gap:.4rem;
  padding:.4rem .9rem; border-radius:7px; border:2px solid var(--navy);
  color:var(--navy); font-size:.82rem; font-weight:600; text-decoration:none;
  transition:background .2s, color .2s;
}
.profile-preview-btn:hover { background:var(--navy); color:#fff; }
.sidebar-stat { display:flex; justify-content:space-between; align-items:center; padding:.6rem 0; border-bottom:1px solid var(--border); }
.sidebar-stat:last-child { border:none; }
</style>

<div class="page-header">
  <div>
    <h1>Firm Profile</h1>
    <p>Manage your firm's public-facing profile — visible on the portal and attached to your published articles</p>
  </div>
  <?php if ($firm): ?>
  <a href="<?= BASE_URL ?>/firms/profile.php?id=<?= $sid ?>" class="profile-preview-btn" target="_blank">
    👁 Preview Profile
  </a>
  <?php endif; ?>
</div>

<div class="profile-grid">

  <!-- ── Main form ─────────────────────────────────────────── -->
  <form method="POST" enctype="multipart/form-data">
    <?= csrfField() ?>

    <!-- Firm Identity -->
    <div class="card">
      <div class="card-header"><span class="card-title">🏛 Firm Identity</span></div>

      <div class="form-group">
        <label class="form-label">Firm Name *</label>
        <input type="text" name="firm_name" class="form-control" required
               value="<?= e($firm['firm_name'] ?? $u['firm_name'] ?? '') ?>"
               placeholder="e.g. Smith & Jones Solicitors">
      </div>

      <div class="form-group">
        <label class="form-label">Tagline</label>
        <input type="text" name="tagline" class="form-control"
               value="<?= e($firm['tagline'] ?? '') ?>"
               placeholder="e.g. Expert Employment & Commercial Law since 1994">
        <p class="form-hint">One line that appears under your firm name on the profile</p>
      </div>

      <div class="form-group">
        <label class="form-label">About the Firm</label>
        <textarea name="description" class="form-control auto-resize" rows="5"
                  placeholder="Describe your firm, your values, and what makes you different…"><?= e($firm['description'] ?? '') ?></textarea>
      </div>
    </div>

    <!-- Logo -->
    <div class="card">
      <div class="card-header"><span class="card-title">🖼 Firm Logo</span></div>

      <div style="display:flex;gap:1.5rem;align-items:flex-start;flex-wrap:wrap;">
        <div>
          <div class="logo-preview" id="logo-preview-box" onclick="document.getElementById('logo-input').click();">
            <?php if (!empty($firm['logo_path'])): ?>
            <img id="logo-img" src="<?= $uploadUrl . e($firm['logo_path']) ?>" alt="Firm logo">
            <?php else: ?>
            <div class="logo-placeholder" id="logo-placeholder">
              <div style="font-size:1.8rem;margin-bottom:.25rem;">🖼</div>
              Click to upload logo
            </div>
            <img id="logo-img" src="" alt="" style="display:none;max-width:100%;max-height:100%;">
            <?php endif; ?>
          </div>
          <div style="margin-top:.5rem;font-size:.75rem;color:var(--muted);">
            JPEG, PNG, WebP, SVG · Max 3 MB
          </div>
        </div>

        <div style="flex:1;min-width:200px;">
          <input type="file" id="logo-input" name="logo" accept="image/*" style="display:none;"
                 onchange="previewLogo(this)">
          <button type="button" class="btn btn-ghost btn-sm"
                  onclick="document.getElementById('logo-input').click();">
            📁 Choose file
          </button>

          <?php if (!empty($firm['logo_path'])): ?>
          <div style="margin-top:.75rem;">
            <label style="display:flex;align-items:center;gap:.5rem;font-size:.85rem;cursor:pointer;">
              <input type="checkbox" name="remove_logo" value="1">
              <span>Remove current logo</span>
            </label>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Specialisms -->
    <div class="card">
      <div class="card-header"><span class="card-title">⚖ Practice Areas / Specialisms</span></div>

      <div class="form-group">
        <label class="form-label">Practice Areas <span style="font-weight:400;color:var(--muted);">(one per line)</span></label>
        <textarea name="specialisms" class="form-control" rows="6"
                  placeholder="Employment Law&#10;Commercial Property&#10;Corporate & M&A&#10;Data Protection &amp; GDPR&#10;Dispute Resolution"><?= e(implode("\n", $specialismsList)) ?></textarea>
        <p class="form-hint">These appear as tags on your firm profile page</p>
      </div>

      <?php if ($specialismsList): ?>
      <div style="display:flex;flex-wrap:wrap;gap:.4rem;margin-top:.5rem;">
        <?php foreach ($specialismsList as $sp): ?>
        <span class="specialism-tag"><?= e($sp) ?></span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Contact & Address -->
    <div class="card">
      <div class="card-header"><span class="card-title">📍 Contact & Address</span></div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Website</label>
          <input type="url" name="website" class="form-control"
                 value="<?= e($firm['website'] ?? '') ?>"
                 placeholder="https://www.yourfirm.co.uk">
        </div>
        <div class="form-group">
          <label class="form-label">Contact Email</label>
          <input type="email" name="email" class="form-control"
                 value="<?= e($firm['email'] ?? '') ?>"
                 placeholder="enquiries@yourfirm.co.uk">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Phone</label>
        <input type="tel" name="phone" class="form-control"
               value="<?= e($firm['phone'] ?? '') ?>"
               placeholder="+44 (0)20 7946 0958">
      </div>

      <div class="form-group">
        <label class="form-label">Address Line 1</label>
        <input type="text" name="address_line1" class="form-control"
               value="<?= e($firm['address_line1'] ?? '') ?>"
               placeholder="123 Legal Street">
      </div>
      <div class="form-group">
        <label class="form-label">Address Line 2</label>
        <input type="text" name="address_line2" class="form-control"
               value="<?= e($firm['address_line2'] ?? '') ?>"
               placeholder="Chambers, Floor 4">
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">City / Town</label>
          <input type="text" name="city" class="form-control"
                 value="<?= e($firm['city'] ?? '') ?>"
                 placeholder="London">
        </div>
        <div class="form-group">
          <label class="form-label">Postcode</label>
          <input type="text" name="postcode" class="form-control"
                 value="<?= e($firm['postcode'] ?? '') ?>"
                 placeholder="EC2A 1NT">
        </div>
      </div>
    </div>

    <!-- Social -->
    <div class="card">
      <div class="card-header"><span class="card-title">🔗 Social & Online Presence</span></div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">LinkedIn URL</label>
          <input type="url" name="linkedin_url" class="form-control"
                 value="<?= e($firm['linkedin_url'] ?? '') ?>"
                 placeholder="https://www.linkedin.com/company/yourfirm">
        </div>
        <div class="form-group">
          <label class="form-label">Twitter / X URL</label>
          <input type="url" name="twitter_url" class="form-control"
                 value="<?= e($firm['twitter_url'] ?? '') ?>"
                 placeholder="https://twitter.com/yourfirm">
        </div>
      </div>
    </div>

    <div style="display:flex;gap:1rem;align-items:center;">
      <button type="submit" class="btn btn-primary" style="padding:.7rem 2rem;">
        💾 Save Profile
      </button>
      <?php if ($firm): ?>
      <a href="<?= BASE_URL ?>/firms/profile.php?id=<?= $sid ?>" class="btn btn-ghost" target="_blank">
        👁 Preview
      </a>
      <?php endif; ?>
    </div>

  </form>

  <!-- ── Sidebar ────────────────────────────────────────────── -->
  <div>

    <!-- Profile completeness -->
    <?php
    $fields = ['firm_name','tagline','description','logo_path','website',
               'phone','address_line1','city','specialisms','linkedin_url'];
    $filled  = 0;
    foreach ($fields as $f) {
        if (!empty($firm[$f])) $filled++;
    }
    $pct = $firm ? round(($filled / count($fields)) * 100) : 0;
    $pctColor = $pct >= 80 ? 'var(--success)' : ($pct >= 50 ? 'var(--warn)' : 'var(--danger)');
    ?>
    <div class="card">
      <div class="card-header"><span class="card-title">📊 Profile Strength</span></div>
      <div style="text-align:center;padding:.5rem 0 1rem;">
        <div style="font-size:2.5rem;font-weight:800;color:<?= $pctColor ?>;"><?= $pct ?>%</div>
        <div style="font-size:.8rem;color:var(--muted);margin-top:.2rem;">complete</div>
      </div>
      <div style="background:var(--border);border-radius:99px;height:8px;overflow:hidden;">
        <div style="background:<?= $pctColor ?>;height:100%;width:<?= $pct ?>%;border-radius:99px;transition:width .4s;"></div>
      </div>
      <?php if ($pct < 100): ?>
      <div style="margin-top:1rem;font-size:.78rem;color:var(--muted);line-height:1.8;">
        <strong style="color:var(--text);">To complete your profile, add:</strong><br>
        <?php
        $missing = [
            'tagline'      => 'Tagline',
            'description'  => 'About the firm',
            'logo_path'    => 'Firm logo',
            'website'      => 'Website',
            'phone'        => 'Phone number',
            'address_line1'=> 'Address',
            'city'         => 'City',
            'specialisms'  => 'Practice areas',
            'linkedin_url' => 'LinkedIn',
        ];
        foreach ($missing as $k => $label) {
            if (empty($firm[$k])) echo '· ' . $label . '<br>';
        }
        ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Quick stats -->
    <div class="card">
      <div class="card-header"><span class="card-title">📈 Your Stats</span></div>
      <div class="sidebar-stat">
        <span style="font-size:.85rem;">Authors</span>
        <a href="<?= BASE_URL ?>/subscriber/authors.php" style="font-weight:700;color:var(--navy);"><?= $authorCount ?></a>
      </div>
      <div class="sidebar-stat">
        <span style="font-size:.85rem;">Cached Articles</span>
        <strong style="color:var(--navy);"><?= $articleCount ?></strong>
      </div>
      <div class="sidebar-stat">
        <span style="font-size:.85rem;">Published on Ghost</span>
        <a href="<?= BASE_URL ?>/subscriber/articles.php?pushed=1" style="font-weight:700;color:var(--success);"><?= $pushedCount ?></a>
      </div>
    </div>

    <!-- Authors preview -->
    <?php
    $authors = dbAll(
        'SELECT id, name, position, photo_path FROM author_profiles WHERE subscriber_id=? ORDER BY name LIMIT 6',
        [$sid]
    );
    ?>
    <?php if ($authors): ?>
    <div class="card">
      <div class="card-header">
        <span class="card-title">✍ Authors</span>
        <a href="<?= BASE_URL ?>/subscriber/authors.php" style="font-size:.78rem;color:var(--navy);">View all →</a>
      </div>
      <div style="display:flex;flex-direction:column;gap:.6rem;">
        <?php foreach ($authors as $a): ?>
        <div style="display:flex;align-items:center;gap:.75rem;">
          <?php if ($a['photo_path']): ?>
          <img src="<?= BASE_URL ?>/uploads/author-photos/<?= e($a['photo_path']) ?>"
               style="width:36px;height:36px;border-radius:50%;object-fit:cover;flex-shrink:0;" alt="">
          <?php else:
            $initials = implode('', array_map(fn($w)=>mb_strtoupper(mb_substr($w,0,1)), array_slice(explode(' ',$a['name']),0,2)));
          ?>
          <div style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--navy),#1e4d8c);display:flex;align-items:center;justify-content:center;color:#fff;font-size:.72rem;font-weight:700;flex-shrink:0;">
            <?= e($initials) ?>
          </div>
          <?php endif; ?>
          <div style="min-width:0;">
            <div style="font-size:.83rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= e($a['name']) ?></div>
            <?php if ($a['position']): ?>
            <div style="font-size:.73rem;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= e($a['position']) ?></div>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /sidebar -->
</div>

<script>
function previewLogo(input) {
  if (!input.files || !input.files[0]) return;
  const reader = new FileReader();
  reader.onload = function(e) {
    const img       = document.getElementById('logo-img');
    const ph        = document.getElementById('logo-placeholder');
    img.src         = e.target.result;
    img.style.display = 'block';
    if (ph) ph.style.display = 'none';
  };
  reader.readAsDataURL(input.files[0]);
}
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
