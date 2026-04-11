<?php
require_once __DIR__ . '/../includes/auth.php';
blog_require_admin();

$pdo = blog_db();
$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: posts.php');
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM posts WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$post = $stmt->fetch();

if (!$post) {
    header('Location: posts.php');
    exit;
}

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
        $slug = blog_slugify($title);
        $check = $pdo->prepare('SELECT COUNT(*) FROM posts WHERE slug = :slug AND id != :id');
        $check->execute([':slug' => $slug, ':id' => $id]);
        if ((int) $check->fetchColumn() > 0) {
            $slug .= '-' . $id;
        }

        $update = $pdo->prepare('UPDATE posts SET title = :title, slug = :slug, summary = :summary, content = :content, category = :category, image_url = :image_url, status = :status, updated_at = :updated_at WHERE id = :id');
        $update->execute([
            ':title' => $title,
            ':slug' => $slug,
            ':summary' => $summary,
            ':content' => $content,
            ':category' => $category,
            ':image_url' => $imageUrl,
            ':status' => $status,
            ':updated_at' => date('c'),
            ':id' => $id,
        ]);

        header('Location: posts.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit Post | Blog Admin</title>
  <link rel="stylesheet" href="../assets/styles.css">
</head>
<body>
  <header class="topbar">
    <div class="container topbar-inner">
      <div class="brand"><img src="../assets/csts-logo.png" alt="CSTS Logo" class="brand-logo">Edit Post</div>
      <nav class="nav-links">
        <a href="posts.php">Back</a>
        <a href="logout.php">Logout</a>
      </nav>
    </div>
  </header>

  <main class="container admin-shell">
    <div class="panel">
      <?php if ($error): ?><div class="flash err"><?php echo blog_e($error); ?></div><?php endif; ?>
      <form method="post">
        <label>Title</label>
        <input class="input" name="title" value="<?php echo blog_e($post['title']); ?>" required>

        <label style="margin-top:10px; display:block;">Summary</label>
        <textarea class="textarea" name="summary" rows="3"><?php echo blog_e($post['summary']); ?></textarea>

        <label style="margin-top:10px; display:block;">Content (HTML supported)</label>
        <textarea class="textarea" name="content" rows="8" required><?php echo blog_e($post['content']); ?></textarea>

        <div style="display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:10px; margin-top:10px;">
          <div>
            <label>Category</label>
            <select class="select" name="category">
              <option value="news" <?php echo $post['category'] === 'news' ? 'selected' : ''; ?>>News</option>
              <option value="events" <?php echo $post['category'] === 'events' ? 'selected' : ''; ?>>Events</option>
            </select>
          </div>
          <div>
            <label>Status</label>
            <select class="select" name="status">
              <option value="published" <?php echo $post['status'] === 'published' ? 'selected' : ''; ?>>Published</option>
              <option value="draft" <?php echo $post['status'] === 'draft' ? 'selected' : ''; ?>>Draft</option>
            </select>
          </div>
          <div>
            <label>Image URL</label>
            <input class="input" name="image_url" value="<?php echo blog_e($post['image_url'] ?? ''); ?>">
          </div>
        </div>

        <button class="btn" style="margin-top:12px;" type="submit">Update Post</button>
      </form>
    </div>
  </main>
</body>
</html>
