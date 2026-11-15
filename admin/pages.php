<?php
declare(strict_types=1);

// Data for the dashboard's "Page" search box: GET pages.php?q=text
// Returns up to 15 matching pages as JSON, like [{"url": "...", "title": "..."}]

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/stats.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// only a logged in admin can search
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in.']);
    exit;
}

$q = $_GET['q'] ?? '';
if (!is_string($q) || mb_strlen($q) > 100) {
    http_response_code(422);
    echo json_encode(['error' => 'Invalid search text.']);
    exit;
}

try {
    $rows = array_map(
        static fn (array $r): array => ['url' => (string) $r['page_url'], 'title' => trim((string) ($r['page_title'] ?? ''))],
        stats_page_search($q)
    );
} catch (PDOException $ex) {
    error_log('page search failed: ' . $ex->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Search failed.']);
    exit;
}

echo json_encode($rows, JSON_UNESCAPED_UNICODE);
