<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';
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
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $title = trim((string) ($_POST['title'] ?? ''));
  $summary = trim((string) ($_POST['summary'] ?? ''));
  $content = blog_content_html((string) ($_POST['content'] ?? ''));
  $category = (string) ($_POST['category'] ?? 'news');
  $status = (string) ($_POST['status'] ?? 'published');
  $imageUrl = trim((string) ($_POST['image_url'] ?? ''));
  $removeImage = (string) ($_POST['remove_image'] ?? '') === '1';
  $existingImage = (string) ($post['image_url'] ?? '');

    if ($title === '' || blog_content_is_empty($content)) {
        $error = 'Title and content are required.';
  } elseif (!in_array($category, ['news', 'events', 'market-insights'], true) || !in_array($status, ['draft', 'published'], true)) {
        $error = 'Invalid category or status.';
    } else {
    if ($removeImage) {
      blog_delete_uploaded_image($existingImage);
      $imageUrl = '';
    }

    try {
      if (isset($_FILES['image_file']) && is_array($_FILES['image_file'])) {
        $uploadedPath = blog_store_uploaded_image($_FILES['image_file']);
        if ($uploadedPath !== '') {
          blog_delete_uploaded_image($existingImage);
          $imageUrl = $uploadedPath;
        }
      }
    } catch (RuntimeException $exception) {
      $error = $exception->getMessage();
    }

    if ($error !== '') {
      $post['title'] = $title;
      $post['summary'] = $summary;
      $post['content'] = $content;
      $post['category'] = $category;
      $post['status'] = $status;
      $post['image_url'] = $imageUrl;
    }

        $slug = blog_slugify($title);
        $check = $pdo->prepare('SELECT COUNT(*) FROM posts WHERE slug = :slug AND id != :id');
        $check->execute([':slug' => $slug, ':id' => $id]);
        if ((int) $check->fetchColumn() > 0) {
            $slug .= '-' . $id;
        }

    if ($error === '') {
      $update = $pdo->prepare('UPDATE posts SET title = :title, slug = :slug, summary = :summary, content = :content, category = :category, image_url = :image_url, status = :status, updated_at = :updated_at WHERE id = :id');
      $update->execute([
        ':title' => $title,
        ':slug' => $slug,
        ':summary' => $summary,
        ':content' => $content,
        ':category' => $category,
        ':image_url' => $imageUrl,
        ':status' => $status,
        ':updated_at' => date('Y-m-d H:i:s'),
        ':id' => $id,
      ]);

      $reload = $pdo->prepare('SELECT * FROM posts WHERE id = :id LIMIT 1');
      $reload->execute([':id' => $id]);
      $post = $reload->fetch() ?: $post;
      $flash = 'Post updated successfully.';
    }
    }
}

