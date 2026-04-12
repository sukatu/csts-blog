<?php
require_once __DIR__ . '/includes/config.php';

/**
 * Metadata-only importer for GhanaWeb Business links.
 *
 * Usage:
 *   php import_ghanaweb_business.php [maxListPages] [maxArticles]
 *
 * Defaults:
 *   maxListPages = 30
 *   maxArticles  = 500
 */

$maxListPages = isset($argv[1]) ? max(1, (int) $argv[1]) : 30;
$maxArticles = isset($argv[2]) ? max(1, (int) $argv[2]) : 500;

$pdo = blog_db();
$adminId = blog_get_default_admin_user_id($pdo);

$listQueue = [
    'https://www.ghanaweb.com/GhanaHomePage/business/',
    'https://www.ghanaweb.com/GhanaHomePage/business/browse.archive.php',
];
$seenListUrls = [];
$articleUrls = [];

while (!empty($listQueue) && count($seenListUrls) < $maxListPages) {
    $listUrl = array_shift($listQueue);
    if (isset($seenListUrls[$listUrl])) {
        continue;
    }
    $seenListUrls[$listUrl] = true;

    $html = fetch_url($listUrl);
    if ($html === null) {
        continue;
    }

    foreach (extract_links($html, $listUrl) as $url) {
        if (is_business_article_url($url)) {
            $articleUrls[$url] = true;
            continue;
        }

        if (is_business_listing_url($url) && !isset($seenListUrls[$url])) {
            $listQueue[] = $url;
        }
    }
}

$articleLinks = array_slice(array_keys($articleUrls), 0, $maxArticles);

$insertStmt = $pdo->prepare('INSERT INTO posts (
        author_user_id, title, slug, summary, content, category, image_url, source_name, source_url, status, published_at, created_at, updated_at
    ) VALUES (
        :author_user_id, :title, :slug, :summary, :content, :category, :image_url, :source_name, :source_url, :status, :published_at, :created_at, :updated_at
    ) ON DUPLICATE KEY UPDATE
        title = VALUES(title),
        summary = VALUES(summary),
        content = VALUES(content),
        image_url = VALUES(image_url),
        source_name = VALUES(source_name),
        status = VALUES(status),
        published_at = VALUES(published_at),
        updated_at = VALUES(updated_at)');

$imported = 0;
$updated = 0;
$skipped = 0;

foreach ($articleLinks as $articleUrl) {
    $meta = fetch_article_metadata($articleUrl);
    if ($meta === null || $meta['title'] === '') {
        $skipped++;
        continue;
    }

    $slugBase = basename((string) parse_url($articleUrl, PHP_URL_PATH));
    $slug = blog_slugify($slugBase !== '' ? $slugBase : $meta['title']);
    $summary = $meta['description'] !== '' ? $meta['description'] : 'Imported headline from GhanaWeb Business.';
    $safeSummary = htmlspecialchars($summary, ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars($articleUrl, ENT_QUOTES, 'UTF-8');

    $content = '<p>' . $safeSummary . '</p>'
        . '<p><a href="' . $safeUrl . '" target="_blank" rel="noopener noreferrer nofollow">Read full story on GhanaWeb</a></p>';

    $publishedAt = normalize_datetime($meta['published_at']);
    $now = date('Y-m-d H:i:s');

    $insertStmt->execute([
        ':author_user_id' => $adminId,
        ':title' => trim($meta['title']),
        ':slug' => $slug,
        ':summary' => $summary,
        ':content' => $content,
        ':category' => 'news',
        ':image_url' => $meta['image_url'] !== '' ? $meta['image_url'] : blog_seed_image_for_category('news'),
        ':source_name' => 'GhanaWeb',
        ':source_url' => $articleUrl,
        ':status' => 'published',
        ':published_at' => $publishedAt,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    if ((int) $insertStmt->rowCount() === 1) {
        $imported++;
    } else {
        $updated++;
    }
}

echo 'List pages scanned: ' . count($seenListUrls) . PHP_EOL;
echo 'Article URLs discovered: ' . count($articleLinks) . PHP_EOL;
echo 'Imported: ' . $imported . PHP_EOL;
echo 'Updated: ' . $updated . PHP_EOL;
echo 'Skipped: ' . $skipped . PHP_EOL;

function fetch_url(string $url): ?string
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: CSTSBlogImporter/1.0\r\nAccept: text/html,application/xhtml+xml\r\n",
            'timeout' => 20,
        ],
    ]);

    $html = @file_get_contents($url, false, $context);
    if ($html === false || !is_string($html) || trim($html) === '') {
        return null;
    }

    return $html;
}

