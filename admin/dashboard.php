<?php
require_once __DIR__ . '/../includes/auth.php';
blog_require_admin();

$pdo = blog_db();
$totalPosts = (int) $pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn();
$publishedPosts = (int) $pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'published'")->fetchColumn();
$draftPosts = (int) $pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'draft'")->fetchColumn();
$totalUsers = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE status = "active"')->fetchColumn();
$subscribers = (int) $pdo->query('SELECT COUNT(*) FROM newsletter_subscribers WHERE status = "subscribed"')->fetchColumn();
$openInquiries = (int) $pdo->query('SELECT COUNT(*) FROM inquiries WHERE status IN ("new", "in_review")')->fetchColumn();

$monthStart = date('Y-m-01 00:00:00');
$postsThisMonthStmt = $pdo->prepare('SELECT COUNT(*) FROM posts WHERE created_at >= :month_start');
$postsThisMonthStmt->execute([':month_start' => $monthStart]);
$postsThisMonth = (int) $postsThisMonthStmt->fetchColumn();

$recentPosts = $pdo->query('SELECT id, title, slug, status, updated_at FROM posts ORDER BY updated_at DESC LIMIT 6')->fetchAll();
$categoryBreakdown = $pdo->query('SELECT category, COUNT(*) AS total FROM posts GROUP BY category ORDER BY total DESC')->fetchAll();
$sourceBreakdown = $pdo->query('SELECT COALESCE(NULLIF(source_name, ""), "CSTS Original") AS source_label, COUNT(*) AS total FROM posts GROUP BY source_label ORDER BY total DESC LIMIT 6')->fetchAll();

$dailyRows = $pdo->query('SELECT DATE(created_at) AS day_label, COUNT(*) AS total FROM posts WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) GROUP BY DATE(created_at) ORDER BY day_label ASC')->fetchAll();
$dailyMap = [];
foreach ($dailyRows as $row) {
  $dailyMap[$row['day_label']] = (int) $row['total'];
}

$dailyStats = [];
$maxDaily = 0;
for ($i = 13; $i >= 0; $i--) {
  $day = date('Y-m-d', strtotime('-' . $i . ' days'));
  $total = $dailyMap[$day] ?? 0;
  if ($total > $maxDaily) {
    $maxDaily = $total;
  }
  $dailyStats[] = [
    'label' => date('M d', strtotime($day)),
    'total' => $total,
  ];
}

$publishRate = $totalPosts > 0 ? round(($publishedPosts / $totalPosts) * 100) : 0;
$draftRate = $totalPosts > 0 ? round(($draftPosts / $totalPosts) * 100) : 0;
$contentHealth = max(0, min(100, $publishRate - (int) round($draftRate * 0.3)));

