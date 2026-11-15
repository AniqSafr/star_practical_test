<?php
declare(strict_types=1);

// Data for the admin dashboard: reads the filters from the URL and returns the numbers the charts and tables need.
//
// Speed with millions of clicks: the charts and numbers are read from `share_clicks_daily` (one row per day, page and
// platform), not from `share_clicks` (one row per click). A page is identified by its post id (0 = home page), never by
// its URL or title. Only "Latest clicks" reads `share_clicks`, and only its newest 50 rows.

require_once __DIR__ . '/helpers.php';

// Chart colours, in a fixed order. A platform keeps its colour by its position in social_platforms.
const STATS_PALETTE     = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'];
const STATS_OTHER_COLOR = '#9b9a93';

function stats_parse_date(mixed $value): ?DateTimeImmutable
{
    if (!is_string($value)) {
        return null;
    }

    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    return ($d && $d->format('Y-m-d') === $value) ? $d : null;
}

// Name of the quick range that matches these dates ('' = custom).
function stats_preset_of(DateTimeImmutable $from, DateTimeImmutable $to): string
{
    $today = new DateTimeImmutable('today');
    if ($to != $today) {
        return '';
    }

    foreach (['7' => '-6 days', '30' => '-29 days', '90' => '-89 days', 'month' => 'first day of this month'] as $name => $mod) {
        if ($from == $today->modify($mod)) {
            return (string) $name;   // array keys like '7' become integers, so convert back
        }
    }

    return '';
}

// Read and clean the filters from $_GET.
function stats_filters(array $get): array
{
    $today = new DateTimeImmutable('today');
    $from  = stats_parse_date($get['from'] ?? null);
    $to    = stats_parse_date($get['to'] ?? null);
    $range = is_string($get['range'] ?? null) ? $get['range'] : '';

    if (!$from && !$to) {
        // quick range (default: last 30 days)
        $range = in_array($range, ['7', '30', '90', 'month', 'all'], true) ? $range : '30';
        $to    = $today;

        if ($range === 'all') {
            $min  = db()->query('SELECT MIN(click_date) FROM share_clicks_daily')->fetchColumn();
            $from = $min ? (new DateTimeImmutable((string) $min))->setTime(0, 0) : $today->modify('-29 days');
        } else {
            $from = $today->modify(['7' => '-6 days', '30' => '-29 days', '90' => '-89 days', 'month' => 'first day of this month'][$range]);
        }
    } else {
        $to   = $to ?? $today;
        $from = $from ?? $to->modify('-29 days');
        $range = '';
    }

    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    if ($from < $to->modify('-3650 days')) {
        $from = $to->modify('-3650 days');
    }

    // platforms: only known slugs; none selected (or all selected) = no filter
    $known     = array_keys(share_platforms());
    $platforms = [];
    foreach ((array) ($get['platforms'] ?? []) as $slug) {
        if (is_string($slug) && preg_match('/^[a-z0-9_-]{1,50}$/', $slug) && in_array($slug, $known, true)) {
            $platforms[$slug] = $slug;
        }
    }
    $platforms = array_values($platforms);
    if (count($platforms) === count($known)) {
        $platforms = [];
    }

    // page: '' = all pages, 'home' = the home page, or the id of a post
    $page   = is_string($get['page'] ?? null) ? $get['page'] : '';
    $postId = null;
    if ($page === 'home') {
        $postId = 0;
    } elseif (preg_match('/^[1-9][0-9]{0,9}$/', $page) && (int) $page <= 4294967295) {
        $postId = (int) $page;
    } else {
        $page = '';
    }

    $group = is_string($get['group'] ?? null) && in_array($get['group'], ['day', 'week', 'month'], true)
        ? $get['group'] : 'day';

    // too many bars is unreadable: switch to a bigger step and tell the user
    $days = (int) $from->diff($to)->days + 1;
    $note = '';
    if ($group === 'day' && $days > 92) {
        $group = 'week';
        $note  = 'The date range is long, so the chart is grouped by week.';
    }
    if ($group === 'week' && $days > 730) {
        $group = 'month';
        $note  = 'The date range is long, so the chart is grouped by month.';
    }

    return [
        'from'      => $from,
        'to'        => $to,
        'preset'    => $range !== '' ? $range : stats_preset_of($from, $to),
        'platforms' => $platforms,
        'page'      => $page,       // the value of the Page box: '', 'home' or a post id
        'post_id'   => $postId,     // null = all pages, 0 = home page
        'group'     => $group,
        'note'      => $note,
    ];
}

