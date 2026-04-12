<?php
require_once __DIR__ . '/../includes/auth.php';
blog_require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        $pdo = blog_db();
        $find = $pdo->prepare('SELECT image_url FROM posts WHERE id = :id LIMIT 1');
        $find->execute([':id' => $id]);
        $post = $find->fetch();

        $stmt = $pdo->prepare('DELETE FROM posts WHERE id = :id');
        $stmt->execute([':id' => $id]);

        blog_delete_uploaded_image($post['image_url'] ?? null);
    }
}

header('Location: posts.php');
exit;
