<?php
// ============================================================
// feeds/crawl.php — Back-fill full content for cached articles
// ============================================================
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
// crawlArticle() and crawlCleanHtml() live in fetch.php — include it without running
require_once __DIR__ . '/fetch.php';
requireRole('admin', 'manager');

set_time_limit(600);
$pageTitle = 'Back-fill Article Content';
$feedId    = (int)($_GET['feed_id'] ?? 0);
$run       = isset($_GET['run']);
$results   = [];
$stats     = [];

if ($run) {
    csrfCheckGet(); // simple token check

    $where  = 'WHERE rc.pushed_to_ghost = 0 AND rc.link IS NOT NULL AND rc.link != ""
               AND (rc.crawled = 0 OR rc.crawled IS NULL)
               AND (rc.content IS NULL OR rc.content = "" OR rc.content = rc.description)';
    $params = [];
    if ($feedId) {
        $where  .= ' AND rc.feed_id = ?';
        $params[] = $feedId;
    }

    $articles = dbAll(
        "SELECT rc.id, rc.title, rc.link, rc.feed_id, rf.title AS feed_title
         FROM rss_cache rc
         JOIN rss_feeds rf ON rf.id = rc.feed_id
         $where
         ORDER BY rc.cached_at DESC
         LIMIT 200",
        $params
    );

    $ok = 0; $fail = 0; $noImg = 0;
    foreach ($articles as $art) {
        $crawled = crawlArticle($art['link']);
        if ($crawled['error'] || strlen(strip_tags($crawled['content'])) < 50) {
            dbRun('UPDATE rss_cache SET crawled=0, crawl_error=? WHERE id=?',
                  [$crawled['error'] ?: 'Insufficient content extracted', $art['id']]);
            $results[] = ['title' => $art['title'], 'ok' => false, 'error' => $crawled['error'] ?: 'Insufficient content', 'image' => false];
            $fail++;
        } else {
            dbRun(
                'UPDATE rss_cache
                 SET content = ?,
                     featured_image = CASE WHEN (featured_image IS NULL OR featured_image = "") AND ? != "" THEN ? ELSE featured_image END,
                     crawled = 1, crawl_error = NULL
                 WHERE id = ?',
                [$crawled['content'], $crawled['image'], $crawled['image'], $art['id']]
            );
            if (!$crawled['image']) $noImg++;
            $results[] = ['title' => $art['title'], 'ok' => true, 'error' => '', 'image' => !empty($crawled['image'])];
            $ok++;
        }
    }

    logActivity('crawl_backfill', "Back-filled {$ok} articles" . ($feedId ? " for feed {$feedId}" : '') . ", {$fail} failed");
    flashSet($fail && !$ok ? 'error' : ($fail ? 'warning' : 'success'),
        "{$ok} article" . ($ok !== 1 ? 's' : '') . " crawled successfully." .
        ($fail ? " {$fail} failed." : '') .
        ($noImg ? " {$noImg} had no image found." : ''));
}

// Stats
$baseWhere = $feedId
    ? 'AND rc.feed_id = ' . $feedId
    : '';
$needsCrawl = dbGet(
    "SELECT COUNT(*) c FROM rss_cache rc
     WHERE rc.pushed_to_ghost = 0 AND rc.link IS NOT NULL AND rc.link != ''
     AND (rc.crawled = 0 OR rc.crawled IS NULL)
     AND (rc.content IS NULL OR rc.content = '' OR rc.content = rc.description)
     $baseWhere"
)['c'] ?? 0;

$alreadyCrawled = dbGet("SELECT COUNT(*) c FROM rss_cache rc WHERE rc.crawled = 1 $baseWhere")['c'] ?? 0;
$crawlErrors    = dbGet("SELECT COUNT(*) c FROM rss_cache rc WHERE rc.crawl_error IS NOT NULL $baseWhere")['c'] ?? 0;

$feedsWithCrawl = dbAll(
    'SELECT f.id, f.title, f.url,
            COUNT(rc.id) total,
            SUM(CASE WHEN rc.crawled=1 THEN 1 ELSE 0 END) crawled_count,
            SUM(CASE WHEN (rc.content IS NULL OR rc.content="" OR rc.content=rc.description)
                         AND rc.pushed_to_ghost=0 AND (rc.crawled=0 OR rc.crawled IS NULL) THEN 1 ELSE 0 END) needs_crawl
     FROM rss_feeds f
     LEFT JOIN rss_cache rc ON rc.feed_id=f.id
     WHERE f.crawl_full_content = 1
     GROUP BY f.id ORDER BY needs_crawl DESC'
);

// CSRF token for the run link
$token = csrfToken();

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <h1>Back-fill Article Content</h1>
    <p>Crawl article URLs for cached articles that are missing full content or images.</p>
  </div>
</div>

