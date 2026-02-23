<?php
// ============================================================
// feeds/index.php — RSS feed management
// ============================================================
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
requireRole('admin', 'manager');

$pageTitle = 'RSS Feed Management';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action = $_POST['action'] ?? '';
    $fid    = (int)($_POST['fid'] ?? 0);

    if ($action === 'add') {
        $url          = trim($_POST['url']               ?? '');
        $sid          = (int)($_POST['subscriber_id']    ?? 0);
        $aid          = (int)($_POST['author_profile_id'] ?? 0) ?: null;
        $title        = trim($_POST['title']             ?? '');
        $crawl        = empty($_POST['crawl_full_content']) ? 0 : 1;

        if (!$url || !$sid) {
            flashSet('error', 'Feed URL and subscriber are required.');
        } elseif (!filter_var($url, FILTER_VALIDATE_URL)) {
            flashSet('error', 'Invalid URL format.');
        } elseif (!dbGet('SELECT id FROM users WHERE id=? AND role="subscriber"', [$sid])) {
            flashSet('error', 'Invalid subscriber selected.');
        } else {
            try {
                dbRun(
                    'INSERT INTO rss_feeds
                     (url, subscriber_id, author_profile_id, title, crawl_full_content, status, created_by)
                     VALUES (?,?,?,?,?,"active",?)',
                    [$url, $sid, $aid, $title ?: null, $crawl, $_SESSION['user_id']]
                );
                logActivity('feed_added', "Added feed: {$url} for subscriber {$sid}" . ($crawl ? ' [crawl on]' : ''));
                flashSet('success', 'RSS feed added successfully.');
            } catch (PDOException $e) {
                flashSet('error', 'Error adding feed: ' . $e->getMessage());
            }
        }
    }

    elseif ($action === 'edit' && $fid) {
        $url   = trim($_POST['url']               ?? '');
        $sid   = (int)($_POST['subscriber_id']    ?? 0);
        $aid   = (int)($_POST['author_profile_id'] ?? 0) ?: null;
        $title = trim($_POST['title']             ?? '');
        $stat  = $_POST['status']                 ?? 'active';
        $crawl = empty($_POST['crawl_full_content']) ? 0 : 1;

        dbRun(
            'UPDATE rss_feeds
             SET url=?, subscriber_id=?, author_profile_id=?, title=?,
                 crawl_full_content=?, status=?
             WHERE id=?',
            [$url, $sid, $aid, $title ?: null, $crawl, $stat, $fid]
        );
        logActivity('feed_edited', "Edited feed ID {$fid}" . ($crawl ? ' [crawl on]' : ''));
        flashSet('success', 'Feed updated.');
    }

    elseif ($action === 'delete' && $fid) {
        dbRun('DELETE FROM rss_feeds WHERE id=?', [$fid]);
        logActivity('feed_deleted', "Deleted feed ID {$fid}");
        flashSet('success', 'Feed deleted.');
    }

    redirect(BASE_URL . '/feeds/index.php');
}

$feeds       = dbAll(
    'SELECT f.*, u.full_name, u.firm_name, ap.name AS author_name
     FROM rss_feeds f
     JOIN users u ON u.id = f.subscriber_id
     LEFT JOIN author_profiles ap ON ap.id = f.author_profile_id
     ORDER BY f.created_at DESC'
);
$subscribers = dbAll('SELECT id, full_name, firm_name FROM users WHERE role="subscriber" AND status="active" ORDER BY firm_name, full_name');
$authors     = dbAll('SELECT ap.*, u.firm_name FROM author_profiles ap JOIN users u ON u.id=ap.subscriber_id ORDER BY u.firm_name, ap.name');

include dirname(__DIR__) . '/includes/header.php';
?>

