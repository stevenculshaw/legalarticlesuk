<?php
// ============================================================
// subscriber/authors.php — Subscriber author profiles
// ============================================================
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
requireRole('subscriber');

$pageTitle = 'Author Profiles';
$u   = currentUser();
$sid = $u['id'];

// Subscribers can view their own author profiles (read-only)
$authors = dbAll(
    'SELECT * FROM author_profiles WHERE subscriber_id = ? ORDER BY name',
    [$sid]
);

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <h1>Author Profiles</h1>
    <p>Legal authors associated with your firm's content</p>
  </div>
</div>

<?php if (empty($authors)): ?>
<div class="card text-center" style="padding:3rem;">
  <p class="text-muted" style="font-size:1rem;">No author profiles have been set up yet.</p>
  <p class="text-muted" style="font-size:.85rem;margin-top:.5rem;">Contact your portal manager to add author profiles for your firm.</p>
</div>
<?php else: ?>

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:1.25rem;">
  <?php foreach ($authors as $a): ?>
  <div class="card" style="margin-bottom:0;">
    <div style="display:flex;align-items:flex-start;gap:1rem;margin-bottom:1rem;">
      <div style="width:52px;height:52px;background:linear-gradient(135deg,var(--navy),#1e4d8c);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.3rem;font-weight:700;flex-shrink:0;">
        <?= e(mb_strtoupper(mb_substr($a['name'],0,1))) ?>
      </div>
      <div>
        <h3 style="font-size:1rem;font-weight:700;color:var(--navy);"><?= e($a['name']) ?></h3>
        <?php if ($a['position']): ?>
        <p style="font-size:.82rem;color:var(--gold);font-weight:600;margin-top:.15rem;"><?= e($a['position']) ?></p>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($a['bio']): ?>
    <p style="font-size:.85rem;color:var(--text);line-height:1.6;margin-bottom:1rem;">
      <?= e(mb_strimwidth($a['bio'], 0, 200, '…')) ?>
    </p>
    <?php endif; ?>

    <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:auto;">
      <?php if ($a['linkedin_url']): ?>
      <a href="<?= e($a['linkedin_url']) ?>" target="_blank" rel="noopener"
         class="btn btn-sm btn-ghost" style="font-size:.78rem;">
        🔗 LinkedIn
      </a>
      <?php endif; ?>
      <?php if ($a['official_profile_url']): ?>
      <a href="<?= e($a['official_profile_url']) ?>" target="_blank" rel="noopener"
         class="btn btn-sm btn-ghost" style="font-size:.78rem;">
        🏛 Official Profile
      </a>
      <?php endif; ?>
      <?php if ($a['ghost_author_id']): ?>
      <span class="badge badge-active" style="display:flex;align-items:center;">👻 On Ghost</span>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
