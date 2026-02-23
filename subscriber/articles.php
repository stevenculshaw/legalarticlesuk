<?php
// ============================================================
// subscriber/articles.php — Subscriber article view
// ============================================================
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
requireRole('subscriber');

$pageTitle = 'My Articles';
$u   = currentUser();
$sid = $u['id'];

$page    = max(1, (int)($_GET['p'] ?? 1));
$perPage = 20;
$search  = trim($_GET['q'] ?? '');
$pushed  = $_GET['pushed'] ?? '';

$where  = 'WHERE rf.subscriber_id = ?';
$params = [$sid];

if ($search) { $where .= ' AND rc.title LIKE ?'; $params[] = "%$search%"; }
if ($pushed === '1') { $where .= ' AND rc.pushed_to_ghost=1'; }
if ($pushed === '0') { $where .= ' AND rc.pushed_to_ghost=0'; }

$total  = dbGet("SELECT COUNT(*) c FROM rss_cache rc JOIN rss_feeds rf ON rf.id=rc.feed_id $where", $params)['c'] ?? 0;
$pages  = max(1, ceil($total / $perPage));
$offset = ($page - 1) * $perPage;

$articles = dbAll(
    "SELECT rc.*, rf.title as feed_title, ap.name as author_name
     FROM rss_cache rc
     JOIN rss_feeds rf ON rf.id=rc.feed_id
     LEFT JOIN author_profiles ap ON ap.id=rf.author_profile_id
     $where ORDER BY rc.pub_date DESC LIMIT $perPage OFFSET $offset",
    $params
);

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <h1>My Articles</h1>
    <p><?= number_format($total) ?> articles from your registered feeds</p>
  </div>
</div>

<div class="card" style="padding:1rem;">
  <form method="GET" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center;">
    <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search title…"
           class="form-control" style="max-width:280px;margin:0;">
    <select name="pushed" class="form-control" style="max-width:160px;margin:0;">
      <option value="">All</option>
      <option value="0" <?= $pushed==='0'?'selected':'' ?>>Not Published</option>
      <option value="1" <?= $pushed==='1'?'selected':'' ?>>Published on Ghost</option>
    </select>
    <button class="btn btn-ghost" type="submit">Filter</button>
    <?php if ($search || $pushed !== ''): ?>
    <a href="<?= BASE_URL ?>/subscriber/articles.php" class="btn btn-sm" style="background:#f3f4f6;color:var(--text);">Clear</a>
    <?php endif; ?>
  </form>
</div>

<div class="card">
  <div class="table-wrap">
    <table>
      <thead><tr><th>Title</th><th>Feed</th><th>Author</th><th>Published</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($articles as $a): ?>
        <tr>
          <td style="max-width:380px;">
            <a href="<?= e($a['link'] ?? '#') ?>" target="_blank"
               style="color:var(--navy);font-weight:500;font-size:.88rem;">
              <?= e(mb_strimwidth($a['title'] ?? 'Untitled', 0, 80, '…')) ?>
            </a>
          </td>
          <td style="font-size:.78rem;color:var(--muted);"><?= e($a['feed_title'] ?? '—') ?></td>
          <td style="font-size:.78rem;"><?= e($a['author_name'] ?? $a['author'] ?? '—') ?></td>
          <td style="font-size:.78rem;white-space:nowrap;"><?= $a['pub_date'] ? e(date('d M Y', strtotime($a['pub_date']))) : '—' ?></td>
          <td>
            <?= $a['pushed_to_ghost']
                ? '<span class="badge badge-active">On Ghost</span>'
                : '<span class="badge badge-pending">Cached</span>' ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($articles)): ?>
        <tr><td colspan="5" class="text-center text-muted" style="padding:2rem;">No articles yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pages > 1): ?>
  <div style="display:flex;justify-content:center;gap:.5rem;margin-top:1.25rem;">
    <?php for ($i=1; $i<=$pages; $i++): ?>
    <a href="?<?= http_build_query(array_merge($_GET,['p'=>$i])) ?>"
       class="btn btn-sm <?= $i==$page?'btn-primary':'btn-ghost' ?>"><?= $i ?></a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
