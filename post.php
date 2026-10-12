<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/content.php';
require_once __DIR__ . '/includes/public_auth.php';

$slug = trim($_GET['slug'] ?? '');
if ($slug === '') {
    http_response_code(404);
    exit('Post not found.');
}

$pdo = blog_db();
$stmt = $pdo->prepare('SELECT id, title, summary, content, category, image_url, source_name, source_url, published_at FROM posts WHERE slug = :slug AND status = :status LIMIT 1');
$stmt->execute([':slug' => $slug, ':status' => 'published']);
$post = $stmt->fetch();

if (!$post) {
    http_response_code(404);
    exit('Post not found.');
}

blog_public_auth_bootstrap($pdo);

$authMessage = '';
$authError = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $formType = (string) ($_POST['form_type'] ?? '');

  if ($formType === 'register') {
    $registerResult = blog_public_register(
      $pdo,
      (string) ($_POST['full_name'] ?? ''),
      (string) ($_POST['email'] ?? ''),
      (string) ($_POST['password'] ?? '')
    );
    if ($registerResult['ok']) {
      $authMessage = (string) $registerResult['message'];
    } else {
      $authError = (string) $registerResult['message'];
    }
  }

  if ($formType === 'login') {
    $loginResult = blog_public_login(
      $pdo,
      (string) ($_POST['email'] ?? ''),
      (string) ($_POST['password'] ?? '')
    );
    if ($loginResult['ok']) {
      $authMessage = (string) $loginResult['message'];
    } else {
      $authError = (string) $loginResult['message'];
    }
  }

  if ($formType === 'logout') {
    blog_public_logout();
    $authMessage = 'You have been logged out.';
  }

  if ($formType === 'comment') {
    $currentUser = blog_public_current_user($pdo);
    if (!$currentUser) {
      $authError = 'Please sign in to post a comment.';
    } else {
      $commentText = trim((string) ($_POST['comment_text'] ?? ''));
      if ($commentText === '') {
        $authError = 'Comment cannot be empty.';
      } elseif (mb_strlen($commentText) > 1500) {
        $authError = 'Comment must be 1500 characters or fewer.';
      } else {
        $insertComment = $pdo->prepare('INSERT INTO post_comments (post_id, community_user_id, comment_text, status, created_at, updated_at)
            VALUES (:post_id, :community_user_id, :comment_text, "visible", :created_at, :updated_at)');
        $now = date('Y-m-d H:i:s');
        $insertComment->execute([
          ':post_id' => (int) $post['id'],
          ':community_user_id' => (int) $currentUser['id'],
          ':comment_text' => $commentText,
          ':created_at' => $now,
          ':updated_at' => $now,
        ]);
        $authMessage = 'Comment posted successfully.';
      }
    }
  }

  if ($formType === 'reaction') {
    $currentUser = blog_public_current_user($pdo);
    if (!$currentUser) {
      $authError = 'Please sign in to react to this post.';
    } else {
      $reaction = (string) ($_POST['reaction'] ?? '');
      if (!in_array($reaction, ['like', 'dislike'], true)) {
        $authError = 'Invalid reaction selected.';
      } else {
        $now = date('Y-m-d H:i:s');
        $upsertReaction = $pdo->prepare('INSERT INTO post_reactions (post_id, community_user_id, reaction, created_at, updated_at)
            VALUES (:post_id, :community_user_id, :reaction, :created_at, :updated_at)
            ON DUPLICATE KEY UPDATE reaction = VALUES(reaction), updated_at = VALUES(updated_at)');
        $upsertReaction->execute([
          ':post_id' => (int) $post['id'],
          ':community_user_id' => (int) $currentUser['id'],
          ':reaction' => $reaction,
          ':created_at' => $now,
          ':updated_at' => $now,
        ]);
        $authMessage = ucfirst($reaction) . ' recorded.';
      }
    }
  }
}

$publicUser = blog_public_current_user($pdo);

$reactionCountsStmt = $pdo->prepare('SELECT reaction, COUNT(*) AS total FROM post_reactions WHERE post_id = :post_id GROUP BY reaction');
$reactionCountsStmt->execute([':post_id' => (int) $post['id']]);
$reactionRows = $reactionCountsStmt->fetchAll();
$likeCount = 0;
$dislikeCount = 0;
foreach ($reactionRows as $reactionRow) {
  if (($reactionRow['reaction'] ?? '') === 'like') {
    $likeCount = (int) $reactionRow['total'];
  }
  if (($reactionRow['reaction'] ?? '') === 'dislike') {
    $dislikeCount = (int) $reactionRow['total'];
  }
}

$userReaction = null;
if ($publicUser) {
  $userReactionStmt = $pdo->prepare('SELECT reaction FROM post_reactions WHERE post_id = :post_id AND community_user_id = :community_user_id LIMIT 1');
  $userReactionStmt->execute([
    ':post_id' => (int) $post['id'],
    ':community_user_id' => (int) $publicUser['id'],
  ]);
  $userReaction = $userReactionStmt->fetchColumn() ?: null;
}

$commentsStmt = $pdo->prepare('SELECT c.comment_text, c.created_at, u.full_name
    FROM post_comments c
    INNER JOIN community_users u ON u.id = c.community_user_id
    WHERE c.post_id = :post_id AND c.status = "visible"
    ORDER BY c.created_at DESC
    LIMIT 80');
$commentsStmt->execute([':post_id' => (int) $post['id']]);
$comments = $commentsStmt->fetchAll();

$shareUrl = blog_url('post.php?slug=' . urlencode($slug));

$metaTitle = $post['title'] . ' | ' . BLOG_SITE_NAME;
$metaDescription = $post['summary'] !== '' ? $post['summary'] : blog_excerpt($post['content'], 160);
$canonicalUrl = blog_url('post.php?slug=' . urlencode($slug));
$metaImage = !empty($post['image_url']) ? (string) $post['image_url'] : blog_url('assets/csts-logo.png');
$publishedIso = date('c', strtotime((string) $post['published_at']) ?: time());

$articleSchema = [
  '@context' => 'https://schema.org',
  '@type' => 'NewsArticle',
  'headline' => $post['title'],
  'description' => $metaDescription,
  'datePublished' => $publishedIso,
  'dateModified' => $publishedIso,
  'mainEntityOfPage' => $canonicalUrl,
  'image' => [$metaImage],
  'author' => [
    '@type' => 'Person',
    'name' => BLOG_DEVELOPER_NAME,
  ],
  'publisher' => [
    '@type' => 'Organization',
    'name' => BLOG_SITE_COMPANY,
    'logo' => [
      '@type' => 'ImageObject',
      'url' => blog_url('assets/csts-logo.png'),
    ],
  ],
];

if (!empty($post['source_url'])) {
  $articleSchema['isBasedOn'] = $post['source_url'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo blog_e($metaTitle); ?></title>
  <meta name="description" content="<?php echo blog_e($metaDescription); ?>">
  <meta name="author" content="<?php echo blog_e(BLOG_DEVELOPER_NAME); ?>">
  <meta name="developer" content="<?php echo blog_e(BLOG_DEVELOPER_NAME . ' - ' . BLOG_DEVELOPER_TITLE); ?>">
  <meta name="credit" content="<?php echo blog_e(BLOG_DEVELOPER_CREDIT); ?>">
  <meta name="publisher" content="<?php echo blog_e(BLOG_SITE_COMPANY); ?>">
  <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
  <meta name="googlebot" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
  <meta name="bingbot" content="index, follow">
  <link rel="canonical" href="<?php echo blog_e($canonicalUrl); ?>">

  <meta property="og:type" content="article">
  <meta property="og:site_name" content="<?php echo blog_e(BLOG_SITE_NAME); ?>">
  <meta property="og:title" content="<?php echo blog_e($metaTitle); ?>">
  <meta property="og:description" content="<?php echo blog_e($metaDescription); ?>">
  <meta property="og:url" content="<?php echo blog_e($canonicalUrl); ?>">
  <meta property="og:image" content="<?php echo blog_e($metaImage); ?>">
  <meta property="article:published_time" content="<?php echo blog_e($publishedIso); ?>">

  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?php echo blog_e($metaTitle); ?>">
  <meta name="twitter:description" content="<?php echo blog_e($metaDescription); ?>">
  <meta name="twitter:image" content="<?php echo blog_e($metaImage); ?>">

    <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-6529286790085931"
      crossorigin="anonymous"></script>

  <script type="application/ld+json"><?php echo json_encode($articleSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?></script>
  <link rel="stylesheet" href="assets/styles.css">
</head>
<body class="blog-public">
  <div class="utility-bar">
    <div class="container utility-inner">
      <div class="utility-links">
        <a href="index.php">News Desk</a>
        <a href="?category=events">Events Calendar</a>
        <a href="mailto:events@cstsghana.com">events@cstsghana.com</a>
      </div>
      <div class="utility-cta">
        <?php if ($publicUser): ?>
          <span class="utility-btn" style="background: linear-gradient(120deg, #1e5a86, #2f7cad);">Hi, <?php echo blog_e((string) $publicUser['full_name']); ?></span>
        <?php else: ?>
          <a href="#community-auth" class="utility-btn">Join Conversation</a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <header class="topbar">
    <div class="container topbar-inner">
      <a href="index.php" class="brand logo-only" aria-label="Go to CSTS Blog home"><img src="assets/csts-logo.png" alt="CSTS Logo" class="brand-logo">CSTS Blog<small>News & Events</small></a>
      <nav class="nav-links">
        <a href="index.php">Back to blog</a>
        <a href="https://cstsghana.com" target="_blank" rel="noopener noreferrer">CSTS Website</a>
        <?php if ($publicUser): ?>
          <a href="#comments">My comments</a>
        <?php else: ?>
          <a href="#community-auth">Create Account</a>
        <?php endif; ?>
        <a href="admin/login.php">Admin</a>
      </nav>
    </div>
  </header>

  <section class="hero hero-wide post-hero">
    <div class="container">
      <p class="eyebrow">Article</p>
      <h1 class="post-hero-title"><?php echo blog_e($post['title']); ?></h1>
      <div class="meta post-hero-meta"><?php echo blog_format_date($post['published_at']); ?> • <?php echo strtoupper(blog_e($post['category'])); ?></div>
    </div>
  </section>

  <main class="container">
    <article class="article article-wide">
      <span class="badge"><?php echo blog_e($post['category']); ?></span>
      <?php if ($authMessage): ?><div class="flash ok"><?php echo blog_e($authMessage); ?></div><?php endif; ?>
      <?php if ($authError): ?><div class="flash err"><?php echo blog_e($authError); ?></div><?php endif; ?>
      <?php if (!empty($post['summary'])): ?>
        <p class="summary" style="font-size:16px;"><?php echo blog_e($post['summary']); ?></p>
      <?php endif; ?>
      <?php if (!empty($post['image_url'])): ?>
        <img src="<?php echo blog_e($post['image_url']); ?>" alt="<?php echo blog_e($post['title']); ?>" style="width:100%; border-radius:10px; margin:18px 0;">
      <?php endif; ?>
      <?php if (!empty($post['source_url'])): ?>
        <p class="meta" style="margin: 0 0 12px;">Source: <a href="<?php echo blog_e($post['source_url']); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php echo blog_e($post['source_name'] ?: 'External'); ?></a></p>
      <?php endif; ?>
      <div class="article-content"><?php echo blog_content_html((string) $post['content']); ?></div>

      <section class="post-engagement">
        <h3>Post Engagement</h3>
        <div class="post-actions-grid">
          <form method="post" class="post-action-form">
            <input type="hidden" name="form_type" value="reaction">
            <input type="hidden" name="reaction" value="like">
            <button class="btn btn-outline post-action-btn <?php echo $userReaction === 'like' ? 'is-active' : ''; ?>" type="submit">Like <span><?php echo $likeCount; ?></span></button>
          </form>
          <form method="post" class="post-action-form">
            <input type="hidden" name="form_type" value="reaction">
            <input type="hidden" name="reaction" value="dislike">
            <button class="btn btn-outline post-action-btn <?php echo $userReaction === 'dislike' ? 'is-active' : ''; ?>" type="submit">Dislike <span><?php echo $dislikeCount; ?></span></button>
          </form>
          <button class="btn btn-outline post-action-btn" type="button" id="share-post-btn" data-share-url="<?php echo blog_e($shareUrl); ?>">Share</button>
          <button class="btn btn-outline post-action-btn" type="button" id="copy-post-btn" data-copy-url="<?php echo blog_e($shareUrl); ?>">Copy Link</button>
        </div>
        <p class="meta" id="share-feedback" style="margin:8px 0 0;"></p>
      </section>

      <section class="community-auth" id="community-auth">
        <?php if ($publicUser): ?>
          <div class="community-user-box">
            <p class="meta" style="margin:0 0 8px;">Signed in as <?php echo blog_e((string) $publicUser['full_name']); ?> (<?php echo blog_e((string) $publicUser['email']); ?>)</p>
            <form method="post">
              <input type="hidden" name="form_type" value="logout">
              <button class="btn btn-outline" type="submit">Log out</button>
            </form>
          </div>
        <?php else: ?>
          <div class="community-auth-grid">
            <form method="post" class="community-form">
              <input type="hidden" name="form_type" value="register">
              <h4>Create account</h4>
              <label>Full name</label>
              <input class="input" name="full_name" required>
              <label>Email</label>
              <input class="input" type="email" name="email" required>
              <label>Password</label>
              <input class="input" type="password" name="password" minlength="6" required>
              <button class="btn" type="submit" style="margin-top:10px;">Create account</button>
            </form>

            <form method="post" class="community-form">
              <input type="hidden" name="form_type" value="login">
              <h4>Sign in</h4>
              <label>Email</label>
              <input class="input" type="email" name="email" required>
              <label>Password</label>
              <input class="input" type="password" name="password" required>
              <button class="btn" type="submit" style="margin-top:10px;">Sign in</button>
            </form>
          </div>
        <?php endif; ?>
      </section>

      <section class="post-comments" id="comments">
        <h3>Comments (<?php echo count($comments); ?>)</h3>

        <?php if ($publicUser): ?>
          <form method="post" class="comment-form">
            <input type="hidden" name="form_type" value="comment">
            <label for="comment_text">Share your thoughts</label>
            <textarea class="textarea" id="comment_text" name="comment_text" rows="4" maxlength="1500" required></textarea>
            <button class="btn" type="submit" style="margin-top:10px;">Post comment</button>
          </form>
        <?php else: ?>
          <p class="meta">Create an account or sign in above to post a comment.</p>
        <?php endif; ?>

        <div class="comment-list">
          <?php if (!$comments): ?>
            <p class="meta">No comments yet. Start the conversation.</p>
          <?php endif; ?>
          <?php foreach ($comments as $comment): ?>
            <article class="comment-card">
              <div class="comment-head">
                <strong><?php echo blog_e((string) $comment['full_name']); ?></strong>
                <span class="meta"><?php echo blog_format_date((string) $comment['created_at']); ?></span>
              </div>
              <p><?php echo nl2br(blog_e((string) $comment['comment_text'])); ?></p>
            </article>
          <?php endforeach; ?>
        </div>
      </section>
    </article>
  </main>

  <footer class="footer">
    <div class="container footer-grid">
      <section class="footer-brand">
        <a href="index.php" aria-label="Go to CSTS Blog home"><img src="assets/csts-logo.png" alt="CSTS Logo" class="footer-logo"></a>
        <p>Corporate strategy, governance, and market intelligence for leaders in Ghana.</p>
        <div class="footer-socials">
          <a href="https://www.linkedin.com" target="_blank" rel="noopener noreferrer">LinkedIn</a>
          <a href="https://x.com" target="_blank" rel="noopener noreferrer">X</a>
          <a href="https://www.facebook.com" target="_blank" rel="noopener noreferrer">Facebook</a>
        </div>
      </section>

      <section>
        <h4>Explore</h4>
        <ul>
          <li><a href="index.php">Home</a></li>
          <li><a href="index.php?category=news">Business News</a></li>
          <li><a href="index.php?category=events">Events</a></li>
          <li><a href="index.php?category=all">All Publications</a></li>
        </ul>
      </section>

      <section>
        <h4>Company</h4>
        <ul>
          <li><a href="https://cstsghana.com" target="_blank" rel="noopener noreferrer">About CSTS</a></li>
          <li><a href="https://cstsghana.com" target="_blank" rel="noopener noreferrer">Services</a></li>
          <li><a href="https://cstsghana.com" target="_blank" rel="noopener noreferrer">Insights</a></li>
          <li><a href="mailto:events@cstsghana.com">Contact Team</a></li>
        </ul>
      </section>

      <section>
        <h4>Contact</h4>
        <ul>
          <li><a href="mailto:events@cstsghana.com">events@cstsghana.com</a></li>
          <li><a href="https://cstsghana.com" target="_blank" rel="noopener noreferrer">www.cstsghana.com</a></li>
          <li>Accra, Ghana</li>
          <li><a href="admin/login.php">Admin Portal</a></li>
        </ul>
      </section>
    </div>

    <div class="footer-bottom">
      <div class="container footer-bottom-inner">
        <span>&copy; <?php echo date('Y'); ?> CSTS Blog. All rights reserved.</span>
        <div class="footer-bottom-links">
          <a href="https://cstsghana.com" target="_blank" rel="noopener noreferrer">Main Website</a>
          <a href="mailto:events@cstsghana.com">Support</a>
        </div>
      </div>
    </div>
  </footer>

  <script>
    const shareBtn = document.getElementById('share-post-btn');
    const copyBtn = document.getElementById('copy-post-btn');
    const shareFeedback = document.getElementById('share-feedback');

    function setShareFeedback(message) {
      if (shareFeedback) {
        shareFeedback.textContent = message;
      }
    }

    if (shareBtn) {
      shareBtn.addEventListener('click', async () => {
        const shareUrl = shareBtn.getAttribute('data-share-url') || window.location.href;
        try {
          if (navigator.share) {
            await navigator.share({
              title: document.title,
              text: 'Check out this post on CSTS Blog',
              url: shareUrl,
            });
            setShareFeedback('Post shared successfully.');
          } else if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(shareUrl);
            setShareFeedback('Share not supported here, so link was copied instead.');
          } else {
            window.prompt('Copy this link:', shareUrl);
            setShareFeedback('Share not supported. Use the copied link.');
          }
        } catch (err) {
          setShareFeedback('Share canceled or unavailable.');
        }
      });
    }

    if (copyBtn) {
      copyBtn.addEventListener('click', async () => {
        const copyUrl = copyBtn.getAttribute('data-copy-url') || window.location.href;
        try {
          if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(copyUrl);
            setShareFeedback('Post link copied.');
          } else {
            window.prompt('Copy this link:', copyUrl);
            setShareFeedback('Clipboard API unavailable. Copy link from prompt.');
          }
        } catch (err) {
          setShareFeedback('Unable to copy link on this browser.');
        }
      });
    }
  </script>
</body>
</html>
