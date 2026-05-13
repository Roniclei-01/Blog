<?php
declare(strict_types=1);

header('Content-Type: application/xml; charset=UTF-8');
require_once __DIR__ . '/db.php';

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/sitemap.php';
$basePath = str_replace('\\', '/', dirname($scriptName));
$basePath = ($basePath === '/' || $basePath === '\\' || $basePath === '.') ? '' : rtrim($basePath, '/');
$baseUrl = $scheme . '://' . $host . $basePath;

function x(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

$urls = [];

// URLs principais
$urls[] = [
    'loc' => $baseUrl . '/index.php',
    'lastmod' => date('c'),
    'changefreq' => 'daily',
    'priority' => '1.0'
];
$urls[] = [
    'loc' => $baseUrl . '/todos-artigos.php',
    'lastmod' => date('c'),
    'changefreq' => 'daily',
    'priority' => '0.9'
];

// Categorias existentes
$sqlCat = "SELECT DISTINCT categoria FROM artigos WHERE categoria IS NOT NULL AND categoria <> ''";
$resCat = $conn->query($sqlCat);
if ($resCat) {
    while ($row = $resCat->fetch_assoc()) {
        $cat = (string)$row['categoria'];
        $urls[] = [
            'loc' => $baseUrl . '/categoria.php?categoria=' . rawurlencode($cat),
            'lastmod' => date('c'),
            'changefreq' => 'weekly',
            'priority' => '0.7'
        ];
    }
}

// Artigos
$sqlArt = "SELECT id, data_publicacao, criado_em FROM artigos ORDER BY COALESCE(data_publicacao, criado_em) DESC";
$resArt = $conn->query($sqlArt);
if ($resArt) {
    while ($row = $resArt->fetch_assoc()) {
        $last = $row['data_publicacao'] ?: $row['criado_em'];
        if (!$last) $last = date('Y-m-d H:i:s');

        $urls[] = [
            'loc' => $baseUrl . '/single.php?id=' . (int)$row['id'],
            'lastmod' => date('c', strtotime($last)),
            'changefreq' => 'monthly',
            'priority' => '0.8'
        ];
    }
}

$conn->close();

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($urls as $u): ?>
    <url>
        <loc><?= x($u['loc']) ?></loc>
        <lastmod><?= x($u['lastmod']) ?></lastmod>
        <changefreq><?= x($u['changefreq']) ?></changefreq>
        <priority><?= x($u['priority']) ?></priority>
    </url>
<?php endforeach; ?>
</urlset>
