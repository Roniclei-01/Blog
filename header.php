<?php
declare(strict_types=1);

// header.php - TechVidaReal (SEO dinâmico + menu + security headers)
require_once __DIR__ . '/security.php';
tvr_secure_session_start(false);
tvr_security_headers(false);

// Mantido para compatibilidade com páginas que usam $conn após include 'header.php'
require_once __DIR__ . '/db.php';

if (!function_exists('h')) {
    function h(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('to_absolute_url')) {
    function to_absolute_url(string $url, string $baseUrl): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        if (strpos($url, '/') === 0) {
            return rtrim($baseUrl, '/') . $url;
        }

        return rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
    }
}

$scheme = tvr_is_https() ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
$basePath = str_replace('\\', '/', dirname($scriptName));
$basePath = ($basePath === '/' || $basePath === '\\' || $basePath === '.') ? '' : rtrim($basePath, '/');

$baseUrl = $scheme . '://' . $host . $basePath;
$currentUrl = $scheme . '://' . $host . ($_SERVER['REQUEST_URI'] ?? '/');

$siteName = 'TechVidaReal';
$defaultTitle = $siteName . ' - Tecnologia simples para a vida real';
$defaultDescription = 'Guias e artigos de tecnologia com linguagem simples, prática e útil para o dia a dia.';
$defaultImage = $baseUrl . '/assets/imagem1.png';

$seo_title = isset($seo_title) && $seo_title !== '' ? $seo_title : $defaultTitle;
$seo_description = isset($seo_description) && $seo_description !== '' ? $seo_description : $defaultDescription;
$seo_url = isset($seo_url) && $seo_url !== '' ? to_absolute_url($seo_url, $baseUrl) : $currentUrl;
$seo_image = isset($seo_image) && $seo_image !== '' ? to_absolute_url($seo_image, $baseUrl) : $defaultImage;
$seo_type = isset($seo_type) && $seo_type !== '' ? $seo_type : 'website';
$seo_robots = isset($seo_robots) && $seo_robots !== '' ? $seo_robots : 'index,follow';

$seo_published_time = $seo_published_time ?? '';
$seo_modified_time = $seo_modified_time ?? '';
$seo_section = $seo_section ?? '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($seo_title) ?></title>

    <meta name="description" content="<?= h($seo_description) ?>">
    <meta name="robots" content="<?= h($seo_robots) ?>">
    <link rel="canonical" href="<?= h($seo_url) ?>">

    <meta property="og:locale" content="pt_BR">
    <meta property="og:type" content="<?= h($seo_type) ?>">
    <meta property="og:site_name" content="<?= h($siteName) ?>">
    <meta property="og:title" content="<?= h($seo_title) ?>">
    <meta property="og:description" content="<?= h($seo_description) ?>">
    <meta property="og:url" content="<?= h($seo_url) ?>">
    <meta property="og:image" content="<?= h($seo_image) ?>">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= h($seo_title) ?>">
    <meta name="twitter:description" content="<?= h($seo_description) ?>">
    <meta name="twitter:image" content="<?= h($seo_image) ?>">

    <?php if ($seo_type === 'article'): ?>
        <?php if ($seo_published_time !== ''): ?>
            <meta property="article:published_time" content="<?= h($seo_published_time) ?>">
        <?php endif; ?>
        <?php if ($seo_modified_time !== ''): ?>
            <meta property="article:modified_time" content="<?= h($seo_modified_time) ?>">
        <?php endif; ?>
        <?php if ($seo_section !== ''): ?>
            <meta property="article:section" content="<?= h($seo_section) ?>">
        <?php endif; ?>
    <?php endif; ?>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Montserrat:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<header class="site-header">
    <div class="container">
        <div class="header-inner">

            <a href="index.php" class="logo">Codexa</a>

            <nav class="nav-menu" id="nav-menu">
                <a href="index.php">Home</a>
                <a href="categoria.php?categoria=Not%C3%ADcias%20de%20Tecnologia">Notícias</a>
                <a href="categoria.php?categoria=Tecnologia%20na%20Pr%C3%A1tica">Na Prática</a>
                <a href="categoria.php?categoria=Curiosidades%20Tech">Curiosidades</a>
                <a href="categoria.php?categoria=Ferramentas%20e%20Apps">Ferramentas</a>
                <a href="todos-artigos.php">Todos os Artigos</a>
            </nav>

            <form method="GET" action="busca.php" class="search-form">
                <input type="text"
                       name="q"
                       placeholder="Buscar no blog..."
                       autocomplete="off">
            </form>

            <button class="hamburger" id="hamburger-btn" aria-label="Menu">☰</button>
        </div>
    </div>
</header>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var hamburger = document.getElementById('hamburger-btn');
    var navMenu = document.getElementById('nav-menu');
    if (!hamburger || !navMenu) return;

    hamburger.addEventListener('click', function () {
        navMenu.classList.toggle('active');
        hamburger.textContent = navMenu.classList.contains('active') ? '✕' : '☰';
    });

    var menuLinks = navMenu.querySelectorAll('a');
    menuLinks.forEach(function (link) {
        link.addEventListener('click', function () {
            navMenu.classList.remove('active');
            hamburger.textContent = '☰';
        });
    });
});
</script>
