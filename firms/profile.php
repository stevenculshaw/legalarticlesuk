<?php
// ============================================================
// firms/profile.php — Firm profile view
// Accessible to: admins, managers, and the subscriber themselves.
// ============================================================
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
requireLogin();

$sid  = (int)($_GET['id'] ?? 0);
$me   = currentUser();

// Subscribers can only view their own profile
if (isSubscriber() && $sid !== (int)$me['id']) {
    $sid = (int)$me['id'];
}
if (!$sid) {
    if (isSubscriber()) $sid = (int)$me['id'];
    else { flashSet('error', 'No firm specified.'); redirect(BASE_URL . '/admin/firms.php'); }
}

$firm = dbGet(
    'SELECT fp.*, u.email AS user_email, u.username, u.full_name AS user_full_name, u.status AS user_status
     FROM firm_profiles fp
     JOIN users u ON u.id = fp.subscriber_id
     WHERE fp.subscriber_id = ?',
    [$sid]
);

if (!$firm) {
    if (isSubscriber()) {
        flashSet('info', "Your firm profile hasn't been set up yet.");
        redirect(BASE_URL . '/subscriber/firm.php');
    }
    flashSet('error', 'Firm profile not found.');
    redirect(BASE_URL . '/admin/firms.php');
}

$pageTitle = e($firm['firm_name']) . ' — Firm Profile';

// Specialisms
$specialisms = [];
if (!empty($firm['specialisms'])) {
    $dec = json_decode($firm['specialisms'], true);
    if (is_array($dec)) $specialisms = $dec;
}

// Authors for this firm
$authors = dbAll(
    'SELECT * FROM author_profiles WHERE subscriber_id = ? ORDER BY name',
    [$sid]
);

// Published articles — paginated
$page    = max(1, (int)($_GET['p'] ?? 1));
$perPage = 12;
$search  = trim($_GET['q'] ?? '');
$where   = 'WHERE rf.subscriber_id = ? AND rc.pushed_to_ghost = 1';
$params  = [$sid];

if ($search) {
    $where   .= ' AND rc.title LIKE ?';
    $params[] = "%$search%";
}

$total  = dbGet("SELECT COUNT(*) c FROM rss_cache rc JOIN rss_feeds rf ON rf.id=rc.feed_id $where", $params)['c'] ?? 0;
$pages  = max(1, ceil($total / $perPage));
$page   = min($page, $pages);
$offset = ($page - 1) * $perPage;

$articles = dbAll(
    "SELECT rc.*, ap.name AS author_name, ap.photo_path AS author_photo
     FROM rss_cache rc
     JOIN rss_feeds rf ON rf.id = rc.feed_id
     LEFT JOIN author_profiles ap ON ap.id = rc.author_profile_id
     $where
     ORDER BY rc.pub_date DESC
     LIMIT $perPage OFFSET $offset",
    $params
);

$totalAll   = dbGet('SELECT COUNT(*) c FROM rss_cache rc JOIN rss_feeds rf ON rf.id=rc.feed_id WHERE rf.subscriber_id=?', [$sid])['c'] ?? 0;
$unpushed   = $totalAll - $total;

$uploadLogoUrl  = BASE_URL . '/uploads/firm-logos/';
$uploadPhotoUrl = BASE_URL . '/uploads/author-photos/';

