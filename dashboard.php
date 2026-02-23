<?php
// ============================================================
// dashboard.php — Main dashboard
// ============================================================
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$u = currentUser();
$pageTitle = 'Dashboard';

// Stats by role
if (isAdmin()) {
    $stats = [
        'users'     => dbGet('SELECT COUNT(*) c FROM users WHERE status="active"')['c'] ?? 0,
        'feeds'     => dbGet('SELECT COUNT(*) c FROM rss_feeds')['c'] ?? 0,
        'articles'  => dbGet('SELECT COUNT(*) c FROM rss_cache')['c'] ?? 0,
        'pushed'    => dbGet('SELECT COUNT(*) c FROM rss_cache WHERE pushed_to_ghost=1')['c'] ?? 0,
    ];
    $recentActivity = dbAll(
        'SELECT l.*, u.full_name FROM activity_log l
         LEFT JOIN users u ON u.id = l.user_id
         ORDER BY l.created_at DESC LIMIT 15'
    );
} elseif (isManager()) {
    $stats = [
        'subscribers' => dbGet('SELECT COUNT(*) c FROM users WHERE role="subscriber" AND status="active"')['c'] ?? 0,
        'feeds'       => dbGet('SELECT COUNT(*) c FROM rss_feeds')['c'] ?? 0,
        'articles'    => dbGet('SELECT COUNT(*) c FROM rss_cache')['c'] ?? 0,
        'pushed'      => dbGet('SELECT COUNT(*) c FROM rss_cache WHERE pushed_to_ghost=1')['c'] ?? 0,
    ];
    $recentActivity = dbAll(
        'SELECT l.*, u.full_name FROM activity_log l
         LEFT JOIN users u ON u.id = l.user_id
         WHERE l.action NOT IN ("login_success","logout")
         ORDER BY l.created_at DESC LIMIT 10'
    );
} else {
    // Subscriber
    $sid = $u['id'];
    $stats = [
        'feeds'     => dbGet('SELECT COUNT(*) c FROM rss_feeds WHERE subscriber_id=?', [$sid])['c'] ?? 0,
        'articles'  => dbGet('SELECT COUNT(*) c FROM rss_cache rc JOIN rss_feeds rf ON rf.id=rc.feed_id WHERE rf.subscriber_id=?', [$sid])['c'] ?? 0,
        'published' => dbGet('SELECT COUNT(*) c FROM rss_cache rc JOIN rss_feeds rf ON rf.id=rc.feed_id WHERE rf.subscriber_id=? AND rc.pushed_to_ghost=1', [$sid])['c'] ?? 0,
        'authors'   => dbGet('SELECT COUNT(*) c FROM author_profiles WHERE subscriber_id=?', [$sid])['c'] ?? 0,
    ];
    $recentActivity = null;
}

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <h1>Welcome, <?= e($u['full_name'] ?? $u['username']) ?></h1>
    <p>
      <?php if (isAdmin()): ?>System Administrator — Full access enabled
      <?php elseif (isManager()): ?>Manager — Content & feed management
      <?php else: ?>Subscriber — <?= e($u['firm_name'] ?? 'Your firm') ?>
      <?php endif; ?>
    </p>
  </div>
  <?php if (isManager()): ?>
  <div class="flex gap-2">
    <a href="<?= BASE_URL ?>/feeds/fetch.php" class="btn btn-gold">⟳ Fetch Feeds</a>
    <a href="<?= BASE_URL ?>/ghost/push.php" class="btn btn-primary">↑ Push to Ghost</a>
  </div>
  <?php endif; ?>
</div>

<!-- Stats -->
<div class="stats-grid">
<?php if (isAdmin()): ?>
  <div class="stat-card">
    <div class="stat-icon purple">👥</div>
    <div><div class="stat-num"><?= $stats['users'] ?></div><div class="stat-label">Active Users</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon blue">📡</div>
    <div><div class="stat-num"><?= $stats['feeds'] ?></div><div class="stat-label">RSS Feeds</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon gold">📰</div>
    <div><div class="stat-num"><?= $stats['articles'] ?></div><div class="stat-label">Cached Articles</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green">✓</div>
    <div><div class="stat-num"><?= $stats['pushed'] ?></div><div class="stat-label">Pushed to Ghost</div></div>
  </div>
