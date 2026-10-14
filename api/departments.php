<?php
// api/departments.php
// Прокси к API портала КузГТУ: институты и кафедры

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/session_boot.php';
require_once __DIR__ . '/../includes/security.php';
sendSecurityHeaders();

// Только для авторизованных или регистрирующихся
// (проверка не нужна — эти данные публичные)

$type        = $_GET['type'] ?? 'institutes';
$instituteId = trim($_GET['institute'] ?? '');

$baseUrl = 'https://portal.kuzstu.ru/api';

if ($type === 'institutes') {
    $url = $baseUrl . '/institutes';
} elseif ($type === 'departments') {
    if ($instituteId === '') {
        echo json_encode(['error' => 'Не указан институт']);
        exit;
    }
    $url = $baseUrl . '/departments?' . http_build_query(['institute' => $instituteId]);
} else {
    echo json_encode(['error' => 'Неизвестный тип']);
    exit;
}

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 6,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_HTTPHEADER     => [
        'Accept: application/json',
        'Referer: https://portal.kuzstu.ru/',
    ],
    CURLOPT_USERAGENT      => 'AIS-Raspisanie/1.0',
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$response) {
    echo json_encode(['error' => 'API портала недоступен'], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode($response, true);
if (!is_array($data)) {
    echo json_encode(['error' => 'Некорректный ответ API'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode($data, JSON_UNESCAPED_UNICODE);