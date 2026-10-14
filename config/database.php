<?php
// config/database.php

if (!function_exists('loadEnv')) {
    function loadEnv(string $path): void {
        if (!is_file($path) || !is_readable($path)) return;
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) return;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (strpos($line, '=') === false) continue;
            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);
            if (strlen($value) >= 2) {
                $first = $value[0]; $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }
            if ($key !== '' && getenv($key) === false) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }
    }
}

if (!function_exists('env')) {
    function env(string $key, $default = null) {
        $v = getenv($key);
        if ($v === false) return $default;
        if ($v === 'true')  return true;
        if ($v === 'false') return false;
        if ($v === 'null')  return null;
        return $v;
    }
}

loadEnv(__DIR__ . '/../.env');

$appEnv = env('APP_ENV', 'production');
if ($appEnv === 'development') {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
}

date_default_timezone_set('Asia/Krasnoyarsk');

define('DB_HOST',    env('DB_HOST', 'localhost'));
define('DB_NAME',    env('DB_NAME', ''));
define('DB_USER',    env('DB_USER', ''));
define('DB_PASS',    env('DB_PASS', ''));
define('DB_CHARSET', 'utf8mb4');
define('KUZSTU_API_URL', env('KUZSTU_API_URL', 'https://portal.kuzstu.ru/api'));
define('APP_NAME', env('APP_NAME', 'АИС-расписание.РФ'));

define('LESSON_TIMES', [
    1 => ['start' => '09:00', 'end' => '10:35'],
    2 => ['start' => '10:50', 'end' => '12:25'],
    3 => ['start' => '13:20', 'end' => '14:55'],
    4 => ['start' => '15:10', 'end' => '16:45'],
    5 => ['start' => '17:00', 'end' => '18:35'],
    6 => ['start' => '18:50', 'end' => '20:20'],
]);

function getLessonStart(int $number): ?string { return LESSON_TIMES[$number]['start'] ?? null; }
function getLessonEnd(int $number): ?string   { return LESSON_TIMES[$number]['end'] ?? null; }
function getLessonNumberByStart(string $time): ?int {
    $time = substr($time, 0, 5);
    foreach (LESSON_TIMES as $num => $t) if ($t['start'] === $time) return $num;
    return null;
}
function formatLessonTime(?string $start, ?int $number = null): string {
    if (!$start) return '—';
    $start = substr($start, 0, 5);
    if ($number && isset(LESSON_TIMES[$number])) return $start . '–' . LESSON_TIMES[$number]['end'];
    return $start;
}
function getCurrentLessonNumber(): ?int {
    $now = date('H:i');
    foreach (LESSON_TIMES as $num => $t) if ($now >= $t['start'] && $now <= $t['end']) return $num;
    return null;
}
function getNextLessonNumber(): ?int {
    $now = date('H:i');
    foreach (LESSON_TIMES as $num => $t) if ($now < $t['start']) return $num;
    return null;
}

function startSessionIfNeeded(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
}
function setFlash(string $type, string $message): void {
    startSessionIfNeeded();
    $_SESSION['flash_' . $type] = $message;
}
function getFlash(string $type): ?string {
    startSessionIfNeeded();
    $msg = $_SESSION['flash_' . $type] ?? null;
    unset($_SESSION['flash_' . $type]);
    return $msg;
}
function redirectWith(string $url, string $type, string $message): void {
    setFlash($type, $message);
    header('Location: ' . $url);
    exit;
}
function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    $pdo->exec("SET time_zone = '+07:00'");
} catch (PDOException $e) {
    error_log('DB connection error: ' . $e->getMessage());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "DB connection failed. See error_log.\n");
        exit(1);
    }
    http_response_code(500);
    die('Сервис временно недоступен. Попробуйте позже.');
}

function ruDayShort($date): string {
    $days = ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'];
    return $days[(int)date('w', strtotime($date))];
}
function ruDayFull($date): string {
    $days = ['Воскресенье', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота'];
    return $days[(int)date('w', strtotime($date))];
}