<?php
// ============================================================
// feeds/fetch.php — Fetch & cache RSS feeds (with full-text crawl)
// ============================================================
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
requireRole('admin', 'manager');

set_time_limit(600); // Allow up to 10 min — crawling many articles takes time
$pageTitle = 'Fetch RSS Feeds';
$results   = [];
$feedId    = (int)($_GET['feed_id'] ?? 0);

// ── Author helpers ────────────────────────────────────────────

function parseRssAuthor(string $raw): array {
    $raw = trim($raw);
    $name = $email = '';
    if (preg_match('/^([^\s@]+@[^\s@]+\.[^\s@]+)\s*\((.+)\)$/', $raw, $m)) {
        $email = trim($m[1]); $name = trim($m[2]);
    } elseif (preg_match('/^(.+?)\s*<([^\s@]+@[^\s@]+\.[^\s@]+)>$/', $raw, $m)) {
        $name = trim($m[1]); $email = trim($m[2]);
    } elseif (filter_var($raw, FILTER_VALIDATE_EMAIL)) {
        $email = $raw;
    } else {
        $name = $raw;
    }
    return ['name' => $name, 'email' => $email];
}

function matchAuthorProfile(int $subscriberId, string $rawAuthor, ?int $feedDefaultAuthorId): ?int {
    if ($rawAuthor === '') return $feedDefaultAuthorId;
    ['name' => $name, 'email' => $email] = parseRssAuthor($rawAuthor);
    if ($email) {
        $row = dbGet('SELECT id FROM author_profiles WHERE subscriber_id=? AND email=?', [$subscriberId, $email]);
        if ($row) return (int)$row['id'];
    }
    if ($name) {
        $row = dbGet('SELECT id FROM author_profiles WHERE subscriber_id=? AND LOWER(name)=LOWER(?)', [$subscriberId, $name]);
        if ($row) return (int)$row['id'];
    }
    return $feedDefaultAuthorId;
}

// ── Image extraction from RSS item ───────────────────────────

function extractRssImage(SimpleXMLElement $item, bool $isAtom, string $content, string $desc): string {
    $media = $item->children('media', true);
    if (!empty($media->content)) {
        foreach ($media->content as $mc) {
            $url  = (string)($mc['url']  ?? '');
            $type = (string)($mc['type'] ?? '');
            if ($url && str_starts_with($url, 'http') && (str_starts_with($type, 'image/') || preg_match('/\.(jpe?g|png|gif|webp)/i', $url))) {
                return $url;
            }
        }
    }
    if (!empty($media->thumbnail['url'])) {
        $url = (string)$media->thumbnail['url'];
        if (str_starts_with($url, 'http')) return $url;
    }
    if (!$isAtom && !empty($item->enclosure)) {
        $url  = (string)($item->enclosure['url']  ?? '');
        $type = (string)($item->enclosure['type'] ?? '');
        if ($url && str_starts_with($type, 'image/') && str_starts_with($url, 'http')) return $url;
    }
    $itunes = $item->children('itunes', true);
    if (!empty($itunes->image['href'])) {
        $url = (string)$itunes->image['href'];
        if (str_starts_with($url, 'http')) return $url;
    }
    foreach ([$content, $desc] as $html) {
        if (!$html) continue;
        foreach (['src', 'data-src', 'data-lazy-src'] as $attr) {
            if (preg_match('/<img[^>]+' . $attr . '=["\']([^"\']+)["\']/', $html, $m)) {
                $url = html_entity_decode($m[1]);
                if (str_starts_with($url, 'http') && preg_match('/\.(jpe?g|png|gif|webp)/i', $url)) return $url;
            }
        }
    }
    return '';
}

// ── Article page crawler ──────────────────────────────────────

/**
 * Crawl an article URL and extract:
 *   - 'image'   : og:image / twitter:image URL (most reliable source)
 *   - 'content' : cleaned full article HTML body
 *
 * Returns ['image' => string|null, 'content' => string|null]
 */