<?php if ($results): ?>
<div class="card" style="margin-bottom:1.25rem;">
  <div class="card-header"><span class="card-title">Crawl Results</span>
    <span style="font-size:.82rem;color:var(--muted);"><?= $ok ?? 0 ?> ok · <?= $fail ?? 0 ?> failed</span>
  </div>
  <div style="max-height:360px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius);">
    <?php foreach ($results as $r): ?>
    <div style="display:flex;align-items:center;gap:.75rem;padding:.55rem 1rem;
                border-bottom:1px solid var(--border);font-size:.82rem;
                background:<?= $r['ok'] ? '#f0fdf4' : '#fef2f2' ?>;">
      <span><?= $r['ok'] ? '✅' : '❌' ?></span>
      <span style="flex:1;"><?= e(mb_strimwidth($r['title'] ?? 'Untitled', 0, 70, '…')) ?></span>
      <?php if ($r['ok'] && !$r['image']): ?>
        <span style="font-size:.72rem;color:var(--muted);">no image found</span>
      <?php elseif ($r['ok']): ?>
        <span style="font-size:.72rem;color:var(--success);">📷 image</span>
      <?php else: ?>
        <span style="font-size:.72rem;color:var(--danger);"><?= e($r['error']) ?></span>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="stats-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:1.25rem;">
  <div class="stat-card">
    <div class="stat-icon" style="background:#fef3c7;">📋</div>
    <div><div class="stat-num" style="color:var(--warn);"><?= number_format($needsCrawl) ?></div>
    <div class="stat-label">Needs Crawling</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green">🕷</div>
    <div><div class="stat-num"><?= number_format($alreadyCrawled) ?></div>
    <div class="stat-label">Already Crawled</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="background:#fef2f2;">❌</div>
    <div><div class="stat-num" style="<?= $crawlErrors ? 'color:var(--danger)' : '' ?>"><?= number_format($crawlErrors) ?></div>
    <div class="stat-label">Crawl Errors</div></div>
  </div>
</div>

<?php if ($needsCrawl > 0): ?>
<div class="card" style="margin-bottom:1.25rem;">
  <div class="card-header"><span class="card-title">Run Back-fill</span></div>
  <div style="padding:1rem;">
    <p style="font-size:.85rem;color:var(--text);margin-bottom:1rem;">
      <?= number_format($needsCrawl) ?> unpushed article<?= $needsCrawl !== 1 ? 's need' : ' needs' ?> crawling.
      <?php if ($needsCrawl > 200): ?>
        <strong>The crawl runs in batches of 200</strong> — run it multiple times until complete.
      <?php endif; ?>
      Each article takes 1–3 seconds to crawl.
    </p>
    <div style="display:flex;gap:.75rem;flex-wrap:wrap;">
      <?php if ($feedId): ?>
        <a href="?feed_id=<?= $feedId ?>&run=1&<?= CSRF_TOKEN_NAME ?>=<?= urlencode($token) ?>"
           class="btn btn-primary"
           onclick="return confirm('Crawl up to 200 articles from this feed now?')">
          🕷 Crawl This Feed (up to 200)
        </a>
        <a href="?run=1&<?= CSRF_TOKEN_NAME ?>=<?= urlencode($token) ?>"
           class="btn btn-ghost">Crawl All Feeds</a>
      <?php else: ?>
        <a href="?run=1&<?= CSRF_TOKEN_NAME ?>=<?= urlencode($token) ?>"
           class="btn btn-primary"
           onclick="return confirm('Crawl up to 200 articles across all feeds now? This may take several minutes.')">
          🕷 Crawl All (up to 200)
        </a>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php else: ?>
<div class="alert alert-success" style="margin-bottom:1.25rem;">
  ✅ All articles with crawling enabled have been crawled.
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header"><span class="card-title">Feeds with Crawling Enabled</span>
    <a href="<?= BASE_URL ?>/feeds/index.php" class="btn btn-sm btn-ghost">Manage Feeds</a>
  </div>
  <?php if (empty($feedsWithCrawl)): ?>
  <div style="padding:1.5rem;font-size:.85rem;color:var(--muted);text-align:center;">
    No feeds have crawling enabled.
    <a href="<?= BASE_URL ?>/feeds/index.php">Edit a feed</a> to turn it on.
  </div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Feed</th><th>Total Articles</th><th>Crawled</th><th>Needs Crawl</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($feedsWithCrawl as $f): ?>
      <tr>
        <td>
          <div style="font-weight:600;font-size:.85rem;"><?= e($f['title'] ?: 'Untitled') ?></div>
          <div style="font-size:.73rem;color:var(--muted);"><?= e(mb_strimwidth($f['url'], 0, 55, '…')) ?></div>
        </td>
        <td style="text-align:center;"><?= (int)$f['total'] ?></td>
        <td style="text-align:center;color:#0369a1;"><?= (int)$f['crawled_count'] ?></td>
        <td style="text-align:center;">
          <?php if ($f['needs_crawl'] > 0): ?>
            <span style="color:var(--warn);font-weight:600;"><?= (int)$f['needs_crawl'] ?></span>
          <?php else: ?>
            <span style="color:var(--success);">✓ Done</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($f['needs_crawl'] > 0): ?>
          <a href="?feed_id=<?= $f['id'] ?>&run=1&<?= CSRF_TOKEN_NAME ?>=<?= urlencode($token) ?>"
             class="btn btn-sm btn-ghost" style="border-color:#0369a1;color:#0369a1;"
             onclick="return confirm('Crawl up to 200 articles from this feed?')">🕷 Crawl</a>
          <?php else: ?>
          <span style="font-size:.78rem;color:var(--muted);">All done</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