$postImage = (string) ($post['image_url'] ?? '');
$postImageSrc = $postImage;
if ($postImage !== '' && blog_is_local_upload_path($postImage)) {
  $postImageSrc = '../' . ltrim($postImage, '/');
}
?>
<?php
$adminPageTitle  = 'Edit Post';
$adminActivePage = 'posts';
require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/sidebar.php';
?>

    <header class="a-topbar">
      <div class="a-topbar-left">
        <span class="a-topbar-title">Edit Post</span>
        <ol class="a-breadcrumb">
          <li><a href="dashboard.php">Dashboard</a></li>
          <li><a href="posts.php">Posts</a></li>
          <li>Edit</li>
        </ol>
      </div>
      <div class="a-topbar-right">
        <a href="posts.php" class="a-topbar-btn">← Back to Posts</a>
        <div class="a-avatar"><?php echo htmlspecialchars(strtoupper(substr(blog_admin_username(), 0, 1)), ENT_QUOTES, 'UTF-8'); ?></div>
      </div>
    </header>

    <main class="a-content">

      <?php if ($flash): ?>
        <div class="a-flash a-flash--ok">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 100 20A10 10 0 0012 2zm-2 14.5l-4-4 1.41-1.41L10 13.67l6.59-6.58L18 8.5l-8 8z"/></svg>
          <?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?>
        </div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="a-flash a-flash--err">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 100 20A10 10 0 0012 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
          <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
        </div>
      <?php endif; ?>

      <div class="a-panel" style="max-width:860px;">
        <div class="a-panel-head">
          <div>
            <h2 class="a-panel-title">Edit Post</h2>
            <p class="a-panel-sub">ID #<?php echo (int)$post['id']; ?> &mdash; slug: /<?php echo htmlspecialchars((string)$post['slug'], ENT_QUOTES, 'UTF-8'); ?></p>
          </div>
          <a href="../post.php?slug=<?php echo urlencode((string)$post['slug']); ?>" target="_blank" rel="noopener" class="a-btn a-btn--ghost a-btn--sm">View live →</a>
        </div>
        <div class="a-panel-body">
          <form method="post" enctype="multipart/form-data">
            <div class="a-form-grid" style="gap:16px;">
              <div class="a-form-full a-field">
                <label class="a-label" for="ep-title">Title</label>
                <input class="a-input" id="ep-title" name="title" value="<?php echo htmlspecialchars((string)$post['title'], ENT_QUOTES, 'UTF-8'); ?>" required>
              </div>
              <div class="a-form-full a-field">
                <label class="a-label" for="ep-summary">Summary</label>
                <textarea class="a-textarea" id="ep-summary" name="summary" rows="2"><?php echo htmlspecialchars((string)$post['summary'], ENT_QUOTES, 'UTF-8'); ?></textarea>
              </div>
              <div class="a-form-full a-field">
                <label class="a-label" for="ep-content">Content</label>
                <textarea class="a-textarea" id="ep-content" name="content" data-blog-editor rows="12" required><?php echo htmlspecialchars($error !== '' ? (string) ($_POST['content'] ?? '') : (string) $post['content'], ENT_QUOTES, 'UTF-8'); ?></textarea>
              </div>
              <div class="a-field">
                <label class="a-label" for="ep-category">Category</label>
                <select class="a-select" id="ep-category" name="category">
                  <option value="news"           <?php if ($post['category'] === 'news')            echo 'selected'; ?>>News</option>
                  <option value="events"         <?php if ($post['category'] === 'events')          echo 'selected'; ?>>Events</option>
                  <option value="market-insights"<?php if ($post['category'] === 'market-insights') echo 'selected'; ?>>Market Insights</option>
                </select>
              </div>
              <div class="a-field">
                <label class="a-label" for="ep-status">Status</label>
                <select class="a-select" id="ep-status" name="status">
                  <option value="published"<?php if ($post['status'] === 'published') echo 'selected'; ?>>Published</option>
                  <option value="draft"    <?php if ($post['status'] === 'draft')     echo 'selected'; ?>>Draft</option>
                </select>
              </div>
              <div class="a-form-full a-field">
                <label class="a-label" for="ep-image-url">Image URL <span>(leave blank to keep current)</span></label>
                <input class="a-input" id="ep-image-url" name="image_url" value="<?php echo htmlspecialchars((string)($post['image_url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://…">
              </div>
              <?php if ($postImage !== ''): ?>
                <div class="a-form-full">
                  <p class="a-label">Current Image</p>
                  <img class="a-img-preview" src="<?php echo htmlspecialchars($postImageSrc, ENT_QUOTES, 'UTF-8'); ?>" alt="Current post image" style="max-width:200px;max-height:120px;margin-bottom:8px;">
                  <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--a-muted);cursor:pointer;">
                    <input type="checkbox" name="remove_image" value="1">
                    Remove current image
                  </label>
                </div>
              <?php endif; ?>
              <div class="a-form-full a-field">
                <label class="a-label" for="ep-image-file">Upload New Image <span>(max 5MB — replaces existing uploaded file)</span></label>
                <input class="a-input" type="file" id="ep-image-file" name="image_file" accept="image/jpeg,image/png,image/webp,image/gif">
              </div>
            </div>
            <div style="margin-top:20px;display:flex;gap:10px;align-items:center;">
              <button class="a-btn a-btn--primary" type="submit">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M17 3H5a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2V7l-4-4zm0 16H5V5h11.17L19 7.83V19zm-7-7a3 3 0 100 6 3 3 0 000-6zm-5-6h8V5H8v1h1z"/></svg>
                Update Post
              </button>
              <a class="a-btn a-btn--ghost" href="posts.php">Cancel</a>
            </div>
          </form>
        </div>
      </div>

      <?php require __DIR__ . '/partials/footer.php'; ?>

    </main>
  </div><!-- /.a-main -->
</div><!-- /.a-app -->
<script src="../assets/vendor/quill/quill.js"></script>
<script src="../assets/editor.js"></script>
</body>
</html>
