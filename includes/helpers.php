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
