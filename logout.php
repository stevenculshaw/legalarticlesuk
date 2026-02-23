<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
if (isset($_SESSION['user_id'])) {
    logActivity('logout', 'User logged out');
}
session_destroy();
header('Location: ' . BASE_URL . '/index.php');
exit;
