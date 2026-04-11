<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function blog_env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

function blog_env_bool(string $key, bool $default): bool
{
    $value = getenv($key);
    if ($value === false) {
        return $default;
    }

    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
}

function blog_is_local_environment(): bool
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    return $host === 'localhost' || str_starts_with($host, '127.0.0.1');
}

define('BLOG_DB_HOST', blog_env('BLOG_DB_HOST', 'localhost'));
define('BLOG_DB_PORT', (int) blog_env('BLOG_DB_PORT', '3306'));
define('BLOG_DB_NAME', blog_env('BLOG_DB_NAME', 'csts_blog'));
define('BLOG_DB_USER', blog_env('BLOG_DB_USER', 'root'));
define('BLOG_DB_PASS', blog_env('BLOG_DB_PASS', 'Hausawi@2025'));
define('BLOG_DB_AUTO_SETUP', blog_env_bool('BLOG_DB_AUTO_SETUP', blog_is_local_environment()));

define('BLOG_SITE_NAME', 'CSTS Blog');

define('BLOG_ADMIN_DEFAULT_USER', 'admin');
define('BLOG_ADMIN_DEFAULT_PASS', 'Admin@123');
define('BLOG_ADMIN_DEFAULT_EMAIL', 'admin@cstsghana.com');

function blog_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (BLOG_DB_AUTO_SETUP) {
        blog_prepare_mysql_database();
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        BLOG_DB_HOST,
        BLOG_DB_PORT,
        BLOG_DB_NAME
    );

    try {
        $pdo = new PDO($dsn, BLOG_DB_USER, BLOG_DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    } catch (PDOException $exception) {
        error_log('CSTS Blog DB connection failed: ' . $exception->getMessage());
        throw $exception;
    }

    if (BLOG_DB_AUTO_SETUP) {
        blog_bootstrap($pdo);
    }

    return $pdo;
}

