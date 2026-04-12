<?php
require_once __DIR__ . '/includes/config.php';

$slug = trim($_GET['slug'] ?? '');
if ($slug === '') {
    http_response_code(404);
    exit('Post not found.');
}

$pdo = blog_db();
$stmt = $pdo->prepare('SELECT title, summary, content, category, image_url, source_name, source_url, published_at FROM posts WHERE slug = :slug AND status = :status LIMIT 1');
$stmt->execute([':slug' => $slug, ':status' => 'published']);
$post = $stmt->fetch();

if (!$post) {
    http_response_code(404);
    exit('Post not found.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo blog_e($post['title']); ?> | CSTS Blog</title>
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
      <a href="index.php" class="brand logo-only" aria-label="Go to CSTS Blog home"><img src="assets/csts-logo.png" alt="CSTS Logo" class="brand-logo">CSTS Blog<small>News & Events</small></a>
      <nav class="nav-links">
        <a href="index.php">Back to blog</a>
        <a href="https://cstsghana.com" target="_blank" rel="noopener noreferrer">CSTS Website</a>
        <a href="admin/login.php">Admin</a>
      </nav>
    </div>
  </header>

  <section class="hero hero-wide post-hero">
    <div class="container">
      <p class="eyebrow">Article</p>
      <h1 class="post-hero-title"><?php echo blog_e($post['title']); ?></h1>
      <div class="meta post-hero-meta"><?php echo blog_format_date($post['published_at']); ?> • <?php echo strtoupper(blog_e($post['category'])); ?></div>
    </div>
  </section>

  <main class="container">
    <article class="article article-wide">
      <span class="badge"><?php echo blog_e($post['category']); ?></span>
      <?php if (!empty($post['summary'])): ?>
        <p class="summary" style="font-size:16px;"><?php echo blog_e($post['summary']); ?></p>
      <?php endif; ?>
      <?php if (!empty($post['image_url'])): ?>
        <img src="<?php echo blog_e($post['image_url']); ?>" alt="<?php echo blog_e($post['title']); ?>" style="width:100%; border-radius:10px; margin:18px 0;">
      <?php endif; ?>
      <?php if (!empty($post['source_url'])): ?>
        <p class="meta" style="margin: 0 0 12px;">Source: <a href="<?php echo blog_e($post['source_url']); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php echo blog_e($post['source_name'] ?: 'External'); ?></a></p>
      <?php endif; ?>
      <div class="article-content"><?php echo $post['content']; ?></div>
    </article>
  </main>

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
          <li><a href="index.php?category=all">All Publications</a></li>
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
