<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/stats.php';
require_login();

// read the filters, then get the numbers (every database call is inside the try, before anything is printed)
try {
    $f           = stats_filters($_GET);
    $r           = stats_collect($f);
    $chart       = stats_chart_data($r);
    $platforms   = share_platforms();
    $meta        = stats_series_meta();
    $pageLabel   = stats_page_label($f['post_id']);
} catch (PDOException $ex) {
    show_error_page($ex, 'admin dashboard');
}
$total       = $r['total'];

$fromStr = $f['from']->format('Y-m-d');
$toStr   = $f['to']->format('Y-m-d');
$today   = (new DateTimeImmutable('today'))->format('Y-m-d');

// the "Download CSV" link carries the filters that are applied now
$exportQuery = http_build_query(array_filter(
    ['from' => $fromStr, 'to' => $toStr, 'platforms' => $f['platforms'], 'page' => $f['page']],
    static fn (mixed $v): bool => $v !== '' && $v !== []
));

$presets = ['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days', 'month' => 'This month', 'all' => 'All time'];

// table cell content: just the URL if there is no title.
function page_cell(array $row): string
{
    $url   = e($row['page_url']);
    $title = trim((string) ($row['page_title'] ?? ''));

    return $title === ''
        ? $url
        : '<strong>' . e($title) . '</strong><br><span class="muted">' . $url . '</span>';
}

function short_text(string $text, int $max): string
{
    return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
}

if (!$f['platforms']) {
    $platformSummary = 'All social media';
} elseif (count($f['platforms']) <= 2) {
    $platformSummary = implode(', ', array_map(static fn (string $s): string => $platforms[$s]['name'], $f['platforms']));
} else {
    $platformSummary = count($f['platforms']) . ' selected';
}

