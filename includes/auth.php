<?php
require_once __DIR__ . '/config.php';

function blog_is_admin_logged_in(): bool
{
    return isset($_SESSION['blog_admin_id']) && is_numeric($_SESSION['blog_admin_id']);
}

function blog_admin_username(): string
{
    return $_SESSION['blog_admin_username'] ?? 'Admin';
}

function blog_require_admin(): void
{
    if (!blog_is_admin_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function blog_admin_login(string $username, string $password): bool
{
    $pdo = blog_db();
    $stmt = $pdo->prepare('SELECT id, username, password_hash, role, status FROM users WHERE (username = :identity OR email = :identity) LIMIT 1');
    $stmt->execute([':identity' => $username]);
    $admin = $stmt->fetch();

    if (
        !$admin ||
        !password_verify($password, $admin['password_hash']) ||
        !in_array($admin['role'], ['admin', 'editor'], true) ||
        $admin['status'] !== 'active'
    ) {
        return false;
    }

    $update = $pdo->prepare('UPDATE users SET last_login_at = :last_login_at, updated_at = :updated_at WHERE id = :id');
    $now = date('Y-m-d H:i:s');
    $update->execute([
        ':last_login_at' => $now,
        ':updated_at' => $now,
        ':id' => (int) $admin['id'],
    ]);

    session_regenerate_id(true);
    $_SESSION['blog_admin_id'] = (int) $admin['id'];
    $_SESSION['blog_admin_username'] = $admin['username'];

    return true;
}

function blog_admin_logout(): void
{
    unset($_SESSION['blog_admin_id'], $_SESSION['blog_admin_username']);
    session_regenerate_id(true);
}
