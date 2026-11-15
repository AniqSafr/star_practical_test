<?php
declare(strict_types=1);

// Downloads the dashboard numbers as a CSV file: GET export.php?from=...&to=...&platforms[]=...&page=...
// (the same filters as dashboard.php). One row per day, page and social media that had clicks.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/stats.php';
require_login();

// A cell that starts with = + - @ (or a tab / line break) is read as a formula by Excel and Google Sheets.
// A leading ' makes it plain text, so a post title can never run as a formula.
function csv_text(string $value): string
{
    return ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false) ? "'" . $value : $value;
}

// Everything that can fail on the database happens here, BEFORE the download starts, so a problem
// shows the normal error page instead of a broken file.
try {
    $f         = stats_filters($_GET);
    $platforms = share_platforms();
    $rows      = stats_export_statement($f);
} catch (PDOException $ex) {
    show_error_page($ex, 'csv export');
}

$filename = 'share-clicks_' . $f['from']->format('Y-m-d') . '_to_' . $f['to']->format('Y-m-d') . '.csv';

set_time_limit(300);   // a very large export may take a while

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');

echo "\xEF\xBB\xBF";   // byte order mark: Excel then opens the file as UTF-8 (accents, other alphabets)

$out = fopen('php://output', 'w');
fputcsv($out, ['Date', 'Post ID', 'Page', 'Social media', 'Clicks'], ',', '"', '', "\r\n");

try {
    while (($r = $rows->fetch()) !== false) {
        $postId = (int) $r['post_id'];
        $title  = trim((string) ($r['title'] ?? ''));
        if ($title === '') {
            $title = $postId === 0 ? 'Home' : 'Post #' . $postId . ' (deleted)';
        }

        fputcsv($out, [
            $r['click_date'],
            $postId,
            csv_text($title),
            csv_text((string) ($platforms[$r['platform']]['name'] ?? $r['platform'])),
            (int) $r['clicks'],
        ], ',', '"', '', "\r\n");
    }
} catch (PDOException $ex) {
    // the download has already started, so all that can be done is to log it and end the file here
    error_log('csv export stopped: ' . $ex->getMessage());
}

fclose($out);
