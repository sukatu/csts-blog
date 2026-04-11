<?php
require_once __DIR__ . '/includes/config.php';

$pdo = blog_db();
$q = trim($_GET['q'] ?? '');
$category = $_GET['category'] ?? 'all';

$where = ['status = :status'];
$params = [':status' => 'published'];

if ($q !== '') {
    $where[] = '(title LIKE :q OR summary LIKE :q OR content LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}

if (in_array($category, ['news', 'events'], true)) {
    $where[] = 'category = :category';
    $params[':category'] = $category;
}

$sql = 'SELECT id, title, slug, summary, content, category, image_url, published_at
        FROM posts
        WHERE ' . implode(' AND ', $where) . '
  ORDER BY published_at DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$posts = $stmt->fetchAll();

$featuredPost = $posts[0] ?? null;
$listPosts = array_slice($posts, 1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CSTS Blog | News & Events</title>
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
      <div class="brand logo-only">
        <img src="assets/csts-logo.png" alt="CSTS Logo" class="brand-logo">
        CSTS Blog
        <small>News & Events</small>
      </div>
      <nav class="nav-links">
        <a href="index.php">Home</a>
        <a href="?category=news">News</a>
        <a href="?category=events">Events</a>
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

  <main class="container">
    <div class="section-head">
      <h2>Recent Publications</h2>
      <a href="?category=all">View all</a>
    </div>

    <?php if (empty($posts)): ?>
      <div class="panel" style="margin-top:24px;">No posts found for your filter.</div>
    <?php else: ?>
      <div class="grid">
        <?php foreach ($listPosts ?: $posts as $post): ?>
          <article class="card">
            <div class="card-image" style="background-image:url('<?php echo blog_e($post['image_url'] ?: 'https://images.unsplash.com/photo-1454165804606-c3d57bc86b40?auto=format&fit=crop&w=1200&q=80'); ?>');"></div>
            <div class="card-body">
              <span class="badge"><?php echo blog_e($post['category']); ?></span>
              <h3><?php echo blog_e($post['title']); ?></h3>
              <div class="meta"><?php echo blog_format_date($post['published_at']); ?></div>
              <p class="summary"><?php echo blog_e($post['summary'] ?: blog_excerpt($post['content'])); ?></p>
              <a class="read-more" href="post.php?slug=<?php echo urlencode($post['slug']); ?>">Read more</a>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>

  <section class="newsletter-band">
    <div class="container">
      <h3>CSTS Governance Newsletter</h3>
    </div>
  </section>

  <footer class="footer">
    <div class="container">&copy; <?php echo date('Y'); ?> CSTS Blog. All rights reserved.</div>
  </footer>
</body>
</html>
