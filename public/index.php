<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/share_buttons.php';

// Posts are loaded one page at a time with keyset (cursor) pagination: the next page starts right after the last post
// shown, so the database never skips over rows. It costs the same on page 1 and page 100000, and it does not need COUNT(*).
$perPage = 12;

// A cursor looks like "2026-11-14 10:30:00_57" (created_at + id of a post). Anything else is ignored.
function post_cursor(mixed $value): ?array
{
    if (!is_string($value) || !preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})_(\d{1,10})$/', $value, $m)) {
        return null;
    }

    $d = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $m[1]);

    return ($d && $d->format('Y-m-d H:i:s') === $m[1]) ? ['created_at' => $m[1], 'id' => (int) $m[2]] : null;
}

function cursor_of(array $post): string
{
    return $post['created_at'] . '_' . $post['id'];
}

$before = post_cursor($_GET['before'] ?? null);                  // ?before=... = older posts
$after  = $before ? null : post_cursor($_GET['after'] ?? null);  // ?after=...  = back to newer posts

// The cards only show a short excerpt, so we do not load the whole content of every post.
$columns = 'id, title, SUBSTRING(content, 1, 600) AS content, created_at';

if ($after) {
    $sql  = "SELECT $columns FROM posts WHERE created_at > :c1 OR (created_at = :c2 AND id > :i)
             ORDER BY created_at ASC, id ASC LIMIT :limit";
    $args = [':c1' => $after['created_at'], ':c2' => $after['created_at'], ':i' => $after['id']];
} elseif ($before) {
    $sql  = "SELECT $columns FROM posts WHERE created_at < :c1 OR (created_at = :c2 AND id < :i)
             ORDER BY created_at DESC, id DESC LIMIT :limit";
    $args = [':c1' => $before['created_at'], ':c2' => $before['created_at'], ':i' => $before['id']];
} else {
    $sql  = "SELECT $columns FROM posts ORDER BY created_at DESC, id DESC LIMIT :limit";
    $args = [];
}

$stmt = db()->prepare($sql);
foreach ($args as $name => $value) {
    $stmt->bindValue($name, $value, $name === ':i' ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $perPage + 1, PDO::PARAM_INT);   // one extra row tells us if there is another page
$stmt->execute();
$rows = $stmt->fetchAll();

$more  = count($rows) > $perPage;
$posts = array_slice($rows, 0, $perPage);
if ($after) {
    $posts = array_reverse($posts);   // that query ran oldest-first, so turn it back
}

// Back at the very first page: use the plain address.
if ($after && !$more) {
    header('Location: index.php');
    exit;
}

// Links: "Newer" is there when we came from an older page, "Older" when there are more posts after this page.
$newerUrl = ($posts && ($before || ($after && $more))) ? 'index.php?after=' . rawurlencode(cursor_of($posts[0])) : null;
$olderUrl = ($posts && ($after || $more)) ? 'index.php?before=' . rawurlencode(cursor_of($posts[count($posts) - 1])) : null;

$pageTitle = 'Home';
$pageDesc  = 'Latest posts from ' . SITE_NAME . '. Read and share them with your friends.';

require __DIR__ . '/../includes/header.php';
?>
<h1>Latest posts</h1>

<?php if (!$posts): ?>
    <p><?= $before ? 'No older posts. <a href="index.php">Back to the newest posts</a>' : 'No posts yet.' ?></p>
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

<?php if ($newerUrl || $olderUrl): ?>
    <nav class="pager" aria-label="Pages of posts">
        <?php if ($newerUrl): ?><a href="<?= e($newerUrl) ?>" rel="prev">&larr; Previous</a><?php endif; ?>
        <?php if ($olderUrl): ?><a href="<?= e($olderUrl) ?>" rel="next">Next &rarr;</a><?php endif; ?>
    </nav>
<?php endif; ?>

<?php
require __DIR__ . '/../includes/footer.php';
