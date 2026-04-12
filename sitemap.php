<?php
require_once __DIR__ . '/includes/config.php';

header('Content-Type: application/xml; charset=UTF-8');

$pdo = blog_db();
$rows = $pdo->query('SELECT slug, updated_at, published_at FROM posts WHERE status = "published" ORDER BY published_at DESC')->fetchAll();

$urls = [];
$nowIso = date('c');

$urls[] = [
    'loc' => blog_url('index.php'),
    'lastmod' => $nowIso,
    'changefreq' => 'hourly',
    'priority' => '1.0',
];

$urls[] = [
    'loc' => blog_url('index.php?category=all&source=all&page=1'),
    'lastmod' => $nowIso,
    'changefreq' => 'hourly',
    'priority' => '0.9',
];

foreach ($rows as $row) {
    $lastmodTs = strtotime((string) ($row['updated_at'] ?: $row['published_at']));
    $urls[] = [
        'loc' => blog_url('post.php?slug=' . urlencode((string) $row['slug'])),
        'lastmod' => date('c', $lastmodTs ?: time()),
        'changefreq' => 'daily',
        'priority' => '0.8',
    ];
}

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

foreach ($urls as $entry) {
    echo '<url>';
    echo '<loc>' . htmlspecialchars($entry['loc'], ENT_QUOTES, 'UTF-8') . '</loc>';
    echo '<lastmod>' . htmlspecialchars($entry['lastmod'], ENT_QUOTES, 'UTF-8') . '</lastmod>';
    echo '<changefreq>' . htmlspecialchars($entry['changefreq'], ENT_QUOTES, 'UTF-8') . '</changefreq>';
    echo '<priority>' . htmlspecialchars($entry['priority'], ENT_QUOTES, 'UTF-8') . '</priority>';
    echo '</url>';
}

echo '</urlset>';
