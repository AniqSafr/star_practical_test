<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/share_buttons.php';

$id  = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);   // a whole number, 1 or more
$post = false;

try {
    if ($id) {
        $stmt = db()->prepare('SELECT id, title, content, created_at FROM posts WHERE id = ?');
        $stmt->execute([$id]);
        $post = $stmt->fetch();
    }

    if ($post) {
        share_platforms();   // loaded now (and remembered), so a database problem shows the error page, not half a page
    }
} catch (PDOException $ex) {
    show_error_page($ex, 'post page');
}

if (!$post) {
    http_response_code(404);
    $pageTitle = 'Post not found';
    $pageDesc  = 'The post you are looking for does not exist.';
    require __DIR__ . '/../includes/header.php';
    echo '<h1>Post not found</h1><p><a href="index.php">&larr; Back to home</a></p>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

// Canonical address of this post: post.php?id=30&title=the_title_as_slug
// If someone opens post.php?id=30 (or a wrong title), send them to the canonical address,
// so the shared / tracked URL always contains the title.
$pageUrl = asset_url(post_path((int) $post['id'], $post['title']));
if (($_GET['title'] ?? null) !== slugify($post['title'])) {
    header('Location: ' . $pageUrl, true, 301);
    exit;
}

// Metadata for this specific post (used by the OG tags and the share links).
$pageTitle = $post['title'];
$pageDesc  = mb_strimwidth(trim(preg_replace('/\s+/', ' ', strip_tags($post['content']))), 0, 160, '...');

// Where "Back to all posts" goes: the page of the list the visitor came from
$backUrl  = list_back_url();
$fromList = $backUrl !== null;
$backUrl  ??= 'index.php';

require __DIR__ . '/../includes/header.php';
?>
<article class="post">
    <h1><?= e($post['title']) ?></h1>
    <?php render_share_buttons($pageUrl, $pageTitle, $pageDesc, 'share-title-row'); ?>
    <p class="muted">Published <?= e(date('j M Y', strtotime($post['created_at']))) ?></p>
    <div class="post-body"><?= nl2br(e($post['content'])) ?></div>
</article>

<p><a id="back-link" href="<?= e($backUrl) ?>">&larr; Back to all posts</a></p>
<?php if ($fromList): ?>
<script>
// Came from the list: go back in the browser history, so the list shows the same page at the same scroll position.
// (Without JavaScript, or in a new tab, the link above still opens the right page of the list.)
document.getElementById('back-link').addEventListener('click', function (ev) {
  if (history.length > 1) { ev.preventDefault(); history.back(); }
});
</script>
<?php endif; ?>
<?php
require __DIR__ . '/../includes/footer.php';
