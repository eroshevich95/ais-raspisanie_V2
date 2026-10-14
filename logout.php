<?php
// logout.php

require_once __DIR__ . '/includes/session_boot.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/audit.php';

if (!empty($_SESSION['user_id'])) {
    $userId   = (int)$_SESSION['user_id'];
    $userName = $_SESSION['user_name'] ?? null;

    try {
        auditLog($pdo, 'logout', [
            'user_id'     => $userId,
            'user_name'   => $userName,
            'description' => 'Выход из системы',
        ]);
    } catch (Throwable $e) {
        error_log('logout audit error: ' . $e->getMessage());
    }
}

$_SESSION = [];

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), '',
        time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();
header('Location: login.php');
exit;