function crawlArticleUrl(string $url): array {
    $result = ['image' => null, 'content' => null];

    if (!$url || !preg_match('#^https?://#', $url)) return $result;

    $ctx = stream_context_create(['http' => [
        'timeout'         => 12,
        'follow_location' => true,
        'max_redirects'   => 5,
        'ignore_errors'   => true,
        'user_agent'      => 'Mozilla/5.0 (compatible; UKLegalPortal/1.0; +https://legalportal.co.uk)',
        'header'          => "Accept: text/html,application/xhtml+xml\r\nAccept-Language: en-GB,en;q=0.9",
    ]]);

    $html = @file_get_contents($url, false, $ctx);
    if (!$html || strlen($html) < 500) return $result;

    // ── 1. Featured image from Open Graph / Twitter Card ─────
    // These are the most reliable — set by the site's CMS specifically
    // for social sharing, so they're always the "hero" image of the article.
    $imagePatterns = [
        // og:image — both attribute orderings
        '/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']{10,})["\'][^>]*>/i',
        '/<meta[^>]+content=["\']([^"\']{10,})["\'][^>]+property=["\']og:image["\'][^>]*>/i',
        // twitter:image — both orderings
        '/<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']{10,})["\'][^>]*>/i',
        '/<meta[^>]+content=["\']([^"\']{10,})["\'][^>]+name=["\']twitter:image["\'][^>]*>/i',
        // og:image:secure_url
        '/<meta[^>]+property=["\']og:image:secure_url["\'][^>]+content=["\']([^"\']{10,})["\'][^>]*>/i',
    ];
    foreach ($imagePatterns as $pattern) {
        if (preg_match($pattern, $html, $m)) {
            $img = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
            if (preg_match('#^https?://#', $img)) {
                $result['image'] = $img;
                break;
            }
        }
    }

    // ── 2. Full article content ───────────────────────────────
    // Step 1: strip entire blocks we never want
    $clean = preg_replace(
        '#<(script|style|nav|header|footer|aside|form|iframe|noscript|figure\s+class="[^"]*wp-block-embed[^"]*")[^>]*>.*?</\1>#si',
        '',
        $html
    );

    $content = null;

    // Try semantic article tag
    if (preg_match('#<article[^>]*>(.*?)</article>#si', $clean, $m)) {
        $content = $m[1];
    }

    // Try <main>
    if (!$content) {
        if (preg_match('#<main[^>]*>(.*?)</main>#si', $clean, $m)) {
            $content = $m[1];
        }
    }

    // Try common CMS content div class patterns (order: most → least specific)
    if (!$content) {
        $divPatterns = [
            'entry-content', 'post-content', 'article-content', 'article-body',
            'post-body', 'blog-post-content', 'blog-content', 'content-body',
            'single-content', 'page-content', 'td-post-content',
            'jet-listing-dynamic-field', // Elementor
            'wpb_wrapper',               // WPBakery
        ];
        $classRe = implode('|', array_map('preg_quote', $divPatterns));
        if (preg_match('#<div[^>]+class=["\'][^"\']*(?:' . $classRe . ')[^"\']*["\'][^>]*>(.*)</div>#si', $clean, $m)) {
            $content = $m[1];
        }
    }

    // Fallback: look for the largest <div> that contains multiple <p> tags
    if (!$content) {
        preg_match_all('#<div[^>]*>(.*?)</div>#si', $clean, $divMatches);
        $best = ''; $bestCount = 0;
        foreach ($divMatches[1] as $div) {
            $pCount = substr_count(strtolower($div), '<p');
            if ($pCount > $bestCount && strlen(strip_tags($div)) > 200) {
                $bestCount = $pCount;
                $best = $div;
            }
        }
        if ($bestCount >= 3) $content = $best;
    }

    if ($content) {
        // Keep structural and inline tags, strip everything else
        $content = strip_tags($content,
            '<p><h1><h2><h3><h4><h5><h6><ul><ol><li>'
            . '<strong><b><em><i><u><a><blockquote><br>'
            . '<table><thead><tbody><tr><th><td><img>'
        );

        // Remove empty paragraphs and excessive whitespace
        $content = preg_replace('/<p[^>]*>\s*<\/p>/i', '', $content);
        $content = preg_replace('/\n{3,}/', "\n\n", $content);
        $content = preg_replace('/[ \t]{2,}/', ' ', $content);
        $content = trim($content);

        // Only use if there's a meaningful amount of text
        if (mb_strlen(strip_tags($content)) >= 200) {
            $result['content'] = $content;
        }
    }

    return $result;
}

// ── Main feed fetch ───────────────────────────────────────────

