<?php
// ============================================================
// feeds/cache.php — Article moderation queue + selective push
// ============================================================
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/ghost_helper.php';
requireRole('admin', 'manager');

set_time_limit(300);
$pageTitle = 'Article Moderation Queue';

// ── Inline author assignment (AJAX-style POST) ────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_author'])) {
    csrfCheck();
    $articleId       = (int)$_POST['article_id'];
    $authorProfileId = $_POST['author_profile_id'] === '' ? null : (int)$_POST['author_profile_id'];
    dbRun('UPDATE rss_cache SET author_profile_id=? WHERE id=?', [$authorProfileId, $articleId]);
    // Return JSON for JS fetch(), or redirect if no JS
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }
    flashSet('success', 'Author updated.');
    header('Location: ' . $_SERVER['HTTP_REFERER'] ?? BASE_URL . '/feeds/cache.php');
    exit;
}

// ── Selective push ────────────────────────────────────────────
$pushResults = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['push_selected'])) {
    csrfCheck();
    $confId = (int)($_POST['ghost_config_id'] ?? 0);
    $ids    = array_map('intval', array_filter($_POST['article_ids'] ?? []));

    if (!$confId) {
        flashSet('error', 'Please select a Ghost CMS configuration before pushing.');
    } elseif (empty($ids)) {
        flashSet('error', 'No articles selected.');
    } else {
        $conf = dbGet('SELECT * FROM ghost_config WHERE id=?', [$confId]);
        if (!$conf) {
            flashSet('error', 'Invalid Ghost configuration.');
        } else {
            $ph       = implode(',', array_fill(0, count($ids), '?'));
            $articles = dbAll(
                "SELECT rc.*, rf.subscriber_id
                 FROM rss_cache rc
                 JOIN rss_feeds rf ON rf.id = rc.feed_id
                 WHERE rc.id IN ($ph) AND rc.pushed_to_ghost = 0",
                $ids
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

            logActivity('selective_push',
                "Pushed {$ok} to Ghost (conf {$confId}), {$fail} failed. IDs: " . implode(',', $ids));

            $msg = "{$ok} article" . ($ok !== 1 ? 's' : '') . " published to Ghost.";
            if ($fail) $msg .= " {$fail} failed — see results below.";
            flashSet($fail ? 'warning' : 'success', $msg);
        }
    }
}

// ── Filters ───────────────────────────────────────────────────
$sid      = (int)($_GET['sid']      ?? 0);
$fid      = (int)($_GET['fid']      ?? 0);
$status   = $_GET['status']         ?? 'unpushed';
$noAuthor = isset($_GET['no_author']);
$search   = trim($_GET['q']         ?? '');
$page     = max(1, (int)($_GET['p'] ?? 1));
$perPage  = 50;

$where  = 'WHERE 1=1';
$params = [];

if ($sid)      { $where .= ' AND rf.subscriber_id=?';     $params[] = $sid; }
if ($fid)      { $where .= ' AND rc.feed_id=?';            $params[] = $fid; }
if ($status === 'unpushed') { $where .= ' AND rc.pushed_to_ghost=0'; }
if ($status === 'pushed')   { $where .= ' AND rc.pushed_to_ghost=1'; }
if ($noAuthor) { $where .= ' AND rc.author_profile_id IS NULL AND rc.pushed_to_ghost=0'; }
if ($search)   { $where .= ' AND rc.title LIKE ?';         $params[] = "%$search%"; }

$total  = dbGet("SELECT COUNT(*) c FROM rss_cache rc JOIN rss_feeds rf ON rf.id=rc.feed_id $where", $params)['c'] ?? 0;
$pages  = max(1, (int)ceil($total / $perPage));
$offset = ($page - 1) * $perPage;

$articles = dbAll(
    "SELECT rc.*, rf.title AS feed_title, rf.subscriber_id,
            u.full_name, u.firm_name,
            ap.id AS ap_id, ap.name AS ap_name, ap.email AS ap_email,
            ap.position AS ap_position
     FROM rss_cache rc
     JOIN rss_feeds rf ON rf.id = rc.feed_id
     JOIN users u       ON u.id = rf.subscriber_id
     LEFT JOIN author_profiles ap ON ap.id = rc.author_profile_id
     $where
     ORDER BY rc.pub_date DESC
     LIMIT $perPage OFFSET $offset",
    $params
);

// All author profiles grouped by subscriber for the inline dropdowns
$allProfiles = dbAll(
    'SELECT ap.id, ap.name, ap.email, ap.position, ap.subscriber_id, u.firm_name
     FROM author_profiles ap
     JOIN users u ON u.id = ap.subscriber_id
     ORDER BY u.firm_name, ap.name'
);
$profilesBySubscriber = [];
foreach ($allProfiles as $ap) {
    $profilesBySubscriber[$ap['subscriber_id']][] = $ap;
}

$subscribers   = dbAll('SELECT id, full_name, firm_name FROM users WHERE role="subscriber" AND status="active" ORDER BY firm_name');
$feeds         = dbAll('SELECT f.id, f.title, f.subscriber_id FROM rss_feeds f ORDER BY f.title');
$ghostConfs    = dbAll('SELECT * FROM ghost_config ORDER BY is_default DESC, name');

$unpushedTotal = dbGet('SELECT COUNT(*) c FROM rss_cache WHERE pushed_to_ghost=0')['c'] ?? 0;
$pushedTotal   = dbGet('SELECT COUNT(*) c FROM rss_cache WHERE pushed_to_ghost=1')['c'] ?? 0;
$noAuthorTotal = dbGet('SELECT COUNT(*) c FROM rss_cache WHERE pushed_to_ghost=0 AND author_profile_id IS NULL')['c'] ?? 0;

$firmCounts = dbAll(
    'SELECT u.id, u.firm_name, u.full_name, COUNT(rc.id) AS cnt
     FROM users u
     JOIN rss_feeds rf ON rf.subscriber_id=u.id
     JOIN rss_cache rc ON rc.feed_id=rf.id
     WHERE rc.pushed_to_ghost=0
     GROUP BY u.id ORDER BY cnt DESC'
);

include dirname(__DIR__) . '/includes/header.php';
?>
<style>
.mod-layout { display:grid; grid-template-columns:260px 1fr; gap:1.25rem; align-items:start; }
.mod-sidebar .card { margin-bottom:.85rem; }
.sidebar-section-title { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:var(--muted); padding:.6rem 1rem .35rem; }
.filter-link { display:flex; align-items:center; justify-content:space-between; padding:.42rem 1rem; border-radius:6px; font-size:.83rem; color:var(--text); text-decoration:none; transition:background .15s; }
.filter-link:hover { background:#f1f5f9; }
.filter-link.active { background:#e0e7ff; color:var(--navy); font-weight:600; }
.filter-badge { background:var(--navy); color:#fff; font-size:.65rem; font-weight:700; padding:.15rem .45rem; border-radius:20px; min-width:22px; text-align:center; }
.filter-badge.gold { background:var(--gold); color:var(--navy); }
.filter-badge.warn { background:#f59e0b; color:#fff; }

.mod-toolbar { display:flex; align-items:center; gap:.75rem; flex-wrap:wrap; background:#fff; border:1px solid var(--border); border-radius:var(--radius) var(--radius) 0 0; padding:.75rem 1rem; position:sticky; top:64px; z-index:50; }
.mod-toolbar .sel-count { font-size:.82rem; font-weight:600; color:var(--navy); min-width:110px; }
.separator { width:1px; height:22px; background:var(--border); }

.mod-table { border-radius:0 0 var(--radius) var(--radius); overflow:hidden; box-shadow:var(--shadow); }
.mod-table table { width:100%; border-collapse:collapse; font-size:.84rem; }
.mod-table thead th { background:var(--navy); color:#fff; padding:.65rem .85rem; font-size:.73rem; font-weight:600; text-transform:uppercase; letter-spacing:.04em; white-space:nowrap; }
.mod-table thead th:first-child { width:36px; padding-left:1rem; }
.mod-table tbody td { padding:.7rem .85rem; border-bottom:1px solid var(--border); vertical-align:middle; }
.mod-table tbody tr:last-child td { border-bottom:none; }
.mod-table tbody tr:hover { background:#f8fafc; }
.mod-table tbody tr.is-pushed { opacity:.55; }
.mod-table tbody tr.selected-row { background:#eff6ff !important; }
.mod-table tbody tr.no-author td:first-child { border-left:3px solid #f59e0b; }

.cb-wrap { display:flex; align-items:center; justify-content:center; }
input[type=checkbox] { width:16px; height:16px; cursor:pointer; accent-color:var(--navy); }

.article-title a { color:var(--navy); font-weight:600; text-decoration:none; }
.article-title a:hover { text-decoration:underline; }
.article-excerpt { font-size:.76rem; color:var(--muted); margin-top:.2rem; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; max-width:420px; line-height:1.45; }
.firm-pill { display:inline-flex; align-items:center; gap:.3rem; font-size:.74rem; background:#f1f5f9; border-radius:20px; padding:.15rem .55rem; color:var(--navy); white-space:nowrap; }

/* Author cell */
.author-cell { min-width:180px; }
.author-matched { display:flex; align-items:center; gap:.4rem; font-size:.8rem; }
.author-matched .ap-name { font-weight:600; color:var(--navy); }
.author-matched .ap-meta { font-size:.72rem; color:var(--muted); }
.author-unmatched { font-size:.78rem; color:var(--muted); font-style:italic; }
.author-raw { font-size:.72rem; color:var(--muted); margin-top:.15rem; }
.author-select-wrap { display:flex; align-items:center; gap:.4rem; flex-direction:column; }
.author-select-wrap select { font-size:.78rem; padding:.25rem .4rem; border:1.5px solid var(--border); border-radius:6px; cursor:pointer; width:100%; max-width:190px; }
.author-select-wrap select.is-unmatched { border-color:#f59e0b; background:#fffbeb; }

.no-author-banner { background:#fffbeb; border:1.5px solid #f59e0b; border-radius:8px; padding:.75rem 1rem; font-size:.85rem; margin-bottom:1rem; display:flex; align-items:center; justify-content:space-between; gap:1rem; }

.status-tabs { display:flex; gap:.35rem; margin-bottom:1rem; }
.status-tab { padding:.45rem 1rem; border-radius:8px; font-size:.83rem; font-weight:600; text-decoration:none; color:var(--muted); background:#fff; border:1.5px solid var(--border); transition:all .15s; }
.status-tab:hover { border-color:var(--navy); color:var(--navy); }
.status-tab.active { background:var(--navy); color:#fff; border-color:var(--navy); }
.status-tab .tab-count { background:rgba(255,255,255,.25); border-radius:20px; padding:.05rem .4rem; font-size:.72rem; margin-left:.35rem; }
.status-tab:not(.active) .tab-count { background:#e5e7eb; color:var(--muted); }

.push-results { border:1.5px solid var(--border); border-radius:var(--radius); overflow:hidden; margin-bottom:1.25rem; }
.push-result-row { display:flex; align-items:center; gap:.75rem; padding:.6rem 1rem; font-size:.82rem; border-bottom:1px solid var(--border); }
.push-result-row:last-child { border-bottom:none; }
.push-result-row.ok { background:#f0fdf4; }
.push-result-row.fail { background:#fef2f2; }
.push-result-error { font-size:.75rem; color:var(--danger); }

.mod-pager { display:flex; justify-content:center; gap:.4rem; padding:1rem 0 .25rem; }

@media (max-width:900px) { .mod-layout { grid-template-columns:1fr; } .mod-sidebar { display:none; } }
</style>

<div class="page-header">
  <div><h1>Article Moderation Queue</h1><p>Review cached articles, assign authors, and push to Ghost CMS</p></div>
</div>

<?php if ($pushResults): ?>
<div class="card" style="margin-bottom:1.25rem;">
  <div class="card-header" style="margin-bottom:.85rem;">
    <span class="card-title">Push Results</span>
    <span style="font-size:.82rem;color:var(--muted);">
      <?= array_sum(array_column(array_column($pushResults,'res'),'success')) ?> published,
      <?= count(array_filter($pushResults, fn($r)=>!$r['res']['success'])) ?> failed
    </span>
  </div>
  <div class="push-results">
    <?php foreach ($pushResults as $pr): ?>
    <div class="push-result-row <?= $pr['res']['success']?'ok':'fail' ?>">
      <span><?= $pr['res']['success']?'✅':'❌' ?></span>
      <span style="flex:1;font-weight:500;"><?= e(mb_strimwidth($pr['art']['title']??'Untitled',0,70,'…')) ?></span>
      <?php if ($pr['res']['success']): ?>
        <span style="font-size:.75rem;color:var(--muted);"><?= e($pr['res']['ghost_post_id']) ?></span>
      <?php else: ?>
        <span class="push-result-error"><?= e($pr['res']['error']) ?></span>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php
$tabBase = http_build_query(array_filter(['sid'=>$sid,'fid'=>$fid,'q'=>$search]));
?>
<div class="status-tabs">
  <?php foreach ([
    ['status'=>'unpushed','label'=>'Queue',    'count'=>$unpushedTotal],
    ['status'=>'pushed',  'label'=>'Published','count'=>$pushedTotal],
    ['status'=>'all',     'label'=>'All',      'count'=>$unpushedTotal+$pushedTotal],
  ] as $t):
    $q = ($tabBase?$tabBase.'&':'').'status='.$t['status'];
  ?>
  <a href="?<?= $q ?>" class="status-tab <?= $status===$t['status']?'active':'' ?>">
    <?= $t['label'] ?><span class="tab-count"><?= number_format($t['count']) ?></span>
  </a>
  <?php endforeach; ?>

  <?php if ($noAuthorTotal && $status!=='pushed'): ?>
  <a href="?status=unpushed&no_author=1" class="status-tab <?= $noAuthor?'active':'' ?>"
     style="<?= $noAuthor?'':'border-color:#f59e0b;color:#92400e;' ?>">
    ⚠ No Author<span class="tab-count" style="background:#f59e0b;"><?= $noAuthorTotal ?></span>
  </a>
  <?php endif; ?>
</div>

<?php if ($noAuthor): ?>
<div class="no-author-banner">
  <span><strong>⚠ Showing articles with no author assigned.</strong>
  Use the dropdown in the Author column to assign one before pushing.</span>
  <a href="?status=unpushed" class="btn btn-sm btn-ghost">Show all</a>
</div>
<?php endif; ?>

<div class="mod-layout">

<!-- ── Sidebar ───────────────────────────────────────────── -->
<aside class="mod-sidebar">

  <div class="card" style="padding:.85rem;">
    <form method="GET">
      <input type="hidden" name="status" value="<?= e($status) ?>">
      <?php if ($sid): ?><input type="hidden" name="sid" value="<?= $sid ?>"><?php endif; ?>
      <?php if ($fid): ?><input type="hidden" name="fid" value="<?= $fid ?>"><?php endif; ?>
      <input type="text" name="q" value="<?= e($search) ?>" placeholder="🔍 Search titles…"
             class="form-control" style="margin:0;">
      <button type="submit" class="btn btn-sm btn-ghost" style="width:100%;margin-top:.5rem;">Search</button>
    </form>
  </div>

  <div class="card" style="padding:.5rem 0;">
    <div class="sidebar-section-title">Filter by Firm</div>
    <a href="?<?= http_build_query(array_filter(['status'=>$status,'q'=>$search])) ?>"
       class="filter-link <?= !$sid&&!$fid&&!$noAuthor?'active':'' ?>">
      <span>All Firms</span>
      <span class="filter-badge gold"><?= number_format($unpushedTotal) ?></span>
    </a>
    <?php foreach ($firmCounts as $fc): ?>
    <a href="?<?= http_build_query(array_filter(['status'=>$status,'sid'=>$fc['id'],'q'=>$search])) ?>"
       class="filter-link <?= $sid==$fc['id']&&!$fid?'active':'' ?>">
      <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:170px;">
        <?= e($fc['firm_name']?:$fc['full_name']) ?>
      </span>
      <span class="filter-badge"><?= $fc['cnt'] ?></span>
    </a>
    <?php endforeach; ?>
  </div>

  <?php if (!empty($ghostConfs)): ?>
  <div class="card" style="padding:.85rem;">
    <div style="font-size:.78rem;font-weight:700;color:var(--navy);margin-bottom:.5rem;text-transform:uppercase;letter-spacing:.05em;">Ghost Configuration</div>
    <select id="sidebar-ghost-conf" class="form-control" style="margin:0;font-size:.82rem;">
      <?php foreach ($ghostConfs as $gc): ?>
      <option value="<?= $gc['id'] ?>" <?= $gc['is_default']?'selected':'' ?>><?= e($gc['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <p style="font-size:.72rem;color:var(--muted);margin-top:.4rem;">Select before pushing</p>
  </div>
  <?php endif; ?>

</aside>

<!-- ── Main ──────────────────────────────────────────────── -->
<div class="mod-main">

  <?php if (empty($ghostConfs) && $status==='unpushed'): ?>
  <div class="alert alert-warning">No Ghost CMS configured. <a href="<?= BASE_URL ?>/admin/settings.php">Add one in Settings →</a></div>
  <?php endif; ?>

  <form method="POST" id="mod-form">
    <?= csrfField() ?>
    <input type="hidden" name="ghost_config_id" id="form-ghost-conf-id"
           value="<?= e($ghostConfs[0]['id'] ?? '') ?>">

    <div class="mod-toolbar">
      <label class="cb-wrap"><input type="checkbox" id="select-all-cb" title="Select all"></label>
      <span class="sel-count" id="sel-count-label">0 selected</span>
      <div class="separator"></div>
      <button type="button" class="btn btn-sm btn-ghost" onclick="selectVisible(true)">Select page</button>
      <button type="button" class="btn btn-sm btn-ghost" onclick="selectVisible(false)">Deselect all</button>
      <?php if ($status!=='pushed'): ?>
      <div class="separator"></div>
      <button type="submit" name="push_selected" id="push-btn" class="btn btn-primary btn-sm" disabled
              onclick="syncGhostConf(); return confirmPush();">↑ Push Selected to Ghost</button>
      <?php endif; ?>
      <span style="font-size:.78rem;color:var(--muted);margin-left:auto;">
        <?= number_format($total) ?> article<?= $total!==1?'s':'' ?>
        <?= $total>$perPage?' (page '.$page.' of '.$pages.')':'' ?>
      </span>
    </div>

    <div class="mod-table">
      <table>
        <thead>
          <tr>
            <th></th>
            <th>Article</th>
            <th>Firm</th>
            <th>Author</th>
            <th>Date</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($articles as $a):
          $isPushed = (bool)$a['pushed_to_ghost'];
          $hasAuthor = !empty($a['author_profile_id']);
          $excerpt  = mb_strimwidth(preg_replace('/\s+/',' ',strip_tags($a['description']?:($a['content']?:''))),0,180,'…');
          $rowClass = ($isPushed?'is-pushed':'') . (!$isPushed&&!$hasAuthor?' no-author':'');
          $subProfiles = $profilesBySubscriber[$a['subscriber_id']] ?? [];
        ?>
        <tr class="article-row <?= $rowClass ?>" data-id="<?= $a['id'] ?>">

          <td class="cb-wrap" style="padding-left:1rem;">
            <?php if (!$isPushed): ?>
            <input type="checkbox" name="article_ids[]" value="<?= $a['id'] ?>"
                   class="article-cb" onchange="updateSelCount()">
            <?php else: ?>
            <span title="Published" style="font-size:.85rem;color:var(--success);">✓</span>
            <?php endif; ?>
          </td>

          <td style="max-width:380px;">
            <div class="article-title">
              <a href="<?= e($a['link']??'#') ?>" target="_blank" rel="noopener"
                 title="<?= e($a['title']??'') ?>">
                <?= e(mb_strimwidth($a['title']??'Untitled',0,80,'…')) ?>
              </a>
            </div>
            <?php if ($excerpt): ?>
            <div class="article-excerpt"><?= e($excerpt) ?></div>
            <?php endif; ?>
            <div style="margin-top:.3rem;">
              <span class="firm-pill" style="background:#f0f4ff;">📡 <?= e(mb_strimwidth($a['feed_title']??'',0,35,'…')) ?></span>
            </div>
          </td>

          <td>
            <span class="firm-pill" style="background:#fef9f0;">🏛 <?= e(mb_strimwidth($a['firm_name']?:$a['full_name'],0,25,'…')) ?></span>
          </td>

          <!-- Author cell with inline assignment -->
          <td class="author-cell">
            <?php if (!$isPushed): ?>
            <div class="author-select-wrap">
              <select class="author-assign-select <?= $hasAuthor?'':'is-unmatched' ?>"
                      data-article-id="<?= $a['id'] ?>"
                      onchange="assignAuthor(this, <?= $a['id'] ?>, <?= (int)$a['subscriber_id'] ?>)">
                <option value="">— Unassigned —</option>
                <?php foreach ($subProfiles as $ap): ?>
                <option value="<?= $ap['id'] ?>"
                        <?= $a['author_profile_id']==$ap['id']?'selected':'' ?>>
                  <?= e($ap['name']) ?>
                  <?= $ap['email']?' <'.$ap['email'].'>':'' ?>
                </option>
                <?php endforeach; ?>
              </select>
              <?php if ($a['author']): ?>
              <div class="author-raw" title="As it appeared in the RSS feed">
                RSS: <?= e(mb_strimwidth($a['author'],0,35,'…')) ?>
              </div>
              <?php endif; ?>
            </div>
            <?php else: ?>
              <?php if ($hasAuthor): ?>
              <div class="author-matched">
                <div>
                  <div class="ap-name"><?= e($a['ap_name']??'') ?></div>
                  <div class="ap-meta"><?= e($a['ap_position']??'') ?></div>
                </div>
              </div>
              <?php else: ?>
              <span class="author-unmatched">No profile</span>
              <?php endif; ?>
              <?php if ($a['author']): ?>
              <div class="author-raw">RSS: <?= e(mb_strimwidth($a['author'],0,30,'…')) ?></div>
              <?php endif; ?>
            <?php endif; ?>
          </td>

          <td style="font-size:.78rem;white-space:nowrap;color:var(--muted);">
            <?= $a['pub_date'] ? e(date('d M Y', strtotime($a['pub_date']))) : '—' ?>
          </td>

          <td style="white-space:nowrap;">
            <?php if ($isPushed): ?>
              <span class="badge badge-active">Published</span>
              <?php if ($a['ghost_post_id']): ?>
              <div style="font-size:.65rem;color:var(--muted);font-family:monospace;margin-top:.2rem;">
                <?= e(substr($a['ghost_post_id'],0,12)) ?>…
              </div>
              <?php endif; ?>
            <?php elseif (!$hasAuthor): ?>
              <span class="badge" style="background:#fef3c7;color:#92400e;">⚠ No Author</span>
            <?php else: ?>
              <span class="badge badge-pending">In Queue</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($articles)): ?>
        <tr><td colspan="6" class="text-center text-muted" style="padding:3rem;">
          <?= $status==='unpushed' ? ($noAuthor?'No unmatched articles.':'🎉 Queue is empty!') : 'No articles found.' ?>
        </td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div><!-- /mod-table -->

    <?php if ($status!=='pushed' && !empty($articles)): ?>
    <div style="background:#fff;border:1px solid var(--border);border-top:none;border-radius:0 0 var(--radius) var(--radius);padding:.75rem 1rem;display:flex;align-items:center;gap:.75rem;">
      <span id="bot-sel-label" style="font-size:.83rem;font-weight:600;color:var(--navy);">0 selected</span>
      <button type="submit" name="push_selected" id="push-btn-bot" class="btn btn-primary btn-sm" disabled
              onclick="syncGhostConf(); return confirmPush();">↑ Push Selected to Ghost</button>
      <span style="font-size:.78rem;color:var(--muted);">Articles without an author will push with no byline.</span>
    </div>
    <?php endif; ?>

  </form>

  <?php if ($pages>1): ?>
  <div class="mod-pager">
    <?php if ($page>1): ?>
    <a href="?<?= http_build_query(array_merge($_GET,['p'=>$page-1])) ?>" class="btn btn-sm btn-ghost">← Prev</a>
    <?php endif; ?>
    <?php
    $s = max(1,$page-3); $e = min($pages,$page+3);
    if ($s>1) echo '<span style="align-self:center;font-size:.82rem;color:var(--muted);">1 …</span>';
    for ($i=$s;$i<=$e;$i++):
    ?>
    <a href="?<?= http_build_query(array_merge($_GET,['p'=>$i])) ?>"
       class="btn btn-sm <?= $i===$page?'btn-primary':'btn-ghost' ?>"><?= $i ?></a>
    <?php endfor;
    if ($e<$pages) echo '<span style="align-self:center;font-size:.82rem;color:var(--muted);">… '.$pages.'</span>';
    ?>
    <?php if ($page<$pages): ?>
    <a href="?<?= http_build_query(array_merge($_GET,['p'=>$page+1])) ?>" class="btn btn-sm btn-ghost">Next →</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div><!-- /mod-main -->
</div><!-- /mod-layout -->

<script>
// CSRF token for AJAX calls
const CSRF = <?= json_encode(csrfToken()) ?>;

// ── Inline author assignment ──────────────────────────────────
async function assignAuthor(sel, articleId, subscriberId) {
  const profileId = sel.value;
  sel.disabled = true;

  const fd = new FormData();
  fd.append('assign_author', '1');
  fd.append('article_id', articleId);
  fd.append('author_profile_id', profileId);
  fd.append(<?= json_encode(CSRF_TOKEN_NAME) ?>, CSRF);

  try {
    const r = await fetch(window.location.pathname, {
      method: 'POST',
      headers: {'X-Requested-With': 'XMLHttpRequest'},
      body: fd
    });
    const data = await r.json();
    if (data.ok) {
      // Visual feedback
      sel.classList.toggle('is-unmatched', profileId === '');
      const row = sel.closest('tr');
      row.classList.toggle('no-author', profileId === '');
      const badge = row.querySelector('td:last-child span.badge');
      if (badge && profileId !== '') {
        badge.className = 'badge badge-pending';
        badge.textContent = 'In Queue';
      } else if (badge && profileId === '') {
        badge.className = 'badge';
        badge.style = 'background:#fef3c7;color:#92400e;';
        badge.textContent = '⚠ No Author';
      }
      // Brief green flash
      sel.style.borderColor = '#16a34a';
      setTimeout(() => { sel.style.borderColor = ''; }, 1200);
    }
  } catch(e) {
    console.error('Author assign failed', e);
    alert('Failed to save author — please refresh and try again.');
  } finally {
    sel.disabled = false;
  }
}

// ── Selection logic ───────────────────────────────────────────
function getAllCbs() { return document.querySelectorAll('.article-cb'); }

function updateSelCount() {
  const cbs     = getAllCbs();
  const checked = [...cbs].filter(c => c.checked).length;
  const label   = checked + ' selected';
  document.getElementById('sel-count-label').textContent = label;
  const bot = document.getElementById('bot-sel-label');
  if (bot) bot.textContent = label;
  document.querySelectorAll('#push-btn,#push-btn-bot').forEach(b => b.disabled = checked===0);
  const allCb = document.getElementById('select-all-cb');
  if (allCb) { allCb.indeterminate = checked>0 && checked<cbs.length; allCb.checked = checked===cbs.length && cbs.length>0; }
  document.querySelectorAll('.article-row').forEach(row => {
    const cb = row.querySelector('.article-cb');
    row.classList.toggle('selected-row', cb && cb.checked);
  });
}

function selectVisible(state) { getAllCbs().forEach(cb => cb.checked=state); updateSelCount(); }
document.getElementById('select-all-cb')?.addEventListener('change', function() { selectVisible(this.checked); });

document.querySelectorAll('.article-row').forEach(row => {
  row.addEventListener('click', function(e) {
    if (e.target.tagName==='A'||e.target.tagName==='INPUT'||e.target.tagName==='SELECT'||e.target.tagName==='OPTION'||e.target.closest('a')||e.target.closest('select')) return;
    const cb = this.querySelector('.article-cb');
    if (cb) { cb.checked=!cb.checked; updateSelCount(); }
  });
  if (row.querySelector('.article-cb')) row.style.cursor='pointer';
});

function syncGhostConf() {
  const sel = document.getElementById('sidebar-ghost-conf');
  const inp = document.getElementById('form-ghost-conf-id');
  if (sel && inp) inp.value = sel.value;
}

function confirmPush() {
  const n = [...getAllCbs()].filter(c => c.checked).length;
  if (!n) { alert('No articles selected.'); return false; }
  const unmatched = [...document.querySelectorAll('.article-row')]
    .filter(r => { const cb=r.querySelector('.article-cb'); return cb&&cb.checked&&r.classList.contains('no-author'); }).length;
  let msg = `Push ${n} article${n!==1?'s':''} to Ghost CMS?`;
  if (unmatched) msg += `\n\n⚠ ${unmatched} selected article${unmatched!==1?'s have':' has'} no author assigned — ${unmatched!==1?'they':'it'} will publish with no byline.`;
  return confirm(msg);
}

document.addEventListener('keydown', e => {
  if ((e.metaKey||e.ctrlKey) && e.key==='a' && document.activeElement.tagName!=='INPUT' && document.activeElement.tagName!=='SELECT') {
    e.preventDefault(); selectVisible(true);
  }
});

updateSelCount();
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
