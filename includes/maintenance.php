<?php
// includes/maintenance.php

if (!function_exists('checkMaintenanceMode')) {
    function checkMaintenanceMode(PDO $pdo): void {
        if (PHP_SAPI === 'cli') return;

        require_once __DIR__ . '/settings.php';

        if (!isMaintenanceEnabled($pdo)) return;

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        if (($_SESSION['role'] ?? '') === 'admin') return;

        $current = basename($_SERVER['PHP_SELF'] ?? '');
        $whitelist = [
            'maintenance.php',
            'login.php',
            'logout.php',
            'reset_password.php',
            'forgot_password.php',
        ];
        if (in_array($current, $whitelist, true)) return;

        $scriptDir = basename(dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($scriptDir === 'admin') return;

        $basePath = (strpos($_SERVER['PHP_SELF'], '/admin/') !== false) ? '../' : '';
        header('Location: ' . $basePath . 'maintenance.php', true, 302);
        header('Retry-After: 3600');
        exit;
    }
}