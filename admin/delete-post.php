<?php
require_once __DIR__ . '/../includes/auth.php';
blog_require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        $pdo = blog_db();
        $stmt = $pdo->prepare('DELETE FROM posts WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }
}

header('Location: posts.php');
exit;
