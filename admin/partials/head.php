<?php
/**
 * Admin shared <head> partial.
 * Usage: require __DIR__ . '/partials/head.php';
 * Variables:
 *   $adminPageTitle  string  — page title (required before include)
 */
$adminPageTitle = $adminPageTitle ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($adminPageTitle, ENT_QUOTES, 'UTF-8'); ?> — CSTS Blog Admin</title>
  <link rel="stylesheet" href="../assets/admin.css">
</head>
<body class="a-body">
<div class="a-app">
