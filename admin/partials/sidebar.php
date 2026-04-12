<?php
/**
 * Admin sidebar navigation partial.
 * Usage: require __DIR__ . '/partials/sidebar.php';
 * Variables:
 *   $adminActivePage  string  — one of: dashboard, posts, comments, users
 */
$adminActivePage = $adminActivePage ?? '';
$adminUsername   = blog_admin_username();
$adminInitial    = strtoupper(substr($adminUsername, 0, 1));

function a_is_active(string $page, string $current): string {
    return $page === $current ? ' class="active"' : '';
}
?>
  <!-- ── Sidebar ── -->
  <aside class="a-sidebar">
    <a href="dashboard.php" class="a-sidebar-brand">
      <img src="../assets/csts-logo.png" alt="CSTS" class="a-sidebar-logo">
      <div>
        <div class="a-sidebar-brand-name">CSTS Blog</div>
        <div class="a-sidebar-brand-sub">Admin Console</div>
      </div>
    </a>

    <ul class="a-nav">
      <li class="a-nav-section">Main</li>

      <li class="a-nav-item">
        <a href="dashboard.php"<?php echo a_is_active('dashboard', $adminActivePage); ?>>
          <svg class="a-nav-icon" viewBox="0 0 24 24"><path d="M10 3H3v7h7V3zm11 0h-7v7h7V3zm0 11h-7v7h7v-7zM10 14H3v7h7v-7z"/></svg>
          Dashboard
        </a>
      </li>

      <li class="a-nav-section">Content</li>

      <li class="a-nav-item">
        <a href="posts.php"<?php echo a_is_active('posts', $adminActivePage); ?>>
          <svg class="a-nav-icon" viewBox="0 0 24 24"><path d="M19 3H5a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2V5a2 2 0 00-2-2zm-7 3a1 1 0 110 2 1 1 0 010-2zm3 12H8a1 1 0 010-2h7a1 1 0 010 2zm1-4H8a1 1 0 010-2h8a1 1 0 010 2z"/></svg>
          Posts
        </a>
      </li>

      <li class="a-nav-item">
        <a href="comments.php"<?php echo a_is_active('comments', $adminActivePage); ?>>
          <svg class="a-nav-icon" viewBox="0 0 24 24"><path d="M20 2H4a2 2 0 00-2 2v18l4-4h14a2 2 0 002-2V4a2 2 0 00-2-2zm-2 10H6a1 1 0 010-2h12a1 1 0 010 2zm0-4H6a1 1 0 010-2h12a1 1 0 010 2z"/></svg>
          Comments
        </a>
      </li>

      <li class="a-nav-section">Community</li>

      <li class="a-nav-item">
        <a href="users.php"<?php echo a_is_active('users', $adminActivePage); ?>>
          <svg class="a-nav-icon" viewBox="0 0 24 24"><path d="M17 12a5 5 0 100-10 5 5 0 000 10zm-10 0a5 5 0 100-10 5 5 0 000 10zm0 2c-3.33 0-10 1.67-10 5v2h20v-2c0-3.33-6.67-5-10-5zm10 0c-.42 0-.87.02-1.34.06C17.23 15.18 19 16.28 19 19v2h6v-2c0-3.33-6.67-5-10-5z"/></svg>
          Users
        </a>
      </li>

      <li class="a-nav-section">Site</li>

      <li class="a-nav-item">
        <a href="../index.php" target="_blank" rel="noopener">
          <svg class="a-nav-icon" viewBox="0 0 24 24"><path d="M19 19H5V5h7V3H5a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7h-2v7zM14 3v2h3.59l-9.8 9.8 1.42 1.41L19 6.41V10h2V3h-7z"/></svg>
          View Live Site
        </a>
      </li>

      <li class="a-nav-item">
        <a href="logout.php">
          <svg class="a-nav-icon" viewBox="0 0 24 24"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5-5-5zM4 5h8V3H4a2 2 0 00-2 2v14a2 2 0 002 2h8v-2H4V5z"/></svg>
          Logout
        </a>
      </li>
    </ul>

    <div class="a-sidebar-footer">
      <div class="a-user-cell">
        <div class="a-user-avatar" style="width:30px;height:30px;font-size:12px;"><?php echo htmlspecialchars($adminInitial, ENT_QUOTES, 'UTF-8'); ?></div>
        <div>
          <strong><?php echo htmlspecialchars($adminUsername, ENT_QUOTES, 'UTF-8'); ?></strong>
          <span style="display:block;font-size:11px;">Administrator</span>
        </div>
      </div>
    </div>
  </aside>

  <!-- ── Main column ── -->
  <div class="a-main">