function fetchAndCacheFeed(array $feed): array {
    $result = [
        'feed'      => $feed,
        'new'       => 0,
        'crawled'   => 0,
        'matched'   => 0,
        'unmatched' => 0,
        'errors'    => [],
    ];

    $ctx = stream_context_create(['http' => [
        'timeout'    => 30,
        'user_agent' => 'UK Legal Articles Portal/1.0 (+https://legalportal.co.uk)',
    ]]);

    $raw = @file_get_contents($feed['url'], false, $ctx);
    if ($raw === false) {
        $result['errors'][] = 'Failed to download feed.';
        dbRun('UPDATE rss_feeds SET status="error", error_message=? WHERE id=?', ['Failed to download feed', $feed['id']]);
        return $result;
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NOCDATA);
    if (!$xml) {
        $result['errors'][] = 'Failed to parse RSS/XML.';
        dbRun('UPDATE rss_feeds SET status="error", error_message=? WHERE id=?', ['Invalid XML', $feed['id']]);
        return $result;
    }
    libxml_clear_errors();

    $isAtom = ($xml->getName() === 'feed');
    $items  = $isAtom ? $xml->entry : ($xml->channel->item ?? []);

    $feedTitle = $isAtom ? (string)($xml->title ?? '') : (string)($xml->channel->title ?? '');
    if ($feedTitle && !$feed['title']) {
        dbRun('UPDATE rss_feeds SET title=? WHERE id=?', [$feedTitle, $feed['id']]);
    }

    $defaultAuthorId = $feed['author_profile_id'] ? (int)$feed['author_profile_id'] : null;
    $subscriberId    = (int)$feed['subscriber_id'];
    $doCrawl         = !empty($feed['crawl_full_content']);

    foreach ($items as $item) {
        try {
            // ── Parse RSS/Atom item fields ─────────────────────
            if ($isAtom) {
                $guid      = (string)($item->id ?? '');
                $link      = (string)($item->link['href'] ?? ($item->link ?? ''));
                $itemTitle = (string)($item->title ?? '');
                $content   = (string)($item->content ?? ($item->summary ?? ''));
                $desc      = (string)($item->summary ?? '');
                $pubDate   = (string)($item->published ?? ($item->updated ?? ''));
                $authorName  = (string)($item->author->name  ?? '');
                $authorEmail = (string)($item->author->email ?? '');
                $rawAuthor   = ($authorName && $authorEmail)
                    ? "{$authorName} <{$authorEmail}>"
                    : ($authorName ?: $authorEmail);
            } else {
                $guid      = (string)($item->guid ?? ($item->link ?? ''));
                $link      = (string)($item->link ?? '');
                $itemTitle = (string)($item->title ?? '');
                $desc      = (string)($item->description ?? '');
                $ns        = $item->children('content', true);
                $content   = (string)($ns->encoded ?? $desc);
                $pubDate   = (string)($item->pubDate ?? ($item->children('dc', true)->date ?? ''));
                $rssAuthor = trim((string)($item->author ?? ''));
                $dcCreator = trim((string)($item->children('dc', true)->creator ?? ''));
                $rawAuthor = $rssAuthor ?: $dcCreator;
            }

            if (!$guid) $guid = $link;
            if (!$guid) continue;

            $pubTs = $pubDate ? date('Y-m-d H:i:s', strtotime($pubDate)) : null;

            $categories = [];
            foreach (($item->category ?? []) as $cat) $categories[] = (string)$cat;

            $parsed      = parseRssAuthor($rawAuthor);
            $authorEmail = $parsed['email'];

            // ── Try to get image from RSS item first ──────────
            $featuredImage = extractRssImage($item, $isAtom, $content, $desc);

            // ── Decide if content is thin (needs crawl) ───────
            $plainLength    = mb_strlen(strip_tags($content ?: $desc));
            $contentIsThin  = $plainLength < 500;

            // ── Crawl article page if enabled ─────────────────
            $crawledAt = null;
            if ($doCrawl && $link && ($contentIsThin || !$featuredImage)) {
                $crawled   = crawlArticleUrl($link);
                $crawledAt = date('Y-m-d H:i:s');
                $result['crawled']++;

                // Use crawled content if RSS was thin
                if ($contentIsThin && $crawled['content']) {
                    $content = $crawled['content'];
                }
                // Use crawled image if RSS had none
                if (!$featuredImage && $crawled['image']) {
                    $featuredImage = $crawled['image'];
                }

                // Small polite delay between page fetches
                usleep(300000); // 0.3s
            }

            // ── Author matching ───────────────────────────────
            $authorProfileId = matchAuthorProfile($subscriberId, $rawAuthor, $defaultAuthorId);
            if ($authorProfileId) $result['matched']++; else $result['unmatched']++;

            // ── Store in DB ───────────────────────────────────
            $exists = dbGet('SELECT id, crawled_at FROM rss_cache WHERE feed_id=? AND guid=?',
                            [$feed['id'], substr($guid, 0, 999)]);

            if (!$exists) {
                dbRun(
                    'INSERT INTO rss_cache
                       (feed_id, guid, title, link, description, content,
                        author, author_email, author_profile_id,
                        featured_image, crawled_at, pub_date, categories)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [
                        $feed['id'], substr($guid, 0, 999),
                        $itemTitle, $link,
                        $desc, $content,
                        $rawAuthor, $authorEmail, $authorProfileId,
                        $featuredImage ?: null,
                        $crawledAt,
                        $pubTs, json_encode($categories),
                    ]
                );
                $result['new']++;
            } else {
                // Update missing fields on existing rows
                dbRun(
                    'UPDATE rss_cache
                     SET author_profile_id = COALESCE(author_profile_id, ?),
                         author_email       = COALESCE(author_email, ?),
                         featured_image     = COALESCE(featured_image, ?),
                         content            = IF(? IS NOT NULL AND (content IS NULL OR content = "" OR content = description), ?, content),
                         crawled_at         = COALESCE(crawled_at, ?)
                     WHERE id = ?',
                    [
                        $authorProfileId, $authorEmail,
                        $featuredImage ?: null,
                        $content, $content,
                        $crawledAt,
                        $exists['id'],
                    ]
                );
            }

        } catch (Throwable $e) {
            $result['errors'][] = 'Item error: ' . $e->getMessage();
        }
    }

    dbRun('UPDATE rss_feeds SET status="active", last_fetched=NOW(), error_message=NULL WHERE id=?', [$feed['id']]);
    return $result;
}

