<?php
// ============================================================
// includes/ghost_helper.php — Ghost Admin API shared helpers
// ============================================================
if (!defined('BASE_PATH')) exit;

// ── JWT + HTTP ────────────────────────────────────────────────

function ghostJWT(string $adminApiKey): string {
    [$id, $secret] = explode(':', $adminApiKey, 2);
    $header  = ghost_b64u(json_encode(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => $id]));
    $now     = time();
    $payload = ghost_b64u(json_encode(['iat' => $now, 'exp' => $now + 300, 'aud' => '/admin/']));
    $sig     = ghost_b64u(hash_hmac('sha256', "$header.$payload", hex2bin($secret), true));
    return "$header.$payload.$sig";
}

function ghost_b64u(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Scrub a string to valid UTF-8 so json_encode never silently returns false.
 * Strips null bytes and control characters that are illegal in JSON strings.
 */
function ghost_sanitizeString(string $s): string {
    // Force to valid UTF-8 (replaces invalid sequences with U+FFFD)
    $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    // Strip control characters illegal in JSON (except tab, newline, carriage return)
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
    return $s ?? '';
}

/**
 * JSON-encode an array, scrubbing any strings that would break encoding.
 * Returns the JSON string, or throws a RuntimeException on failure.
 */
function ghost_jsonEncode(array $data): string {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($json === false && json_last_error() !== JSON_ERROR_NONE) {
        // Walk the structure and scrub every string value, then retry
        $data = ghost_scrubArray($data);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    if ($json === false) {
        throw new \RuntimeException('json_encode failed: ' . json_last_error_msg());
    }

    return $json;
}

function ghost_scrubArray(mixed $v): mixed {
    if (is_string($v)) return ghost_sanitizeString($v);
    if (is_array($v))  return array_map('ghost_scrubArray', $v);
    return $v;
}

/**
 * JSON request to the Ghost Admin API.
 * Returns ['code' => int, 'body' => array|null, 'error' => string]
 */
function ghostRequest(array $conf, string $method, string $path, ?array $body = null): array {
    $jwt = ghostJWT($conf['admin_api_key']);
    $url = rtrim($conf['ghost_url'], '/') . '/ghost/api/admin/' . ltrim($path, '/');

    $opts = ['http' => [
        'method'        => $method,
        'header'        => implode("\r\n", [
            "Authorization: Ghost {$jwt}",
            'Content-Type: application/json',
            'Accept-Version: v5.0',
        ]),
        'timeout'       => 30,
        'ignore_errors' => true,
    ]];

    if ($body !== null) {
        try {
            $opts['http']['content'] = ghost_jsonEncode($body);
        } catch (\RuntimeException $e) {
            return ['code' => 0, 'body' => null, 'error' => 'JSON encode failed: ' . $e->getMessage()];
        }
    }

    $ctx      = stream_context_create($opts);
    $response = @file_get_contents($url, false, $ctx);
    $code     = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('/HTTP\/[\d.]+ (\d+)/', $h, $m)) $code = (int)$m[1];
    }
    return ['code' => $code, 'body' => $response ? json_decode($response, true) : null, 'error' => ''];
}

// ── Image upload ──────────────────────────────────────────────

/**
 * Upload an image to Ghost via multipart/form-data.
 * $imageSource : local absolute path OR a remote http(s) URL.
 * $purpose     : 'image' | 'profile_image' | 'icon'
 * Returns the hosted Ghost image URL, or null on failure.
 */
function ghostUploadImage(array $conf, string $imageSource, string $purpose = 'image'): ?string {
    $tempFile  = null;
    $localPath = $imageSource;

    if (str_starts_with($imageSource, 'http://') || str_starts_with($imageSource, 'https://')) {
        $ctx  = stream_context_create(['http' => [
            'timeout'         => 20,
            'user_agent'      => 'UK Legal Articles Portal/1.0',
            'follow_location' => true,
            'ignore_errors'   => true,
        ]]);
        $data = @file_get_contents($imageSource, false, $ctx);
        if (!$data || strlen($data) < 100) return null;

        $ext      = ghost_guessExt($imageSource);
        $tempFile = sys_get_temp_dir() . '/ghost_img_' . bin2hex(random_bytes(8)) . '.' . $ext;
        file_put_contents($tempFile, $data);
        $localPath = $tempFile;
    }

    if (!file_exists($localPath) || !is_readable($localPath)) {
        if ($tempFile) @unlink($tempFile);
        return null;
    }

    $mime     = mime_content_type($localPath) ?: 'image/jpeg';
    $filename = basename($localPath);
    $boundary = '----GhostUpload' . bin2hex(random_bytes(8));
    $fileData = file_get_contents($localPath);
    if ($tempFile) @unlink($tempFile);

    $body  = "--{$boundary}\r\n";
    $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
    $body .= "Content-Type: {$mime}\r\n\r\n";
    $body .= $fileData . "\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Disposition: form-data; name=\"purpose\"\r\n\r\n";
    $body .= "{$purpose}\r\n";
    $body .= "--{$boundary}--\r\n";

    $jwt = ghostJWT($conf['admin_api_key']);
    $url = rtrim($conf['ghost_url'], '/') . '/ghost/api/admin/images/upload/';

    $ctx      = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => implode("\r\n", [
            "Authorization: Ghost {$jwt}",
            "Content-Type: multipart/form-data; boundary={$boundary}",
            'Accept-Version: v5.0',
        ]),
        'content'       => $body,
        'timeout'       => 45,
        'ignore_errors' => true,
    ]]);

    $response = @file_get_contents($url, false, $ctx);
    $code     = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('/HTTP\/[\d.]+ (\d+)/', $h, $m)) $code = (int)$m[1];
    }

    if (in_array($code, [200, 201]) && $response) {
        $data = json_decode($response, true);
        return $data['images'][0]['url'] ?? null;
    }
    return null;
}

