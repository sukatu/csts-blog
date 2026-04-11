<?php
require_once __DIR__ . '/../includes/auth.php';
blog_admin_logout();
header('Location: login.php');
exit;
