<?php
// includes/security.php

if (!function_exists('sendSecurityHeaders')) {
    function sendSecurityHeaders(): void {
        if (headers_sent()) return;

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

        $csp = "default-src 'self'; "
             . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
             . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; "
             . "font-src 'self' data: https://cdn.jsdelivr.net https://fonts.gstatic.com; "
             . "img-src 'self' data: https:; "
             . "connect-src 'self' https://portal.kuzstu.ru; "
             . "frame-ancestors 'self'; "
             . "base-uri 'self'; "
             . "form-action 'self'";
        header('Content-Security-Policy: ' . $csp);
    }
}

if (!function_exists('clientIp')) {
    function clientIp(): string {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($parts[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

if (!function_exists('jsonError')) {
    function jsonError(string $logMessage, string $userMessage = 'Ошибка сервера', int $httpCode = 500): void {
        error_log($logMessage);
        if (!headers_sent()) {
            http_response_code($httpCode);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'error' => $userMessage], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('safeErrorMessage')) {
    function safeErrorMessage(Throwable $e, string $context = ''): string {
        $appEnv = getenv('APP_ENV') ?: 'production';
        $logLine = ($context !== '' ? "[$context] " : '')
                 . $e->getMessage()
                 . ' in ' . $e->getFile() . ':' . $e->getLine();
        error_log($logLine);
        if ($appEnv === 'development') return $e->getMessage();
        return 'Ошибка сервера. Попробуйте позже.';
    }
}