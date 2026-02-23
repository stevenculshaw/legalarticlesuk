<?php
// ============================================================
// admin/firms.php — All firm profiles (manager/admin)
// ============================================================
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
requireRole('admin', 'manager');

$pageTitle = 'Firm Profiles';

$search = trim($_GET['q'] ?? '');
$params = [];
$where  = 'WHERE u.role = "subscriber" AND u.status = "active"';
if ($search) {
    $where   .= ' AND (fp.firm_name LIKE ? OR u.full_name LIKE ? OR u.firm_name LIKE ? OR fp.city LIKE ?)';
    $params   = ["%$search%", "%$search%", "%$search%", "%$search%"];
}

$firms = dbAll(
    "SELECT
        u.id AS subscriber_id,
        u.full_name, u.email AS user_email, u.firm_name AS user_firm_name,
        fp.id AS profile_id,
        fp.firm_name, fp.tagline, fp.logo_path, fp.city, fp.postcode,
        fp.website, fp.specialisms, fp.description, fp.phone, fp.address_line1, fp.linkedin_url, fp.updated_at,
        (SELECT COUNT(*) FROM author_profiles ap WHERE ap.subscriber_id = u.id) AS author_count,
        (SELECT COUNT(*) FROM rss_feeds rf WHERE rf.subscriber_id = u.id) AS feed_count,
        (SELECT COUNT(*) FROM rss_cache rc JOIN rss_feeds rf2 ON rf2.id=rc.feed_id
         WHERE rf2.subscriber_id = u.id AND rc.pushed_to_ghost=1) AS article_count
     FROM users u
     LEFT JOIN firm_profiles fp ON fp.subscriber_id = u.id
     $where
     ORDER BY COALESCE(fp.firm_name, u.firm_name, u.full_name)",
    $params
);

function profileScore(array $f): int {
    if (!$f['profile_id']) return 0;
    $fields = ['firm_name','tagline','description','logo_path','website',
               'phone','address_line1','city','specialisms','linkedin_url'];
    $filled = 0;
    foreach ($fields as $k) { if (!empty($f[$k])) $filled++; }
    return (int)round(($filled / count($fields)) * 100);
}

$totalFirms    = count($firms);
$profiledFirms = count(array_filter($firms, fn($f) => !empty($f['profile_id'])));
$noProfile     = $totalFirms - $profiledFirms;
$totalPublished = array_sum(array_column($firms, 'article_count'));

include dirname(__DIR__) . '/includes/header.php';
?>

