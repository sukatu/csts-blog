<?php
require_once __DIR__ . '/../includes/auth.php';

if (blog_is_admin_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (blog_admin_login($username, $password)) {
        header('Location: dashboard.php');
        exit;
    }

    $error = 'Invalid username or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Blog Admin Login</title>
  <link rel="stylesheet" href="../assets/styles.css">
</head>
<body>
  <header class="topbar">
    <div class="container topbar-inner">
      <div class="brand"><img src="../assets/csts-logo.png" alt="CSTS Logo" class="brand-logo">CSTS Blog Admin</div>
      <nav class="nav-links"><a href="../index.php">View Site</a></nav>
    </div>
  </header>

  <main class="container admin-shell" style="max-width:540px;">
    <div class="panel">
      <h2 style="margin-top:0; color:var(--navy);">Admin Sign In</h2>
      <p class="meta">Default credentials: admin / Admin@123</p>
      <?php if ($error): ?><div class="flash err"><?php echo blog_e($error); ?></div><?php endif; ?>
      <form method="post">
        <label>Username</label>
        <input class="input" name="username" required>
        <label style="margin-top:10px; display:block;">Password</label>
        <input class="input" type="password" name="password" required>
        <button class="btn" style="margin-top:14px;">Sign in</button>
      </form>
    </div>
  </main>
</body>
</html>