function extract_links(string $html, string $baseUrl): array
{
    $links = [];
    $dom = new DOMDocument();
    @$dom->loadHTML($html);

    foreach ($dom->getElementsByTagName('a') as $anchor) {
        $href = trim((string) $anchor->getAttribute('href'));
        if ($href === '') {
            continue;
        }

        $resolved = normalize_url($href, $baseUrl);
        if ($resolved !== null) {
            $links[$resolved] = true;
        }
    }

    return array_keys($links);
}

function normalize_url(string $href, string $baseUrl): ?string
{
    if (str_starts_with($href, 'javascript:') || str_starts_with($href, 'mailto:') || str_starts_with($href, '#')) {
        return null;
    }

    if (str_starts_with($href, '//')) {
        $href = 'https:' . $href;
    } elseif (str_starts_with($href, '/')) {
        $base = parse_url($baseUrl);
        if (!isset($base['scheme'], $base['host'])) {
            return null;
        }
        $href = $base['scheme'] . '://' . $base['host'] . $href;
    } elseif (!preg_match('/^https?:\/\//i', $href)) {
        $basePath = rtrim((string) parse_url($baseUrl, PHP_URL_PATH), '/');
        $baseDir = $basePath === '' ? '' : dirname($basePath);
        $base = parse_url($baseUrl);
        if (!isset($base['scheme'], $base['host'])) {
            return null;
        }
        $href = $base['scheme'] . '://' . $base['host'] . rtrim($baseDir, '/') . '/' . ltrim($href, '/');
    }

    $parts = parse_url($href);
    if (!isset($parts['scheme'], $parts['host'])) {
        return null;
    }

    $path = $parts['path'] ?? '/';
    $normalized = strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . $path;
    if (isset($parts['query']) && $parts['query'] !== '') {
        $normalized .= '?' . $parts['query'];
    }

    return rtrim($normalized, '/');
}

function is_business_article_url(string $url): bool
{
    return (bool) preg_match('#^https://www\.ghanaweb\.com/GhanaHomePage/business/.+-\d+$#', $url);
}

function is_business_listing_url(string $url): bool
{
    if (!str_starts_with($url, 'https://www.ghanaweb.com/GhanaHomePage/business')) {
        return false;
    }

    return str_contains($url, 'browse.archive.php')
        || str_contains($url, '?page=')
        || $url === 'https://www.ghanaweb.com/GhanaHomePage/business';
}

function fetch_article_metadata(string $url): ?array
{
    $html = fetch_url($url);
    if ($html === null) {
        return null;
    }

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    $title = pick_meta($xpath, ['og:title', 'twitter:title']);
    if ($title === '') {
        $nodes = $xpath->query('//title');
        if ($nodes !== false && $nodes->length > 0) {
            $title = trim((string) $nodes->item(0)?->textContent);
            $title = preg_replace('/\s*\|\s*GhanaWeb.*$/i', '', $title) ?? $title;
        }
    }

    $description = pick_meta($xpath, ['og:description', 'description', 'twitter:description']);
    $imageUrl = pick_meta($xpath, ['og:image', 'twitter:image']);
    $publishedAt = pick_meta($xpath, ['article:published_time', 'og:published_time']);

    return [
        'title' => $title,
        'description' => $description,
        'image_url' => $imageUrl,
        'published_at' => $publishedAt,
    ];
}

function pick_meta(DOMXPath $xpath, array $names): string
{
    foreach ($names as $name) {
        $query = sprintf('//meta[@property="%1$s"]/@content | //meta[@name="%1$s"]/@content', $name);
        $result = $xpath->query($query);
        if ($result !== false && $result->length > 0) {
            $value = trim((string) $result->item(0)?->nodeValue);
            if ($value !== '') {
                return $value;
            }
        }
    }

    return '';
}

function normalize_datetime(string $value): string
{
    if ($value === '') {
        return date('Y-m-d H:i:s');
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return date('Y-m-d H:i:s');
    }

    return date('Y-m-d H:i:s', $timestamp);
}