$groupWord = ['day' => 'day', 'week' => 'week', 'month' => 'month'][$f['group']];
$rangeText = $f['from']->format('j M Y') . ' – ' . $f['to']->format('j M Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Share statistics | Admin</title>
    <link rel="stylesheet" href="../public/css/style.css">
</head>
<body>
<div class="admin-wrap">
    <div class="admin-bar">
        <h1>Share button statistics</h1>
        <div>
            <span class="muted">Logged in as <?= e($_SESSION['admin_user'] ?? 'admin') ?></span>
            <a class="btn btn-light" href="logout.php">Log out</a>
        </div>
    </div>

    <!-- Filters (they apply to everything below) -->
    <form class="filters" method="get" action="dashboard.php" id="filters">
        <div class="filters-row">
            <div class="presets" role="group" aria-label="Quick date range">
                <?php foreach ($presets as $key => $label): ?>
                    <a class="chip<?= $f['preset'] === (string) $key ? ' is-active' : '' ?>"
                       href="dashboard.php?range=<?= e((string) $key) ?>"><?= e($label) ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="filters-row">
            <label class="field">From
                <input type="date" name="from" value="<?= e($fromStr) ?>" max="<?= e($today) ?>">
            </label>
            <label class="field">To
                <input type="date" name="to" value="<?= e($toStr) ?>" max="<?= e($today) ?>">
            </label>
            <div class="field field-wide" id="page-field">
                <label for="page-search">Page</label>
                <div class="combo">
                    <input type="hidden" name="page" id="page-value" value="<?= e($f['page']) ?>">
                    <input type="text" id="page-search" role="combobox" aria-expanded="false" aria-controls="page-list"
                           aria-autocomplete="list" autocomplete="off" maxlength="100"
                           placeholder="All pages (type to search)" value="<?= e($pageLabel) ?>">
                    <button type="button" class="combo-clear" id="page-clear" aria-label="Show all pages"<?= $f['page'] === '' ? ' hidden' : '' ?>>&times;</button>
                    <ul class="combo-list" id="page-list" role="listbox" hidden></ul>
                </div>
            </div>
            <div class="field field-multi">
                <span id="platform-label">Social media</span>
                <details class="multi" id="platform-filter">
                    <summary aria-labelledby="platform-label"><span class="multi-text" id="platform-summary"><?= e($platformSummary) ?></span></summary>
                    <div class="multi-panel">
                        <div class="multi-actions">
                            <button type="button" class="linkbtn" data-multi="all">Select all</button>
                            <button type="button" class="linkbtn" data-multi="none">Clear</button>
                        </div>
                        <?php foreach ($platforms as $slug => $p): ?>
                            <label>
                                <input type="checkbox" name="platforms[]" value="<?= e($slug) ?>"
                                       data-name="<?= e($p['name']) ?>"
                                    <?= (!$f['platforms'] || in_array($slug, $f['platforms'], true)) ? 'checked' : '' ?>>
                                <span class="dot" style="background:<?= e($meta[$slug]['color'] ?? STATS_OTHER_COLOR) ?>"></span>
                                <?= e($p['name']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </details>
            </div>
            <label class="field">Group by
                <select name="group">
                    <?php foreach (['day' => 'Day', 'week' => 'Week', 'month' => 'Month'] as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= $f['group'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="btn" type="submit">Apply</button>
            <a class="btn btn-light" href="dashboard.php">Reset</a>
        </div>
    </form>

    <?php if ($f['note'] !== ''): ?>
        <p class="muted"><?= e($f['note']) ?></p>
    <?php endif; ?>

    <?php if ($total > 0): ?>
        <p>
            <a class="btn btn-light" href="export.php?<?= e($exportQuery) ?>">Download CSV</a>
        </p>
    <?php endif; ?>

    <!-- Numbers -->
    <div class="stats">
        <div class="stat"><div class="num"><?= number_format($total) ?></div><div class="lbl">Total share clicks</div></div>
        <?php foreach ($meta as $slug => $m): ?>
            <div class="stat">
                <div class="num"><?= number_format($r['totals'][$slug] ?? 0) ?></div>
                <div class="lbl"><span class="dot" style="background:<?= e($m['color']) ?>"></span><?= e($m['name']) ?></div>
            </div>
        <?php endforeach; ?>
        <?php if (!empty($r['totals']['other'])): ?>
            <div class="stat">
                <div class="num"><?= number_format($r['totals']['other']) ?></div>
                <div class="lbl"><span class="dot" style="background:<?= e(STATS_OTHER_COLOR) ?>"></span>Other</div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($total === 0): ?>
        <div class="panel empty">
            <strong>No share clicks match these filters.</strong>
            <p class="muted">Try a longer date range, or press Reset. Clicks appear here after someone uses a share button on the website.</p>
        </div>
    <?php else: ?>

    <div class="viz-root">

        <!-- Chart 1: clicks over time -->
        <section class="chart-card">
            <h2>Clicks over time</h2>
            <p class="chart-sub"><?= number_format($total) ?> clicks · <?= e($rangeText) ?> · by <?= e($groupWord) ?></p>
            <div class="chart-box" style="height:340px">
                <canvas id="chart-time" role="img"
                        aria-label="Stacked bar chart of share clicks per <?= e($groupWord) ?>, split by social media platform. The same numbers are in the table below the chart."></canvas>
            </div>
            <details class="data-table">
                <summary>View as table</summary>
                <div class="table-scroll">
                    <table>
                        <thead>
                        <tr>
                            <th><?= e(ucfirst($groupWord)) ?></th>
                            <?php foreach ($r['series'] as $m): ?><th><?= e($m['name']) ?></th><?php endforeach; ?>
                            <th>Total</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($r['buckets'] as $bucket => $b): ?>
                            <?php $rowTotal = 0; ?>
                            <tr>
                                <td><?= e($b['title']) ?></td>
                                <?php foreach ($r['series'] as $key => $m): ?>
                                    <?php $n = $r['timeline'][$key][$bucket] ?? 0; $rowTotal += $n; ?>
                                    <td><?= $n ?></td>
                                <?php endforeach; ?>
                                <td><strong><?= $rowTotal ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        </section>

        <div class="chart-grid">
            <!-- Chart 2: clicks by platform -->
            <section class="chart-card">
                <h2>Clicks by social media</h2>
                <p class="chart-sub"><?= e($rangeText) ?></p>
                <div class="chart-box" style="height:<?= max(150, count($chart['platforms']['labels']) * 44 + 40) ?>px">
                    <canvas id="chart-platforms" role="img"
                            aria-label="Bar chart of share clicks per social media platform. The numbers are also in the tiles above."></canvas>
                </div>
            </section>

            <!-- Chart 3: most shared pages -->
            <section class="chart-card">
                <h2>Most shared pages</h2>
                <p class="chart-sub">Top <?= count($r['pages']) ?> pages, split by social media</p>
                <div class="chart-box" style="height:<?= max(180, count($r['pages']) * 40 + 90) ?>px">
                    <canvas id="chart-pages" role="img"
                            aria-label="Stacked bar chart of the most shared pages, split by social media platform. The same numbers are in the table below the chart."></canvas>
                </div>
            </section>
        </div>
    </div>

    <script type="application/json" id="chart-data"><?= json_encode(
        $chart,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) ?></script>
    <script src="assets/chart.umd.js"></script>

    <?php endif; ?>

    <!-- Latest clicks (same filters) -->
    <div class="panel">
        <h2>Latest clicks</h2>
        <?php if (!$r['recent']): ?>
            <p class="muted">No clicks to show.</p>
        <?php else: ?>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Time</th><th>Platform</th><th>Page</th></tr></thead>
                    <tbody>
                    <?php foreach ($r['recent'] as $row): ?>
                        <tr>
                            <td><?= e($row['clicked_at']) ?></td>
                            <td><?= e($platforms[$row['platform']]['name'] ?? $row['platform']) ?></td>
                            <td class="url"><?= page_cell($row) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (count($r['recent']) === 50): ?>
                <p class="muted">Showing the 50 most recent clicks that match the filters.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script src="assets/dashboard.js"></script>
</body>
</html>