function ghost_guessExt(string $url): string {
    if (preg_match('/\.(jpe?g|png|gif|webp|svg)/i', $url, $m)) return strtolower($m[1]);
    return 'jpg';
}

// ── Content helpers ───────────────────────────────────────────

/**
 * Generate a clean plain-text excerpt from HTML, respecting sentence boundaries.
 */
function generateExcerpt(string $html, int $maxChars = 300): string {
    if (!$html) return '';
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', trim($text));
    if (mb_strlen($text) <= $maxChars) return $text;

    $truncated    = mb_substr($text, 0, $maxChars);
    $lastSentence = 0;
    foreach (['. ', '! ', '? ', '." ', '!" ', '?" '] as $marker) {
        $pos = mb_strrpos($truncated, $marker);
        if ($pos !== false) {
            $end = $pos + mb_strlen($marker) - 1;
            if ($end > $lastSentence) $lastSentence = $end;
        }
    }
    if ($lastSentence > (int)($maxChars * 0.4)) {
        return rtrim(mb_substr($text, 0, $lastSentence));
    }
    $lastSpace = mb_strrpos($truncated, ' ');
    return rtrim(mb_substr($text, 0, $lastSpace ?: $maxChars)) . '…';
}

// ── Author management ─────────────────────────────────────────

/**
 * Ensure a Ghost author exists for the given author_profiles row.
 * Creates if missing, uploads profile photo, stores Ghost IDs back.
 * Returns Ghost user ID string, or null on failure.
 */
function ensureGhostAuthor(array $conf, array $author): ?string {
    $slug  = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($author['name'])), '-');
    $email = !empty($author['email'])
        ? $author['email']
        : $slug . '@authors.legalportal.local';

    $ghostId      = null;
    $ghostUpdAt   = null;
    $currentPhoto = null;

    // ── Find existing Ghost user ──────────────────────────────
    if (!empty($author['ghost_author_id'])) {
        $res = ghostRequest($conf, 'GET', "users/{$author['ghost_author_id']}/?include=roles");
        if ($res['code'] === 200 && !empty($res['body']['users'][0])) {
            $u = $res['body']['users'][0];
            $ghostId = $u['id']; $ghostUpdAt = $u['updated_at'] ?? null; $currentPhoto = $u['profile_image'] ?? null;
        }
    }
    if (!$ghostId) {
        $res = ghostRequest($conf, 'GET', "users/slug/{$slug}/?include=roles");
        if ($res['code'] === 200 && !empty($res['body']['users'][0]['id'])) {
            $u = $res['body']['users'][0];
            $ghostId = $u['id']; $ghostUpdAt = $u['updated_at'] ?? null; $currentPhoto = $u['profile_image'] ?? null;
        }
    }
    if (!$ghostId) {
        $res = ghostRequest($conf, 'GET', "users/email/{$email}/");
        if ($res['code'] === 200 && !empty($res['body']['users'][0]['id'])) {
            $u = $res['body']['users'][0];
            $ghostId = $u['id']; $ghostUpdAt = $u['updated_at'] ?? null; $currentPhoto = $u['profile_image'] ?? null;
        }
    }

    // ── Profile photo ─────────────────────────────────────────
    $profileImageUrl = $currentPhoto;
    if (!$currentPhoto && !empty($author['photo_path'])) {
        $localPath = BASE_PATH . '/uploads/author-photos/' . basename($author['photo_path']);
        $uploaded  = ghostUploadImage($conf, $localPath, 'profile_image');
        if ($uploaded) $profileImageUrl = $uploaded;
    }

    // ── Create author if not found ────────────────────────────
    if (!$ghostId) {
        $payload = [
            'name'     => ghost_sanitizeString($author['name']),
            'slug'     => $slug,
            'email'    => $email,
            'password' => bin2hex(random_bytes(16)),
            'roles'    => [['name' => 'Contributor']],
            'bio'      => ghost_sanitizeString($author['bio'] ?? ''),
            'website'  => $author['official_profile_url'] ?? '',
        ];
        if ($profileImageUrl) $payload['profile_image'] = $profileImageUrl;

        $res = ghostRequest($conf, 'POST', 'users/', ['users' => [$payload]]);
        if (in_array($res['code'], [200, 201]) && !empty($res['body']['users'][0]['id'])) {
            $u = $res['body']['users'][0];
            $ghostId = $u['id'];
            dbRun('UPDATE author_profiles SET ghost_author_id=?, ghost_author_slug=? WHERE id=?',
                  [$ghostId, $u['slug'] ?? $slug, $author['id']]);
        } else {
            return null;
        }
    } else {
        dbRun('UPDATE author_profiles SET ghost_author_id=?, ghost_author_slug=? WHERE id=? AND ghost_author_id IS NULL',
              [$ghostId, $slug, $author['id']]);
        // Patch profile photo onto existing author if they lack one
        if ($profileImageUrl && !$currentPhoto && $ghostUpdAt) {
            ghostRequest($conf, 'PUT', "users/{$ghostId}/", ['users' => [[
                'id'            => $ghostId,
                'profile_image' => $profileImageUrl,
                'updated_at'    => $ghostUpdAt,
            ]]]);
        }
    }

    return $ghostId;
}

