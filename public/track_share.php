<?php
declare(strict_types=1);

/*
 * Receives a share-button click from js/share.js and stores it in `share_clicks`.
 * Nothing the browser sends is trusted. Every value is checked before it reaches the database.
 */

require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

// 1) Method
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, ['ok' => false, 'error' => 'Use POST.']);
}

// 2) Read the body (JSON from share.js, or a normal form post), max 8 KB
$raw   = file_get_contents('php://input', false, null, 0, 8192) ?: '';
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = $_POST;
}

// Both fields must be plain text (not arrays, numbers, null ...)
if (!is_string($input['platform'] ?? null) || !is_string($input['page_url'] ?? null)) {
    respond(422, ['ok' => false, 'error' => 'platform and page_url are required text fields.']);
}

$platform = strtolower(trim($input['platform']));
$pageUrl  = trim($input['page_url']);

// 3) platform: simple format, and it must exist in the social_platforms table
if (
    !preg_match('/^[a-z0-9_-]{1,50}$/', $platform)
    || !array_key_exists($platform, share_platforms())
) {
    respond(422, ['ok' => false, 'error' => 'Unknown platform.']);
}

// 4) page_url: a clean http(s) URL
if (
    $pageUrl === ''
    || strlen($pageUrl) > 2048
    || preg_match('/[\x00-\x20\x7f]/', $pageUrl)          // no spaces / control characters
    || !filter_var($pageUrl, FILTER_VALIDATE_URL)
) {
    respond(422, ['ok' => false, 'error' => 'Invalid page_url.']);
}

$parts = parse_url($pageUrl);
if (
    $parts === false
    || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
    || isset($parts['user']) || isset($parts['pass'])      // no user:pass@host tricks
) {
    respond(422, ['ok' => false, 'error' => 'Invalid page_url.']);
}

// 5) ... that belongs to THIS website
$ownHost = parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
if (SITE_URL !== '') {
    $ownHost = parse_url(SITE_URL, PHP_URL_HOST);
}
if (!$ownHost || strcasecmp((string) ($parts['host'] ?? ''), (string) $ownHost) !== 0) {
    respond(422, ['ok' => false, 'error' => 'page_url must belong to this site.']);
}

// Drop "#fragment" - it is never sent to the server and is not part of the page identity.
$pageUrl = explode('#', $pageUrl, 2)[0];

// 6) ... and be a real page: the home page, or an existing post
$path      = (string) ($parts['path'] ?? '');
$pageTitle = null;

try {
    if (str_ends_with($path, '/post.php')) {
        parse_str((string) ($parts['query'] ?? ''), $query);
        $postId = filter_var($query['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($postId === false) {
            respond(422, ['ok' => false, 'error' => 'Invalid post id.']);
        }

        // The title is looked up here on the server, so the visitor cannot fake it.
        $t = db()->prepare('SELECT title FROM posts WHERE id = ?');
        $t->execute([$postId]);
        $pageTitle = $t->fetchColumn();

        if ($pageTitle === false) {
            respond(422, ['ok' => false, 'error' => 'Unknown page.']);
        }
    } elseif (str_ends_with($path, '/index.php') || str_ends_with($path, '/')) {
        $pageTitle = 'Home';
    } else {
        respond(422, ['ok' => false, 'error' => 'Unknown page.']);
    }

    // 7) store it (prepared statement = safe from SQL injection)
    $stmt = db()->prepare(
        'INSERT INTO share_clicks (page_url, page_title, platform) VALUES (?, ?, ?)'
    );
    $stmt->execute([$pageUrl, $pageTitle, $platform]);
} catch (PDOException $ex) {
    error_log('share click insert failed: ' . $ex->getMessage());
    respond(500, ['ok' => false, 'error' => 'Could not save the click.']);
}

respond(201, ['ok' => true]);
