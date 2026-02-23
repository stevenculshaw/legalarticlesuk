<?php
if (!defined('BASE_PATH')) exit;
$u    = currentUser();
$role = $u['role'] ?? '';
$flash = flashGet();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($pageTitle ?? APP_NAME) ?> — <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/portal.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
<nav class="navbar">
  <div class="nav-brand">
    <a href="<?= BASE_URL ?>/dashboard.php"><span class="brand-icon">⚖</span><?= e(APP_NAME) ?></a>
  </div>
  <ul class="nav-links">
    <li><a href="<?= BASE_URL ?>/dashboard.php">Dashboard</a></li>
    <?php if (isManager()): ?>
    <li><a href="<?= BASE_URL ?>/admin/firms.php">Firms</a></li>
    <li><a href="<?= BASE_URL ?>/feeds/index.php">RSS Feeds</a></li>
    <li><a href="<?= BASE_URL ?>/feeds/cache.php?status=unpushed">Moderation Queue</a></li>
    <li><a href="<?= BASE_URL ?>/feeds/crawl.php">Back-fill Crawl</a></li>
    <li><a href="<?= BASE_URL ?>/ghost/push.php">Ghost CMS</a></li>
    <li><a href="<?= BASE_URL ?>/admin/authors.php">Authors</a></li>
    <?php endif; ?>
    <?php if (isAdmin()): ?>
    <li><a href="<?= BASE_URL ?>/admin/users.php">Users</a></li>
    <li><a href="<?= BASE_URL ?>/admin/settings.php">Settings</a></li>
    <li><a href="<?= BASE_URL ?>/admin/2fa_log.php">2FA Log</a></li>
    <?php endif; ?>
    <?php if (isSubscriber()): ?>
    <li><a href="<?= BASE_URL ?>/subscriber/firm.php">Firm Profile</a></li>
    <li><a href="<?= BASE_URL ?>/firms/profile.php">View Profile</a></li>
    <li><a href="<?= BASE_URL ?>/subscriber/articles.php">My Articles</a></li>
    <li><a href="<?= BASE_URL ?>/subscriber/authors.php">Authors</a></li>
    <?php endif; ?>
  </ul>
  <div class="nav-user">
    <span class="role-badge role-<?= e($role) ?>"><?= e(ucfirst($role)) ?></span>
    <span><?= e($u['full_name'] ?? $u['username'] ?? '') ?></span>
    <a href="<?= BASE_URL ?>/logout.php" class="btn-logout">Sign Out</a>
  </div>
</nav>
<main class="main-wrap">
<?php if ($flash): ?>
<div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
<?php endif; ?>