include dirname(__DIR__) . '/includes/header.php';
?>
<style>
.firm-header {
  background: linear-gradient(135deg, var(--navy) 0%, #1a3f6f 100%);
  border-radius: var(--radius);
  padding: 2.5rem 2rem;
  margin-bottom: 2rem;
  color: #fff;
  position: relative;
  overflow: hidden;
}
.firm-header::before {
  content:''; position:absolute; inset:0;
  background:url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
}
.firm-header-inner { position:relative;z-index:1;display:flex;gap:2rem;align-items:flex-start; }
.firm-logo-box {
  width:110px;height:72px;background:#fff;border-radius:10px;
  display:flex;align-items:center;justify-content:center;flex-shrink:0;
  padding:.5rem;box-shadow:0 4px 12px rgba(0,0,0,.25);
}
.firm-logo-box img { max-width:100%;max-height:100%;object-fit:contain; }
.firm-logo-initials { font-size:1.8rem;font-weight:800;color:var(--navy);letter-spacing:-.02em; }
.firm-name { font-size:1.65rem;font-weight:800;line-height:1.2;margin-bottom:.3rem; }
.firm-tagline { font-size:.92rem;opacity:.8;margin-bottom:.85rem; }
.firm-meta-row { display:flex;gap:1.1rem;flex-wrap:wrap;margin-top:.6rem; }
.firm-meta-item { display:flex;align-items:center;gap:.35rem;font-size:.82rem;opacity:.85; }
.firm-meta-item a { color:#fff;text-decoration:underline;text-underline-offset:2px; }
.specialism-pill {
  display:inline-block;background:rgba(255,255,255,.15);backdrop-filter:blur(4px);
  border:1px solid rgba(255,255,255,.25);color:#fff;
  padding:.28rem .75rem;border-radius:20px;font-size:.76rem;font-weight:600;
}
.section-title {
  font-size:1.15rem;font-weight:800;color:var(--navy);
  padding-bottom:.7rem;border-bottom:2px solid var(--border);
  margin-bottom:1.5rem;display:flex;align-items:center;gap:.5rem;
}
/* Author cards */
.author-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(255px,1fr));gap:1.25rem; }
.author-card {
  background:#fff;border-radius:var(--radius);box-shadow:var(--shadow);
  padding:1.35rem;display:flex;flex-direction:column;
}
.author-avatar { width:64px;height:64px;border-radius:50%;object-fit:cover;flex-shrink:0;border:3px solid var(--border); }
.author-avatar-initials {
  width:64px;height:64px;border-radius:50%;flex-shrink:0;
  background:linear-gradient(135deg,var(--navy),#1e4d8c);
  display:flex;align-items:center;justify-content:center;
  color:#fff;font-size:1.3rem;font-weight:700;
}
.author-name { font-size:.93rem;font-weight:700;color:var(--navy);margin-top:.7rem; }
.author-position { font-size:.76rem;color:var(--gold);font-weight:600;margin:.15rem 0 .55rem; }
.author-bio { font-size:.81rem;color:var(--text);line-height:1.65;flex:1; }
.author-links { display:flex;gap:.5rem;margin-top:1rem;flex-wrap:wrap;align-items:center; }
.author-link {
  padding:.28rem .65rem;border-radius:6px;font-size:.73rem;font-weight:600;
  text-decoration:none;background:#f1f5f9;color:var(--navy);transition:background .15s;
}
.author-link:hover { background:var(--navy);color:#fff; }
/* Article cards */
.article-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:1.25rem; }
.article-card {
  background:#fff;border-radius:var(--radius);box-shadow:var(--shadow);
  overflow:hidden;display:flex;flex-direction:column;transition:box-shadow .2s;
}
.article-card:hover { box-shadow:0 8px 24px rgba(0,0,0,.12); }
.article-thumb {
  width:100%;height:150px;object-fit:cover;flex-shrink:0;
  background:linear-gradient(135deg,#e0e8f0,#c8d6e5);
  display:flex;align-items:center;justify-content:center;
  color:#94a3b8;font-size:2rem;
}
.article-body { padding:1rem;flex:1;display:flex;flex-direction:column; }
.article-title { font-size:.87rem;font-weight:700;color:var(--navy);line-height:1.4;margin-bottom:.5rem;flex:1; }
.article-title a { color:inherit;text-decoration:none; }
.article-title a:hover { color:var(--gold); }
.article-meta { font-size:.72rem;color:var(--muted);display:flex;gap:.6rem;flex-wrap:wrap;margin-top:.5rem;align-items:center; }
.author-chip {
  display:inline-flex;align-items:center;gap:.3rem;
  background:#f1f5f9;border-radius:99px;padding:.18rem .55rem;
  font-size:.7rem;font-weight:600;color:var(--navy);
}
.author-chip img { width:16px;height:16px;border-radius:50%;object-fit:cover; }
/* Pagination */
.pagination { display:flex;gap:.4rem;justify-content:center;margin-top:2rem;flex-wrap:wrap; }
.page-btn {
  padding:.38rem .8rem;border-radius:7px;font-size:.8rem;font-weight:600;
  text-decoration:none;border:2px solid var(--border);color:var(--text);transition:all .15s;
}
.page-btn:hover,.page-btn.active { background:var(--navy);border-color:var(--navy);color:#fff; }
</style>

<!-- Admin/subscriber action bar -->
<?php if (isManager() || isSubscriber()): ?>
<div style="display:flex;gap:.75rem;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;">
  <?php if (isAdmin()): ?>
  <a href="<?= BASE_URL ?>/admin/firms.php" class="btn btn-ghost btn-sm">← All Firms</a>
  <?php endif; ?>
  <?php if (isSubscriber()): ?>
  <a href="<?= BASE_URL ?>/subscriber/firm.php" class="btn btn-ghost btn-sm">✏ Edit Profile</a>
  <?php elseif (isManager()): ?>
  <a href="<?= BASE_URL ?>/admin/authors.php?subscriber=<?= $sid ?>" class="btn btn-ghost btn-sm">✍ Authors</a>
  <?php endif; ?>
  <?php if ($unpushed > 0 && isManager()): ?>
  <span style="font-size:.82rem;color:var(--warn);">
    ⚠ <?= $unpushed ?> article<?= $unpushed !== 1 ? 's' : '' ?> not yet pushed to Ghost
  </span>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Firm header -->
<div class="firm-header">
  <div class="firm-header-inner">
    <div class="firm-logo-box">
      <?php if ($firm['logo_path']): ?>
      <img src="<?= $uploadLogoUrl . e($firm['logo_path']) ?>" alt="<?= e($firm['firm_name']) ?>">
      <?php else: ?>
      <div class="firm-logo-initials"><?= e(mb_strtoupper(mb_substr($firm['firm_name'],0,2))) ?></div>
      <?php endif; ?>
    </div>

    <div style="flex:1;min-width:0;">
      <div class="firm-name"><?= e($firm['firm_name']) ?></div>
      <?php if ($firm['tagline']): ?>
      <div class="firm-tagline"><?= e($firm['tagline']) ?></div>
      <?php endif; ?>

      <?php if ($specialisms): ?>
      <div style="display:flex;flex-wrap:wrap;gap:.35rem;margin-bottom:.8rem;">
        <?php foreach ($specialisms as $sp): ?>
        <span class="specialism-pill"><?= e($sp) ?></span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div class="firm-meta-row">
        <?php if ($firm['city'] || $firm['postcode']): ?>
        <div class="firm-meta-item">📍 <?= e(implode(', ', array_filter([$firm['city'],$firm['postcode']]))) ?></div>
        <?php endif; ?>
        <?php if ($firm['website']): ?>
        <div class="firm-meta-item">🌐 <a href="<?= e($firm['website']) ?>" target="_blank" rel="noopener"><?= e(parse_url($firm['website'],PHP_URL_HOST)?:$firm['website']) ?></a></div>
        <?php endif; ?>
        <?php if ($firm['phone']): ?>
        <div class="firm-meta-item">📞 <a href="tel:<?= e(preg_replace('/\s/','',$firm['phone'])) ?>"><?= e($firm['phone']) ?></a></div>
        <?php endif; ?>
        <?php if ($firm['email']): ?>
        <div class="firm-meta-item">✉ <a href="mailto:<?= e($firm['email']) ?>"><?= e($firm['email']) ?></a></div>
        <?php endif; ?>
        <?php if ($firm['linkedin_url']): ?>
        <div class="firm-meta-item"><a href="<?= e($firm['linkedin_url']) ?>" target="_blank" rel="noopener">LinkedIn →</a></div>
        <?php endif; ?>
        <?php if ($firm['twitter_url']): ?>
        <div class="firm-meta-item"><a href="<?= e($firm['twitter_url']) ?>" target="_blank" rel="noopener">𝕏 →</a></div>
        <?php endif; ?>
      </div>

      <div style="display:flex;gap:1.25rem;margin-top:1rem;flex-wrap:wrap;">
        <span style="font-size:.82rem;opacity:.7;"><strong style="font-size:1.05rem;opacity:1;"><?= count($authors) ?></strong> author<?= count($authors)!==1?'s':'' ?></span>
        <span style="font-size:.82rem;opacity:.7;"><strong style="font-size:1.05rem;opacity:1;"><?= number_format($total) ?></strong> article<?= $total!==1?'s':'' ?></span>
      </div>
    </div>
  </div>
</div>

<!-- About -->
<?php if ($firm['description'] || array_filter([$firm['address_line1'],$firm['city']])): ?>
<div class="card">
  <div class="section-title">📋 About</div>
  <?php if ($firm['description']): ?>
  <div style="font-size:.9rem;line-height:1.85;color:var(--text);white-space:pre-line;margin-bottom:<?= array_filter([$firm['address_line1'],$firm['city']]) ? '1.25rem' : '0' ?>;">
    <?= e($firm['description']) ?>
  </div>
  <?php endif; ?>
  <?php $addrParts = array_filter([$firm['address_line1']??'',$firm['address_line2']??'',$firm['city']??'',$firm['postcode']??'']); ?>
  <?php if ($addrParts): ?>
  <div style="<?= $firm['description'] ? 'padding-top:1.25rem;border-top:1px solid var(--border);' : '' ?>font-size:.85rem;color:var(--muted);">
    <strong style="color:var(--text);">📍 Address</strong><br><?= e(implode(', ',$addrParts)) ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Authors -->
<?php if ($authors): ?>
<div style="margin-bottom:2rem;">
  <div class="section-title">✍ Our Authors</div>
  <div class="author-grid">
    <?php foreach ($authors as $a):
      $initials = implode('', array_map(fn($w)=>mb_strtoupper(mb_substr($w,0,1)), array_slice(explode(' ',$a['name']),0,2)));
      $aCount   = dbGet('SELECT COUNT(*) c FROM rss_cache WHERE author_profile_id=? AND pushed_to_ghost=1', [$a['id']])['c'] ?? 0;
    ?>
    <div class="author-card">
      <?php if ($a['photo_path']): ?>
      <img src="<?= $uploadPhotoUrl.e($a['photo_path']) ?>" class="author-avatar" alt="<?= e($a['name']) ?>">
      <?php else: ?>
      <div class="author-avatar-initials"><?= e($initials) ?></div>
      <?php endif; ?>
      <div class="author-name"><?= e($a['name']) ?></div>
      <?php if ($a['position']): ?><div class="author-position"><?= e($a['position']) ?></div><?php endif; ?>
      <?php if ($a['bio']): ?><p class="author-bio"><?= e(mb_strimwidth($a['bio'],0,180,'…')) ?></p><?php endif; ?>
      <div class="author-links">
        <?php if ($a['linkedin_url']): ?><a href="<?= e($a['linkedin_url']) ?>" class="author-link" target="_blank" rel="noopener">LinkedIn</a><?php endif; ?>
        <?php if ($a['official_profile_url']): ?><a href="<?= e($a['official_profile_url']) ?>" class="author-link" target="_blank" rel="noopener">Profile</a><?php endif; ?>
        <?php if ($aCount > 0): ?><span style="margin-left:auto;font-size:.71rem;color:var(--muted);align-self:center;"><?= $aCount ?> article<?= $aCount!==1?'s':'' ?></span><?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Articles -->
<div>
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem;">
    <div class="section-title" style="margin:0;border:none;padding:0;">
      📰 Published Articles
      <span style="font-size:.78rem;font-weight:400;color:var(--muted);"><?= number_format($total) ?> total</span>
    </div>
    <form method="GET" style="display:flex;gap:.4rem;">
      <input type="hidden" name="id" value="<?= $sid ?>">
      <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search articles…"
             class="form-control" style="margin:0;max-width:200px;padding:.38rem .7rem;font-size:.82rem;">
      <button class="btn btn-ghost btn-sm">Go</button>
      <?php if ($search): ?><a href="?id=<?= $sid ?>" class="btn btn-sm" style="background:#f3f4f6;">✕</a><?php endif; ?>
    </form>
  </div>

  <?php if (empty($articles)): ?>
  <div class="card text-center" style="padding:3rem;">
    <p class="text-muted"><?= $search ? 'No articles match "'.e($search).'".' : 'No published articles yet.' ?></p>
  </div>
  <?php else: ?>
  <div class="article-grid">
    <?php foreach ($articles as $art): ?>
    <div class="article-card">
      <?php if ($art['featured_image']): ?>
      <img src="<?= e($art['featured_image']) ?>" class="article-thumb" alt="" loading="lazy"
           onerror="this.style.display='none'">
      <?php else: ?>
      <div class="article-thumb">📰</div>
      <?php endif; ?>
      <div class="article-body">
        <div class="article-title">
          <?php if ($art['link']): ?>
          <a href="<?= e($art['link']) ?>" target="_blank" rel="noopener"><?= e($art['title'] ?? 'Untitled') ?></a>
          <?php else: ?><?= e($art['title'] ?? 'Untitled') ?><?php endif; ?>
        </div>
        <?php if (!empty($art['author_name'])): ?>
        <div style="margin-bottom:.3rem;">
          <span class="author-chip">
            <?php if ($art['author_photo']): ?><img src="<?= $uploadPhotoUrl.e($art['author_photo']) ?>" alt=""><?php endif; ?>
            <?= e($art['author_name']) ?>
          </span>
        </div>
        <?php endif; ?>
        <div class="article-meta">
          <?php if ($art['pub_date']): ?><span><?= e(date('d M Y',strtotime($art['pub_date']))) ?></span><?php endif; ?>
          <?php
          $cats = [];
          if (!empty($art['categories'])) { $d=json_decode($art['categories'],true); if(is_array($d)) $cats=array_slice($d,0,2); }
          foreach($cats as $cat): ?>
          <span style="background:#f1f5f9;padding:.1rem .4rem;border-radius:4px;font-size:.69rem;"><?= e($cat) ?></span>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if ($pages > 1): ?>
  <div class="pagination">
    <?php if ($page > 1): ?><a href="?id=<?= $sid ?>&p=<?= $page-1 ?><?= $search?'&q='.urlencode($search):'' ?>" class="page-btn">‹</a><?php endif; ?>
    <?php
    $s = max(1,$page-2); $e = min($pages,$page+2);
    if($s>1){echo '<a href="?id='.$sid.'&p=1'.($search?'&q='.urlencode($search):'').'" class="page-btn">1</a>';if($s>2)echo '<span style="padding:.4rem .3rem;color:var(--muted)">…</span>';}
    for($i=$s;$i<=$e;$i++) echo '<a href="?id='.$sid.'&p='.$i.($search?'&q='.urlencode($search):'').'" class="page-btn'.($i===$page?' active':'').'">'.$i.'</a>';
    if($e<$pages){if($e<$pages-1)echo '<span style="padding:.4rem .3rem;color:var(--muted)">…</span>';echo '<a href="?id='.$sid.'&p='.$pages.($search?'&q='.urlencode($search):'').'" class="page-btn">'.$pages.'</a>';}
    ?>
    <?php if ($page < $pages): ?><a href="?id=<?= $sid ?>&p=<?= $page+1 ?><?= $search?'&q='.urlencode($search):'' ?>" class="page-btn">›</a><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