// ── Post push ─────────────────────────────────────────────────

/**
 * Push a single cached article row to Ghost CMS as a published post.
 * Returns ['success' => bool, 'ghost_post_id' => string|null, 'error' => string]
 */
function pushArticleToGhost(array $conf, array $article, ?array $author): array {
    $result = ['success' => false, 'ghost_post_id' => null, 'error' => ''];

    // ── Author ────────────────────────────────────────────────
    $authors = [];
    if ($author) {
        $gid = ensureGhostAuthor($conf, $author);
        if ($gid) $authors = [['id' => $gid]];
    }

    // ── Content ───────────────────────────────────────────────
    $rawContent = $article['content'] ?: ($article['description'] ?: '');
    if (empty(strip_tags($rawContent))) {
        $rawContent = '<p>' . htmlspecialchars($article['title'] ?? 'Article') . '</p>';
    }

    // ── Excerpt ───────────────────────────────────────────────
    $excerpt = generateExcerpt($rawContent, 300);
    if (!$excerpt && !empty($article['description'])) {
        $excerpt = generateExcerpt($article['description'], 300);
    }

    // ── Featured image ────────────────────────────────────────
    $featureImageUrl = null;
    if (!empty($article['featured_image'])) {
        $uploaded        = ghostUploadImage($conf, $article['featured_image'], 'image');
        $featureImageUrl = $uploaded ?: $article['featured_image'];
    }

    // ── Tags ──────────────────────────────────────────────────
    $tags = [['name' => 'UK Legal Articles']];
    $cats = json_decode($article['categories'] ?? '[]', true) ?: [];
    foreach (array_slice($cats, 0, 5) as $c) {
        $tags[] = ['name' => ghost_sanitizeString((string)$c)];
    }

    // ── Post payload ──────────────────────────────────────────
    // Ghost Admin API requires:
    //   - content in the `html` field inside the post object
    //   - `"source": "html"` at the ROOT of the request body (not inside the post)
    // Without source:"html" at the root, Ghost ignores the html field entirely
    // and stores only the title. This is documented Ghost API behaviour.
    $post = [
        'title'         => ghost_sanitizeString($article['title'] ?? 'Untitled'),
        'html'          => ghost_sanitizeString($rawContent),
        'status'        => 'published',
        'published_at'  => $article['pub_date']
                              ? date('c', strtotime($article['pub_date']))
                              : date('c'),
        'canonical_url' => $article['link'] ?? '',
        'tags'          => $tags,
    ];
    if ($excerpt)         $post['custom_excerpt'] = ghost_sanitizeString($excerpt);
    if ($authors)         $post['authors']        = $authors;
    if ($featureImageUrl) $post['feature_image']  = $featureImageUrl;

    // ── Send ──────────────────────────────────────────────────
    // Ghost requires source=html as a URL query parameter — NOT a body key.
    // Without it in the URL, Ghost silently ignores the html field and only saves the title.
    try {
        $res = ghostRequest($conf, 'POST', 'posts/?source=html', [
            'posts' => [$post],
        ]);
    } catch (\Throwable $e) {
        $result['error'] = 'Request failed: ' . $e->getMessage();
        return $result;
    }

    if (in_array($res['code'], [200, 201]) && !empty($res['body']['posts'][0]['id'])) {
        $result['success']       = true;
        $result['ghost_post_id'] = $res['body']['posts'][0]['id'];
    } else {
        $messages = [];
        foreach ($res['body']['errors'] ?? [] as $e) {
            $messages[] = ($e['message'] ?? '') . (isset($e['context']) ? ': ' . $e['context'] : '');
        }
        if (!$messages && isset($res['error']) && $res['error']) {
            $messages[] = $res['error'];
        }
        $result['error'] = $messages ? implode('; ', $messages) : "HTTP {$res['code']} — no error detail returned";
    }

    return $result;
}