<?php
// includes/rate_limit.php

if (!function_exists('isRateLimited')) {
    function isRateLimited(PDO $pdo, string $action, string $keyType, string $keyValue, int $max = 5, int $windowMin = 15): bool {
        try {
            $stmt = $pdo->prepare("
                SELECT attempts, last_attempt
                FROM login_attempts
                WHERE action = ? AND key_type = ? AND key_value = ?
            ");
            $stmt->execute([$action, $keyType, $keyValue]);
            $row = $stmt->fetch();

            if (!$row) return false;

            $lastTs = strtotime($row['last_attempt']);
            if (time() - $lastTs > $windowMin * 60) return false;

            return (int)$row['attempts'] >= $max;
        } catch (Throwable $e) {
            error_log('isRateLimited error: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('recordFailedAttempt')) {
    function recordFailedAttempt(PDO $pdo, string $action, string $keyType, string $keyValue): void {
        try {
            $pdo->prepare("
                INSERT INTO login_attempts (action, key_type, key_value, attempts, last_attempt)
                VALUES (?, ?, ?, 1, NOW())
                ON DUPLICATE KEY UPDATE
                    attempts = IF(TIMESTAMPDIFF(MINUTE, last_attempt, NOW()) > 15, 1, attempts + 1),
                    last_attempt = NOW()
            ")->execute([$action, $keyType, $keyValue]);
        } catch (Throwable $e) {
            error_log('recordFailedAttempt error: ' . $e->getMessage());
        }
    }
}

if (!function_exists('clearAttempts')) {
    function clearAttempts(PDO $pdo, string $action, string $keyType, string $keyValue): void {
        try {
            $pdo->prepare("
                DELETE FROM login_attempts
                WHERE action = ? AND key_type = ? AND key_value = ?
            ")->execute([$action, $keyType, $keyValue]);
        } catch (Throwable $e) {
            error_log('clearAttempts error: ' . $e->getMessage());
        }
    }
}

if (!function_exists('cleanupOldAttempts')) {
    function cleanupOldAttempts(PDO $pdo): void {
        try {
            $pdo->prepare("
                DELETE FROM login_attempts
                WHERE last_attempt < DATE_SUB(NOW(), INTERVAL 1 DAY)
            ")->execute();
        } catch (Throwable $e) {
            error_log('cleanupOldAttempts error: ' . $e->getMessage());
        }
    }
}