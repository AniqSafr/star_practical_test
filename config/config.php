<?php
declare(strict_types=1);

// Database settings (XAMPP defaults)
const DB_HOST = '127.0.0.1';
const DB_NAME = 'moon_news';
const DB_USER = 'root';
const DB_PASS = '';

// Site settings
const SITE_NAME = 'Moon News';

// Leave empty on localhost (the URL is detected automatically).
// When deployed behind a proxy / on a real domain, set it to e.g. 'https://example.com'
const SITE_URL = '';

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }

    return $pdo;
}
