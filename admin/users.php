<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/public_auth.php';
blog_require_admin();

$pdo = blog_db();

// Bootstrap tables so the page doesn't crash on a fresh install
blog_public_auth_bootstrap($pdo);

$flash = '';
$error = '';

// ── Actions ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $userId = (int) ($_POST['user_id'] ?? 0);

  if ($userId > 0 && in_array($action, ['activate', 'suspend', 'delete', 'reset_password'], true)) {
        if ($action === 'activate') {
            $stmt = $pdo->prepare("UPDATE community_users SET status = 'active', updated_at = :now WHERE id = :id");
            $stmt->execute([':now' => date('Y-m-d H:i:s'), ':id' => $userId]);
            $flash = 'User activated.';
        } elseif ($action === 'suspend') {
            $stmt = $pdo->prepare("UPDATE community_users SET status = 'inactive', updated_at = :now WHERE id = :id");
            $stmt->execute([':now' => date('Y-m-d H:i:s'), ':id' => $userId]);
            $flash = 'User suspended.';
        } elseif ($action === 'delete') {
            // Cascade deletes comments and reactions via FK constraints
            $stmt = $pdo->prepare('DELETE FROM community_users WHERE id = :id');
            $stmt->execute([':id' => $userId]);
            $flash = 'User and all their content deleted.';
    } elseif ($action === 'reset_password') {
      $newPass = (string) ($_POST['new_password'] ?? '');
      $confirmPass = (string) ($_POST['confirm_password'] ?? '');

      if (strlen($newPass) < 8) {
        $error = 'Password must be at least 8 characters.';
      } elseif ($newPass !== $confirmPass) {
        $error = 'Passwords do not match.';
      } else {
        $hash = password_hash($newPass, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare('UPDATE community_users SET password_hash = :hash, updated_at = :now WHERE id = :id');
        $stmt->execute([
          ':hash' => $hash,
          ':now' => date('Y-m-d H:i:s'),
          ':id' => $userId,
        ]);
        $flash = 'Password reset successfully.';
      }
        }
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$q            = trim((string) ($_GET['q'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? 'all');
$allowedStatuses = ['all', 'active', 'inactive'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'all';
}

$where   = [];
$filters = [];

if ($q !== '') {
    $where[]       = '(u.full_name LIKE :q OR u.email LIKE :q)';
    $filters[':q'] = '%' . $q . '%';
}

if ($statusFilter !== 'all') {
    $where[]           = 'u.status = :status';
    $filters[':status'] = $statusFilter;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Pagination
$perPage    = 20;
$countStmt  = $pdo->prepare('SELECT COUNT(*) FROM community_users u ' . $whereSql);
$countStmt->execute($filters);
$totalFiltered = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalFiltered / $perPage));
$page       = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
$offset     = ($page - 1) * $perPage;

// Users with engagement counts
$sql = '
    SELECT
        u.id, u.full_name, u.email, u.status, u.last_login_at, u.created_at,
        COUNT(DISTINCT c.id)  AS comment_count,
        COUNT(DISTINCT CASE WHEN r.reaction = "like"    THEN r.post_id END) AS like_count,
        COUNT(DISTINCT CASE WHEN r.reaction = "dislike" THEN r.post_id END) AS dislike_count
    FROM community_users u
    LEFT JOIN post_comments  c ON c.community_user_id = u.id AND c.status = "visible"
    LEFT JOIN post_reactions r ON r.community_user_id = u.id
    ' . $whereSql . '
    GROUP BY u.id, u.full_name, u.email, u.status, u.last_login_at, u.created_at
    ORDER BY u.created_at DESC
    LIMIT :limit OFFSET :offset
';

$stmt = $pdo->prepare($sql);
foreach ($filters as $key => $value) {
    $stmt->bindValue($key, $value, PDO::PARAM_STR);
}
$stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll();

// Summary stats
$totalMembers  = (int) $pdo->query('SELECT COUNT(*) FROM community_users')->fetchColumn();
$totalActive   = (int) $pdo->query("SELECT COUNT(*) FROM community_users WHERE status = 'active'")->fetchColumn();
$totalInactive = (int) $pdo->query("SELECT COUNT(*) FROM community_users WHERE status = 'inactive'")->fetchColumn();
$joinStmt = $pdo->prepare('SELECT COUNT(*) FROM community_users WHERE created_at >= :d');
$joinStmt->execute([':d' => date('Y-m-01 00:00:00')]);
$joinedMonth = (int) $joinStmt->fetchColumn();

// URL builder for pagination
function users_page_url(int $p, string $q, string $status): string
{
    $params = array_filter(['q' => $q, 'status' => $status !== 'all' ? $status : '', 'page' => $p > 1 ? $p : '']);
    $qs = http_build_query($params);
    return 'users.php' . ($qs ? '?' . $qs : '');
}

function users_initials(string $name): string
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
?>
<?php
$adminPageTitle = 'Users';
$adminActivePage = 'users';
require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/sidebar.php';
?>

    <header class="a-topbar">
      <div class="a-topbar-left">
        <span class="a-topbar-title">Community Users</span>
        <ol class="a-breadcrumb"><li><a href="dashboard.php">Dashboard</a></li><li>Users</li></ol>
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
          <div class="a-kpi-label">Total Members</div>
          <div class="a-kpi-value"><?php echo number_format($totalMembers); ?></div>
          <div class="a-kpi-sub">All registered readers</div>
        </div>
        <div class="a-kpi" data-color="green">
          <div class="a-kpi-label">Active</div>
          <div class="a-kpi-value"><?php echo number_format($totalActive); ?></div>
          <div class="a-kpi-sub">Can comment and react</div>
        </div>
        <div class="a-kpi" data-color="amber">
          <div class="a-kpi-label">Suspended</div>
          <div class="a-kpi-value"><?php echo number_format($totalInactive); ?></div>
          <div class="a-kpi-sub">Access restricted</div>
        </div>
        <div class="a-kpi" data-color="teal">
          <div class="a-kpi-label">Joined This Month</div>
          <div class="a-kpi-value"><?php echo number_format($joinedMonth); ?></div>
          <div class="a-kpi-sub">New sign-ups</div>
        </div>
      </div>

      <div class="a-panel" style="margin-bottom:20px;">
        <div class="a-panel-body">
          <form method="get" style="display:grid;grid-template-columns:1fr 180px auto auto;gap:10px;">
            <input class="a-input" name="q" placeholder="Search name or email" value="<?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>">
            <select class="a-select" name="status">
              <option value="all"<?php if ($statusFilter === 'all') echo ' selected'; ?>>All statuses</option>
              <option value="active"<?php if ($statusFilter === 'active') echo ' selected'; ?>>Active</option>
              <option value="inactive"<?php if ($statusFilter === 'inactive') echo ' selected'; ?>>Suspended</option>
            </select>
            <button class="a-btn a-btn--primary" type="submit">Filter</button>
            <a class="a-btn a-btn--ghost" href="users.php">Reset</a>
          </form>
        </div>
      </div>

      <div class="a-panel">
        <div class="a-panel-head">
          <div>
            <h2 class="a-panel-title">Members</h2>
            <p class="a-panel-sub"><?php echo number_format($totalFiltered); ?> filtered / showing <?php echo count($users); ?></p>
          </div>
          <?php if ($totalPages > 1): ?>
            <span class="a-panel-sub">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
          <?php endif; ?>
        </div>

        <?php if (empty($users)): ?>
          <div class="a-panel-body">
            <p style="margin:0;color:var(--a-muted);">No community members found.</p>
          </div>
        <?php else: ?>
          <div class="a-table-wrap">
            <table class="a-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>User</th>
                  <th>Status</th>
                  <th>Comments</th>
                  <th>Likes</th>
                  <th>Dislikes</th>
                  <th>Last Login</th>
                  <th>Joined</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($users as $i => $user): ?>
                  <?php
                    $displayName = (string) ($user['full_name'] ?? 'Unknown User');
                    $displayEmail = (string) ($user['email'] ?? '');
                    $isActive = ((string) ($user['status'] ?? 'inactive')) === 'active';
                  ?>
                  <tr>
                    <td><?php echo $offset + $i + 1; ?></td>
                    <td>
                      <div class="a-user-cell">
                        <div class="a-user-avatar"><?php echo htmlspecialchars(users_initials($displayName), ENT_QUOTES, 'UTF-8'); ?></div>
                        <div>
                          <div class="a-user-name"><?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></div>
                          <div class="a-user-email"><?php echo htmlspecialchars($displayEmail, ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                      </div>
                    </td>
                    <td>
                      <span class="a-badge <?php echo $isActive ? 'a-badge--green' : 'a-badge--red'; ?>">
                        <?php echo $isActive ? 'Active' : 'Suspended'; ?>
                      </span>
                    </td>
                    <td><span class="a-count-badge a-count-badge--blue"><?php echo (int) $user['comment_count']; ?></span></td>
                    <td><span class="a-count-badge a-count-badge--green"><?php echo (int) $user['like_count']; ?></span></td>
                    <td><span class="a-count-badge a-count-badge--red"><?php echo (int) $user['dislike_count']; ?></span></td>
                    <td><?php echo $user['last_login_at'] ? htmlspecialchars(blog_format_date((string) $user['last_login_at']), ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                    <td><?php echo htmlspecialchars(blog_format_date((string) $user['created_at']), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>
                      <div style="display:flex;gap:6px;flex-wrap:wrap;">
                        <button type="button" class="a-btn a-btn--amber a-btn--xs" onclick="openResetModal(<?php echo (int) $user['id']; ?>, '<?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?>')">Reset PW</button>

                        <?php if ($isActive): ?>
                          <form method="post" style="margin:0;">
                            <input type="hidden" name="action" value="suspend">
                            <input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>">
                            <button class="a-btn a-btn--ghost a-btn--xs" type="submit">Suspend</button>
                          </form>
                        <?php else: ?>
                          <form method="post" style="margin:0;">
                            <input type="hidden" name="action" value="activate">
                            <input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>">
                            <button class="a-btn a-btn--green a-btn--xs" type="submit">Activate</button>
                          </form>
                        <?php endif; ?>

                        <form method="post" style="margin:0;" onsubmit="return confirm('Delete this user and all their content?');">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>">
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
                <a class="a-page-btn" href="<?php echo users_page_url($page - 1, $q, $statusFilter); ?>">Prev</a>
              <?php endif; ?>
              <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <?php if (abs($p - $page) <= 2 || $p === 1 || $p === $totalPages): ?>
                  <a class="a-page-btn<?php echo $p === $page ? ' active' : ''; ?>" href="<?php echo users_page_url($p, $q, $statusFilter); ?>"><?php echo $p; ?></a>
                <?php elseif (abs($p - $page) === 3): ?>
                  <span class="a-panel-sub" style="padding:0 2px;">...</span>
                <?php endif; ?>
              <?php endfor; ?>
              <?php if ($page < $totalPages): ?>
                <a class="a-page-btn" href="<?php echo users_page_url($page + 1, $q, $statusFilter); ?>">Next</a>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <?php require __DIR__ . '/partials/footer.php'; ?>

      <div class="a-overlay" id="resetOverlay" aria-hidden="true">
        <div class="a-modal" role="dialog" aria-modal="true" aria-labelledby="resetModalTitle">
          <div class="a-modal-head">
            <h3 id="resetModalTitle" class="a-modal-title">Reset Password</h3>
            <button type="button" class="a-modal-close" onclick="closeResetModal()" aria-label="Close">×</button>
          </div>

          <form method="post" id="resetForm">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="user_id" id="resetUserId" value="">

            <div class="a-modal-body">
              <p class="a-modal-sub" id="resetModalSub">Set a new password for this member.</p>

              <div>
                <label class="a-label" for="resetNewPassword">New password</label>
                <input class="a-input" type="password" id="resetNewPassword" name="new_password" minlength="8" required>
              </div>

              <div>
                <label class="a-label" for="resetConfirmPassword">Confirm password</label>
                <input class="a-input" type="password" id="resetConfirmPassword" name="confirm_password" minlength="8" required>
              </div>

              <p id="pwMatchHint" class="a-modal-sub">Minimum 8 characters.</p>
            </div>

            <div class="a-modal-foot">
              <button type="button" class="a-btn a-btn--ghost" onclick="closeResetModal()">Cancel</button>
              <button type="submit" class="a-btn a-btn--primary" id="resetSubmitBtn">Reset Password</button>
            </div>
          </form>
        </div>
      </div>

    </main>
  </div>
</div>

<script>
  const overlay = document.getElementById('resetOverlay');
  const resetUserId = document.getElementById('resetUserId');
  const resetModalSub = document.getElementById('resetModalSub');
  const newPassInput = document.getElementById('resetNewPassword');
  const confirmPassInput = document.getElementById('resetConfirmPassword');
  const pwMatchHint = document.getElementById('pwMatchHint');
  const resetSubmitBtn = document.getElementById('resetSubmitBtn');

  function openResetModal(userId, userName) {
    resetUserId.value = String(userId);
    resetModalSub.textContent = 'Set a new password for ' + userName + '.';
    newPassInput.value = '';
    confirmPassInput.value = '';
    pwMatchHint.textContent = 'Minimum 8 characters.';
    pwMatchHint.style.color = 'var(--a-muted)';
    resetSubmitBtn.disabled = false;
    overlay.classList.add('open');
    overlay.setAttribute('aria-hidden', 'false');
    newPassInput.focus();
  }

  function closeResetModal() {
    overlay.classList.remove('open');
    overlay.setAttribute('aria-hidden', 'true');
  }

  function validatePasswordMatch() {
    const a = newPassInput.value;
    const b = confirmPassInput.value;
    if (b.length === 0) {
      pwMatchHint.textContent = 'Minimum 8 characters.';
      pwMatchHint.style.color = 'var(--a-muted)';
      resetSubmitBtn.disabled = false;
      return;
    }

    if (a === b) {
      pwMatchHint.textContent = 'Passwords match.';
      pwMatchHint.style.color = 'var(--a-green-text)';
      resetSubmitBtn.disabled = false;
    } else {
      pwMatchHint.textContent = 'Passwords do not match.';
      pwMatchHint.style.color = 'var(--a-red-text)';
      resetSubmitBtn.disabled = true;
    }
  }

  overlay.addEventListener('click', function (event) {
    if (event.target === overlay) {
      closeResetModal();
    }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && overlay.classList.contains('open')) {
      closeResetModal();
    }
  });

  newPassInput.addEventListener('input', validatePasswordMatch);
  confirmPassInput.addEventListener('input', validatePasswordMatch);
</script>
</body>
</html>
