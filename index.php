<?php
require_once __DIR__ . '/includes/config.php';

$pdo = blog_db();
$newsletterName = '';
$newsletterEmail = '';
$newsletterSuccess = '';
$newsletterError = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['form_type'] ?? '') === 'newsletter_subscribe') {
  $newsletterName = trim((string) ($_POST['name'] ?? ''));
  $newsletterEmail = trim((string) ($_POST['email'] ?? ''));

  if ($newsletterName === '' || $newsletterEmail === '') {
    $newsletterError = 'Please enter both name and email.';
  } elseif (!filter_var($newsletterEmail, FILTER_VALIDATE_EMAIL)) {
    $newsletterError = 'Please enter a valid email address.';
  } else {
    try {
      blog_subscribe_newsletter($pdo, $newsletterName, $newsletterEmail);
      $newsletterSuccess = 'Thanks for subscribing. You will receive the latest CSTS updates in your inbox.';
      $newsletterName = '';
      $newsletterEmail = '';
    } catch (Throwable $exception) {
      $newsletterError = 'Subscription failed. Please try again shortly.';
      error_log('Newsletter subscription error: ' . $exception->getMessage());
    }
  }
}

$q = trim($_GET['q'] ?? '');
$category = $_GET['category'] ?? 'all';
$source = trim($_GET['source'] ?? 'all');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 9;

try {
  $settingsStmt = $pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key = :setting_key LIMIT 1');
  $settingsStmt->execute([':setting_key' => 'posts_per_page']);
  $configuredPerPage = (int) $settingsStmt->fetchColumn();
  if ($configuredPerPage > 0 && $configuredPerPage <= 50) {
    $perPage = $configuredPerPage;
  }
} catch (Throwable $exception) {
  // Keep default page size if settings table is unavailable.
}

$where = ['status = :status'];
$params = [':status' => 'published'];

$sourcesStmt = $pdo->query('SELECT DISTINCT source_name FROM posts WHERE status = "published" AND source_name IS NOT NULL AND source_name <> "" ORDER BY source_name ASC');
$availableSources = $sourcesStmt ? array_values(array_filter(array_map(static fn($v) => (string) $v, $sourcesStmt->fetchAll(PDO::FETCH_COLUMN)))) : [];

if ($q !== '') {
    $where[] = '(title LIKE :q OR summary LIKE :q OR content LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}

if (in_array($category, ['news', 'events'], true)) {
    $where[] = 'category = :category';
    $params[':category'] = $category;
}

if ($source !== 'all' && in_array($source, $availableSources, true)) {
  $where[] = 'source_name = :source_name';
  $params[':source_name'] = $source;
}

$countSql = 'SELECT COUNT(*) FROM posts WHERE ' . implode(' AND ', $where);
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalPosts = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalPosts / $perPage));

if ($page > $totalPages) {
  $page = $totalPages;
}

