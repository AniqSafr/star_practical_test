<?php
declare(strict_types=1);

// Data for the admin dashboard: reads the filters from the URL and returns the numbers the charts and tables need.

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
            $min  = db()->query('SELECT MIN(clicked_at) FROM share_clicks')->fetchColumn();
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

    $page = is_string($get['page'] ?? null) && strlen($get['page']) <= 2048 ? $get['page'] : '';

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
        'page'      => $page,
        'group'     => $group,
        'note'      => $note,
    ];
}

// SQL WHERE (without the word WHERE) + parameters for the filters. 
function stats_where(array $f): array
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

    if ($f['page'] !== '') {
        $sql     .= ' AND page_url = ?';
        $params[] = $f['page'];
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
    [$where, $params] = stats_where($f);

    $meta  = stats_series_meta();
    $keyOf = static fn (string $slug): string => isset($meta[$slug]) ? $slug : 'other';

    // 1) totals per platform
    $st = $pdo->prepare("SELECT platform, COUNT(*) AS c FROM share_clicks WHERE $where GROUP BY platform");
    $st->execute($params);
    $totals = [];
    foreach ($st->fetchAll() as $r) {
        $k          = $keyOf((string) $r['platform']);
        $totals[$k] = ($totals[$k] ?? 0) + (int) $r['c'];
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

    // 2) clicks over time
    $buckets  = stats_buckets($f['from'], $f['to'], $f['group']);
    $timeline = [];
    $st = $pdo->prepare(
        "SELECT DATE(clicked_at) AS d, platform, COUNT(*) AS c FROM share_clicks WHERE $where GROUP BY DATE(clicked_at), platform"
    );
    $st->execute($params);
    foreach ($st->fetchAll() as $r) {
        $bucket = stats_bucket_start(new DateTimeImmutable((string) $r['d']), $f['group'])->format('Y-m-d');
        if (!isset($buckets[$bucket])) {
            continue;
        }
        $k = $keyOf((string) $r['platform']);
        $timeline[$k][$bucket] = ($timeline[$k][$bucket] ?? 0) + (int) $r['c'];
    }

    // 3) most shared pages (top 10), split by platform
    $st = $pdo->prepare(
        "SELECT page_url, MAX(page_title) AS page_title, platform, COUNT(*) AS c
         FROM share_clicks WHERE $where GROUP BY page_url, platform"
    );
    $st->execute($params);
    $pages = [];
    foreach ($st->fetchAll() as $r) {
        $url = (string) $r['page_url'];
        $k   = $keyOf((string) $r['platform']);
        $pages[$url]['url']          = $url;
        $pages[$url]['title']        = $pages[$url]['title'] ?? (string) ($r['page_title'] ?? '');
        $pages[$url]['total']        = ($pages[$url]['total'] ?? 0) + (int) $r['c'];
        $pages[$url]['by'][$k]       = ($pages[$url]['by'][$k] ?? 0) + (int) $r['c'];
    }
    uasort($pages, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);
    $pages = array_slice(array_values($pages), 0, 10);

    // 4) latest clicks
    $st = $pdo->prepare(
        "SELECT page_url, page_title, platform, clicked_at
         FROM share_clicks WHERE $where ORDER BY id DESC LIMIT 50"
    );
    $st->execute($params);
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

// Pages to choose from in the "Page" filter.
function stats_page_options(): array
{
    return db()->query(
        'SELECT page_url, MAX(page_title) AS page_title FROM share_clicks
         GROUP BY page_url ORDER BY MAX(page_title), page_url LIMIT 300'
    )->fetchAll();
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
