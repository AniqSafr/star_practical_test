<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

// Pages set these before including the header.
$pageTitle = $pageTitle ?? 'Home';
$pageDesc  = $pageDesc ?? 'A simple PHP and MySQL website with social share buttons.';
$pageImage = $pageImage ?? asset_url('img/share-default.png');
$pageUrl   = $pageUrl ?? current_url();   // a page (e.g. post.php) may set its own canonical URL first
$fullTitle = $pageTitle === 'Home' ? SITE_NAME : $pageTitle . ' | ' . SITE_NAME;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($fullTitle) ?></title>
    <meta name="description" content="<?= e($pageDesc) ?>">
    <link rel="canonical" href="<?= e($pageUrl) ?>">

    <!-- Open Graph: used by Facebook, WhatsApp, Telegram, LinkedIn, etc. -->
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= e(SITE_NAME) ?>">
    <meta property="og:title" content="<?= e($pageTitle) ?>">
    <meta property="og:description" content="<?= e($pageDesc) ?>">
    <meta property="og:url" content="<?= e($pageUrl) ?>">
    <meta property="og:image" content="<?= e($pageImage) ?>">

    <!-- Twitter / X card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= e($pageTitle) ?>">
    <meta name="twitter:description" content="<?= e($pageDesc) ?>">
    <meta name="twitter:image" content="<?= e($pageImage) ?>">

    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<header class="site-header">
    <div class="container">
        <a class="brand" href="index.php"><?= e(SITE_NAME) ?></a>
        <nav><a href="index.php">Home</a></nav>
    </div>
</header>
<main class="container">