<style>
.toggle-wrap { display:flex; align-items:center; gap:.55rem; }
.toggle-wrap input[type=checkbox] { width:18px; height:18px; accent-color:var(--navy); cursor:pointer; }
.crawl-info { background:#e0f2fe; border:1px solid #7dd3fc; border-radius:6px; padding:.65rem .9rem; font-size:.82rem; color:#0369a1; margin-top:.5rem; }
</style>

<div class="page-header">
  <div><h1>RSS Feeds</h1><p>Manage feeds and configure full-content crawling per feed</p></div>
  <div class="flex gap-2">
    <a href="<?= BASE_URL ?>/feeds/fetch.php" class="btn btn-gold">⟳ Fetch All Feeds</a>
    <button class="btn btn-primary" data-modal-open="modal-add">+ Add Feed</button>
  </div>
</div>

<div class="card">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Title / URL</th>
          <th>Subscriber</th>
          <th>Default Author</th>
          <th>Crawl</th>
          <th>Status</th>
          <th>Last Fetched</th>
          <th>Articles</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($feeds as $f):
        $count   = dbGet('SELECT COUNT(*) c FROM rss_cache WHERE feed_id=?', [$f['id']])['c'] ?? 0;
        $nocontent = dbGet('SELECT COUNT(*) c FROM rss_cache WHERE feed_id=? AND pushed_to_ghost=0 AND (content IS NULL OR content="" OR content=description)', [$f['id']])['c'] ?? 0;
      ?>
      <tr>
        <td>
          <div style="font-weight:600;font-size:.85rem;"><?= e($f['title'] ?: 'Untitled Feed') ?></div>
          <a href="<?= e($f['url']) ?>" target="_blank" rel="noopener"
             style="font-size:.75rem;color:#3b82f6;"><?= e(mb_strimwidth($f['url'], 0, 60, '…')) ?></a>
        </td>
        <td>
          <div style="font-size:.85rem;"><?= e($f['full_name']) ?></div>
          <div style="font-size:.75rem;color:var(--muted);"><?= e($f['firm_name'] ?? '') ?></div>
        </td>
        <td style="font-size:.82rem;"><?= e($f['author_name'] ?? '—') ?></td>
        <td>
          <?php if ($f['crawl_full_content']): ?>
            <span class="badge" style="background:#e0f2fe;color:#0369a1;">🕷 On</span>
            <?php if ($nocontent > 0): ?>
              <div style="margin-top:.2rem;">
                <a href="<?= BASE_URL ?>/feeds/crawl.php?feed_id=<?= $f['id'] ?>"
                   style="font-size:.72rem;color:var(--warn);">⚠ <?= $nocontent ?> need crawl</a>
              </div>
            <?php endif; ?>
          <?php else: ?>
            <span style="color:var(--muted);font-size:.78rem;">RSS only</span>
          <?php endif; ?>
        </td>
        <td><span class="badge badge-<?= e($f['status']) ?>"><?= e(ucfirst($f['status'])) ?></span></td>
        <td style="font-size:.78rem;color:var(--muted);"><?= $f['last_fetched'] ? e(date('d M y H:i', strtotime($f['last_fetched']))) : 'Never' ?></td>
        <td style="text-align:center;"><?= (int)$count ?></td>
        <td>
          <div class="flex gap-2" style="flex-wrap:wrap;">
            <button class="btn btn-sm btn-ghost"
                    onclick="fillEditFeed(<?= htmlspecialchars(json_encode($f), ENT_QUOTES) ?>)"
                    data-modal-open="modal-edit">Edit</button>
            <a href="<?= BASE_URL ?>/feeds/fetch.php?feed_id=<?= $f['id'] ?>&run=1"
               class="btn btn-sm btn-gold">Fetch</a>
            <?php if ($f['crawl_full_content'] && $nocontent > 0): ?>
            <a href="<?= BASE_URL ?>/feeds/crawl.php?feed_id=<?= $f['id'] ?>"
               class="btn btn-sm btn-ghost" style="border-color:#0369a1;color:#0369a1;">🕷 Back-fill</a>
            <?php endif; ?>
            <form method="POST" style="display:inline;">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="fid" value="<?= $f['id'] ?>">
              <button class="btn btn-sm btn-danger"
                      data-confirm="Delete this feed and all its cached articles?">Del</button>
            </form>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($feeds)): ?>
      <tr><td colspan="8" class="text-center text-muted" style="padding:2rem;">No feeds yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── Add Feed Modal ─────────────────────────────────────── -->