// SQL WHERE (without the word WHERE) + parameters for the filters, for the daily summary table.
function stats_where_daily(array $f): array
{
    $sql    = 'click_date >= ? AND click_date <= ?';
    $params = [$f['from']->format('Y-m-d'), $f['to']->format('Y-m-d')];

    if ($f['platforms']) {
        $sql   .= ' AND platform IN (' . implode(',', array_fill(0, count($f['platforms']), '?')) . ')';
        $params = array_merge($params, $f['platforms']);
    }

    if ($f['post_id'] !== null) {
        $sql     .= ' AND post_id = ?';
        $params[] = $f['post_id'];
    }

    return [$sql, $params];
}

// The same filters for the click rows (only used by "Latest clicks").
function stats_where_clicks(array $f): array
{
    $sql    = 'clicked_at >= ? AND clicked_at < ?';
    $params = [
        $f['from']->format('Y-m-d') . ' 00:00:00',
        $f['to']->modify('+1 day')->format('Y-m-d') . ' 00:00:00',
    ];

    if ($f['platforms']) {
        $sql   .= ' AND platform IN (' . implode(',', array_fill(0, count($f['platforms']), '?')) . ')';
        $params = array_merge($params, $f['platforms']);
    }

    if ($f['post_id'] !== null) {
        $sql     .= ' AND post_id = ?';
        $params[] = $f['post_id'];
    }

    return [$sql, $params];
}

// slug => name / colour. The 9th platform onwards share one grey "Other" series.
function stats_series_meta(): array
{
    $meta = [];
    $i    = 0;

    foreach (share_platforms() as $slug => $p) {
        if ($i < count(STATS_PALETTE)) {
            $meta[$slug] = ['name' => $p['name'], 'color' => STATS_PALETTE[$i]];
        }
        $i++;
    }

    return $meta;
}

function stats_bucket_start(DateTimeImmutable $d, string $group): DateTimeImmutable
{
    return match ($group) {
        'week'  => $d->modify('monday this week'),
        'month' => $d->modify('first day of this month'),
        default => $d,
    };
}

// The bars on the time axis: start date => ['label', 'title']
function stats_buckets(DateTimeImmutable $from, DateTimeImmutable $to, string $group): array
{
    $out = [];
    $cur = stats_bucket_start($from, $group);

    while ($cur <= $to) {
        $out[$cur->format('Y-m-d')] = match ($group) {
            'week'  => ['label' => $cur->format('j M'), 'title' => 'Week of ' . $cur->format('j M Y')],
            'month' => ['label' => $cur->format('M Y'), 'title' => $cur->format('F Y')],
            default => ['label' => $cur->format('j M'), 'title' => $cur->format('D, j M Y')],
        };

        $cur = match ($group) {
            'week'  => $cur->modify('+1 week'),
            'month' => $cur->modify('first day of next month'),
            default => $cur->modify('+1 day'),
        };
    }

    return $out;
}

