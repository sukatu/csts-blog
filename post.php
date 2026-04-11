<?php
require_once __DIR__ . '/includes/config.php';

$slug = trim($_GET['slug'] ?? '');
if ($slug === '') {
    http_response_code(404);
    exit('Post not found.');
}

$pdo = blog_db();
$stmt = $pdo->prepare('SELECT title, summary, content, category, image_url, published_at FROM posts WHERE slug = :slug AND status = :status LIMIT 1');
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
      <div class="brand logo-only"><img src="assets/csts-logo.png" alt="CSTS Logo" class="brand-logo">CSTS Blog<small>News & Events</small></div>
      <nav class="nav-links">
        <a href="index.php">Back to blog</a>
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
      <div class="article-content"><?php echo $post['content']; ?></div>
    </article>
  </main>

  <footer class="footer">
    <div class="container">&copy; <?php echo date('Y'); ?> CSTS Blog.</div>
  </footer>
</body>
</html>