$offset = ($page - 1) * $perPage;
$sql = 'SELECT id, title, slug, summary, content, category, image_url, source_name, published_at
    FROM posts
    WHERE ' . implode(' AND ', $where) . '
  ORDER BY published_at DESC, id DESC
     LIMIT :limit OFFSET :offset';

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
  $stmt->bindValue($key, $value, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$posts = $stmt->fetchAll();

$featuredPost = $posts[0] ?? null;
$listPosts = array_slice($posts, 1);
$feedPosts = $listPosts ?: $posts;
$leadPost = $feedPosts[0] ?? null;
$gridPosts = array_slice($feedPosts, 1);

$headlineStmt = $pdo->query('SELECT title, slug, source_name, published_at FROM posts WHERE status = "published" ORDER BY published_at DESC, id DESC LIMIT 1');
$headlinePost = $headlineStmt ? $headlineStmt->fetch() : null;

$sourceMixStmt = $pdo->query('SELECT source_name, COUNT(*) AS total_posts FROM posts WHERE status = "published" AND source_name IS NOT NULL AND source_name <> "" GROUP BY source_name ORDER BY total_posts DESC, source_name ASC LIMIT 6');
$sourceMix = $sourceMixStmt ? $sourceMixStmt->fetchAll() : [];

$last24hStmt = $pdo->query('SELECT COUNT(*) FROM posts WHERE status = "published" AND published_at >= (NOW() - INTERVAL 1 DAY)');
$last24hCount = $last24hStmt ? (int) $last24hStmt->fetchColumn() : 0;

$buildPageUrl = static function (int $targetPage) use ($q, $category, $source): string {
  $query = [];
  if ($q !== '') {
    $query['q'] = $q;
  }
  if ($category !== 'all') {
    $query['category'] = $category;
  }
  if ($source !== 'all') {
    $query['source'] = $source;
  }
  $query['page'] = $targetPage;

  return 'index.php?' . http_build_query($query);
};

$metaTitle = BLOG_SITE_NAME . ' | News & Events';
$metaDescription = 'Latest business and corporate updates in Ghana from CSTS, including market intelligence, governance updates, and verified source references.';
$canonicalUrl = blog_current_url();
$metaImage = !empty($featuredPost['image_url']) ? (string) $featuredPost['image_url'] : blog_url('assets/csts-logo.png');

$websiteSchema = [
  '@context' => 'https://schema.org',
  '@type' => 'WebSite',
  'name' => BLOG_SITE_NAME,
  'url' => blog_url('/'),
  'description' => BLOG_SITE_TAGLINE,
  'publisher' => [
    '@type' => 'Organization',
    'name' => BLOG_SITE_COMPANY,
  ],
];

$organizationSchema = [
  '@context' => 'https://schema.org',
  '@type' => 'Organization',
  'name' => BLOG_SITE_COMPANY,
  'url' => blog_url('/'),
  'logo' => blog_url('assets/csts-logo.png'),
  'sameAs' => [
    'https://www.linkedin.com/company/csts-ghana',
    'https://www.facebook.com/cstsghana',
    'https://x.com/cstsgh',
    'https://www.instagram.com/cstsghana/',
  ],
];

$developerSchema = [
  '@context' => 'https://schema.org',
  '@type' => 'Person',
  'name' => BLOG_DEVELOPER_NAME,
  'jobTitle' => BLOG_DEVELOPER_TITLE,
  'description' => BLOG_DEVELOPER_CREDIT,
  'url' => 'https://alphasmacodeinnovations.com',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo blog_e($metaTitle); ?></title>
  <meta name="description" content="<?php echo blog_e($metaDescription); ?>">
  <meta name="keywords" content="Ghana business news, corporate governance Ghana, market insights Ghana, CSTS blog, leadership updates">
  <meta name="author" content="<?php echo blog_e(BLOG_DEVELOPER_NAME); ?>">
  <meta name="developer" content="<?php echo blog_e(BLOG_DEVELOPER_NAME . ' - ' . BLOG_DEVELOPER_TITLE); ?>">
  <meta name="credit" content="<?php echo blog_e(BLOG_DEVELOPER_CREDIT); ?>">
  <meta name="publisher" content="<?php echo blog_e(BLOG_SITE_COMPANY); ?>">
  <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
  <meta name="googlebot" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
  <meta name="bingbot" content="index, follow">
  <link rel="canonical" href="<?php echo blog_e($canonicalUrl); ?>">
  <link rel="sitemap" type="application/xml" title="Sitemap" href="<?php echo blog_e(blog_url('sitemap.php')); ?>">

  <meta property="og:type" content="website">
  <meta property="og:site_name" content="<?php echo blog_e(BLOG_SITE_NAME); ?>">
  <meta property="og:title" content="<?php echo blog_e($metaTitle); ?>">
  <meta property="og:description" content="<?php echo blog_e($metaDescription); ?>">
  <meta property="og:url" content="<?php echo blog_e($canonicalUrl); ?>">
  <meta property="og:image" content="<?php echo blog_e($metaImage); ?>">

  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?php echo blog_e($metaTitle); ?>">
  <meta name="twitter:description" content="<?php echo blog_e($metaDescription); ?>">
  <meta name="twitter:image" content="<?php echo blog_e($metaImage); ?>">

    <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-6529286790085931"
      crossorigin="anonymous"></script>

  <script type="application/ld+json"><?php echo json_encode($websiteSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?></script>
  <script type="application/ld+json"><?php echo json_encode($organizationSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?></script>
  <script type="application/ld+json"><?php echo json_encode($developerSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?></script>
  <link rel="stylesheet" href="assets/styles.css">
</head>
<body class="blog-public">
  <div class="utility-bar">
    <div class="container utility-inner">
      <div class="utility-links">
        <a href="index.php">News Desk</a>
        <a href="?category=events">Events Calendar</a>
        <a href="mailto:events@cstsghana.com">events@cstsghana.com</a>
      </div>
      <div class="utility-cta">
        <a href="admin/login.php" class="utility-btn">Admin Portal</a>
      </div>
    </div>
  </div>

  <header class="topbar">
    <div class="container topbar-inner">
      <a href="index.php" class="brand logo-only" aria-label="Go to CSTS Blog home">
        <img src="assets/csts-logo.png" alt="CSTS Logo" class="brand-logo">
        CSTS Blog
        <small>News & Events</small>
      </a>
      <nav class="nav-links">
        <a href="index.php">Home</a>
        <a href="?category=news">News</a>
        <a href="?category=events">Events</a>
        <a href="https://cstsghana.com" target="_blank" rel="noopener noreferrer">CSTS Website</a>
        <a href="admin/login.php">Admin</a>
      </nav>
    </div>
  </header>

  <section class="hero hero-wide">
    <div class="container">
      <div class="hero-grid">
        <div class="hero-copy">
          <p class="eyebrow">CSTS Knowledge & Impact Journal</p>
          <h1>News, events, and market signals that matter to Ghanaian leaders.</h1>
          <p>Track board-level intelligence, policy trends, and practical governance updates from CSTS.</p>
          <form class="filters" method="get" action="index.php">
            <input class="input" type="text" name="q" placeholder="Search posts..." value="<?php echo blog_e($q); ?>">
            <select class="select" name="category">
              <option value="all" <?php echo $category === 'all' ? 'selected' : ''; ?>>All categories</option>
              <option value="news" <?php echo $category === 'news' ? 'selected' : ''; ?>>News</option>
              <option value="events" <?php echo $category === 'events' ? 'selected' : ''; ?>>Events</option>
            </select>
            <select class="select" name="source">
              <option value="all" <?php echo $source === 'all' ? 'selected' : ''; ?>>All sources</option>
              <?php foreach ($availableSources as $sourceOption): ?>
                <option value="<?php echo blog_e($sourceOption); ?>" <?php echo $source === $sourceOption ? 'selected' : ''; ?>><?php echo blog_e($sourceOption); ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn" type="submit">Filter</button>
          </form>
        </div>

        <?php if ($featuredPost): ?>
          <a class="hero-feature" href="post.php?slug=<?php echo urlencode($featuredPost['slug']); ?>">
            <div class="hero-feature-image" style="background-image:url('<?php echo blog_e($featuredPost['image_url'] ?: 'https://images.unsplash.com/photo-1454165804606-c3d57bc86b40?auto=format&fit=crop&w=1400&q=80'); ?>');"></div>
            <div class="hero-feature-overlay">
              <span class="badge"><?php echo blog_e($featuredPost['category']); ?></span>
              <h2><?php echo blog_e($featuredPost['title']); ?></h2>
              <p><?php echo blog_e($featuredPost['summary'] ?: blog_excerpt($featuredPost['content'], 135)); ?></p>
            </div>
          </a>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <div class="pulse-strip">
    <div class="container pulse-track">
      <span>Governance Briefing</span>
      <span>CSTS Boardroom Intelligence</span>
      <span>Regulatory Watch: Ghana</span>
      <span>Leadership & Compliance Updates</span>
    </div>
  </div>

  <main class="container content-layout">
    <section class="feed-main">
      <div class="section-head">
        <h2>Recent Publications</h2>
        <a href="index.php?category=all&amp;source=all&amp;page=1">View all</a>
      </div>

      <div class="feed-signal-bar">
        <div class="feed-signal-title">Editorial Desk</div>
        <div class="feed-signal-chips">
          <span class="signal-chip">Latest first</span>
          <span class="signal-chip"><?php echo $totalPosts; ?> stories</span>
          <?php if ($category !== 'all'): ?>
            <span class="signal-chip"><?php echo strtoupper(blog_e($category)); ?></span>
          <?php endif; ?>
          <?php if ($source !== 'all'): ?>
            <span class="signal-chip"><?php echo blog_e($source); ?></span>
          <?php endif; ?>
        </div>
      </div>

      <?php if (empty($posts)): ?>
        <div class="panel" style="margin-top:24px;">No posts found for your filter.</div>
      <?php else: ?>
        <?php if ($leadPost): ?>
          <article class="lead-story">
            <a class="lead-story-image" href="post.php?slug=<?php echo urlencode($leadPost['slug']); ?>" style="background-image:url('<?php echo blog_e($leadPost['image_url'] ?: 'https://images.unsplash.com/photo-1454165804606-c3d57bc86b40?auto=format&fit=crop&w=1400&q=80'); ?>');"></a>
            <div class="lead-story-body">
              <span class="badge"><?php echo blog_e($leadPost['category']); ?></span>
              <h3><a href="post.php?slug=<?php echo urlencode($leadPost['slug']); ?>"><?php echo blog_e($leadPost['title']); ?></a></h3>
              <div class="meta"><?php echo blog_format_date($leadPost['published_at']); ?><?php if (!empty($leadPost['source_name'])): ?> • <?php echo blog_e($leadPost['source_name']); ?><?php endif; ?></div>
              <p class="summary"><?php echo blog_e($leadPost['summary'] ?: blog_excerpt($leadPost['content'], 210)); ?></p>
              <a class="read-more" href="post.php?slug=<?php echo urlencode($leadPost['slug']); ?>">Read full brief</a>
            </div>
          </article>
        <?php endif; ?>

        <?php if (!empty($gridPosts)): ?>
          <div class="grid feed-grid">
            <?php foreach ($gridPosts as $post): ?>
              <article class="card">
                <div class="card-image" style="background-image:url('<?php echo blog_e($post['image_url'] ?: 'https://images.unsplash.com/photo-1454165804606-c3d57bc86b40?auto=format&fit=crop&w=1200&q=80'); ?>');"></div>
                <div class="card-body">
                  <span class="badge"><?php echo blog_e($post['category']); ?></span>
                  <h3><?php echo blog_e($post['title']); ?></h3>
                  <div class="meta"><?php echo blog_format_date($post['published_at']); ?><?php if (!empty($post['source_name'])): ?> • <?php echo blog_e($post['source_name']); ?><?php endif; ?></div>
                  <p class="summary"><?php echo blog_e($post['summary'] ?: blog_excerpt($post['content'])); ?></p>
                  <a class="read-more" href="post.php?slug=<?php echo urlencode($post['slug']); ?>">Read more</a>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if ($totalPages > 1): ?>
          <nav class="pagination" aria-label="Posts pagination">
            <a class="page-link <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo $page <= 1 ? '#' : blog_e($buildPageUrl($page - 1)); ?>">Previous</a>

            <span class="page-status">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>

            <a class="page-link <?php echo $page >= $totalPages ? 'disabled' : ''; ?>" href="<?php echo $page >= $totalPages ? '#' : blog_e($buildPageUrl($page + 1)); ?>">Next</a>
          </nav>
        <?php endif; ?>
      <?php endif; ?>
    </section>

    <aside class="feed-sidebar" aria-label="Important updates and quick panels">
      <section class="sidebar-panel sidebar-highlight">
        <p class="sidebar-kicker">Important</p>
        <h4>Breaking Market Update</h4>
        <?php if ($headlinePost): ?>
          <a class="sidebar-link" href="post.php?slug=<?php echo urlencode($headlinePost['slug']); ?>"><?php echo blog_e($headlinePost['title']); ?></a>
          <p class="meta"><?php echo blog_format_date($headlinePost['published_at']); ?><?php if (!empty($headlinePost['source_name'])): ?> • <?php echo blog_e($headlinePost['source_name']); ?><?php endif; ?></p>
        <?php else: ?>
          <p class="meta">No recent update available.</p>
        <?php endif; ?>
      </section>

      <section class="sidebar-panel">
        <h4>Live Snapshot</h4>
        <div class="sidebar-stats">
          <div>
            <span>Published (24h)</span>
            <strong><?php echo $last24hCount; ?></strong>
          </div>
          <div>
            <span>Total Sources</span>
            <strong><?php echo count($availableSources); ?></strong>
          </div>
        </div>
      </section>

      <section class="sidebar-panel">
        <h4>Top Sources</h4>
        <?php if (empty($sourceMix)): ?>
          <p class="meta">No external source data yet.</p>
        <?php else: ?>
          <ul class="sidebar-list">
            <?php foreach ($sourceMix as $sourceRow): ?>
              <li>
                <span><?php echo blog_e($sourceRow['source_name']); ?></span>
                <strong><?php echo (int) $sourceRow['total_posts']; ?></strong>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>

      <section class="sidebar-panel">
        <h4>Quick Actions</h4>
        <ul class="sidebar-links">
          <li><a href="index.php?category=news">View Business Headlines</a></li>
          <li><a href="index.php?category=events">Check Events</a></li>
          <li><a href="https://cstsghana.com" target="_blank" rel="noopener noreferrer">Visit CSTS Main Site</a></li>
          <li><a href="mailto:events@cstsghana.com">Request Partnership Desk</a></li>
        </ul>
      </section>
    </aside>
  </main>

  <section class="newsletter-band">
    <div class="container">
      <div class="newsletter-card">
        <div class="newsletter-intro">
          <p class="newsletter-kicker">CSTS Briefings</p>
          <h3>Get the latest news right in your inbox.</h3>
          <p class="newsletter-note">Receive practical updates on business, corporate, and marketing trends in Ghana.</p>
        </div>

        <div class="newsletter-form-wrap">
          <?php if ($newsletterSuccess !== ''): ?>
            <div class="flash ok newsletter-flash"><?php echo blog_e($newsletterSuccess); ?></div>
          <?php endif; ?>

          <?php if ($newsletterError !== ''): ?>
            <div class="flash err newsletter-flash"><?php echo blog_e($newsletterError); ?></div>
          <?php endif; ?>

          <form class="newsletter-form" method="post" action="index.php">
            <input type="hidden" name="form_type" value="newsletter_subscribe">

            <label class="newsletter-field">
              <span>Name</span>
              <input class="input" type="text" name="name" placeholder="Your full name" value="<?php echo blog_e($newsletterName); ?>" required>
            </label>

            <label class="newsletter-field">
              <span>Email</span>
              <input class="input" type="email" name="email" placeholder="you@example.com" value="<?php echo blog_e($newsletterEmail); ?>" required>
            </label>

            <button class="btn newsletter-submit" type="submit">Subscribe now</button>
          </form>
        </div>
      </div>
    </div>
  </section>

  <footer class="footer">
    <div class="container footer-grid">
      <section class="footer-brand">
        <a href="index.php" aria-label="Go to CSTS Blog home"><img src="assets/csts-logo.png" alt="CSTS Logo" class="footer-logo"></a>
        <p>Corporate strategy, governance, and market intelligence for leaders in Ghana.</p>
        <div class="footer-socials">
          <a href="https://www.linkedin.com" target="_blank" rel="noopener noreferrer">LinkedIn</a>
          <a href="https://x.com" target="_blank" rel="noopener noreferrer">X</a>
          <a href="https://www.facebook.com" target="_blank" rel="noopener noreferrer">Facebook</a>
        </div>
      </section>

      <section>
        <h4>Explore</h4>
        <ul>
          <li><a href="index.php">Home</a></li>
          <li><a href="index.php?category=news">Business News</a></li>
          <li><a href="index.php?category=events">Events</a></li>
          <li><a href="index.php?category=all&amp;source=all&amp;page=1">All Publications</a></li>
        </ul>
      </section>

      <section>
        <h4>Company</h4>
        <ul>
          <li><a href="https://cstsghana.com" target="_blank" rel="noopener noreferrer">About CSTS</a></li>
          <li><a href="https://cstsghana.com" target="_blank" rel="noopener noreferrer">Services</a></li>
          <li><a href="https://cstsghana.com" target="_blank" rel="noopener noreferrer">Insights</a></li>
          <li><a href="mailto:events@cstsghana.com">Contact Team</a></li>
        </ul>
      </section>

      <section>
        <h4>Contact</h4>
        <ul>
          <li><a href="mailto:events@cstsghana.com">events@cstsghana.com</a></li>
          <li><a href="https://cstsghana.com" target="_blank" rel="noopener noreferrer">www.cstsghana.com</a></li>
          <li>Accra, Ghana</li>
          <li><a href="admin/login.php">Admin Portal</a></li>
        </ul>
      </section>
    </div>

    <div class="footer-bottom">
      <div class="container footer-bottom-inner">
        <span>&copy; <?php echo date('Y'); ?> CSTS Blog. All rights reserved.</span>
        <div class="footer-bottom-links">
          <a href="https://cstsghana.com" target="_blank" rel="noopener noreferrer">Main Website</a>
          <a href="mailto:events@cstsghana.com">Support</a>
        </div>
      </div>
    </div>
  </footer>
</body>
</html>