// Run the queries and shape the results.
function stats_collect(array $f): array
{
    $pdo = db();
    [$where, $params] = stats_where_daily($f);

    $meta  = stats_series_meta();
    $keyOf = static fn (string $slug): string => isset($meta[$slug]) ? $slug : 'other';

    // 1) clicks per day and platform, from the daily summary table.
    // The totals per platform and the "clicks over time" chart are both worked out from these rows.
    $buckets  = stats_buckets($f['from'], $f['to'], $f['group']);
    $totals   = [];
    $timeline = [];
    $st = $pdo->prepare(
        "SELECT click_date, platform, SUM(clicks) AS c FROM share_clicks_daily WHERE $where GROUP BY click_date, platform"
    );
    $st->execute($params);
    foreach ($st->fetchAll() as $r) {
        $k = $keyOf((string) $r['platform']);
        $n = (int) $r['c'];

        $totals[$k] = ($totals[$k] ?? 0) + $n;

        $bucket = stats_bucket_start(new DateTimeImmutable((string) $r['click_date']), $f['group'])->format('Y-m-d');
        if (isset($buckets[$bucket])) {
            $timeline[$k][$bucket] = ($timeline[$k][$bucket] ?? 0) + $n;
        }
    }

    // series that actually have clicks, in a fixed order (platform order, then "Other")
    $series = [];
    foreach ($meta as $slug => $m) {
        if (!empty($totals[$slug])) {
            $series[$slug] = $m;
        }
    }
    if (!empty($totals['other'])) {
        $series['other'] = ['name' => 'Other', 'color' => STATS_OTHER_COLOR];
    }

    // 2) most shared pages (top 10), split by platform.
    // The database picks the top 10 post ids first, so only 10 pages are loaded however many pages have clicks.
    $st = $pdo->prepare(
        "SELECT post_id, SUM(clicks) AS c FROM share_clicks_daily
         WHERE $where GROUP BY post_id ORDER BY c DESC, post_id LIMIT 10"
    );
    $st->execute($params);
    $top = [];     // post id => ['total', 'by', 'url', 'title'], in ranking order
    foreach ($st->fetchAll() as $r) {
        $top[(int) $r['post_id']] = ['total' => (int) $r['c'], 'by' => [], 'url' => '', 'title' => ''];
    }

    if ($top) {
        $ids = array_keys($top);
        $in  = implode(',', array_fill(0, count($ids), '?'));

        // the platform split, only for those pages
        $st = $pdo->prepare(
            "SELECT post_id, platform, SUM(clicks) AS c FROM share_clicks_daily
             WHERE $where AND post_id IN ($in) GROUP BY post_id, platform"
        );
        $st->execute(array_merge($params, $ids));
        foreach ($st->fetchAll() as $r) {
            $k  = $keyOf((string) $r['platform']);
            $id = (int) $r['post_id'];
            $top[$id]['by'][$k] = ($top[$id]['by'][$k] ?? 0) + (int) $r['c'];
        }

        // the URL and title to show: taken from the newest click of each of those pages (one index lookup per page)
        $newest = '(SELECT post_id, page_url, page_title FROM share_clicks WHERE post_id = ? ORDER BY clicked_at DESC, id DESC LIMIT 1)';
        $st = $pdo->prepare(implode(' UNION ALL ', array_fill(0, count($ids), $newest)));
        $st->execute($ids);
        foreach ($st->fetchAll() as $r) {
            $id = (int) $r['post_id'];
            $top[$id]['url']   = (string) $r['page_url'];
            $top[$id]['title'] = trim((string) ($r['page_title'] ?? ''));
        }
    }

    $pages = [];
    foreach ($top as $id => $p) {
        $pages[] = [
            'url'   => $p['url'],
            'title' => $p['title'] !== '' ? $p['title'] : ($id === 0 ? 'Home' : 'Post #' . $id),
            'total' => $p['total'],
            'by'    => $p['by'],
        ];
    }

    // 3) latest clicks: the only query on the click rows. It is ordered by clicked_at, so the database reads just
    // the newest rows of the date range from an index.
    [$cwhere, $cparams] = stats_where_clicks($f);
    $st = $pdo->prepare(
        "SELECT page_url, page_title, platform, clicked_at
         FROM share_clicks WHERE $cwhere ORDER BY clicked_at DESC, id DESC LIMIT 50"
    );
    $st->execute($cparams);
    $recent = $st->fetchAll();

    return [
        'total'    => array_sum($totals),
        'totals'   => $totals,
        'series'   => $series,
        'buckets'  => $buckets,
        'timeline' => $timeline,
        'pages'    => $pages,
        'recent'   => $recent,
    ];
}

// Letters and digits of the typed text (3 or more characters each, at most 5 words). Nothing else gets through,
// so a typed + - * " ( ) is never read as a search operator.
function stats_search_words(string $q): array
{
    preg_match_all('/[\p{L}\p{N}]{3,}/u', $q, $m);

    return array_slice(array_values(array_unique($m[0])), 0, 5);
}