<?php elseif (isManager()): ?>
  <div class="stat-card">
    <div class="stat-icon purple">🏛</div>
    <div><div class="stat-num"><?= $stats['subscribers'] ?></div><div class="stat-label">Subscriber Firms</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon blue">📡</div>
    <div><div class="stat-num"><?= $stats['feeds'] ?></div><div class="stat-label">Active Feeds</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon gold">📰</div>
    <div><div class="stat-num"><?= $stats['articles'] ?></div><div class="stat-label">Cached Articles</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green">✓</div>
    <div><div class="stat-num"><?= $stats['pushed'] ?></div><div class="stat-label">Published on Ghost</div></div>
  </div>
<?php else: ?>
  <div class="stat-card">
    <div class="stat-icon blue">📡</div>
    <div><div class="stat-num"><?= $stats['feeds'] ?></div><div class="stat-label">Your Feeds</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon gold">📰</div>
    <div><div class="stat-num"><?= $stats['articles'] ?></div><div class="stat-label">Your Articles</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green">✓</div>
    <div><div class="stat-num"><?= $stats['published'] ?></div><div class="stat-label">Published</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon purple">✍</div>
    <div><div class="stat-num"><?= $stats['authors'] ?></div><div class="stat-label">Author Profiles</div></div>
  </div>
<?php endif; ?>
</div>

<?php if (isAdmin() || isManager()): ?>
<!-- Recent Activity -->
<div class="card">
  <div class="card-header">
    <span class="card-title">Recent Activity</span>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Action</th><th>User</th><th>Details</th><th>IP</th><th>Time</th></tr></thead>
      <tbody>
      <?php foreach ($recentActivity as $log): ?>
        <tr>
          <td><code style="font-size:.78rem;"><?= e($log['action']) ?></code></td>
          <td><?= e($log['full_name'] ?? '—') ?></td>
          <td><?= e(mb_strimwidth($log['details'] ?? '', 0, 80, '…')) ?></td>
          <td style="font-size:.75rem;color:var(--muted);"><?= e($log['ip_address'] ?? '') ?></td>
          <td style="font-size:.75rem;color:var(--muted);"><?= e(date('d M y H:i', strtotime($log['created_at']))) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($recentActivity)): ?>
        <tr><td colspan="5" class="text-center text-muted" style="padding:2rem;">No activity yet</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php else: ?>
<!-- Subscriber: Recent articles -->
<div class="card">
  <div class="card-header">
    <span class="card-title">Your Latest Articles</span>
    <a href="<?= BASE_URL ?>/subscriber/articles.php" class="btn btn-sm btn-ghost">View All</a>
  </div>
  <?php
  $articles = dbAll(
      'SELECT rc.*, rf.title as feed_title FROM rss_cache rc
       JOIN rss_feeds rf ON rf.id = rc.feed_id
       WHERE rf.subscriber_id = ? ORDER BY rc.pub_date DESC LIMIT 10',
      [$u['id']]
  );
  ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Title</th><th>Feed</th><th>Date</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($articles as $a): ?>
        <tr>
          <td><a href="<?= e($a['link'] ?? '#') ?>" target="_blank" style="color:var(--navy);"><?= e(mb_strimwidth($a['title'] ?? '', 0, 70, '…')) ?></a></td>
          <td style="font-size:.8rem;color:var(--muted);"><?= e($a['feed_title'] ?? '') ?></td>
          <td style="font-size:.8rem;"><?= $a['pub_date'] ? e(date('d M Y', strtotime($a['pub_date']))) : '—' ?></td>
          <td><?= $a['pushed_to_ghost'] ? '<span class="badge badge-active">Published</span>' : '<span class="badge badge-pending">Cached</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($articles)): ?>
        <tr><td colspan="4" class="text-center text-muted" style="padding:2rem;">No articles cached yet</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
