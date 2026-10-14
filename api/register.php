<?php
// api/register.php
// Регистрация преподавателя

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/session_boot.php';
require_once __DIR__ . '/../includes/security.php';
sendSecurityHeaders();
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Только POST']);
    exit;
}

// Читаем JSON
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    $data = $_POST;
}

// CSRF
$csrfRequest = $data['csrf'] ?? '';
$csrfSession = $_SESSION['csrf_token'] ?? '';
if ($csrfSession === '' || !hash_equals($csrfSession, (string)$csrfRequest)) {
    echo json_encode(['success' => false, 'error' => 'CSRF-токен недействителен. Обновите страницу.']);
    exit;
}

try {
    $teacherId  = trim($data['teacher_id'] ?? '');
    $fullName   = trim($data['full_name'] ?? '');
    $lastName   = trim($data['last_name'] ?? '');
    $firstName  = trim($data['first_name'] ?? '');
    $middleName = trim($data['middle_name'] ?? '');

    $instituteId    = trim($data['institute_id'] ?? '');
    $instituteName  = trim($data['institute_name'] ?? '');
    $departmentId   = trim($data['department_id'] ?? '');
    $departmentName = trim($data['department_name'] ?? '');

    $login     = trim($data['login'] ?? '');
    $email     = trim($data['email'] ?? '');
    $password  = $data['password'] ?? '';
    $passwordC = $data['password_confirm'] ?? '';

    if ($fullName === '') { echo json_encode(['success' => false, 'error' => 'Не выбран преподаватель']); exit; }
    if ($login === '' || $password === '') { echo json_encode(['success' => false, 'error' => 'Заполните логин и пароль']); exit; }
    if (!preg_match('/^[a-zA-Z0-9_.\-]{4,}$/', $login)) {
        echo json_encode(['success' => false, 'error' => 'Некорректный логин (латиница, цифры, _ . -, мин. 4)']); exit;
    }
    if (mb_strlen($password) < 6) { echo json_encode(['success' => false, 'error' => 'Пароль минимум 6 символов']); exit; }
    if ($password !== $passwordC) { echo json_encode(['success' => false, 'error' => 'Пароли не совпадают']); exit; }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Введите корректный email']); exit;
    }
    if ($instituteId === '' || $departmentId === '') {
        echo json_encode(['success' => false, 'error' => 'Выберите институт и кафедру']); exit;
    }

    // Уникальность
    $stmt = $pdo->prepare("SELECT id FROM users WHERE login = ?");
    $stmt->execute([$login]);
    if ($stmt->fetch()) { echo json_encode(['success' => false, 'error' => 'Логин уже занят']); exit; }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) { echo json_encode(['success' => false, 'error' => 'Email уже используется']); exit; }

    // Сохранение
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("
        INSERT INTO users
            (login, password_hash, full_name, last_name, first_name, middle_name,
             email, department, institute_id, institute_name,
             department_id, department_name,
             api_teacher_id, post, role, is_active, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'teacher', 1, NOW())
    ");
    $stmt->execute([
        $login,
        $hash,
        $fullName,
        $lastName ?: null,
        $firstName ?: null,
        $middleName ?: null,
        $email,
        $departmentName ?: null,
        $instituteId ?: null,
        $instituteName ?: null,
        $departmentId ?: null,
        $departmentName ?: null,
        $teacherId ?: null,
        null,
    ]);

    // Автовход
    $newId = (int)$pdo->lastInsertId();

    regenerateSession();
    $_SESSION['user_id']    = $newId;
    $_SESSION['user_type']  = 'teacher';
    $_SESSION['user_name']  = $fullName;
    $_SESSION['user_login'] = $login;
    $_SESSION['role']       = 'teacher';

    unset(
        $_SESSION['_profile_checked'],
        $_SESSION['_has_email'],
        $_SESSION['_has_department']
    );

    echo json_encode([
        'success'  => true,
        'redirect' => 'dashboard.php',
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('api/register error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error'   => 'Ошибка сервера. Попробуйте позже.',
    ], JSON_UNESCAPED_UNICODE);
}