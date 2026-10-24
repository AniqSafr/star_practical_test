<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';


 // -Share buttons (icons only), built from the `social_platforms` table.
 // -To add or remove a social network, change that table.
 
 /* "Metadata" (title, description, image) comes from the Open Graph tags in header.php,
     which Facebook / WhatsApp / Telegram read when they fetch the shared URL.   */


// Fill the placeholders of a platform's share_url_template (values are URL-encoded).
function share_build_url(string $template, string $url, string $title, string $desc): string
{
    return strtr($template, [
        '{url}'       => rawurlencode($url),
        '{title}'     => rawurlencode($title),
        '{desc}'      => rawurlencode($desc),
        '{title_url}' => rawurlencode($title . ' ' . $url),
        '{desc_url}'  => rawurlencode($desc . "\n\n" . $url),
    ]);
}

// One row of icon buttons (one per platform) that share $url.
function render_share_buttons(string $url, string $title, string $desc, string $class = ''): void
{
    echo '<div class="share-buttons ' . e($class) . '" data-share-url="' . e($url) . '" data-track-url="track_share.php">';

    foreach (share_platforms() as $slug => $p) {
        $href = share_build_url($p['share_url_template'], $url, $title, $desc);

        // Only normal web / mail links are allowed (blocks things like javascript:)
        if (!preg_match('~^(https?:|mailto:|sms:)~i', $href)) {
            continue;
        }

        $text  = 'Share via ' . $p['name'];
        $style = preg_match('/^#[0-9a-f]{3,8}$/i', (string) $p['color'])
            ? ' style="background:' . $p['color'] . '"'
            : '';
        $tab   = (int) $p['opens_new_tab'] === 1 ? ' target="_blank" rel="noopener noreferrer"' : '';

        // icon_svg is trusted markup that only an admin can put in the database.
        echo '<a class="share-btn share-' . e($slug) . '"' . $style
           . ' href="' . e($href) . '"'
           . ' data-platform="' . e($slug) . '"'
           . ' title="' . e($text) . '" aria-label="' . e($text) . '"' . $tab . '>'
           . $p['icon_svg']
           . '</a>';
    }

    echo '</div>';
}