<div class="modal-overlay" id="modal-add">
  <div class="modal" style="max-width:520px;">
    <div class="modal-header"><h2>Add RSS Feed</h2><button class="modal-close">✕</button></div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add">
      <div class="form-group">
        <label class="form-label">Feed URL *</label>
        <input type="url" name="url" class="form-control" required placeholder="https://www.lawfirm.co.uk/feed/">
      </div>
      <div class="form-group">
        <label class="form-label">Feed Title <span style="font-weight:400;color:var(--muted);">(auto-detected if blank)</span></label>
        <input type="text" name="title" class="form-control" placeholder="e.g. Smith & Jones – Employment Law">
      </div>
      <div class="form-group">
        <label class="form-label">Subscriber (Law Firm) *</label>
        <select name="subscriber_id" class="form-control" required>
          <option value="">— Select Subscriber —</option>
          <?php foreach ($subscribers as $s): ?>
          <option value="<?= $s['id'] ?>"><?= e($s['firm_name'] ?: $s['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Default Author Profile <span style="font-weight:400;color:var(--muted);">(used when article author can't be matched)</span></label>
        <select name="author_profile_id" class="form-control">
          <option value="">— None —</option>
          <?php foreach ($authors as $a): ?>
          <option value="<?= $a['id'] ?>"><?= e($a['name'] . ' (' . ($a['firm_name'] ?? '') . ')') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Full Content Crawling</label>
        <label class="toggle-wrap">
          <input type="checkbox" name="crawl_full_content" value="1">
          <span>Crawl each article URL to extract full content &amp; images</span>
        </label>
        <div class="crawl-info" style="margin-top:.5rem;">
          🕷 When enabled, each new article's URL is fetched during import to extract the
          full article body and featured image from the actual page — useful when the RSS feed
          only contains a short excerpt. Adds ~1–3 seconds per article.
        </div>
      </div>
      <button type="submit" class="btn btn-primary w-full">Add Feed</button>
    </form>
  </div>
</div>

<!-- ── Edit Feed Modal ────────────────────────────────────── -->
<div class="modal-overlay" id="modal-edit">
  <div class="modal" style="max-width:520px;">
    <div class="modal-header"><h2>Edit Feed</h2><button class="modal-close">✕</button></div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="fid" id="edit-fid">
      <div class="form-group">
        <label class="form-label">Feed URL</label>
        <input type="url" name="url" id="edit-url" class="form-control" required>
      </div>
      <div class="form-group">
        <label class="form-label">Feed Title</label>
        <input type="text" name="title" id="edit-title" class="form-control">
      </div>
      <div class="form-group">
        <label class="form-label">Subscriber</label>
        <select name="subscriber_id" id="edit-sid" class="form-control">
          <?php foreach ($subscribers as $s): ?>
          <option value="<?= $s['id'] ?>"><?= e($s['firm_name'] ?: $s['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Default Author Profile</label>
        <select name="author_profile_id" id="edit-aid" class="form-control">
          <option value="">— None —</option>
          <?php foreach ($authors as $a): ?>
          <option value="<?= $a['id'] ?>"><?= e($a['name'] . ' (' . ($a['firm_name'] ?? '') . ')') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Full Content Crawling</label>
        <label class="toggle-wrap">
          <input type="checkbox" name="crawl_full_content" id="edit-crawl" value="1">
          <span>Crawl each article URL to extract full content &amp; images</span>
        </label>
        <div class="crawl-info" id="edit-crawl-note" style="display:none;margin-top:.5rem;">
          🕷 Already-cached articles without content can be back-filled via
          <a href="<?= BASE_URL ?>/feeds/crawl.php">Feeds → Back-fill Crawl</a>.
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Status</label>
        <select name="status" id="edit-status" class="form-control">
          <option value="active">Active</option>
          <option value="paused">Paused</option>
        </select>
      </div>
      <button type="submit" class="btn btn-primary w-full">Save Changes</button>
    </form>
  </div>
</div>

<script>
function fillEditFeed(f) {
  document.getElementById('edit-fid').value    = f.id;
  document.getElementById('edit-url').value    = f.url;
  document.getElementById('edit-title').value  = f.title || '';
  document.getElementById('edit-sid').value    = f.subscriber_id;
  document.getElementById('edit-aid').value    = f.author_profile_id || '';
  document.getElementById('edit-status').value = f.status;
  const cb   = document.getElementById('edit-crawl');
  const note = document.getElementById('edit-crawl-note');
  cb.checked         = !!parseInt(f.crawl_full_content || 0);
  note.style.display = cb.checked ? 'block' : 'none';
  cb.addEventListener('change', () => { note.style.display = cb.checked ? 'block' : 'none'; }, { once: true });
}
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
