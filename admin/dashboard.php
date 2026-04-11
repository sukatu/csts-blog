<?php
require_once __DIR__ . '/../includes/auth.php';
blog_require_admin();

$pdo = blog_db();
$totalPosts = (int) $pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn();
$publishedPosts = (int) $pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'published'")->fetchColumn();
$draftPosts = (int) $pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'draft'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Blog Admin Dashboard</title>
  <link rel="stylesheet" href="../assets/styles.css">
</head>
<body>
  <header class="topbar">
    <div class="container topbar-inner">
      <div class="brand"><img src="../assets/csts-logo.png" alt="CSTS Logo" class="brand-logo">CSTS Blog Admin<small>Welcome, <?php echo blog_e(blog_admin_username()); ?></small></div>
      <nav class="nav-links">
        <a href="../index.php">View Site</a>
        <a href="posts.php">Manage Posts</a>
        <a href="logout.php">Logout</a>
      </nav>
    </div>
  </header>

  <main class="container admin-shell">
    <div class="panel">
      <h2 style="margin-top:0; color:var(--navy);">Dashboard</h2>
      <p class="meta">Manage the CSTS blog content from here.</p>
      <div class="grid" style="grid-template-columns:repeat(3,minmax(0,1fr)); margin:14px 0 0;">
        <div class="card"><div class="card-body"><h3><?php echo $totalPosts; ?></h3><p class="meta">Total posts</p></div></div>
        <div class="card"><div class="card-body"><h3><?php echo $publishedPosts; ?></h3><p class="meta">Published</p></div></div>
        <div class="card"><div class="card-body"><h3><?php echo $draftPosts; ?></h3><p class="meta">Drafts</p></div></div>
      </div>
      <p style="margin-top:16px;"><a class="btn" href="posts.php">Go to post manager</a></p>
    </div>
  </main>
</body>
</html>
