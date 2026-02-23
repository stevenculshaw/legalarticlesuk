<?php
// ============================================================
// ghost/push.php — Bulk / automated push to Ghost CMS
// ============================================================
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/ghost_helper.php';
requireRole('admin', 'manager');

set_time_limit(300);
$pageTitle   = 'Ghost CMS Publisher';
$pushResults = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['push_bulk'])) {
    csrfCheck();

    $confId = (int)($_POST['ghost_config_id'] ?? 0);
    $limit  = min(500, max(1, (int)($_POST['limit'] ?? 100)));
    $conf   = dbGet('SELECT * FROM ghost_config WHERE id=?', [$confId]);

    if (!$conf) {
        flashSet('error', 'Please select a valid Ghost configuration.');
    } else {
        $articles = dbAll(
            'SELECT rc.*, rf.author_profile_id
             FROM rss_cache rc
             JOIN rss_feeds rf ON rf.id = rc.feed_id
             WHERE rc.pushed_to_ghost = 0
             ORDER BY rc.pub_date DESC
             LIMIT ?',
            [$limit]
        );

        $ok = 0; $fail = 0;
        foreach ($articles as $art) {
            $author = $art['author_profile_id']
                ? dbGet('SELECT * FROM author_profiles WHERE id=?', [$art['author_profile_id']])
                : null;

            $res = pushArticleToGhost($conf, $art, $author);
            $pushResults[] = ['art' => $art, 'res' => $res];

            if ($res['success']) {
                dbRun('UPDATE rss_cache SET pushed_to_ghost=1, ghost_post_id=?, pushed_at=NOW() WHERE id=?',
                      [$res['ghost_post_id'], $art['id']]);
                $ok++;
            } else {
                $fail++;
            }
        }

        logActivity('bulk_push', "Bulk push: {$ok} published, {$fail} failed (conf {$confId})");
        flashSet($fail ? 'warning' : 'success',
            "{$ok} article" . ($ok !== 1 ? 's' : '') . " published to Ghost. " . ($fail ? "{$fail} failed." : ''));
    }
}

$ghostConfs  = dbAll('SELECT * FROM ghost_config ORDER BY is_default DESC, name');
$unpushed    = dbGet('SELECT COUNT(*) c FROM rss_cache WHERE pushed_to_ghost=0')['c'] ?? 0;
$pushed      = dbGet('SELECT COUNT(*) c FROM rss_cache WHERE pushed_to_ghost=1')['c'] ?? 0;
$recent      = dbAll(
    'SELECT rc.title, rc.link, rc.ghost_post_id, rc.pushed_at, rf.title AS feed_title, u.firm_name
     FROM rss_cache rc
     JOIN rss_feeds rf ON rf.id=rc.feed_id
     JOIN users u ON u.id=rf.subscriber_id
     WHERE rc.pushed_to_ghost=1
     ORDER BY rc.pushed_at DESC LIMIT 20'
);

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <h1>Ghost CMS Publisher</h1>
    <p>Bulk-push all queued articles, or use the moderation queue for selective control</p>
  </div>
  <a href="<?= BASE_URL ?>/feeds/cache.php?status=unpushed" class="btn btn-gold">
    ☰ Open Moderation Queue
  </a>
</div>

<!-- Recommend moderation queue -->
<div class="alert alert-info" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;">
  <span>
    <strong>Tip:</strong> For article-level control — preview, filter by firm or feed, and push selectively —
    use the <strong>Moderation Queue</strong>.
  </span>
  <a href="<?= BASE_URL ?>/feeds/cache.php?status=unpushed" class="btn btn-sm btn-primary" style="flex-shrink:0;">
    Go to Queue →
  </a>
</div>

<!-- Stats -->
<div class="stats-grid" style="grid-template-columns:repeat(3,1fr);">
  <div class="stat-card">
    <div class="stat-icon gold">⏳</div>
    <div><div class="stat-num"><?= number_format($unpushed) ?></div><div class="stat-label">Ready to Push</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green">✓</div>
    <div><div class="stat-num"><?= number_format($pushed) ?></div><div class="stat-label">Published on Ghost</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon blue">👻</div>
    <div><div class="stat-num"><?= count($ghostConfs) ?></div><div class="stat-label">Ghost Configurations</div></div>
  </div>
</div>

<?php if (empty($ghostConfs)): ?>
<div class="alert alert-warning">
  No Ghost CMS configured. <a href="<?= BASE_URL ?>/admin/settings.php">Add one in Settings →</a>
</div>
<?php else: ?>