// Community engagement data — wrapped in try/catch in case tables haven't been bootstrapped yet
$communityUsersTotal = 0;
$communityUsersMonth = 0;
$totalComments = 0;
$totalLikes = 0;
$totalDislikes = 0;
$topCommentedPosts = [];
$topLikedPosts = [];
$recentComments = [];
try {
    $communityUsersTotal = (int) $pdo->query('SELECT COUNT(*) FROM community_users')->fetchColumn();
    $cuMonthStmt = $pdo->prepare('SELECT COUNT(*) FROM community_users WHERE created_at >= :month_start');
    $cuMonthStmt->execute([':month_start' => $monthStart]);
    $communityUsersMonth = (int) $cuMonthStmt->fetchColumn();

    $totalComments = (int) $pdo->query("SELECT COUNT(*) FROM post_comments WHERE status = 'visible'")->fetchColumn();
    $totalLikes    = (int) $pdo->query("SELECT COUNT(*) FROM post_reactions WHERE reaction = 'like'")->fetchColumn();
    $totalDislikes = (int) $pdo->query("SELECT COUNT(*) FROM post_reactions WHERE reaction = 'dislike'")->fetchColumn();

    $topCommentedPosts = $pdo->query('
        SELECT p.id, p.title, p.slug, COUNT(c.id) AS comment_count
        FROM posts p
        JOIN post_comments c ON c.post_id = p.id AND c.status = "visible"
        GROUP BY p.id, p.title, p.slug
        ORDER BY comment_count DESC
        LIMIT 5
    ')->fetchAll();

    $topLikedPosts = $pdo->query('
        SELECT p.id, p.title, p.slug, COUNT(r.post_id) AS like_count
        FROM posts p
        JOIN post_reactions r ON r.post_id = p.id AND r.reaction = "like"
        GROUP BY p.id, p.title, p.slug
        ORDER BY like_count DESC
        LIMIT 5
    ')->fetchAll();

    $recentComments = $pdo->query('
        SELECT c.id, c.comment_text, c.status, c.created_at,
               u.full_name AS user_name, u.email AS user_email,
               p.title AS post_title, p.id AS post_id, p.slug AS post_slug
        FROM post_comments c
        JOIN community_users u ON u.id = c.community_user_id
        JOIN posts p ON p.id = c.post_id
        ORDER BY c.created_at DESC
        LIMIT 8
    ')->fetchAll();
} catch (PDOException $e) {
    // Community schema not yet bootstrapped — show zero states
}

$totalReactions = $totalLikes + $totalDislikes;
$likeRate = $totalReactions > 0 ? round(($totalLikes / $totalReactions) * 100) : 0;
?>
<?php
$adminPageTitle  = 'Dashboard';
$adminActivePage = 'dashboard';
require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/sidebar.php';
?>

    <header class="a-topbar">
      <div class="a-topbar-left">
        <span class="a-topbar-title">Overview</span>
        <ol class="a-breadcrumb"><li>Dashboard</li></ol>
      </div>
      <div class="a-topbar-right">
        <a href="posts.php" class="a-topbar-btn a-topbar-btn--primary">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M19 3H5a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2V5a2 2 0 00-2-2zm-7 3a1 1 0 110 2 1 1 0 010-2zm3 12H8a1 1 0 010-2h7a1 1 0 010 2zm1-4H8a1 1 0 010-2h8a1 1 0 010 2z"/></svg>
          New Post
        </a>
        <a href="../index.php" target="_blank" rel="noopener" class="a-topbar-btn">View Site</a>
        <div class="a-avatar"><?php echo htmlspecialchars(strtoupper(substr(blog_admin_username(), 0, 1)), ENT_QUOTES, 'UTF-8'); ?></div>
      </div>
    </header>


    <main class="a-content">

      <!-- Publishing KPIs -->
      <div class="a-kpi-grid" style="margin-bottom:24px;">
        <div class="a-kpi">
          <div class="a-kpi-icon"><svg viewBox="0 0 24 24"><path d="M19 3H5a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2V5a2 2 0 00-2-2zm-7 3a1 1 0 110 2 1 1 0 010-2zm3 12H8v-2h7v2zm1-4H8v-2h8v2z"/></svg></div>
          <div class="a-kpi-label">Total Posts</div>
          <div class="a-kpi-value"><?php echo $totalPosts; ?></div>
          <div class="a-kpi-sub">All content records</div>
        </div>
        <div class="a-kpi" data-color="green">
          <div class="a-kpi-icon"><svg viewBox="0 0 24 24"><path d="M12 2a10 10 0 100 20A10 10 0 0012 2zm-2 14.5l-4-4 1.41-1.41L10 13.67l6.59-6.58L18 8.5l-8 8z"/></svg></div>
          <div class="a-kpi-label">Published</div>
          <div class="a-kpi-value"><?php echo $publishedPosts; ?></div>
          <div class="a-kpi-sub"><strong><?php echo $publishRate; ?>%</strong> publish rate</div>
        </div>
        <div class="a-kpi" data-color="amber">
          <div class="a-kpi-icon"><svg viewBox="0 0 24 24"><path d="M12 2a10 10 0 100 20A10 10 0 0012 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg></div>
          <div class="a-kpi-label">Drafts</div>
          <div class="a-kpi-value"><?php echo $draftPosts; ?></div>
          <div class="a-kpi-sub">Backlog to review</div>
        </div>
        <div class="a-kpi" data-color="teal">
          <div class="a-kpi-icon"><svg viewBox="0 0 24 24"><path d="M9 11H7v2h2v-2zm4 0h-2v2h2v-2zm4 0h-2v2h2v-2zm2-7h-1V2h-2v2H8V2H6v2H5a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2V6a2 2 0 00-2-2zm0 16H5V9h14v11z"/></svg></div>
          <div class="a-kpi-label">This Month</div>
          <div class="a-kpi-value"><?php echo $postsThisMonth; ?></div>
          <div class="a-kpi-sub">Fresh output</div>
        </div>
        <div class="a-kpi" data-color="purple">
          <div class="a-kpi-icon"><svg viewBox="0 0 24 24"><path d="M20 4H4a2 2 0 00-2 2v12a2 2 0 002 2h16a2 2 0 002-2V6a2 2 0 00-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg></div>
          <div class="a-kpi-label">Subscribers</div>
          <div class="a-kpi-value"><?php echo $subscribers; ?></div>
          <div class="a-kpi-sub">Active newsletter</div>
        </div>
        <div class="a-kpi" data-color="red">
          <div class="a-kpi-icon"><svg viewBox="0 0 24 24"><path d="M20 2H4a2 2 0 00-2 2v18l4-4h14a2 2 0 002-2V4a2 2 0 00-2-2zm-2 10H6v-2h12v2zm0-4H6V6h12v2z"/></svg></div>
          <div class="a-kpi-label">Open Inquiries</div>
          <div class="a-kpi-value"><?php echo $openInquiries; ?></div>
          <div class="a-kpi-sub">Pending review</div>
        </div>
      </div>

      <!-- Charts row -->
      <div class="a-grid-2" style="margin-bottom:24px;">
        <div class="a-panel">
          <div class="a-panel-head">
            <div>
              <h2 class="a-panel-title">Publishing Activity</h2>
              <p class="a-panel-sub">Posts created — last 14 days</p>
            </div>
            <div class="a-ring-wrap">
              <div class="a-ring" style="--v:<?php echo $contentHealth; ?>;">
                <span class="a-ring-value"><?php echo $contentHealth; ?>%</span>
              </div>
              <div class="a-ring-meta">
                <span class="a-ring-label">Health Score</span>
                <span class="a-ring-sub">Published <?php echo $publishedPosts; ?> / <?php echo $totalPosts; ?></span>
              </div>
            </div>
          </div>
          <div class="a-panel-body">
            <?php foreach ($dailyStats as $point): ?>
              <?php $pct = $maxDaily > 0 ? max(4, (int) round(($point['total'] / $maxDaily) * 100)) : 4; ?>
              <div class="a-chart-row">
                <span class="a-chart-label"><?php echo htmlspecialchars($point['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                <div class="a-chart-track"><div class="a-chart-fill" style="width:<?php echo $pct; ?>%;"></div></div>
                <span class="a-chart-val"><?php echo $point['total']; ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="a-panel">
          <div class="a-panel-head">
            <div>
              <h2 class="a-panel-title">Category &amp; Sources</h2>
              <p class="a-panel-sub">Editorial distribution</p>
            </div>
          </div>
          <div class="a-panel-body" style="padding-bottom:8px;">
            <p style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--a-muted);margin:0 0 10px;">By Category</p>
            <ul class="a-ranked-list" style="margin-bottom:20px;">
              <?php foreach ($categoryBreakdown as $i => $row): ?>
                <li class="a-ranked-item">
                  <span class="a-ranked-rank"><?php echo $i + 1; ?></span>
                  <span class="a-ranked-name"><?php echo htmlspecialchars((string)$row['category'], ENT_QUOTES, 'UTF-8'); ?></span>
                  <span class="a-ranked-val"><?php echo (int)$row['total']; ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
            <p style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--a-muted);margin:0 0 10px;">Top Sources</p>
            <ul class="a-ranked-list">
              <?php foreach ($sourceBreakdown as $i => $row): ?>
                <li class="a-ranked-item">
                  <span class="a-ranked-rank"><?php echo $i + 1; ?></span>
                  <span class="a-ranked-name"><?php echo htmlspecialchars((string)$row['source_label'], ENT_QUOTES, 'UTF-8'); ?></span>
                  <span class="a-ranked-val"><?php echo (int)$row['total']; ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
      </div>

      <!-- Recent posts -->
      <div class="a-panel" style="margin-bottom:24px;">
        <div class="a-panel-head">
          <div>
            <h2 class="a-panel-title">Recent Updates</h2>
            <p class="a-panel-sub">Latest editorial changes</p>
          </div>
          <a href="posts.php" class="a-btn a-btn--ghost a-btn--sm">View all posts →</a>
        </div>
        <div class="a-table-wrap">
          <table class="a-table">
            <thead>
              <tr>
                <th>Title</th>
                <th>Status</th>
                <th>Updated</th>
                <th style="text-align:right;"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recentPosts as $recent): ?>
                <tr>
                  <td style="max-width:420px;" class="a-truncate"><?php echo htmlspecialchars((string)$recent['title'], ENT_QUOTES, 'UTF-8'); ?></td>
                  <td>
                    <?php if ($recent['status'] === 'published'): ?>
                      <span class="a-badge a-badge--green">Published</span>
                    <?php else: ?>
                      <span class="a-badge a-badge--amber">Draft</span>
                    <?php endif; ?>
                  </td>
                  <td style="font-size:12px;color:var(--a-muted);white-space:nowrap;"><?php echo blog_format_date((string)$recent['updated_at']); ?></td>
                  <td style="text-align:right;"><a class="a-btn a-btn--ghost a-btn--xs" href="edit-post.php?id=<?php echo (int)$recent['id']; ?>">Edit</a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Community section divider -->
      <div class="a-section-divider"><span>Community &amp; Engagement</span></div>

      <!-- Community KPIs -->
      <div class="a-kpi-grid" style="margin-bottom:24px;">
        <div class="a-kpi a-kpi--clickable" data-color="teal" onclick="location.href='users.php'" style="cursor:pointer;">
          <div class="a-kpi-icon"><svg viewBox="0 0 24 24"><path d="M17 12a5 5 0 100-10 5 5 0 000 10zm-10 0a5 5 0 100-10 5 5 0 000 10zm0 2c-3.33 0-10 1.67-10 5v2h20v-2c0-3.33-6.67-5-10-5zm10 0c-.42 0-.87.02-1.34.06C17.23 15.18 19 16.28 19 19v2h6v-2c0-3.33-6.67-5-10-5z"/></svg></div>
          <div class="a-kpi-label">Members</div>
          <div class="a-kpi-value"><?php echo $communityUsersTotal; ?></div>
          <div class="a-kpi-sub"><?php echo $communityUsersMonth; ?> joined this month</div>
        </div>
        <div class="a-kpi" data-color="blue">
          <div class="a-kpi-icon"><svg viewBox="0 0 24 24"><path d="M20 2H4a2 2 0 00-2 2v18l4-4h14a2 2 0 002-2V4a2 2 0 00-2-2zm-2 10H6v-2h12v2zm0-4H6V6h12v2z"/></svg></div>
          <div class="a-kpi-label">Comments</div>
          <div class="a-kpi-value"><?php echo $totalComments; ?></div>
          <div class="a-kpi-sub">Visible responses</div>
        </div>
        <div class="a-kpi" data-color="green">
          <div class="a-kpi-icon"><svg viewBox="0 0 24 24"><path d="M1 21h4V9H1v12zm22-11a2 2 0 00-2-2h-6.31l.95-4.57.03-.32a1.5 1.5 0 00-.44-1.06L14.17 1 7.59 7.58A1.98 1.98 0 007 9v10a2 2 0 002 2h9a2 2 0 001.85-1.23l3.02-7.05A2 2 0 0023 12v-2z"/></svg></div>
          <div class="a-kpi-label">Likes</div>
          <div class="a-kpi-value"><?php echo $totalLikes; ?></div>
          <div class="a-kpi-sub"><?php echo $likeRate; ?>% positive rate</div>
        </div>
        <div class="a-kpi" data-color="red">
          <div class="a-kpi-icon"><svg viewBox="0 0 24 24"><path d="M15 3H6a2 2 0 00-1.85 1.23L1.13 11.28A2 2 0 001 12v2a2 2 0 002 2h6.31l-.95 4.57-.03.32a1.5 1.5 0 00.44 1.06L9.83 23l6.58-6.58A1.98 1.98 0 0017 15V5a2 2 0 00-2-2zm4 0v12h4V3h-4z"/></svg></div>
          <div class="a-kpi-label">Dislikes</div>
          <div class="a-kpi-value"><?php echo $totalDislikes; ?></div>
          <div class="a-kpi-sub">Critical reactions</div>
        </div>
      </div>

      <!-- Top engaged posts + recent comments -->
      <div class="a-grid-2" style="margin-bottom:24px;">
        <div class="a-panel">
          <div class="a-panel-head">
            <h2 class="a-panel-title">Most Discussed</h2>
            <span style="font-size:12px;color:var(--a-dim);">By comment count</span>
          </div>
          <div class="a-panel-body">
            <?php if (empty($topCommentedPosts)): ?>
              <div class="a-empty" style="padding:24px;"><div class="a-empty-icon">💬</div><div class="a-empty-sub">No comments yet</div></div>
            <?php else: ?>
              <ul class="a-ranked-list">
                <?php foreach ($topCommentedPosts as $i => $row): ?>
                  <li class="a-ranked-item">
                    <span class="a-ranked-rank"><?php echo $i + 1; ?></span>
                    <span class="a-ranked-name"><a href="../post.php?slug=<?php echo urlencode((string)$row['slug']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars((string)$row['title'], ENT_QUOTES, 'UTF-8'); ?></a></span>
                    <span class="a-count-badge a-count-badge--blue"><?php echo (int)$row['comment_count']; ?></span>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        </div>

        <div class="a-panel">
          <div class="a-panel-head">
            <h2 class="a-panel-title">Most Liked</h2>
            <span style="font-size:12px;color:var(--a-dim);">Highest positive reactions</span>
          </div>
          <div class="a-panel-body">
            <?php if (empty($topLikedPosts)): ?>
              <div class="a-empty" style="padding:24px;"><div class="a-empty-icon">👍</div><div class="a-empty-sub">No reactions yet</div></div>
            <?php else: ?>
              <ul class="a-ranked-list">
                <?php foreach ($topLikedPosts as $i => $row): ?>
                  <li class="a-ranked-item">
                    <span class="a-ranked-rank"><?php echo $i + 1; ?></span>
                    <span class="a-ranked-name"><a href="../post.php?slug=<?php echo urlencode((string)$row['slug']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars((string)$row['title'], ENT_QUOTES, 'UTF-8'); ?></a></span>
                    <span class="a-count-badge a-count-badge--green"><?php echo (int)$row['like_count']; ?></span>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Recent comments -->
      <div class="a-panel">
        <div class="a-panel-head">
          <div>
            <h2 class="a-panel-title">Recent Comments</h2>
            <p class="a-panel-sub">Latest reader responses</p>
          </div>
          <a href="comments.php" class="a-btn a-btn--ghost a-btn--sm">Manage all →</a>
        </div>
        <?php if (empty($recentComments)): ?>
          <div class="a-empty"><div class="a-empty-icon">💬</div><div class="a-empty-sub">No comments posted yet.</div></div>
        <?php else: ?>
          <div class="a-table-wrap">
            <table class="a-table">
              <thead>
                <tr>
                  <th>Member</th>
                  <th>Comment</th>
                  <th>Post</th>
                  <th>Status</th>
                  <th>Date</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($recentComments as $comment): ?>
                  <tr>
                    <td>
                      <div class="a-user-cell">
                        <div class="a-user-avatar" style="width:30px;height:30px;font-size:11px;"><?php echo htmlspecialchars(strtoupper(substr((string)$comment['user_name'], 0, 1)), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div>
                          <div class="a-user-name"><?php echo htmlspecialchars((string)$comment['user_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                          <div class="a-user-email"><?php echo htmlspecialchars((string)$comment['user_email'], ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                      </div>
                    </td>
                    <td style="max-width:240px;" class="a-truncate" title="<?php echo htmlspecialchars((string)$comment['comment_text'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(mb_strimwidth((string)$comment['comment_text'], 0, 80, '…'), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td style="max-width:180px;" class="a-truncate"><?php echo htmlspecialchars(mb_strimwidth((string)$comment['post_title'], 0, 45, '…'), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>
                      <?php if ($comment['status'] === 'visible'): ?>
                        <span class="a-badge a-badge--green">Visible</span>
                      <?php else: ?>
                        <span class="a-badge a-badge--red">Hidden</span>
                      <?php endif; ?>
                    </td>
                    <td style="font-size:12px;color:var(--a-muted);white-space:nowrap;"><?php echo blog_format_date((string)$comment['created_at']); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <?php require __DIR__ . '/partials/footer.php'; ?>

    </main>
  </div><!-- /.a-main -->
</div><!-- /.a-app -->
</body>
</html>
