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
define('BLOG_SITE_COMPANY', 'Corporate Secretarial and Training Services (CSTS) Limited');
define('BLOG_SITE_TAGLINE', 'News, events, and market signals that matter to Ghanaian leaders.');

define('BLOG_DEVELOPER_NAME', 'Issa Sukatu Abdullahi');
define('BLOG_DEVELOPER_TITLE', 'Senior Developer');
define('BLOG_DEVELOPER_CREDIT', 'Developer of DennisLaw Ghana Search Engine; CEO of alphasmacodeinnovations.com');

define('BLOG_ADMIN_DEFAULT_USER', 'admin');
define('BLOG_ADMIN_DEFAULT_PASS', 'Admin@123');
define('BLOG_ADMIN_DEFAULT_EMAIL', 'admin@cstsghana.com');

function blog_base_url(): string
{
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');

    $scheme = $isSecure ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

    return $scheme . '://' . $host;
}

function blog_url(string $path = ''): string
{
    if ($path === '') {
        return blog_base_url();
    }

    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        return $path;
    }

    return rtrim(blog_base_url(), '/') . '/' . ltrim($path, '/');
}

function blog_current_url(): string
{
    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    return rtrim(blog_base_url(), '/') . $requestUri;
}

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
        source_name VARCHAR(120) NULL,
        source_url VARCHAR(255) NULL,
        status ENUM("draft", "published") NOT NULL DEFAULT "published",
        published_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_posts_status_published_at (status, published_at),
        INDEX idx_posts_category (category),
        UNIQUE KEY uniq_posts_source_url (source_url),
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

    $pdo->exec('CREATE TABLE IF NOT EXISTS newsletter_subscribers (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(191) NOT NULL,
        email VARCHAR(191) NOT NULL UNIQUE,
        status ENUM("subscribed", "unsubscribed") NOT NULL DEFAULT "subscribed",
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_newsletter_status (status)
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

    if (!blog_column_exists($pdo, 'posts', 'source_name')) {
        $pdo->exec('ALTER TABLE posts ADD COLUMN source_name VARCHAR(120) NULL AFTER image_url');
    }

    if (!blog_column_exists($pdo, 'posts', 'source_url')) {
        $pdo->exec('ALTER TABLE posts ADD COLUMN source_url VARCHAR(255) NULL AFTER source_name');
    }

    if (!blog_index_exists($pdo, 'posts', 'uniq_posts_source_url')) {
        $pdo->exec('ALTER TABLE posts ADD UNIQUE KEY uniq_posts_source_url (source_url)');
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

function blog_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table AND INDEX_NAME = :index_name');
    $stmt->execute([
        ':schema' => BLOG_DB_NAME,
        ':table' => $table,
        ':index_name' => $index,
    ]);

    return (int) $stmt->fetchColumn() > 0;
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
        [
            'title' => 'Ghana FMCG Playbook: Winning Distribution in Regional Cities',
            'slug' => 'ghana-fmcg-playbook-regional-distribution',
            'summary' => 'How consumer brands can scale beyond Accra through route discipline and channel partnerships.',
            'content' => '<p>For FMCG firms in Ghana, growth now depends on depth in regional trade channels, not just urban visibility. Structured route plans, distributor scorecards, and sell-through targets can improve consistency across Kumasi, Tamale, and Cape Coast.</p><p>Leaders should monitor outlet coverage quality, stock-out rates, and field execution weekly. Better data in these basics often unlocks margin and volume simultaneously.</p>',
            'category' => 'market-insights',
            'image_url' => 'https://images.unsplash.com/photo-1556740738-b6a63e27c4df?auto=format&fit=crop&w=1200&q=80',
            'status' => 'published',
        ],
        [
            'title' => 'Corporate Procurement in Ghana: Five Controls That Reduce Cost Leakage',
            'slug' => 'corporate-procurement-ghana-cost-leakage-controls',
            'summary' => 'A practical framework for procurement governance and vendor accountability.',
            'content' => '<p>Many mid-sized firms in Ghana can improve profitability by tightening procurement controls before renegotiating contracts. Standardized vendor onboarding, approval thresholds, and three-way matching significantly reduce avoidable spend.</p><p>Executive teams should pair policy with monthly exception reporting so departments are measured on compliance and commercial outcomes.</p>',
            'category' => 'news',
            'image_url' => 'https://images.unsplash.com/photo-1454165804606-c3d57bc86b40?auto=format&fit=crop&w=1200&q=80',
            'status' => 'published',
        ],
        [
            'title' => 'B2B Marketing in Ghana: Building Trust Through Thought Leadership',
            'slug' => 'b2b-marketing-ghana-thought-leadership-trust',
            'summary' => 'Why consistent insight publishing now outperforms aggressive promotion in B2B sectors.',
            'content' => '<p>In Ghanaian B2B markets, decision-makers increasingly reward firms that educate rather than just advertise. Publishing sector notes, regulatory explainers, and implementation case studies helps shorten sales cycles.</p><p>Marketing teams should align content with buying committee concerns: risk, execution reliability, and return on investment.</p>',
            'category' => 'market-insights',
            'image_url' => 'https://images.unsplash.com/photo-1460925895917-afdab827c52f?auto=format&fit=crop&w=1200&q=80',
            'status' => 'published',
        ],
        [
            'title' => '2026 Board Agenda: Corporate Risk Signals Ghana Executives Should Track',
            'slug' => '2026-board-agenda-corporate-risk-signals-ghana',
            'summary' => 'A concise risk watchlist for board and management meetings.',
            'content' => '<p>Boards in Ghana should prioritize risk signals tied to liquidity pressure, supplier concentration, and compliance exposure. A quarterly dashboard with clear ownership helps directors intervene before issues escalate.</p><p>High-performing boards also require management to show mitigation progress, not only risk descriptions.</p>',
            'category' => 'news',
            'image_url' => 'https://images.unsplash.com/photo-1454165804606-c3d57bc86b40?auto=format&fit=crop&w=1200&q=80',
            'status' => 'published',
        ],
        [
            'title' => 'Retail Pricing Strategy in Ghana: Balancing Volume and Margin',
            'slug' => 'retail-pricing-strategy-ghana-volume-margin',
            'summary' => 'How retailers can preserve profitability while protecting customer loyalty.',
            'content' => '<p>Retail operators can avoid destructive discount cycles by segmenting price architecture across traffic drivers, margin builders, and premium categories. This approach protects basket size while maintaining brand trust.</p><p>In-store execution and shelf communication are as important as the pricing model itself.</p>',
            'category' => 'market-insights',
            'image_url' => 'https://images.unsplash.com/photo-1607082348824-0a96f2a4b9da?auto=format&fit=crop&w=1200&q=80',
            'status' => 'published',
        ],
        [
            'title' => 'CSTS Masterclass: Corporate Communications During Market Volatility',
            'slug' => 'csts-masterclass-corporate-communications-market-volatility',
            'summary' => 'Join senior advisors for a practical session on stakeholder messaging and reputation control.',
            'content' => '<p>This masterclass covers communication planning for volatile business periods, including board messaging, investor updates, and internal alignment templates.</p><p>Attendees will receive scenario-based playbooks tailored to Ghanaian corporate environments.</p>',
            'category' => 'events',
            'image_url' => 'https://images.unsplash.com/photo-1515169067868-5387ec356754?auto=format&fit=crop&w=1200&q=80',
            'status' => 'published',
        ],
        [
            'title' => 'Corporate Branding in Ghana: Positioning Beyond Visual Identity',
            'slug' => 'corporate-branding-ghana-positioning-beyond-visuals',
            'summary' => 'A brand strategy perspective for firms targeting long-term trust and premium positioning.',
            'content' => '<p>Strong corporate brands in Ghana are built through consistent service delivery, transparent communication, and leadership credibility, not logos alone.</p><p>Executive teams should align operational standards with brand promises to close the gap between message and experience.</p>',
            'category' => 'market-insights',
            'image_url' => 'https://images.unsplash.com/photo-1553028826-f4804a6dfd3f?auto=format&fit=crop&w=1200&q=80',
            'status' => 'published',
        ],
        [
            'title' => 'Ghana SME Growth: Turning Informal Sales Data Into Commercial Insight',
            'slug' => 'ghana-sme-growth-sales-data-commercial-insight',
            'summary' => 'Simple analytics practices that improve sales planning without complex systems.',
            'content' => '<p>SMEs can improve growth quality by converting weekly sales logs into a basic performance dashboard that tracks product mix, channel velocity, and customer retention.</p><p>The objective is not complexity but consistency in decision-making.</p>',
            'category' => 'news',
            'image_url' => 'https://images.unsplash.com/photo-1450101499163-c8848c66ca85?auto=format&fit=crop&w=1200&q=80',
            'status' => 'published',
        ],
        [
            'title' => 'Marketing ROI in Ghana: Metrics Every Leadership Team Should Review Monthly',
            'slug' => 'marketing-roi-ghana-monthly-leadership-metrics',
            'summary' => 'From lead quality to payback periods, the signals that matter most.',
            'content' => '<p>Marketing should be evaluated as a commercial investment, not just a visibility exercise. Teams should report conversion by channel, cost per qualified lead, and customer lifetime value trends.</p><p>When these metrics are reviewed with finance and sales, campaign quality improves quickly.</p>',
            'category' => 'market-insights',
            'image_url' => 'https://images.unsplash.com/photo-1551281044-8b7a4a9f5b18?auto=format&fit=crop&w=1200&q=80',
            'status' => 'published',
        ],
        [
            'title' => 'Corporate Governance Brief: Strengthening Management Accountability Structures',
            'slug' => 'corporate-governance-brief-management-accountability-structures',
            'summary' => 'How role clarity and reporting cadence improve execution in Ghanaian firms.',
            'content' => '<p>Many execution challenges are accountability design problems. Clear mandate statements, defined KPIs, and recurring decision forums improve management discipline.</p><p>Boards should require evidence of cross-functional ownership for strategic priorities.</p>',
            'category' => 'news',
            'image_url' => 'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?auto=format&fit=crop&w=1200&q=80',
            'status' => 'published',
        ],
        [
            'title' => 'Customer Experience in Ghana Banking: The New Competitive Frontier',
            'slug' => 'customer-experience-ghana-banking-competitive-frontier',
            'summary' => 'Service reliability and response time now shape brand preference more than campaigns alone.',
            'content' => '<p>Banks that win in Ghana are improving core customer journeys: onboarding, issue resolution, and digital consistency. Experience metrics should be reviewed alongside product performance.</p><p>The strongest gains often come from fixing recurring pain points rather than launching new features.</p>',
            'category' => 'market-insights',
            'image_url' => 'https://images.unsplash.com/photo-1563013544-824ae1b704d3?auto=format&fit=crop&w=1200&q=80',
            'status' => 'published',
        ],
        [
            'title' => 'CSTS Business Breakfast: Corporate Strategy for 2027 Planning Cycles',
            'slug' => 'csts-business-breakfast-corporate-strategy-2027-planning',
            'summary' => 'An executive forum on growth priorities, governance, and commercial resilience.',
            'content' => '<p>This breakfast session will examine strategy planning assumptions for 2027, with emphasis on market uncertainty, cost discipline, and leadership alignment.</p><p>Participants will engage in facilitated peer exchange with board and C-suite perspectives.</p>',
            'category' => 'events',
            'image_url' => 'https://images.unsplash.com/photo-1511795409834-ef04bbd61622?auto=format&fit=crop&w=1200&q=80',
            'status' => 'published',
        ],
        [
            'title' => 'Corporate Sales Enablement in Ghana: Turning Training Into Revenue Results',
            'slug' => 'corporate-sales-enablement-ghana-training-revenue-results',
            'summary' => 'A guide to structured enablement programs for B2B teams.',
            'content' => '<p>Sales training works when linked to specific pipeline behaviors and manager coaching rhythms. Organizations should track proposal quality, conversion stages, and cycle time improvements.</p><p>Enablement investments should be tied to measurable commercial outcomes each quarter.</p>',
            'category' => 'market-insights',
            'image_url' => 'https://images.unsplash.com/photo-1552664730-d307ca884978?auto=format&fit=crop&w=1200&q=80',
            'status' => 'published',
        ],
        [
            'title' => 'Ghana Corporate Tax Changes: What CFOs Should Prioritize This Quarter',
            'slug' => 'ghana-corporate-tax-changes-cfo-quarter-priorities',
            'summary' => 'Execution priorities for finance teams navigating policy updates.',
            'content' => '<p>CFO offices should focus on impact mapping, documentation readiness, and scenario modeling to reduce disruption from tax changes. Cross-functional coordination with legal and operations is critical.</p><p>Firms that prepare early avoid costly compliance surprises.</p>',
            'category' => 'news',
            'image_url' => 'https://images.unsplash.com/photo-1444653614773-995cb1ef9efa?auto=format&fit=crop&w=1200&q=80',
            'status' => 'published',
        ],
        [
            'title' => 'Digital Marketing in Ghana: Channel Mix Strategies for Service Brands',
            'slug' => 'digital-marketing-ghana-channel-mix-service-brands',
            'summary' => 'How service businesses can balance awareness, leads, and retention online.',
            'content' => '<p>Service brands should design channel roles intentionally: search for intent capture, social for education, and email for retention. This avoids duplicated spend and inconsistent messaging.</p><p>Marketing calendars should sync with operational capacity to protect customer experience.</p>',
            'category' => 'market-insights',
            'image_url' => 'https://images.unsplash.com/photo-1467232004584-a241de8bcf5d?auto=format&fit=crop&w=1200&q=80',
            'status' => 'published',
        ],
        [
            'title' => 'Corporate Culture and Performance: Lessons From Ghanaian Growth Firms',
            'slug' => 'corporate-culture-performance-lessons-ghana-growth-firms',
            'summary' => 'Why operating culture is now a hard business metric.',
            'content' => '<p>Organizations scaling successfully in Ghana treat culture as a performance system. Clear expectations, manager capability, and transparent communication reduce execution friction.</p><p>Leadership teams should track engagement risks alongside operational KPIs.</p>',
            'category' => 'news',
            'image_url' => 'https://images.unsplash.com/photo-1521737604893-d14cc237f11d?auto=format&fit=crop&w=1200&q=80',
            'status' => 'published',
        ],
        [
            'title' => 'Market Entry in Ghana: Corporate Checklist for Regional Expansion',
            'slug' => 'market-entry-ghana-corporate-checklist-regional-expansion',
            'summary' => 'A strategic checklist for firms entering new zones in Ghana.',
            'content' => '<p>Before entering new regions, companies should validate demand assumptions, route economics, and compliance obligations. Pilot phases with clear success criteria reduce launch risk.</p><p>Expansion strategy should prioritize repeatability, not speed alone.</p>',
            'category' => 'market-insights',
            'image_url' => 'https://images.unsplash.com/photo-1451187580459-43490279c0fa?auto=format&fit=crop&w=1200&q=80',
            'status' => 'published',
        ],
        [
            'title' => 'CSTS Workshop: Executive Marketing Dashboards for Board Reporting',
            'slug' => 'csts-workshop-executive-marketing-dashboards-board-reporting',
            'summary' => 'A hands-on session for aligning marketing reporting with board expectations.',
            'content' => '<p>This workshop will help participants build concise dashboards that connect campaign activity to revenue outcomes and strategic risk factors.</p><p>Templates include board-ready scorecards and monthly review structures.</p>',
            'category' => 'events',
            'image_url' => 'https://images.unsplash.com/photo-1552664730-d307ca884978?auto=format&fit=crop&w=1200&q=80',
            'status' => 'published',
        ],
        [
            'title' => 'Corporate Reputation Management in Ghana: A Crisis Readiness Framework',
            'slug' => 'corporate-reputation-management-ghana-crisis-readiness-framework',
            'summary' => 'Preparedness steps for leadership teams and communications units.',
            'content' => '<p>Reputation resilience requires pre-approved response protocols, spokesperson readiness, and stakeholder mapping. Waiting until a crisis emerges increases legal and commercial exposure.</p><p>Executives should test response plans through simulation exercises at least twice yearly.</p>',
            'category' => 'news',
            'image_url' => 'https://images.unsplash.com/photo-1520607162513-77705c0f0d4a?auto=format&fit=crop&w=1200&q=80',
            'status' => 'published',
        ],
        [
            'title' => 'Consumer Loyalty in Ghana: Retention Strategies That Actually Work',
            'slug' => 'consumer-loyalty-ghana-retention-strategies',
            'summary' => 'From service reliability to targeted offers, what drives repeat purchase behavior.',
            'content' => '<p>Retention improves when companies combine service consistency with data-informed offers. Blanket promotions are expensive and often ineffective.</p><p>Segmented loyalty mechanics and proactive support can significantly increase customer lifetime value.</p>',
            'category' => 'market-insights',
            'image_url' => 'https://images.unsplash.com/photo-1556742393-d75f468bfcb0?auto=format&fit=crop&w=1200&q=80',
            'status' => 'published',
        ],
        [
            'title' => 'Board Pack Excellence: Structuring Corporate Reports for Better Decisions',
            'slug' => 'board-pack-excellence-corporate-reports-better-decisions',
            'summary' => 'Improve board meeting quality through clearer narrative and metrics.',
            'content' => '<p>Effective board packs link strategy, financial performance, and key risks in one coherent narrative. Overly detailed appendices without decision context reduce board effectiveness.</p><p>Management teams should frame each section around decisions required, options available, and trade-offs.</p>',
            'category' => 'news',
            'image_url' => 'https://images.unsplash.com/photo-1521791136064-7986c2920216?auto=format&fit=crop&w=1200&q=80',
            'status' => 'published',
        ],
        [
            'title' => 'Ghana Real Estate Marketing: Demand Signals for Commercial Developers',
            'slug' => 'ghana-real-estate-marketing-demand-signals-commercial-developers',
            'summary' => 'A demand-focused perspective for project positioning and leasing strategy.',
            'content' => '<p>Commercial developers should refine positioning based on tenant mix economics, transport access, and occupancy risk. Marketing campaigns perform better when backed by clear value propositions for target sectors.</p><p>Pre-leasing intelligence can reduce revenue volatility after launch.</p>',
            'category' => 'market-insights',
            'image_url' => 'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?auto=format&fit=crop&w=1200&q=80',
            'status' => 'published',
        ],
        [
            'title' => 'CSTS Corporate Roundtable: Leadership Communication for Growing Teams',
            'slug' => 'csts-corporate-roundtable-leadership-communication-growing-teams',
            'summary' => 'An invitation-only roundtable for executive communication strategy.',
            'content' => '<p>This roundtable explores communication practices that maintain alignment during rapid growth, including role clarity, feedback systems, and strategic messaging cadence.</p><p>Sessions are designed for CEOs, HR leaders, and business unit heads.</p>',
            'category' => 'events',
            'image_url' => 'https://images.unsplash.com/photo-1519389950473-47ba0277781c?auto=format&fit=crop&w=1200&q=80',
            'status' => 'published',
        ],
        [
            'title' => 'Corporate Partnerships in Ghana: Structuring Win-Win Distribution Alliances',
            'slug' => 'corporate-partnerships-ghana-distribution-alliances',
            'summary' => 'Partnership models that improve market access without diluting control.',
            'content' => '<p>Well-structured alliances can accelerate access to channels and capabilities. Success depends on governance terms, joint KPIs, and periodic performance reviews.</p><p>Partnerships should be treated as managed portfolios, not informal relationships.</p>',
            'category' => 'market-insights',
            'image_url' => 'https://images.unsplash.com/photo-1521737711867-e3b97375f902?auto=format&fit=crop&w=1200&q=80',
            'status' => 'published',
        ],
        [
            'title' => 'Ghana Corporate Hiring Trends: Building Commercial Teams for Scale',
            'slug' => 'ghana-corporate-hiring-trends-commercial-teams-scale',
            'summary' => 'Talent patterns shaping sales, marketing, and strategy functions.',
            'content' => '<p>Companies scaling in Ghana are redesigning commercial roles around analytical capability, customer insight, and cross-functional collaboration. Hiring for adaptability is becoming as important as technical expertise.</p><p>Leadership should align incentive structures with long-term value creation.</p>',
            'category' => 'news',
            'image_url' => 'https://images.unsplash.com/photo-1450101499163-c8848c66ca85?auto=format&fit=crop&w=1200&q=80',
            'status' => 'published',
        ],
        [
            'title' => 'Brand Architecture for Diversified Ghanaian Companies',
            'slug' => 'brand-architecture-diversified-ghanaian-companies',
            'summary' => 'Choosing between house-of-brands and endorsed brand strategies.',
            'content' => '<p>Diversified firms should revisit brand architecture as portfolios expand into adjacent sectors. Clarity in brand hierarchy improves marketing efficiency and customer recognition.</p><p>Strategic brand decisions should follow commercial logic, not design preference.</p>',
            'category' => 'market-insights',
            'image_url' => 'https://images.unsplash.com/photo-1508385082359-f38ae991e8f2?auto=format&fit=crop&w=1200&q=80',
            'status' => 'published',
        ],
        [
            'title' => 'Corporate Performance Reviews: Linking Strategy to Department KPIs',
            'slug' => 'corporate-performance-reviews-strategy-department-kpis',
            'summary' => 'A practical cadence for strategy execution reviews in Ghanaian organizations.',
            'content' => '<p>Execution quality improves when strategic priorities are translated into department-level KPIs with clear owners and timelines. Monthly review forums should focus on decisions and blockers, not just updates.</p><p>Consistency in this rhythm drives faster corrective action.</p>',
            'category' => 'news',
            'image_url' => 'https://images.unsplash.com/photo-1554224154-26032ffc0d07?auto=format&fit=crop&w=1200&q=80',
            'status' => 'published',
        ],
        [
            'title' => 'Ghanaian Consumer Segmentation: Smarter Messaging for Urban Markets',
            'slug' => 'ghanaian-consumer-segmentation-smarter-urban-messaging',
            'summary' => 'Segment-first campaign design for better conversion and retention.',
            'content' => '<p>Urban consumers in Ghana are diverse in income behavior, channel preference, and brand sensitivity. Marketers should segment by needs and purchase context rather than demographics alone.</p><p>Campaign relevance improves significantly when messaging is tailored to real buying moments.</p>',
            'category' => 'market-insights',
            'image_url' => 'https://images.unsplash.com/photo-1472851294608-062f824d29cc?auto=format&fit=crop&w=1200&q=80',
            'status' => 'published',
        ],
        [
            'title' => 'CSTS Executive Session: Corporate Finance for Non-Finance Leaders',
            'slug' => 'csts-executive-session-corporate-finance-non-finance-leaders',
            'summary' => 'A focused learning session to improve financial decision quality across teams.',
            'content' => '<p>This executive session translates core finance concepts into operational decision frameworks for marketing, operations, and strategy leaders.</p><p>Participants will work through practical scenarios relevant to Ghanaian business contexts.</p>',
            'category' => 'events',
            'image_url' => 'https://images.unsplash.com/photo-1450101499163-c8848c66ca85?auto=format&fit=crop&w=1200&q=80',
            'status' => 'published',
        ],
        [
            'title' => 'Corporate Social Impact and Brand Equity in Ghana',
            'slug' => 'corporate-social-impact-brand-equity-ghana',
            'summary' => 'How social initiatives influence trust, loyalty, and long-term valuation.',
            'content' => '<p>Social impact programs strengthen brand equity when they are strategically aligned and measured for real outcomes. Token initiatives may increase skepticism among stakeholders.</p><p>Companies should report impact metrics with the same rigor as business KPIs.</p>',
            'category' => 'news',
            'image_url' => 'https://images.unsplash.com/photo-1469571486292-b53601020dbf?auto=format&fit=crop&w=1200&q=80',
            'status' => 'published',
        ],
        [
            'title' => 'Pricing Governance: Corporate Decision Rights for Revenue Protection',
            'slug' => 'pricing-governance-corporate-decision-rights-revenue-protection',
            'summary' => 'A governance model for disciplined pricing execution in competitive markets.',
            'content' => '<p>Pricing inconsistency often comes from unclear decision rights. Firms should define who approves price moves, discount bands, and exceptions by channel.</p><p>Governance clarity protects both margin and brand integrity.</p>',
            'category' => 'market-insights',
            'image_url' => 'https://images.unsplash.com/photo-1554224155-6726b3ff858f?auto=format&fit=crop&w=1200&q=80',
            'status' => 'published',
        ],
        [
            'title' => 'Ghana Corporate Outlook: Mid-Year Market Sentiment Pulse',
            'slug' => 'ghana-corporate-outlook-mid-year-market-sentiment-pulse',
            'summary' => 'A snapshot of executive sentiment across growth, risk, and investment plans.',
            'content' => '<p>Mid-year sentiment indicates cautious optimism among Ghanaian executives, with strong focus on cash discipline and targeted growth bets. Most firms are prioritizing execution quality over expansion speed.</p><p>Scenario planning remains central to strategic confidence.</p>',
            'category' => 'news',
            'image_url' => 'https://images.unsplash.com/photo-1542744173-8e7e53415bb0?auto=format&fit=crop&w=1200&q=80',
            'status' => 'published',
        ],
        [
            'title' => 'Marketing Operations in Ghana: Building Teams That Execute Consistently',
            'slug' => 'marketing-operations-ghana-teams-execute-consistently',
            'summary' => 'Process and capability design for high-performing marketing departments.',
            'content' => '<p>High-output marketing teams rely on clear planning cycles, campaign governance, and defined accountability by role. Without operational discipline, creative quality alone cannot sustain results.</p><p>Leaders should invest in planning systems as much as media spend.</p>',
            'category' => 'market-insights',
            'image_url' => 'https://images.unsplash.com/photo-1521791136064-7986c2920216?auto=format&fit=crop&w=1200&q=80',
            'status' => 'published',
        ],
        [
            'title' => 'CSTS Annual Forum: The Future of Corporate Leadership in Ghana',
            'slug' => 'csts-annual-forum-future-corporate-leadership-ghana',
            'summary' => 'A flagship event exploring governance, growth, and transformation priorities.',
            'content' => '<p>The CSTS Annual Forum convenes executives, board members, and policy leaders to discuss strategic direction for corporate Ghana.</p><p>The 2026 edition emphasizes leadership capability, operating resilience, and stakeholder trust in uncertain markets.</p>',
            'category' => 'events',
            'image_url' => 'https://images.unsplash.com/photo-1511578314322-379afb476865?auto=format&fit=crop&w=1200&q=80',
            'status' => 'published',
        ],
        [
            'title' => 'Corporate Innovation in Ghana: How to Scale Pilots Into Core Business',
            'slug' => 'corporate-innovation-ghana-scale-pilots-core-business',
            'summary' => 'A practical model for moving innovation from experiments to revenue impact.',
            'content' => '<p>Innovation efforts often stall after pilots because ownership and scaling paths are unclear. Companies should define transition criteria, sponsoring teams, and resource commitments from the start.</p><p>Execution discipline is the bridge between ideas and enterprise value.</p>',
            'category' => 'market-insights',
            'image_url' => 'https://images.unsplash.com/photo-1518779578993-ec3579fee39f?auto=format&fit=crop&w=1200&q=80',
            'status' => 'published',
        ],
        [
            'title' => 'Marketing Leadership in Ghana: Aligning Brand and Commercial Teams',
            'slug' => 'marketing-leadership-ghana-aligning-brand-commercial-teams',
            'summary' => 'How organizational alignment improves campaign quality and revenue outcomes.',
            'content' => '<p>When brand and commercial teams operate in silos, customer messaging and sales execution drift apart. Shared planning sessions and common KPIs reduce this gap.</p><p>Leadership alignment creates the conditions for sustainable growth.</p>',
            'category' => 'market-insights',
            'image_url' => 'https://images.unsplash.com/photo-1460925895917-afdab827c52f?auto=format&fit=crop&w=1200&q=80',
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
            ':image_url' => blog_seed_image_for_category((string) $post['category']),
            ':status' => $post['status'],
            ':published_at' => $now,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }
}

function blog_seed_image_for_category(string $category): string
{
    return match ($category) {
        'events' => 'https://images.unsplash.com/photo-1511578314322-379afb476865?auto=format&fit=crop&w=1200&q=80',
        'news' => 'https://images.unsplash.com/photo-1454165804606-c3d57bc86b40?auto=format&fit=crop&w=1200&q=80',
        default => 'https://images.unsplash.com/photo-1554224155-8d04cb21cd6c?auto=format&fit=crop&w=1200&q=80',
    };
}

function blog_ensure_newsletter_schema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS newsletter_subscribers (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(191) NOT NULL,
        email VARCHAR(191) NOT NULL UNIQUE,
        status ENUM("subscribed", "unsubscribed") NOT NULL DEFAULT "subscribed",
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_newsletter_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function blog_subscribe_newsletter(PDO $pdo, string $fullName, string $email): void
{
    blog_ensure_newsletter_schema($pdo);

    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare('INSERT INTO newsletter_subscribers (full_name, email, status, created_at, updated_at)
        VALUES (:full_name, :email, "subscribed", :created_at, :updated_at)
        ON DUPLICATE KEY UPDATE
            full_name = VALUES(full_name),
            status = "subscribed",
            updated_at = VALUES(updated_at)');

    $stmt->execute([
        ':full_name' => $fullName,
        ':email' => $email,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
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