// ── Run ───────────────────────────────────────────────────────
if (isset($_GET['run']) || $feedId) {
    $feeds = $feedId
        ? dbAll('SELECT * FROM rss_feeds WHERE id=? AND status != "paused"', [$feedId])
        : dbAll('SELECT * FROM rss_feeds WHERE status = "active"');

    foreach ($feeds as $feed) {
        $results[] = fetchAndCacheFeed($feed);
    }

    $totalNew     = array_sum(array_column($results, 'new'));
    $totalCrawled = array_sum(array_column($results, 'crawled'));
    logActivity('feeds_fetched',
        "Fetched " . count($feeds) . " feeds — {$totalNew} new, {$totalCrawled} pages crawled");
}

// ── Stats ─────────────────────────────────────────────────────
$cacheStats  = dbGet('SELECT COUNT(*) total, SUM(pushed_to_ghost) pushed FROM rss_cache');
$noAuthor    = dbGet('SELECT COUNT(*) c FROM rss_cache WHERE pushed_to_ghost=0 AND author_profile_id IS NULL')['c'] ?? 0;
$notCrawled  = dbGet('SELECT COUNT(*) c FROM rss_cache WHERE pushed_to_ghost=0 AND crawled_at IS NULL AND link IS NOT NULL')['c'] ?? 0;
$feedStats   = dbAll(
    'SELECT f.id, f.title, f.url, f.status, f.last_fetched, f.crawl_full_content,
            COUNT(rc.id) article_count,
            SUM(CASE WHEN rc.crawled_at IS NOT NULL THEN 1 ELSE 0 END) crawled_count
     FROM rss_feeds f
     LEFT JOIN rss_cache rc ON rc.feed_id = f.id
     GROUP BY f.id ORDER BY f.last_fetched DESC'
);

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="page-header">
  <div><h1>Fetch RSS Feeds</h1><p>Download, crawl, and cache articles from all active feeds</p></div>
  <div style="display:flex;gap:.5rem;">
    <a href="<?= BASE_URL ?>/feeds/fetch.php?run=1" class="btn btn-gold"
       onclick="return confirm('Fetch all active feeds now? This will also crawl article pages and may take a few minutes.')">
      ⟳ Fetch All Feeds Now
    </a>
  </div>
