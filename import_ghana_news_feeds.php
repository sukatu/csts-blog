<?php
require_once __DIR__ . '/includes/config.php';

/**
 * Import metadata from accessible Ghana news RSS feeds.
 *
 * Usage:
 *   php import_ghana_news_feeds.php [maxPerFeed]
 *
 * Example:
 *   php import_ghana_news_feeds.php 80
 */

$maxPerFeed = isset($argv[1]) ? max(1, (int) $argv[1]) : 60;

$feeds = [
    [
        'name' => 'MyJoyOnline',
        'url' => 'https://www.myjoyonline.com/feed/',
    ],
    [
        'name' => '3News',
        'url' => 'https://3news.com/feed/',
    ],
    [
        'name' => 'Graphic Online',
        'url' => 'https://www.graphic.com.gh/?format=feed&type=rss',
    ],
];

$pdo = blog_db();
blog_ensure_posts_schema($pdo);
$authorId = blog_get_default_admin_user_id($pdo);

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

$totalImported = 0;
$totalUpdated = 0;
$totalSkipped = 0;

foreach ($feeds as $feed) {
    $xml = fetch_feed($feed['url']);
    if ($xml === null || !isset($xml->channel->item)) {
        echo '[WARN] Failed to parse feed: ' . $feed['name'] . PHP_EOL;
        continue;
    }

    $count = 0;
    foreach ($xml->channel->item as $item) {
        if ($count >= $maxPerFeed) {
            break;
        }

        $title = trim((string) ($item->title ?? ''));
        $link = trim((string) ($item->link ?? ''));
        if ($title === '' || $link === '') {
            $totalSkipped++;
            continue;
        }

        $description = trim((string) ($item->description ?? ''));
        $summary = clean_text($description);
        if ($summary === '') {
            $summary = 'Imported from ' . $feed['name'] . '.';
        }
        $summary = mb_substr($summary, 0, 300);

        $imageUrl = extract_item_image_url($item, $description);
        if ($imageUrl === '' || !is_probable_image_url($imageUrl)) {
            $imageUrl = blog_seed_image_for_category('news');
        }

        $publishedAt = normalize_datetime((string) ($item->pubDate ?? ''));
        $slugSeed = basename((string) parse_url($link, PHP_URL_PATH));
        $slug = blog_slugify($slugSeed !== '' ? $slugSeed : $title);

        $safeSummary = htmlspecialchars($summary, ENT_QUOTES, 'UTF-8');
        $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
        $content = '<p>' . $safeSummary . '</p>'
            . '<p><a href="' . $safeLink . '" target="_blank" rel="noopener noreferrer nofollow">Read full story on ' . htmlspecialchars($feed['name'], ENT_QUOTES, 'UTF-8') . '</a></p>';

        $now = date('Y-m-d H:i:s');
        $insertStmt->execute([
            ':author_user_id' => $authorId,
            ':title' => $title,
            ':slug' => $slug,
            ':summary' => $summary,
            ':content' => $content,
            ':category' => 'news',
            ':image_url' => $imageUrl,
            ':source_name' => $feed['name'],
            ':source_url' => $link,
            ':status' => 'published',
            ':published_at' => $publishedAt,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        if ((int) $insertStmt->rowCount() === 1) {
            $totalImported++;
        } else {
            $totalUpdated++;
        }

        $count++;
    }

    echo '[OK] ' . $feed['name'] . ': processed ' . $count . ' items' . PHP_EOL;
}

echo 'Imported: ' . $totalImported . PHP_EOL;
echo 'Updated: ' . $totalUpdated . PHP_EOL;
echo 'Skipped: ' . $totalSkipped . PHP_EOL;

function fetch_feed(string $url): ?SimpleXMLElement
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: CSTSBlogFeedImporter/1.0\r\nAccept: application/rss+xml, application/xml, text/xml\r\n",
            'timeout' => 25,
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);
    if ($raw === false || trim($raw) === '') {
        return null;
    }

    $xml = @simplexml_load_string($raw);
    return $xml instanceof SimpleXMLElement ? $xml : null;
}

function clean_text(string $html): string
{
    $text = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html) ?? $html;
    $text = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $text) ?? $text;
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    return trim($text);
}

function extract_item_image_url(SimpleXMLElement $item, string $description): string
{
    $mediaNs = $item->children('media', true);
    if (isset($mediaNs->content)) {
        foreach ($mediaNs->content as $contentNode) {
            $attrs = $contentNode->attributes();
            if (isset($attrs['url']) && trim((string) $attrs['url']) !== '') {
                return trim((string) $attrs['url']);
            }
        }
    }

    if (isset($item->enclosure)) {
        foreach ($item->enclosure as $enclosure) {
            $attrs = $enclosure->attributes();
            $type = strtolower((string) ($attrs['type'] ?? ''));
            if (str_starts_with($type, 'image/') && isset($attrs['url'])) {
                $url = trim((string) $attrs['url']);
                if ($url !== '') {
                    return $url;
                }
            }
        }
    }

    if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $description, $matches) === 1) {
        return trim($matches[1]);
    }

    return '';
}

function is_probable_image_url(string $url): bool
{
    $clean = strtolower((string) parse_url($url, PHP_URL_PATH));
    if ($clean === '') {
        return false;
    }

    foreach (['.jpg', '.jpeg', '.png', '.webp', '.gif', '.avif'] as $ext) {
        if (str_ends_with($clean, $ext)) {
            return true;
        }
    }

    return str_contains($url, 'wp-content/uploads') || str_contains($url, 'cdn');
}

function normalize_datetime(string $value): string
{
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return date('Y-m-d H:i:s');
    }

    return date('Y-m-d H:i:s', $timestamp);
}