function blog_prepare_mysql_database(): void
{
    $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', BLOG_DB_HOST, BLOG_DB_PORT);
    $pdo = new PDO($dsn, BLOG_DB_USER, BLOG_DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . BLOG_DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}

function blog_bootstrap(PDO $pdo): void
{
    // Legacy admin table kept for backward compatibility.
    $pdo->exec('CREATE TABLE IF NOT EXISTS admins (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(191) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(191) NOT NULL,
        username VARCHAR(100) NOT NULL UNIQUE,
        email VARCHAR(191) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM("admin", "editor", "author") NOT NULL DEFAULT "author",
        status ENUM("active", "inactive") NOT NULL DEFAULT "active",
        last_login_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS categories (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        slug VARCHAR(140) NOT NULL UNIQUE,
        description TEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS tags (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        slug VARCHAR(140) NOT NULL UNIQUE,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS posts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        author_user_id INT UNSIGNED NULL,
        title VARCHAR(255) NOT NULL,
        slug VARCHAR(255) NOT NULL UNIQUE,
        summary TEXT NOT NULL,
        content MEDIUMTEXT NOT NULL,
        category VARCHAR(100) NOT NULL,
        image_url TEXT NULL,
        status ENUM("draft", "published") NOT NULL DEFAULT "published",
        published_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_posts_status_published_at (status, published_at),
        INDEX idx_posts_category (category),
        CONSTRAINT fk_posts_author_user FOREIGN KEY (author_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS post_tags (
        post_id INT UNSIGNED NOT NULL,
        tag_id INT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (post_id, tag_id),
        CONSTRAINT fk_post_tags_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
        CONSTRAINT fk_post_tags_tag FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS site_settings (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(140) NOT NULL UNIQUE,
        setting_value TEXT NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS inquiries (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(191) NOT NULL,
        email VARCHAR(191) NOT NULL,
        subject VARCHAR(191) NOT NULL,
        message TEXT NOT NULL,
        status ENUM("new", "in_review", "resolved") NOT NULL DEFAULT "new",
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    blog_ensure_posts_schema($pdo);
    blog_seed_legacy_admin($pdo);
    blog_seed_users($pdo);
    blog_seed_categories($pdo);
    blog_seed_tags($pdo);
    blog_seed_settings($pdo);
    blog_seed_posts($pdo);
}

function blog_ensure_posts_schema(PDO $pdo): void
{
    if (!blog_column_exists($pdo, 'posts', 'author_user_id')) {
        $pdo->exec('ALTER TABLE posts ADD COLUMN author_user_id INT UNSIGNED NULL AFTER id');
        $pdo->exec('ALTER TABLE posts ADD INDEX idx_posts_status_published_at (status, published_at)');
        $pdo->exec('ALTER TABLE posts ADD INDEX idx_posts_category (category)');
        $pdo->exec('ALTER TABLE posts ADD CONSTRAINT fk_posts_author_user FOREIGN KEY (author_user_id) REFERENCES users(id) ON DELETE SET NULL');
    }

    $columnType = blog_column_data_type($pdo, 'posts', 'category');
    if ($columnType === 'enum') {
        $pdo->exec('ALTER TABLE posts MODIFY category VARCHAR(100) NOT NULL');
    }
}

function blog_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table AND COLUMN_NAME = :column');
    $stmt->execute([
        ':schema' => BLOG_DB_NAME,
        ':table' => $table,
        ':column' => $column,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function blog_column_data_type(PDO $pdo, string $table, string $column): string
{
    $stmt = $pdo->prepare('SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table AND COLUMN_NAME = :column LIMIT 1');
    $stmt->execute([
        ':schema' => BLOG_DB_NAME,
        ':table' => $table,
        ':column' => $column,
    ]);

    return (string) $stmt->fetchColumn();
}

function blog_seed_legacy_admin(PDO $pdo): void
{
    $adminCount = (int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
    if ($adminCount > 0) {
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO admins (username, password_hash, created_at) VALUES (:username, :password_hash, :created_at)');
    $stmt->execute([
        ':username' => BLOG_ADMIN_DEFAULT_USER,
        ':password_hash' => password_hash(BLOG_ADMIN_DEFAULT_PASS, PASSWORD_DEFAULT),
        ':created_at' => date('Y-m-d H:i:s'),
    ]);
}

function blog_seed_users(PDO $pdo): void
{
    $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($userCount > 0) {
        return;
    }

    $now = date('Y-m-d H:i:s');
    $users = [
        [
            'full_name' => 'CSTS Blog Administrator',
            'username' => 'admin',
            'email' => 'admin@cstsghana.com',
            'password' => BLOG_ADMIN_DEFAULT_PASS,
            'role' => 'admin',
            'status' => 'active',
        ],
        [
            'full_name' => 'CSTS Blog Editor',
            'username' => 'editor',
            'email' => 'editor@cstsghana.com',
            'password' => 'Editor@123',
            'role' => 'editor',
            'status' => 'active',
        ],
        [
            'full_name' => 'CSTS Blog Author',
            'username' => 'author',
            'email' => 'author@cstsghana.com',
            'password' => 'Author@123',
            'role' => 'author',
            'status' => 'active',
        ],
    ];

    $stmt = $pdo->prepare('INSERT INTO users (full_name, username, email, password_hash, role, status, created_at, updated_at)
        VALUES (:full_name, :username, :email, :password_hash, :role, :status, :created_at, :updated_at)');

    foreach ($users as $user) {
        $stmt->execute([
            ':full_name' => $user['full_name'],
            ':username' => $user['username'],
            ':email' => $user['email'],
            ':password_hash' => password_hash($user['password'], PASSWORD_DEFAULT),
            ':role' => $user['role'],
            ':status' => $user['status'],
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }
}

function blog_seed_categories(PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $now = date('Y-m-d H:i:s');
    $rows = [
        ['News', 'news', 'General announcements and insights from CSTS.'],
        ['Events', 'events', 'Workshops, webinars, and conference updates.'],
        ['Market Insights', 'market-insights', 'Original commentary on trends in Ghanaian markets.'],
    ];

    $stmt = $pdo->prepare('INSERT INTO categories (name, slug, description, created_at, updated_at) VALUES (:name, :slug, :description, :created_at, :updated_at)');
    foreach ($rows as $row) {
        $stmt->execute([
            ':name' => $row[0],
            ':slug' => $row[1],
            ':description' => $row[2],
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }
}

function blog_seed_tags(PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM tags')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $now = date('Y-m-d H:i:s');
    $tags = ['governance', 'compliance', 'capital-markets', 'ghana-business', 'workshop'];
    $stmt = $pdo->prepare('INSERT INTO tags (name, slug, created_at, updated_at) VALUES (:name, :slug, :created_at, :updated_at)');

    foreach ($tags as $name) {
        $stmt->execute([
            ':name' => ucwords(str_replace('-', ' ', $name)),
            ':slug' => $name,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }
}

function blog_seed_settings(PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM site_settings')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $now = date('Y-m-d H:i:s');
    $settings = [
        ['blog_title', 'CSTS Blog'],
        ['blog_subtitle', 'News and Events'],
        ['contact_email', 'events@cstsghana.com'],
        ['posts_per_page', '9'],
    ];

    $stmt = $pdo->prepare('INSERT INTO site_settings (setting_key, setting_value, created_at, updated_at) VALUES (:k, :v, :created_at, :updated_at)');
    foreach ($settings as $setting) {
        $stmt->execute([
            ':k' => $setting[0],
            ':v' => $setting[1],
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }
}

function blog_seed_posts(PDO $pdo): void
{
    $adminId = blog_get_default_admin_user_id($pdo);

    $seedPosts = [
        [
            'title' => 'Ghana Consumer Market Update: Retail Spending Patterns in Q1',
            'slug' => 'ghana-consumer-market-update-q1',
            'summary' => 'An original CSTS market note on changing household spending behavior in key urban centers.',
            'content' => '<p>Across Accra, Kumasi, and Takoradi, retail operators are seeing stronger demand for value-driven product bundles and smaller pack sizes. This suggests households are balancing spending carefully while maintaining core consumption priorities.</p><p>For businesses, the near-term opportunity is improved stock mix planning: essentials, trusted local brands, and flexible pricing formats continue to outperform premium-only shelves.</p><p>CSTS recommends a quarterly board review of pricing resilience, supply continuity, and customer retention metrics for firms exposed to consumer demand shifts.</p>',
            'category' => 'market-insights',
            'image_url' => 'https://images.unsplash.com/photo-1488459716781-31db52582fe9?auto=format&fit=crop&w=1200&q=80',
            'status' => 'published',
        ],
        [
            'title' => 'Original Brief: FX Management Priorities for Ghana SMEs',
            'slug' => 'fx-management-priorities-ghana-smes',
            'summary' => 'A practical governance brief on foreign-exchange risk planning for SME leadership teams.',
            'content' => '<p>SMEs with import-linked cost bases should update scenario planning assumptions monthly and align procurement cycles with liquidity forecasts.</p><p>Board and management teams can reduce pressure by introducing early-warning triggers for margin compression and by reviewing supplier contract clauses tied to currency movement.</p><p>While market conditions evolve, disciplined reporting and clear risk ownership remain the most reliable stabilizers.</p>',
            'category' => 'market-insights',
            'image_url' => 'https://images.unsplash.com/photo-1554224155-8d04cb21cd6c?auto=format&fit=crop&w=1200&q=80',
            'status' => 'published',
        ],
        [
            'title' => 'CSTS Webinar: Governance and Compliance Checklist for 2026',
            'slug' => 'csts-webinar-governance-compliance-checklist-2026',
            'summary' => 'Join our upcoming webinar focused on board reporting discipline and compliance readiness.',
            'content' => '<p>This webinar will present a board-ready checklist for governance and compliance execution in 2026, including practical templates for risk oversight and management accountability.</p><p>Participants will receive session materials and implementation notes to support internal policy updates.</p>',
            'category' => 'events',
            'image_url' => 'https://images.unsplash.com/photo-1511578314322-379afb476865?auto=format&fit=crop&w=1200&q=80',
            'status' => 'published',
        ],
    ];

    $stmt = $pdo->prepare('INSERT INTO posts (author_user_id, title, slug, summary, content, category, image_url, status, published_at, created_at, updated_at)
        VALUES (:author_user_id, :title, :slug, :summary, :content, :category, :image_url, :status, :published_at, :created_at, :updated_at)');

    foreach ($seedPosts as $post) {
        $exists = $pdo->prepare('SELECT COUNT(*) FROM posts WHERE slug = :slug');
        $exists->execute([':slug' => $post['slug']]);
        if ((int) $exists->fetchColumn() > 0) {
            continue;
        }

        $now = date('Y-m-d H:i:s');
        $stmt->execute([
            ':author_user_id' => $adminId,
            ':title' => $post['title'],
            ':slug' => $post['slug'],
            ':summary' => $post['summary'],
            ':content' => $post['content'],
            ':category' => $post['category'],
            ':image_url' => $post['image_url'],
            ':status' => $post['status'],
            ':published_at' => $now,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }
}

function blog_get_default_admin_user_id(PDO $pdo): ?int
{
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
    $stmt->execute([':username' => BLOG_ADMIN_DEFAULT_USER]);
    $id = $stmt->fetchColumn();

    return $id !== false ? (int) $id : null;
}

function blog_slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');

    if ($value === '') {
        $value = 'post-' . time();
    }

    return $value;
}

function blog_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function blog_excerpt(string $content, int $limit = 160): string
{
    $text = trim(strip_tags($content));
    if (mb_strlen($text) <= $limit) {
        return $text;
    }

    return mb_substr($text, 0, $limit - 1) . '...';
}

function blog_format_date(string $iso): string
{
    $timestamp = strtotime($iso);
    if ($timestamp === false) {
        return $iso;
    }

    return date('M d, Y', $timestamp);
}
