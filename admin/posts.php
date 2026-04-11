<?php
require_once __DIR__ . '/../includes/auth.php';
blog_require_admin();

$pdo = blog_db();
$flash = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $summary = trim($_POST['summary'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $category = $_POST['category'] ?? 'news';
    $status = $_POST['status'] ?? 'published';
    $imageUrl = trim($_POST['image_url'] ?? '');

    if ($title === '' || $content === '') {
        $error = 'Title and content are required.';
    } elseif (!in_array($category, ['news', 'events'], true) || !in_array($status, ['draft', 'published'], true)) {
        $error = 'Invalid category or status.';
    } else {
        $baseSlug = blog_slugify($title);
        $slug = $baseSlug;
        $i = 2;
        while (true) {
            $check = $pdo->prepare('SELECT COUNT(*) FROM posts WHERE slug = :slug');
            $check->execute([':slug' => $slug]);
            if ((int) $check->fetchColumn() === 0) {
                break;
            }
            $slug = $baseSlug . '-' . $i;
            $i++;
        }

        $stmt = $pdo->prepare('INSERT INTO posts (title, slug, summary, content, category, image_url, status, published_at, created_at, updated_at)
            VALUES (:title, :slug, :summary, :content, :category, :image_url, :status, :published_at, :created_at, :updated_at)');

        $now = date('c');
        $stmt->execute([
            ':title' => $title,
            ':slug' => $slug,
            ':summary' => $summary,
            ':content' => $content,
            ':category' => $category,
            ':image_url' => $imageUrl,
            ':status' => $status,
            ':published_at' => $now,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        $flash = 'Post created successfully.';
    }
}

$posts = $pdo->query('SELECT id, title, slug, category, status, published_at, updated_at FROM posts ORDER BY updated_at DESC')->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manage Posts | Blog Admin</title>
  <link rel="stylesheet" href="../assets/styles.css">
</head>
<body>
  <header class="topbar">
    <div class="container topbar-inner">
      <div class="brand"><img src="../assets/csts-logo.png" alt="CSTS Logo" class="brand-logo">CSTS Blog Admin</div>
      <nav class="nav-links">
        <a href="dashboard.php">Dashboard</a>
        <a href="../index.php">View Site</a>
        <a href="logout.php">Logout</a>
      </nav>
    </div>
  </header>

  <main class="container admin-shell">
    <div class="panel">
      <h2 style="margin-top:0; color:var(--navy);">Create Post</h2>
      <?php if ($flash): ?><div class="flash ok"><?php echo blog_e($flash); ?></div><?php endif; ?>
      <?php if ($error): ?><div class="flash err"><?php echo blog_e($error); ?></div><?php endif; ?>
      <form method="post">
        <label>Title</label>
        <input class="input" name="title" required>

        <label style="margin-top:10px; display:block;">Summary</label>
        <textarea class="textarea" name="summary" rows="3"></textarea>

        <label style="margin-top:10px; display:block;">Content (HTML supported)</label>
        <textarea class="textarea" name="content" rows="8" required></textarea>

        <div style="display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:10px; margin-top:10px;">
          <div>
            <label>Category</label>
            <select class="select" name="category">
              <option value="news">News</option>
              <option value="events">Events</option>
            </select>
          </div>
          <div>
            <label>Status</label>
            <select class="select" name="status">
              <option value="published">Published</option>
              <option value="draft">Draft</option>
            </select>
          </div>
          <div>
            <label>Image URL</label>
            <input class="input" name="image_url" placeholder="https://...">
          </div>
        </div>

        <button class="btn" style="margin-top:12px;" type="submit">Save Post</button>
      </form>
    </div>

    <div class="panel">
      <h2 style="margin-top:0; color:var(--navy);">All Posts</h2>
      <table class="table">
        <thead>
          <tr>
            <th>Title</th>
            <th>Category</th>
            <th>Status</th>
            <th>Updated</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($posts as $post): ?>
            <tr>
              <td>
                <strong><?php echo blog_e($post['title']); ?></strong><br>
                <span class="meta">/<?php echo blog_e($post['slug']); ?></span>
              </td>
              <td><?php echo blog_e($post['category']); ?></td>
              <td><?php echo blog_e($post['status']); ?></td>
              <td><?php echo blog_format_date($post['updated_at']); ?></td>
              <td>
                <div class="inline-actions">
                  <a class="btn btn-outline" href="edit-post.php?id=<?php echo (int) $post['id']; ?>">Edit</a>
                  <form action="delete-post.php" method="post" onsubmit="return confirm('Delete this post?');">
                    <input type="hidden" name="id" value="<?php echo (int) $post['id']; ?>">
                    <button class="btn btn-outline" type="submit">Delete</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </main>
</body>
</html>