<style>
.firm-card {
  background:var(--card); border:1px solid var(--border);
  border-radius:var(--radius); padding:1.35rem;
  display:flex; gap:1.25rem; align-items:flex-start;
  transition:box-shadow .2s;
}
.firm-card:hover { box-shadow:0 4px 20px rgba(0,0,0,.09); }
.firm-logo-thumb {
  width:64px; height:44px; border-radius:7px; object-fit:contain;
  background:#f8fafc; border:1px solid var(--border); flex-shrink:0;
}
.firm-logo-ph {
  width:64px; height:44px; border-radius:7px; flex-shrink:0;
  background:linear-gradient(135deg,#dbeafe,#e0f2fe);
  display:flex; align-items:center; justify-content:center;
  font-size:1rem; font-weight:800; color:var(--navy);
  border:1px solid #bfdbfe;
}
.score-bar  { height:5px; border-radius:99px; background:var(--border); overflow:hidden; margin-top:.3rem; }
.score-fill { height:100%; border-radius:99px; }
.pill { display:inline-block; padding:.15rem .55rem; border-radius:20px; font-size:.72rem; font-weight:600; }
.pill-blue  { background:#dbeafe; color:#1d4ed8; }
.pill-green { background:#dcfce7; color:#15803d; }
.pill-warn  { background:#fef9c3; color:#a16207; }
.pill-muted { background:#f3f4f6; color:#6b7280; }
.firms-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(340px,1fr)); gap:1.25rem; }
</style>

<div class="page-header">
  <div><h1>Firm Profiles</h1><p>All subscriber firms — completeness, authors and published articles</p></div>
</div>

<!-- Stats -->
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:1.5rem;">
  <div class="stat-card"><div class="stat-icon purple">🏛</div>
    <div><div class="stat-num"><?= $totalFirms ?></div><div class="stat-label">Total Firms</div></div></div>
  <div class="stat-card"><div class="stat-icon green">✓</div>
    <div><div class="stat-num"><?= $profiledFirms ?></div><div class="stat-label">Have Profiles</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:#fef9c3;">⚠</div>
    <div><div class="stat-num" style="<?= $noProfile ? 'color:var(--warn)' : '' ?>"><?= $noProfile ?></div>
    <div class="stat-label">No Profile Yet</div></div></div>
  <div class="stat-card"><div class="stat-icon gold">📰</div>
    <div><div class="stat-num"><?= number_format($totalPublished) ?></div><div class="stat-label">Total Published</div></div></div>
</div>

<!-- Search -->
<div class="card" style="padding:1rem;margin-bottom:1.25rem;">
  <form method="GET" style="display:flex;gap:.75rem;align-items:center;">
    <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search firm name, city…"
           class="form-control" style="max-width:380px;margin:0;">
    <button class="btn btn-ghost">Search</button>
    <?php if ($search): ?>
    <a href="?" class="btn btn-sm" style="background:#f3f4f6;color:var(--text);">Clear</a>
    <?php endif; ?>
  </form>
</div>

<?php if (empty($firms)): ?>
<div class="card" style="text-align:center;padding:2.5rem;color:var(--muted);">
  No firms found<?= $search ? ' matching "' . e($search) . '"' : '' ?>.
</div>
<?php else: ?>
<div class="firms-grid">
  <?php foreach ($firms as $f):
    $score      = profileScore($f);
    $scoreColor = $score >= 80 ? 'var(--success)' : ($score >= 50 ? 'var(--warn)' : 'var(--danger)');
    $firmName   = $f['firm_name'] ?: $f['user_firm_name'] ?: $f['full_name'];
    $initials   = implode('', array_map(
      fn($w) => mb_strtoupper(mb_substr($w, 0, 1)),
      array_slice(explode(' ', $firmName), 0, 2)
    ));
    $specs = [];
    if (!empty($f['specialisms'])) {
        $dec = json_decode($f['specialisms'], true);
        if (is_array($dec)) $specs = $dec;
    }
  ?>
  <div class="firm-card">

    <?php if (!empty($f['logo_path'])): ?>
    <img class="firm-logo-thumb" src="<?= BASE_URL ?>/uploads/firm-logos/<?= e($f['logo_path']) ?>" alt="">
    <?php else: ?>
    <div class="firm-logo-ph"><?= e($initials) ?></div>
    <?php endif; ?>

    <div style="flex:1;min-width:0;">
      <div style="font-weight:700;font-size:.95rem;color:var(--navy);"><?= e($firmName) ?></div>
      <?php if ($f['tagline']): ?>
        <div style="font-size:.78rem;color:var(--muted);margin-top:.1rem;"><?= e(mb_strimwidth($f['tagline'],0,60,'…')) ?></div>
      <?php endif; ?>
      <?php if ($f['city']): ?>
        <div style="font-size:.75rem;color:var(--muted);margin-top:.1rem;">📍 <?= e($f['city']) ?><?= $f['postcode'] ? ', '.e($f['postcode']) : '' ?></div>
      <?php endif; ?>

      <!-- Completeness bar -->
      <div style="margin-top:.6rem;">
        <div style="display:flex;justify-content:space-between;font-size:.72rem;color:var(--muted);margin-bottom:.2rem;">
          <span><?= $f['profile_id'] ? 'Profile completeness' : 'Profile not started' ?></span>
          <?php if ($f['profile_id']): ?>
          <span style="color:<?= $scoreColor ?>;font-weight:600;"><?= $score ?>%</span>
          <?php endif; ?>
        </div>
        <div class="score-bar">
          <div class="score-fill" style="width:<?= $score ?>%;background:<?= $scoreColor ?>;"></div>
        </div>
      </div>

      <!-- Stats pills -->
      <div style="display:flex;flex-wrap:wrap;gap:.3rem;margin-top:.65rem;">
        <span class="pill pill-blue">✍ <?= $f['author_count'] ?> author<?= $f['author_count']!=1?'s':'' ?></span>
        <span class="pill pill-green">📰 <?= number_format($f['article_count']) ?> published</span>
        <span class="pill pill-muted">📡 <?= $f['feed_count'] ?> feed<?= $f['feed_count']!=1?'s':'' ?></span>
        <?php if (!$f['profile_id']): ?><span class="pill pill-warn">⚠ No profile</span><?php endif; ?>
      </div>

      <!-- Specialism preview -->
      <?php if ($specs): ?>
      <div style="margin-top:.5rem;display:flex;flex-wrap:wrap;gap:.25rem;">
        <?php foreach (array_slice($specs,0,3) as $sp): ?>
        <span style="background:#e0f2fe;color:#0369a1;padding:.1rem .45rem;border-radius:20px;font-size:.68rem;font-weight:600;"><?= e($sp) ?></span>
        <?php endforeach; ?>
        <?php if (count($specs)>3): ?>
        <span style="font-size:.68rem;color:var(--muted);align-self:center;">+<?= count($specs)-3 ?> more</span>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Actions -->
      <div style="display:flex;gap:.5rem;margin-top:.85rem;flex-wrap:wrap;">
        <?php if ($f['profile_id']): ?>
        <a href="<?= BASE_URL ?>/firms/profile.php?id=<?= $f['subscriber_id'] ?>" class="btn btn-sm btn-gold">👁 View Profile</a>
        <?php else: ?>
        <span class="btn btn-sm" style="background:#f3f4f6;color:var(--muted);cursor:default;">No profile</span>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/admin/users.php" class="btn btn-sm btn-ghost">👤 User</a>
        <a href="<?= BASE_URL ?>/admin/authors.php?subscriber_id=<?= $f['subscriber_id'] ?>" class="btn btn-sm btn-ghost">✍ Authors</a>
      </div>

    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
