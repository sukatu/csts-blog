<?php
require_once __DIR__ . '/config.php';

function blog_ensure_community_schema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS community_users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(191) NOT NULL,
        email VARCHAR(191) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        status ENUM("active", "inactive") NOT NULL DEFAULT "active",
        last_login_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_community_users_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS post_comments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        post_id INT UNSIGNED NOT NULL,
        community_user_id INT UNSIGNED NOT NULL,
        comment_text TEXT NOT NULL,
        status ENUM("visible", "hidden") NOT NULL DEFAULT "visible",
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_post_comments_post_status_created (post_id, status, created_at),
        CONSTRAINT fk_post_comments_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
        CONSTRAINT fk_post_comments_user FOREIGN KEY (community_user_id) REFERENCES community_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS post_reactions (
        post_id INT UNSIGNED NOT NULL,
        community_user_id INT UNSIGNED NOT NULL,
        reaction ENUM("like", "dislike") NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (post_id, community_user_id),
        INDEX idx_post_reactions_post_reaction (post_id, reaction),
        CONSTRAINT fk_post_reactions_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
        CONSTRAINT fk_post_reactions_user FOREIGN KEY (community_user_id) REFERENCES community_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function blog_public_auth_bootstrap(PDO $pdo): void
{
    static $bootstrapped = false;
    if ($bootstrapped) {
        return;
    }

    blog_ensure_community_schema($pdo);
    $bootstrapped = true;
}

function blog_public_user_id(): ?int
{
    $id = $_SESSION['blog_public_user_id'] ?? null;
    if (!is_numeric($id)) {
        return null;
    }

    return (int) $id;
}

function blog_public_is_logged_in(): bool
{
    return blog_public_user_id() !== null;
}

function blog_public_current_user(PDO $pdo): ?array
{
    $userId = blog_public_user_id();
    if ($userId === null) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id, full_name, email, status FROM community_users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();

    if (!$user || ($user['status'] ?? '') !== 'active') {
        blog_public_logout();
        return null;
    }

    return $user;
}

function blog_public_register(PDO $pdo, string $fullName, string $email, string $password): array
{
    blog_public_auth_bootstrap($pdo);

    $fullName = trim($fullName);
    $email = strtolower(trim($email));

    if ($fullName === '' || $email === '' || $password === '') {
        return ['ok' => false, 'message' => 'Name, email, and password are required.'];
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Please enter a valid email address.'];
    }

    if (mb_strlen($password) < 6) {
        return ['ok' => false, 'message' => 'Password must be at least 6 characters long.'];
    }

    $exists = $pdo->prepare('SELECT COUNT(*) FROM community_users WHERE email = :email');
    $exists->execute([':email' => $email]);
    if ((int) $exists->fetchColumn() > 0) {
        return ['ok' => false, 'message' => 'An account with this email already exists.'];
    }

    $now = date('Y-m-d H:i:s');
    $insert = $pdo->prepare('INSERT INTO community_users (full_name, email, password_hash, status, created_at, updated_at)
        VALUES (:full_name, :email, :password_hash, "active", :created_at, :updated_at)');
    $insert->execute([
        ':full_name' => $fullName,
        ':email' => $email,
        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    $_SESSION['blog_public_user_id'] = (int) $pdo->lastInsertId();
    session_regenerate_id(true);

    return ['ok' => true, 'message' => 'Account created successfully.'];
}

function blog_public_login(PDO $pdo, string $email, string $password): array
{
    blog_public_auth_bootstrap($pdo);

    $email = strtolower(trim($email));

    if ($email === '' || $password === '') {
        return ['ok' => false, 'message' => 'Email and password are required.'];
    }

    $stmt = $pdo->prepare('SELECT id, password_hash, status FROM community_users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if (!$user || ($user['status'] ?? '') !== 'active' || !password_verify($password, (string) ($user['password_hash'] ?? ''))) {
        return ['ok' => false, 'message' => 'Invalid email or password.'];
    }

    $now = date('Y-m-d H:i:s');
    $update = $pdo->prepare('UPDATE community_users SET last_login_at = :last_login_at, updated_at = :updated_at WHERE id = :id');
    $update->execute([
        ':last_login_at' => $now,
        ':updated_at' => $now,
        ':id' => (int) $user['id'],
    ]);

    $_SESSION['blog_public_user_id'] = (int) $user['id'];
    session_regenerate_id(true);

    return ['ok' => true, 'message' => 'Logged in successfully.'];
}

function blog_public_logout(): void
{
    unset($_SESSION['blog_public_user_id']);
    session_regenerate_id(true);
}
