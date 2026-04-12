<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/public_auth.php';
blog_require_admin();

$pdo = blog_db();
blog_public_auth_bootstrap($pdo);

$flash = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $commentId = (int) ($_POST['comment_id'] ?? 0);

    if ($commentId > 0 && in_array($action, ['hide', 'unhide', 'delete'], true)) {
        if ($action === 'hide') {
            $stmt = $pdo->prepare('UPDATE post_comments SET status = "hidden", updated_at = :now WHERE id = :id');
            $stmt->execute([
                ':now' => date('Y-m-d H:i:s'),
                ':id' => $commentId,
            ]);
            $flash = 'Comment hidden.';
        } elseif ($action === 'unhide') {
            $stmt = $pdo->prepare('UPDATE post_comments SET status = "visible", updated_at = :now WHERE id = :id');
            $stmt->execute([
                ':now' => date('Y-m-d H:i:s'),
                ':id' => $commentId,
            ]);
            $flash = 'Comment made visible.';
        } elseif ($action === 'delete') {
            $stmt = $pdo->prepare('DELETE FROM post_comments WHERE id = :id');
            $stmt->execute([':id' => $commentId]);
            $flash = 'Comment deleted.';
        }
    }
}

$q = trim((string) ($_GET['q'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? 'all');
$allowedStatuses = ['all', 'visible', 'hidden'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'all';
}

$where = [];
$filters = [];

if ($q !== '') {
    $where[] = '(u.full_name LIKE :q OR u.email LIKE :q OR p.title LIKE :q OR c.comment_text LIKE :q)';
    $filters[':q'] = '%' . $q . '%';
}

if ($statusFilter !== 'all') {
    $where[] = 'c.status = :status';
    $filters[':status'] = $statusFilter;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$perPage = 20;
$countStmt = $pdo->prepare('SELECT COUNT(*)
    FROM post_comments c
    INNER JOIN community_users u ON u.id = c.community_user_id
    INNER JOIN posts p ON p.id = c.post_id
    ' . $whereSql);
$countStmt->execute($filters);
$totalFiltered = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalFiltered / $perPage));
$page = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
$offset = ($page - 1) * $perPage;

$sql = 'SELECT
    c.id,
    c.comment_text,
    c.status,
    c.created_at,
    c.updated_at,
    u.id AS user_id,
    u.full_name AS user_name,
    u.email AS user_email,
    p.id AS post_id,
    p.title AS post_title,
    p.slug AS post_slug
FROM post_comments c
INNER JOIN community_users u ON u.id = c.community_user_id
INNER JOIN posts p ON p.id = c.post_id
' . $whereSql . '
ORDER BY c.created_at DESC
LIMIT :limit OFFSET :offset';

$stmt = $pdo->prepare($sql);
foreach ($filters as $key => $value) {
    $stmt->bindValue($key, $value, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$comments = $stmt->fetchAll();

$totalComments = (int) $pdo->query('SELECT COUNT(*) FROM post_comments')->fetchColumn();
$totalVisible = (int) $pdo->query("SELECT COUNT(*) FROM post_comments WHERE status = 'visible'")->fetchColumn();
$totalHidden = (int) $pdo->query("SELECT COUNT(*) FROM post_comments WHERE status = 'hidden'")->fetchColumn();
$commentsThisMonthStmt = $pdo->prepare('SELECT COUNT(*) FROM post_comments WHERE created_at >= :month_start');
$commentsThisMonthStmt->execute([':month_start' => date('Y-m-01 00:00:00')]);
$commentsThisMonth = (int) $commentsThisMonthStmt->fetchColumn();

function comments_page_url(int $p, string $q, string $status): string
{
    $params = array_filter([
        'q' => $q,
        'status' => $status !== 'all' ? $status : '',
        'page' => $p > 1 ? $p : '',
    ]);
    $qs = http_build_query($params);
    return 'comments.php' . ($qs ? '?' . $qs : '');
}

function comments_initials(string $name): string
{
    $trimmed = trim($name);
    if ($trimmed === '') {
        return '?';
    }

    $parts = preg_split('/\s+/', $trimmed) ?: [];
    $first = strtoupper(substr((string) ($parts[0] ?? ''), 0, 1));
    $last = '';
    if (count($parts) > 1) {
        $last = strtoupper(substr((string) $parts[count($parts) - 1], 0, 1));
    }

    return $first . $last;
}

$adminPageTitle = 'Comments';
$adminActivePage = 'comments';
require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/sidebar.php';
?>

    <header class="a-topbar">
      <div class="a-topbar-left">
        <span class="a-topbar-title">Comment Moderation</span>
        <ol class="a-breadcrumb"><li><a href="dashboard.php">Dashboard</a></li><li>Comments</li></ol>
      </div>
      <div class="a-topbar-right">
        <a href="../index.php" target="_blank" rel="noopener" class="a-topbar-btn">View Site</a>
        <div class="a-avatar"><?php echo htmlspecialchars(strtoupper(substr(blog_admin_username(), 0, 1)), ENT_QUOTES, 'UTF-8'); ?></div>
      </div>
    </header>

    <main class="a-content">

      <?php if ($flash): ?>
        <div class="a-flash a-flash--ok"><?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="a-flash a-flash--err"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>

      <div class="a-kpi-grid" style="margin-bottom:24px;">
        <div class="a-kpi" data-color="blue">
          <div class="a-kpi-label">Total Comments</div>
          <div class="a-kpi-value"><?php echo number_format($totalComments); ?></div>
          <div class="a-kpi-sub">All-time submissions</div>
        </div>
        <div class="a-kpi" data-color="green">
          <div class="a-kpi-label">Visible</div>
          <div class="a-kpi-value"><?php echo number_format($totalVisible); ?></div>
          <div class="a-kpi-sub">Publicly shown</div>
        </div>
        <div class="a-kpi" data-color="red">
          <div class="a-kpi-label">Hidden</div>
          <div class="a-kpi-value"><?php echo number_format($totalHidden); ?></div>
          <div class="a-kpi-sub">Moderated out</div>
        </div>
        <div class="a-kpi" data-color="teal">
          <div class="a-kpi-label">This Month</div>
          <div class="a-kpi-value"><?php echo number_format($commentsThisMonth); ?></div>
          <div class="a-kpi-sub">Recent activity</div>
        </div>
      </div>

      <div class="a-panel" style="margin-bottom:20px;">
        <div class="a-panel-body">
          <form method="get" style="display:grid;grid-template-columns:1fr 180px auto auto;gap:10px;">
            <input class="a-input" name="q" placeholder="Search member, email, post title, comment text" value="<?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>">
            <select class="a-select" name="status">
              <option value="all"<?php if ($statusFilter === 'all') echo ' selected'; ?>>All statuses</option>
              <option value="visible"<?php if ($statusFilter === 'visible') echo ' selected'; ?>>Visible</option>
              <option value="hidden"<?php if ($statusFilter === 'hidden') echo ' selected'; ?>>Hidden</option>
            </select>
            <button class="a-btn a-btn--primary" type="submit">Filter</button>
            <a class="a-btn a-btn--ghost" href="comments.php">Reset</a>
          </form>
        </div>
      </div>

      <div class="a-panel">
        <div class="a-panel-head">
          <div>
            <h2 class="a-panel-title">Comments</h2>
            <p class="a-panel-sub"><?php echo number_format($totalFiltered); ?> filtered / showing <?php echo count($comments); ?></p>
          </div>
          <?php if ($totalPages > 1): ?>
            <span class="a-panel-sub">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
          <?php endif; ?>
        </div>

        <?php if (empty($comments)): ?>
          <div class="a-panel-body">
            <p style="margin:0;color:var(--a-muted);">No comments found.</p>
          </div>
        <?php else: ?>
          <div class="a-table-wrap">
            <table class="a-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Member</th>
                  <th>Comment</th>
                  <th>Post</th>
                  <th>Status</th>
                  <th>Created</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($comments as $i => $comment): ?>
                  <?php
                    $isVisible = ((string) ($comment['status'] ?? 'hidden')) === 'visible';
                    $name = (string) ($comment['user_name'] ?? 'Unknown User');
                    $email = (string) ($comment['user_email'] ?? '');
                    $commentText = (string) ($comment['comment_text'] ?? '');
                    $postTitle = (string) ($comment['post_title'] ?? 'Untitled');
                  ?>
                  <tr>
                    <td><?php echo $offset + $i + 1; ?></td>
                    <td>
                      <div class="a-user-cell">
                        <div class="a-user-avatar"><?php echo htmlspecialchars(comments_initials($name), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div>
                          <div class="a-user-name"><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></div>
                          <div class="a-user-email"><?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                      </div>
                    </td>
                    <td style="max-width:340px;">
                      <div class="a-truncate" title="<?php echo htmlspecialchars($commentText, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars(mb_strimwidth($commentText, 0, 120, '...'), ENT_QUOTES, 'UTF-8'); ?>
                      </div>
                    </td>
                    <td style="max-width:220px;">
                      <a href="../post.php?slug=<?php echo urlencode((string) $comment['post_slug']); ?>" target="_blank" rel="noopener" class="a-link-subtle">
                        <?php echo htmlspecialchars(mb_strimwidth($postTitle, 0, 60, '...'), ENT_QUOTES, 'UTF-8'); ?>
                      </a>
                    </td>
                    <td>
                      <span class="a-badge <?php echo $isVisible ? 'a-badge--green' : 'a-badge--red'; ?>">
                        <?php echo $isVisible ? 'Visible' : 'Hidden'; ?>
                      </span>
                    </td>
                    <td style="white-space:nowrap;"><?php echo htmlspecialchars(blog_format_date((string) $comment['created_at']), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>
                      <div style="display:flex;gap:6px;flex-wrap:wrap;">
                        <?php if ($isVisible): ?>
                          <form method="post" style="margin:0;">
                            <input type="hidden" name="action" value="hide">
                            <input type="hidden" name="comment_id" value="<?php echo (int) $comment['id']; ?>">
                            <button class="a-btn a-btn--ghost a-btn--xs" type="submit">Hide</button>
                          </form>
                        <?php else: ?>
                          <form method="post" style="margin:0;">
                            <input type="hidden" name="action" value="unhide">
                            <input type="hidden" name="comment_id" value="<?php echo (int) $comment['id']; ?>">
                            <button class="a-btn a-btn--green a-btn--xs" type="submit">Unhide</button>
                          </form>
                        <?php endif; ?>

                        <form method="post" style="margin:0;" onsubmit="return confirm('Delete this comment permanently?');">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="comment_id" value="<?php echo (int) $comment['id']; ?>">
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
            <div class="a-panel-body" style="border-top:1px solid var(--a-line);display:flex;gap:8px;flex-wrap:wrap;">
              <?php if ($page > 1): ?>
                <a class="a-page-btn" href="<?php echo comments_page_url($page - 1, $q, $statusFilter); ?>">Prev</a>
              <?php endif; ?>
              <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <?php if (abs($p - $page) <= 2 || $p === 1 || $p === $totalPages): ?>
                  <a class="a-page-btn<?php echo $p === $page ? ' active' : ''; ?>" href="<?php echo comments_page_url($p, $q, $statusFilter); ?>"><?php echo $p; ?></a>
                <?php elseif (abs($p - $page) === 3): ?>
                  <span class="a-panel-sub" style="padding:0 2px;">...</span>
                <?php endif; ?>
              <?php endfor; ?>
              <?php if ($page < $totalPages): ?>
                <a class="a-page-btn" href="<?php echo comments_page_url($page + 1, $q, $statusFilter); ?>">Next</a>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <?php require __DIR__ . '/partials/footer.php'; ?>

    </main>
  </div><!-- /.a-main -->
</div><!-- /.a-app -->
</body>
</html>
