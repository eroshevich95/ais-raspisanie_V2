<?php
// includes/settings.php

if (!function_exists('getSetting')) {
    function getSetting(PDO $pdo, string $key, $default = null) {
        static $cache = [];
        if (array_key_exists($key, $cache)) return $cache[$key];

        try {
            $stmt = $pdo->prepare("SELECT value FROM site_settings WHERE `key` = ?");
            $stmt->execute([$key]);
            $row = $stmt->fetchColumn();
            $cache[$key] = $row === false ? $default : $row;
        } catch (Throwable $e) {
            $cache[$key] = $default;
        }
        return $cache[$key];
    }
}

if (!function_exists('setSetting')) {
    function setSetting(PDO $pdo, string $key, $value): void {
        try {
            $pdo->prepare("
                INSERT INTO site_settings (`key`, `value`, `updated_at`)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated_at` = NOW()
            ")->execute([$key, $value]);
        } catch (Throwable $e) {
            error_log('setSetting error: ' . $e->getMessage());
        }
    }
}

if (!function_exists('isMaintenanceEnabled')) {
    function isMaintenanceEnabled(PDO $pdo): bool {
        return (string)getSetting($pdo, 'maintenance_enabled', '0') === '1';
    }
}