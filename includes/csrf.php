<?php
// includes/csrf.php

require_once __DIR__ . '/session_boot.php';

if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        if (session_status() !== PHP_SESSION_ACTIVE) return '';
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string {
        $t = csrf_token();
        return '<input type="hidden" name="csrf" value="' . htmlspecialchars($t, ENT_QUOTES) . '">';
    }
}

if (!function_exists('csrf_meta')) {
    function csrf_meta(): string {
        $t = csrf_token();
        return '<meta name="csrf-token" content="' . htmlspecialchars($t, ENT_QUOTES) . '">';
    }
}

if (!function_exists('csrf_verify')) {
    function csrf_verify(bool $ajax = false): void {
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        $requestToken = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

        if ($sessionToken === '' || !hash_equals($sessionToken, (string)$requestToken)) {
            if ($ajax) {
                if (!headers_sent()) {
                    http_response_code(419);
                    header('Content-Type: application/json; charset=utf-8');
                }
                echo json_encode([
                    'ok' => false,
                    'error' => 'CSRF-токен недействителен. Обновите страницу.'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            http_response_code(419);
            die('CSRF-токен недействителен. Обновите страницу.');
        }
    }
}