<!-- Bulk push form -->
<div class="card">
  <div class="card-header">
    <span class="card-title">Bulk Push (All Queued)</span>
  </div>
  <p style="font-size:.88rem;color:var(--muted);margin-bottom:1.25rem;">
    Pushes the oldest unpushed articles first. Use the moderation queue if you need to choose specific articles.
  </p>
  <form method="POST">
    <?= csrfField() ?>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Ghost Configuration</label>
        <select name="ghost_config_id" class="form-control" required>
          <?php foreach ($ghostConfs as $gc): ?>
          <option value="<?= $gc['id'] ?>" <?= $gc['is_default'] ? 'selected' : '' ?>>
            <?= e($gc['name']) ?> — <?= e($gc['ghost_url']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Articles to push (max)</label>
        <select name="limit" class="form-control">
          <option value="50">50 articles</option>
          <option value="100" selected>100 articles</option>
          <option value="250">250 articles</option>
          <option value="500">500 articles</option>
        </select>
      </div>
    </div>
    <div style="background:#fffbeb;border:1px solid var(--warn);border-radius:8px;padding:.85rem 1rem;margin-bottom:1rem;font-size:.84rem;">
      <strong>⚠</strong> This will immediately publish <strong><?= number_format($unpushed) ?> queued</strong>
      articles to Ghost (up to the limit above). All articles will be marked Published on Ghost and cannot be un-pushed
      from this portal.
    </div>
    <button type="submit" name="push_bulk"
            class="btn btn-primary"
            <?= $unpushed ? '' : 'disabled' ?>
            onclick="return confirm('Bulk-push up to ' + document.querySelector('[name=limit]').value + ' articles to Ghost?\n\nThis cannot be undone.')">
      ↑ Bulk Push to Ghost
    </button>
  </form>
</div>
<?php endif; ?>

<!-- Push results (if just ran) -->
<?php if ($pushResults): ?>
<div class="card">
  <div class="card-header">
    <span class="card-title">Results</span>
    <span style="font-size:.82rem;color:var(--muted);">
      <?= count(array_filter($pushResults, fn($r) => $r['res']['success'])) ?> ok &nbsp;·&nbsp;
      <?= count(array_filter($pushResults, fn($r) => !$r['res']['success'])) ?> failed
    </span>
  </div>
  <div style="max-height:320px;overflow-y:auto;">
    <div style="border:1px solid var(--border);border-radius:8px;overflow:hidden;">
    <?php foreach ($pushResults as $pr): ?>
    <div style="display:flex;align-items:center;gap:.75rem;padding:.55rem .9rem;font-size:.82rem;
                background:<?= $pr['res']['success'] ? '#f0fdf4' : '#fef2f2' ?>;
                border-bottom:1px solid var(--border);">
      <span><?= $pr['res']['success'] ? '✅' : '❌' ?></span>
      <span style="flex:1;font-weight:500;"><?= e(mb_strimwidth($pr['art']['title'] ?? 'Untitled', 0, 70, '…')) ?></span>
      <?php if ($pr['res']['success']): ?>
        <span style="font-size:.72rem;color:var(--muted);"><?= e($pr['res']['ghost_post_id']) ?></span>
      <?php else: ?>
        <span style="color:var(--danger);font-size:.75rem;"><?= e($pr['res']['error']) ?></span>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Recent pushes -->
<?php if ($recent): ?>
<div class="card">
  <div class="card-header">
    <span class="card-title">Recently Published on Ghost</span>
    <a href="<?= BASE_URL ?>/feeds/cache.php?status=pushed" class="btn btn-sm btn-ghost">View all</a>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Title</th><th>Firm</th><th>Ghost Post ID</th><th>Published At</th></tr>
      </thead>
      <tbody>
      <?php foreach ($recent as $r): ?>
        <tr>
          <td style="font-size:.83rem;">
            <a href="<?= e($r['link'] ?? '#') ?>" target="_blank" style="color:var(--navy);">
              <?= e(mb_strimwidth($r['title'] ?? '', 0, 65, '…')) ?>
            </a>
          </td>
          <td style="font-size:.78rem;"><?= e($r['firm_name'] ?? '') ?></td>
          <td style="font-size:.72rem;font-family:monospace;color:var(--muted);"><?= e($r['ghost_post_id'] ?? '') ?></td>
          <td style="font-size:.75rem;color:var(--muted);white-space:nowrap;">
            <?= $r['pushed_at'] ? e(date('d M y H:i', strtotime($r['pushed_at']))) : '—' ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
