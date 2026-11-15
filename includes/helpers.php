<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

// Escape output for HTML (prevents XSS).
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// scheme://host (or SITE_URL if configured). 
function site_origin(): string
{
    if (SITE_URL !== '') {
        return rtrim(SITE_URL, '/');
    }

    $https  = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';

    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

// Full URL of the page currently being viewed (this is what gets shared).
function current_url(): string
{
    return site_origin() . ($_SERVER['REQUEST_URI'] ?? '/');
}

// Absolute URL to a file next to the running script, e.g. img/share-default.png
function asset_url(string $path): string
{
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

    return site_origin() . $dir . '/' . ltrim($path, '/');
}

// "Dave wasn't sure" -> "dave_wasn_t_sure" (lowercase letters, digits and underscores only).
function slugify(string $text, int $max = 80): string
{
    $text = mb_strtolower(trim($text), 'UTF-8');

    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);   // e -> e, etc.
        if ($ascii !== false) {
            $text = $ascii;
        }
    }

    $text = trim((string) preg_replace('/[^a-z0-9]+/', '_', $text), '_');
    $text = trim(substr($text, 0, $max), '_');

    return $text !== '' ? $text : 'post';
}

// Path of a post page, e.g. post.php?id=30&title=she_was_in_a_hurry
function post_path(int $id, string $title): string
{
    return 'post.php?id=' . $id . '&title=' . slugify($title);
}

/**
 * Social media platforms from the `social_platforms` table: slug => row,
 * in sort_order. These are the share buttons shown on the site.
 */
function share_platforms(): array
{
    static $cache = null;

    if ($cache === null) {
        $cache = [];
        $sql = 'SELECT slug, name, share_url_template, icon_svg, color, opens_new_tab
                FROM social_platforms ORDER BY sort_order, id';

        foreach (db()->query($sql) as $row) {
            $cache[$row['slug']] = $row;
        }
    }

    return $cache;
}

/**
 * Shows a plain "something went wrong" page instead of a PHP error, which could reveal database details
 * (host, database name, file paths). The real error is written to the server's error log.
 * Used when a database call fails (see the try / catch blocks in the pages).
 */
function show_error_page(Throwable $ex, string $where, int $status = 500): never
{
    error_log($where . ' failed: ' . get_class($ex) . ': ' . $ex->getMessage());

    if (!headers_sent()) {
        http_response_code($status);
        header('Cache-Control: no-store');
        header('Content-Type: text/html; charset=utf-8');
    }

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta name="robots" content="noindex, nofollow"><title>Something went wrong</title>'
       . '<style>body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;max-width:32rem;margin:15vh auto;padding:0 1rem;color:#222}</style>'
       . '</head><body><h1>Something went wrong</h1>'
       . '<p>The page could not be loaded. Please try again in a moment.</p>'
       . '<p><a href="' . e($_SERVER['REQUEST_URI'] ?? '') . '">Try again</a></p></body></html>';
    exit;
}

// Safety net: an error that no try / catch caught is logged and shown as the plain page above
// (or as JSON for the endpoints that answer in JSON), never as a PHP error with file paths or passwords.
set_exception_handler(static function (Throwable $ex): void {
    foreach (headers_list() as $header) {
        if (stripos($header, 'content-type: application/json') === 0) {
            error_log('uncaught ' . get_class($ex) . ': ' . $ex->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Server error.']);

            return;
        }
    }

    show_error_page($ex, 'uncaught error');
});