</div>

<?php if ($results): ?>
<div class="card">
  <div class="card-header"><span class="card-title">Fetch Results</span></div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Feed</th><th>New</th><th>Pages Crawled</th><th>Authors Matched</th><th>Unmatched</th><th>Errors</th></tr>
      </thead>
      <tbody>
      <?php foreach ($results as $r): ?>
        <tr>
          <td><?= e($r['feed']['title'] ?: $r['feed']['url']) ?></td>
          <td><strong style="color:var(--success);">+<?= $r['new'] ?></strong></td>
          <td style="color:var(--muted);">🌐 <?= $r['crawled'] ?></td>
          <td style="color:var(--success);">✓ <?= $r['matched'] ?></td>
          <td><?= $r['unmatched'] ? '<span style="color:var(--warn);">⚠ '.$r['unmatched'].'</span>' : '<span style="color:var(--muted);">—</span>' ?></td>
          <td style="color:var(--danger);"><?= $r['errors'] ? e(implode('; ', $r['errors'])) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);">
  <div class="stat-card">
    <div class="stat-icon gold">📰</div>
    <div><div class="stat-num"><?= number_format($cacheStats['total'] ?? 0) ?></div><div class="stat-label">Total Cached</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green">✓</div>
    <div><div class="stat-num"><?= number_format($cacheStats['pushed'] ?? 0) ?></div><div class="stat-label">Pushed to Ghost</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon blue">🌐</div>
    <div>
      <div class="stat-num" style="<?= $notCrawled ? 'color:var(--warn)' : '' ?>"><?= number_format($notCrawled) ?></div>
      <div class="stat-label">Not Yet Crawled</div>
      <?php if ($notCrawled): ?>
      <a href="<?= BASE_URL ?>/feeds/fetch.php?run=1" style="font-size:.72rem;color:var(--warn);">Re-fetch to crawl →</a>
      <?php endif; ?>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="background:#fef9c3;">⚠</div>
    <div>
      <div class="stat-num" style="<?= $noAuthor ? 'color:var(--warn)' : '' ?>"><?= number_format($noAuthor) ?></div>
      <div class="stat-label">No Author</div>
      <?php if ($noAuthor): ?>
      <a href="<?= BASE_URL ?>/feeds/cache.php?status=unpushed&no_author=1" style="font-size:.72rem;color:var(--warn);">Review →</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header"><span class="card-title">Feed Status</span></div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Feed</th><th>Status</th><th>Crawl</th><th>Cached</th><th>Crawled</th><th>Last Fetched</th><th>Action</th></tr>
      </thead>
      <tbody>
      <?php foreach ($feedStats as $f): ?>
        <tr>
          <td>
            <div style="font-weight:600;font-size:.85rem;"><?= e($f['title'] ?: 'Untitled') ?></div>
            <div style="font-size:.75rem;color:var(--muted);"><?= e(mb_strimwidth($f['url'],0,60,'…')) ?></div>
          </td>
          <td><span class="badge badge-<?= e($f['status']) ?>"><?= e(ucfirst($f['status'])) ?></span></td>
          <td>
            <?php if ($f['crawl_full_content']): ?>
              <span class="badge badge-active" title="Article pages will be crawled for full content">🌐 On</span>
            <?php else: ?>
              <span class="badge" style="background:#f1f5f9;color:var(--muted);" title="Using RSS content only">Off</span>
            <?php endif; ?>
          </td>
          <td style="text-align:center;"><?= (int)$f['article_count'] ?></td>
          <td style="text-align:center;font-size:.82rem;color:var(--muted);"><?= (int)$f['crawled_count'] ?> / <?= (int)$f['article_count'] ?></td>
          <td style="font-size:.78rem;color:var(--muted);"><?= $f['last_fetched'] ? e(date('d M y H:i', strtotime($f['last_fetched']))) : 'Never' ?></td>
          <td>
            <a href="?feed_id=<?= $f['id'] ?>&run=1" class="btn btn-sm btn-gold"
               onclick="return confirm('Fetch and crawl this feed now?')">Fetch</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
