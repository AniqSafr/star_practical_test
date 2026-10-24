<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/share_buttons.php';

$posts = db()->query(
    'SELECT id, title, content, created_at FROM posts ORDER BY created_at DESC, id DESC'
)->fetchAll();

$pageTitle = 'Home';
$pageDesc  = 'Latest posts from ' . SITE_NAME . '. Read and share them with your friends.';

require __DIR__ . '/../includes/header.php';
?>
<h1>Latest posts</h1>

<?php if (!$posts): ?>
    <p>No posts yet.</p>
<?php endif; ?>

<div class="cards">
    <?php foreach ($posts as $post): ?>
        <?php
        $postPath = post_path((int) $post['id'], $post['title']);   // post.php?id=30&title=...
        $postUrl  = asset_url($postPath);                           // full URL (used for sharing)
        $excerpt = mb_strimwidth(trim(preg_replace('/\s+/', ' ', strip_tags($post['content']))), 0, 140, '...');
        ?>
        <article class="card">
            <h2><a href="<?= e($postPath) ?>"><?= e($post['title']) ?></a></h2>
            <p class="muted"><?= e(date('j M Y', strtotime($post['created_at']))) ?></p>
            <p><?= e($excerpt) ?></p>

            <div class="card-foot">
                <a class="read-more" href="<?= e($postPath) ?>">Read more &rarr;</a>
                <?php render_share_buttons($postUrl, $post['title'], $excerpt, 'share-sm'); ?>
            </div>
        </article>
    <?php endforeach; ?>
</div>

<?php
require __DIR__ . '/../includes/footer.php';
