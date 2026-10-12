<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';
blog_require_admin();

$pdo = blog_db();
$flash = '';
$error = '';

$q = trim((string) ($_GET['q'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? 'all');
$categoryFilter = (string) ($_GET['category'] ?? 'all');

$allowedStatuses = ['all', 'draft', 'published'];
$allowedCategories = ['all', 'news', 'events', 'market-insights'];

if (!in_array($statusFilter, $allowedStatuses, true)) {
  $statusFilter = 'all';
}

if (!in_array($categoryFilter, $allowedCategories, true)) {
  $categoryFilter = 'all';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string) ($_POST['action'] ?? 'create');

  if ($action === 'toggle_status') {
    $id = (int) ($_POST['id'] ?? 0);
    $newStatus = (string) ($_POST['new_status'] ?? 'draft');

    if ($id > 0 && in_array($newStatus, ['draft', 'published'], true)) {
      $toggle = $pdo->prepare('UPDATE posts SET status = :status, updated_at = :updated_at WHERE id = :id');
      $toggle->execute([
        ':status' => $newStatus,
        ':updated_at' => date('Y-m-d H:i:s'),
        ':id' => $id,
      ]);
      $flash = 'Post status updated.';
    } else {
      $error = 'Unable to update post status.';
    }
    } else {
    $title = trim((string) ($_POST['title'] ?? ''));
    $summary = trim((string) ($_POST['summary'] ?? ''));
    $content = blog_content_html((string) ($_POST['content'] ?? ''));
    $category = (string) ($_POST['category'] ?? 'news');
    $status = (string) ($_POST['status'] ?? 'published');
    $imageUrl = trim((string) ($_POST['image_url'] ?? ''));

    if ($title === '' || blog_content_is_empty($content)) {
      $error = 'Title and content are required.';
    } elseif (!in_array($category, ['news', 'events', 'market-insights'], true) || !in_array($status, ['draft', 'published'], true)) {
      $error = 'Invalid category or status.';
    } else {
      try {
        if (isset($_FILES['image_file']) && is_array($_FILES['image_file'])) {
          $uploadedPath = blog_store_uploaded_image($_FILES['image_file']);
          if ($uploadedPath !== '') {
            $imageUrl = $uploadedPath;
          }
        }
      } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
            }

      if ($error === '') {
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

        $stmt = $pdo->prepare('INSERT INTO posts (author_user_id, title, slug, summary, content, category, image_url, status, published_at, created_at, updated_at)
          VALUES (:author_user_id, :title, :slug, :summary, :content, :category, :image_url, :status, :published_at, :created_at, :updated_at)');

        $now = date('Y-m-d H:i:s');
        $stmt->execute([
          ':author_user_id' => (int) ($_SESSION['blog_admin_id'] ?? 0) ?: null,
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
    }
}

$filters = [];
$where = [];

if ($q !== '') {
  $where[] = '(title LIKE :q OR summary LIKE :q OR slug LIKE :q)';
  $filters[':q'] = '%' . $q . '%';
}

if ($statusFilter !== 'all') {
  $where[] = 'status = :status';
  $filters[':status'] = $statusFilter;
}

if ($categoryFilter !== 'all') {
  $where[] = 'category = :category';
  $filters[':category'] = $categoryFilter;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM posts ' . $whereSql);
$countStmt->execute($filters);
$totalPosts = (int) $countStmt->fetchColumn();

$perPage = 12;
$totalPages = max(1, (int) ceil($totalPosts / $perPage));
$page = max(1, (int) ($_GET['page'] ?? 1));
if ($page > $totalPages) {
  $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$sql = 'SELECT id, title, slug, category, status, image_url, source_name, published_at, updated_at
  FROM posts ' . $whereSql . ' ORDER BY updated_at DESC LIMIT :limit OFFSET :offset';
$postStmt = $pdo->prepare($sql);

foreach ($filters as $key => $value) {
  $postStmt->bindValue($key, $value, PDO::PARAM_STR);
}

$postStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$postStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$postStmt->execute();
$posts = $postStmt->fetchAll();

$pageBaseParams = [
  'q' => $q,
  'status' => $statusFilter,
  'category' => $categoryFilter,
];
?>
<?php
$adminPageTitle  = 'Manage Posts';
$adminActivePage = 'posts';
require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/sidebar.php';
?>

    <header class="a-topbar">
      <div class="a-topbar-left">
        <span class="a-topbar-title">Posts</span>
        <ol class="a-breadcrumb"><li><a href="dashboard.php">Dashboard</a></li><li>Posts</li></ol>
      </div>
      <div class="a-topbar-right">
        <button class="a-topbar-btn a-topbar-btn--primary" onclick="document.getElementById('createPanel').scrollIntoView({behavior:'smooth'})">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M19 3H5a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2V5a2 2 0 00-2-2zm-2 8h-4v4h-2v-4H7v-2h4V5h2v4h4v2z"/></svg>
          New Post
        </button>
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

      <!-- Create Post panel -->
      <div class="a-panel" id="createPanel" style="margin-bottom:24px;">
        <div class="a-panel-head">
          <div><h2 class="a-panel-title">Create New Post</h2><p class="a-panel-sub">Draft or publish with optional image attachment</p></div>
        </div>
        <div class="a-panel-body">
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="create">
            <div class="a-form-grid" style="gap:14px;">
              <div class="a-form-full a-field">
                <label class="a-label" for="cp-title">Title</label>
                <input class="a-input" id="cp-title" name="title" required placeholder="Post headline…" value="<?php echo htmlspecialchars($error !== '' ? (string) ($_POST['title'] ?? '') : '', ENT_QUOTES, 'UTF-8'); ?>">
              </div>
              <div class="a-form-full a-field">
                <label class="a-label" for="cp-summary">Summary <span>(optional)</span></label>
                <textarea class="a-textarea" id="cp-summary" name="summary" rows="2" placeholder="Short description…"><?php echo htmlspecialchars($error !== '' ? (string) ($_POST['summary'] ?? '') : '', ENT_QUOTES, 'UTF-8'); ?></textarea>
              </div>
              <div class="a-form-full a-field">
                <label class="a-label" for="cp-content">Content</label>
                <textarea class="a-textarea" id="cp-content" name="content" data-blog-editor rows="8" required placeholder="Full post body…"><?php echo htmlspecialchars($error !== '' ? (string) ($_POST['content'] ?? '') : '', ENT_QUOTES, 'UTF-8'); ?></textarea>
              </div>
              <div class="a-field">
                <label class="a-label" for="cp-category">Category</label>
                <select class="a-select" id="cp-category" name="category">
                  <option value="news">News</option>
                  <option value="events">Events</option>
                  <option value="market-insights">Market Insights</option>
                </select>
              </div>
              <div class="a-field">
                <label class="a-label" for="cp-status">Status</label>
                <select class="a-select" id="cp-status" name="status">
                  <option value="published">Published</option>
                  <option value="draft">Draft</option>
                </select>
              </div>
              <div class="a-field">
                <label class="a-label" for="cp-image-url">Image URL <span>(optional)</span></label>
                <input class="a-input" id="cp-image-url" name="image_url" placeholder="https://…">
              </div>
              <div class="a-field">
                <label class="a-label" for="cp-image-file">Upload Image <span>(max 5MB)</span></label>
                <input class="a-input" type="file" id="cp-image-file" name="image_file" accept="image/jpeg,image/png,image/webp,image/gif">
                <span class="a-field-hint">If both URL and file provided, uploaded file is used.</span>
              </div>
            </div>
            <div style="margin-top:18px;">
              <button class="a-btn a-btn--primary" type="submit">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M17 3H5a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2V7l-4-4zm0 16H5V5h11.17L19 7.83V19zm-7-7a3 3 0 100 6 3 3 0 000-6zm-5-6h8V5H8v1h1zm0 0H8z"/></svg>
                Save Post
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- Posts list -->
      <div class="a-panel">
        <div class="a-panel-head">
          <div>
            <h2 class="a-panel-title">All Posts</h2>
            <p class="a-panel-sub">Showing <?php echo count($posts); ?> of <?php echo $totalPosts; ?></p>
          </div>
        </div>

        <!-- Filters -->
        <div style="padding:14px 22px;border-bottom:1px solid var(--a-line);">
          <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
            <div class="a-field" style="flex:1;min-width:180px;">
              <input class="a-input" name="q" placeholder="Search title, slug…" value="<?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <select class="a-select" name="status" style="max-width:160px;">
              <option value="all"       <?php if ($statusFilter   === 'all')              echo 'selected'; ?>>All statuses</option>
              <option value="published" <?php if ($statusFilter   === 'published')        echo 'selected'; ?>>Published</option>
              <option value="draft"     <?php if ($statusFilter   === 'draft')            echo 'selected'; ?>>Draft</option>
            </select>
            <select class="a-select" name="category" style="max-width:180px;">
              <option value="all"            <?php if ($categoryFilter === 'all')            echo 'selected'; ?>>All categories</option>
              <option value="news"           <?php if ($categoryFilter === 'news')           echo 'selected'; ?>>News</option>
              <option value="events"         <?php if ($categoryFilter === 'events')         echo 'selected'; ?>>Events</option>
              <option value="market-insights"<?php if ($categoryFilter === 'market-insights') echo 'selected'; ?>>Market Insights</option>
            </select>
            <button class="a-btn a-btn--primary" type="submit">Filter</button>
            <a class="a-btn a-btn--ghost" href="posts.php">Reset</a>
          </form>
        </div>

        <?php if (empty($posts)): ?>
          <div class="a-empty">
            <div class="a-empty-icon">📄</div>
            <div class="a-empty-title">No posts found</div>
            <div class="a-empty-sub">Try adjusting your filters or create a new post above.</div>
          </div>
        <?php else: ?>
          <div class="a-table-wrap">
            <table class="a-table">
              <thead>
                <tr>
                  <th>Post</th>
                  <th>Category</th>
                  <th>Status</th>
                  <th>Image</th>
                  <th>Updated</th>
                  <th style="text-align:right;">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($posts as $post): ?>
                  <?php
                    $thumbSrc = (string)($post['image_url'] ?? '');
                    if ($thumbSrc !== '' && blog_is_local_upload_path($thumbSrc)) {
                        $thumbSrc = '../' . ltrim($thumbSrc, '/');
                    }
                  ?>
                  <tr>
                    <td style="max-width:320px;">
                      <div style="font-weight:600;color:var(--a-ink);line-height:1.3;margin-bottom:3px;" class="a-truncate"><?php echo htmlspecialchars((string)$post['title'], ENT_QUOTES, 'UTF-8'); ?></div>
                      <div style="font-size:11.5px;color:var(--a-dim);">/<?php echo htmlspecialchars((string)$post['slug'], ENT_QUOTES, 'UTF-8'); ?></div>
                      <?php if (!empty($post['source_name'])): ?>
                        <div style="font-size:11px;color:var(--a-dim);margin-top:2px;">Source: <?php echo htmlspecialchars((string)$post['source_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                      <?php endif; ?>
                    </td>
                    <td><span class="a-badge a-badge--blue"><?php echo htmlspecialchars((string)$post['category'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                    <td>
                      <?php if ($post['status'] === 'published'): ?>
                        <span class="a-badge a-badge--green">Published</span>
                      <?php else: ?>
                        <span class="a-badge a-badge--amber">Draft</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($thumbSrc !== ''): ?>
                        <img class="a-img-preview" style="max-width:80px;max-height:50px;" src="<?php echo htmlspecialchars($thumbSrc, ENT_QUOTES, 'UTF-8'); ?>" alt="">
                      <?php else: ?>
                        <span style="font-size:12px;color:var(--a-dim);">—</span>
                      <?php endif; ?>
                    </td>
                    <td style="font-size:12px;color:var(--a-muted);white-space:nowrap;"><?php echo blog_format_date((string)$post['updated_at']); ?></td>
                    <td>
                      <div style="display:flex;gap:6px;justify-content:flex-end;align-items:center;flex-wrap:nowrap;">
                        <a class="a-btn a-btn--ghost a-btn--xs" href="../post.php?slug=<?php echo urlencode((string)$post['slug']); ?>" target="_blank" rel="noopener">View</a>
                        <a class="a-btn a-btn--ghost a-btn--xs" href="edit-post.php?id=<?php echo (int)$post['id']; ?>">Edit</a>
                        <form method="post" style="margin:0;">
                          <input type="hidden" name="action" value="toggle_status">
                          <input type="hidden" name="id" value="<?php echo (int)$post['id']; ?>">
                          <input type="hidden" name="new_status" value="<?php echo $post['status'] === 'published' ? 'draft' : 'published'; ?>">
                          <button class="a-btn a-btn--xs <?php echo $post['status'] === 'published' ? 'a-btn--amber' : 'a-btn--green'; ?>" type="submit">
                            <?php echo $post['status'] === 'published' ? 'Unpublish' : 'Publish'; ?>
                          </button>
                        </form>
                        <form action="delete-post.php" method="post" style="margin:0;" onsubmit="return confirm('Delete this post permanently?');">
                          <input type="hidden" name="id" value="<?php echo (int)$post['id']; ?>">
                          <button class="a-btn a-btn--red a-btn--xs" type="submit">Delete</button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <?php if ($totalPages > 1): ?>
            <nav class="a-pagination">
              <?php if ($page > 1): ?>
                <?php $params = $pageBaseParams; $params['page'] = $page - 1; ?>
                <a class="a-page-btn" href="?<?php echo http_build_query($params); ?>">‹</a>
              <?php endif; ?>
              <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <?php if (abs($p - $page) <= 2 || $p === 1 || $p === $totalPages): ?>
                  <?php $params = $pageBaseParams; $params['page'] = $p; ?>
                  <a class="a-page-btn <?php echo $p === $page ? 'active' : ''; ?>" href="?<?php echo http_build_query($params); ?>"><?php echo $p; ?></a>
                <?php elseif (abs($p - $page) === 3): ?>
                  <span style="padding:0 4px;color:var(--a-dim);">…</span>
                <?php endif; ?>
              <?php endfor; ?>
              <?php if ($page < $totalPages): ?>
                <?php $params = $pageBaseParams; $params['page'] = $page + 1; ?>
                <a class="a-page-btn" href="?<?php echo http_build_query($params); ?>">›</a>
              <?php endif; ?>
            </nav>
          <?php endif; ?>
        <?php endif; ?>
      </div>
      <?php require __DIR__ . '/partials/footer.php'; ?>

    </main>
  </div><!-- /.a-main -->
</div><!-- /.a-app -->
<script src="../assets/vendor/quill/quill.js"></script>
<script src="../assets/editor.js"></script>
</body>
</html>