// Pages for the "Page" filter's search box. Searches the `posts` table (never the clicks table) and returns only a few
// matches, so the list stays small however many posts there are. Every row is ['id', 'title', 'sub']:
// id = 'home' or the post id (the value the filter sends back), sub = the small grey text under the title.
//   - nothing typed: the newest posts
//   - a number: the post with that id
//   - text: posts whose title STARTS with it (index idx_posts_title), then posts that contain a word STARTING with each
//     typed word (FULLTEXT index ft_posts_title). Both use an index, so no search reads every post.
function stats_page_search(string $q, int $limit = 15): array
{
    $limit = max(1, min(50, $limit));
    $q     = trim($q);
    $pdo   = db();
    $found = [];    // post id => title, in the order they are shown

    $add = static function (array $rows) use (&$found): void {
        foreach ($rows as $r) {
            $found[(int) $r['id']] ??= (string) $r['title'];
        }
    };

    if ($q === '') {
        $add($pdo->query("SELECT id, title FROM posts ORDER BY created_at DESC, id DESC LIMIT $limit")->fetchAll());
    } else {
        if (preg_match('/^[0-9]{1,10}$/', $q)) {
            $st = $pdo->prepare('SELECT id, title FROM posts WHERE id = ?');
            $st->execute([(int) $q]);
            $add($st->fetchAll());
        }

        // "|" is the escape character, so a typed % or _ is searched as normal text
        $like = str_replace(['|', '%', '_'], ['||', '|%', '|_'], $q) . '%';
        $st   = $pdo->prepare("SELECT id, title FROM posts WHERE title LIKE ? ESCAPE '|' ORDER BY title LIMIT $limit");
        $st->execute([$like]);
        $add($st->fetchAll());

        $words = stats_search_words($q);
        if ($words && count($found) < $limit) {
            $st = $pdo->prepare("SELECT id, title FROM posts WHERE MATCH(title) AGAINST (? IN BOOLEAN MODE) LIMIT $limit");
            $st->execute(['+' . implode('* +', $words) . '*']);
            $add($st->fetchAll());
        }
    }

    // (The home page has no share buttons, so it is not offered here. If you add some, offer it again with
    // ['id' => 'home', 'title' => 'Home', 'sub' => 'Home page'].)
    $out = [];
    foreach ($found as $id => $title) {
        $out[] = ['id' => (string) $id, 'title' => $title, 'sub' => 'Post #' . $id];
    }

    return array_slice($out, 0, $limit);
}

// Text shown in the Page box for the page that is filtered now.
function stats_page_label(?int $postId): string
{
    if ($postId === null) {
        return '';
    }
    if ($postId === 0) {
        return 'Home';
    }

    $st = db()->prepare('SELECT title FROM posts WHERE id = ?');
    $st->execute([$postId]);
    $title = $st->fetchColumn();
    if ($title !== false && trim((string) $title) !== '') {
        return (string) $title;
    }

    // a post that no longer exists: the title it had at its newest click
    $st = db()->prepare('SELECT page_title FROM share_clicks WHERE post_id = ? ORDER BY clicked_at DESC, id DESC LIMIT 1');
    $st->execute([$postId]);
    $title = trim((string) $st->fetchColumn());

    return $title !== '' ? $title : 'Post #' . $postId;
}

// Everything the charts' JavaScript needs, as plain arrays.
function stats_chart_data(array $r): array
{
    $timeSeries = [];
    foreach ($r['series'] as $key => $m) {
        $data = [];
        foreach (array_keys($r['buckets']) as $bucket) {
            $data[] = $r['timeline'][$key][$bucket] ?? 0;
        }
        $timeSeries[] = ['key' => $key, 'name' => $m['name'], 'color' => $m['color'], 'data' => $data];
    }

    // clicks by platform, biggest first
    $byPlatform = $r['totals'];
    arsort($byPlatform);
    $pl = ['labels' => [], 'data' => [], 'colors' => []];
    foreach ($byPlatform as $key => $n) {
        if ($n > 0 && isset($r['series'][$key])) {
            $pl['labels'][] = $r['series'][$key]['name'];
            $pl['data'][]   = $n;
            $pl['colors'][] = $r['series'][$key]['color'];
        }
    }

    $pageLabels = [];
    $pageUrls   = [];
    foreach ($r['pages'] as $p) {
        $pageLabels[] = $p['title'] !== '' ? $p['title'] : $p['url'];
        $pageUrls[]   = $p['url'];
    }
    $pageSeries = [];
    foreach ($r['series'] as $key => $m) {
        $pageSeries[] = [
            'key'   => $key,
            'name'  => $m['name'],
            'color' => $m['color'],
            'data'  => array_map(static fn (array $p): int => (int) ($p['by'][$key] ?? 0), $r['pages']),
        ];
    }

    return [
        'timeline'  => [
            'labels' => array_column($r['buckets'], 'label'),
            'titles' => array_column($r['buckets'], 'title'),
            'series' => $timeSeries,
        ],
        'platforms' => $pl,
        'pages'     => ['labels' => $pageLabels, 'urls' => $pageUrls, 'series' => $pageSeries],
    ];
}
