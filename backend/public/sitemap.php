<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);

$autoloader = $basePath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (file_exists($autoloader)) {
    require_once $autoloader;
} else {
    spl_autoload_register(function (string $class) use ($basePath) {
        $prefix = 'CeylonCues\\';
        if (str_starts_with($class, $prefix)) {
            $file = $basePath . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR
                . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))) . '.php';
            if (file_exists($file)) {
                require_once $file;
            }
        }
    });
}

use CeylonCues\Core\Application;
use CeylonCues\Core\Database;

$app = new Application($basePath);
$app->boot();

$db      = Database::getInstance()->getConnection();
$baseUrl = rtrim($_ENV['APP_URL'] ?? 'https://ceyloncues.lk', '/');

$products   = $db->query("SELECT id, slug, updated_at FROM products WHERE is_active = 1 AND deleted_at IS NULL ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$categories = $db->query("SELECT id, slug, updated_at FROM categories WHERE is_active = 1 AND deleted_at IS NULL ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/xml; charset=utf-8');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

$staticPages = [
    ['loc' => '/',           'priority' => '1.0', 'changefreq' => 'daily'],
    ['loc' => '/products',   'priority' => '0.9', 'changefreq' => 'daily'],
    ['loc' => '/categories', 'priority' => '0.8', 'changefreq' => 'weekly'],
    ['loc' => '/about',      'priority' => '0.5', 'changefreq' => 'monthly'],
    ['loc' => '/contact',    'priority' => '0.5', 'changefreq' => 'monthly'],
];

foreach ($staticPages as $page) {
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars($baseUrl . $page['loc'], ENT_XML1) . "</loc>\n";
    echo '    <changefreq>' . $page['changefreq'] . "</changefreq>\n";
    echo '    <priority>' . $page['priority'] . "</priority>\n";
    echo "  </url>\n";
}

foreach ($categories as $cat) {
    $slug    = $cat['slug'] ?? $cat['id'];
    $lastmod = substr((string)$cat['updated_at'], 0, 10);
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars("{$baseUrl}/categories/{$slug}", ENT_XML1) . "</loc>\n";
    echo '    <lastmod>' . $lastmod . "</lastmod>\n";
    echo "    <changefreq>weekly</changefreq>\n";
    echo "    <priority>0.7</priority>\n";
    echo "  </url>\n";
}

foreach ($products as $product) {
    $slug    = $product['slug'] ?? $product['id'];
    $lastmod = substr((string)$product['updated_at'], 0, 10);
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars("{$baseUrl}/products/{$slug}", ENT_XML1) . "</loc>\n";
    echo '    <lastmod>' . $lastmod . "</lastmod>\n";
    echo "    <changefreq>weekly</changefreq>\n";
    echo "    <priority>0.8</priority>\n";
    echo "  </url>\n";
}

echo '</urlset>' . "\n";
