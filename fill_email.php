<?php
// fill_email.php

require_once __DIR__ . '/includes/session_boot.php';
require_once __DIR__ . '/includes/security.php';
sendSecurityHeaders();
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/config/database.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT email, login, institute_id, department_id FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) { header('Location: logout.php'); exit; }

if (!empty($user['email'])) {
    unset($_SESSION['need_email']);
    if (empty($user['institute_id']) || empty($user['department_id'])) {
        $_SESSION['need_department'] = true;
        header('Location: fill_department.php');
        exit;
    }
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        $error = 'Email обязателен';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Некорректный email';
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $userId]);
        if ($stmt->fetch()) {
            $error = 'Этот email уже используется';
        } else {
            $pdo->prepare("UPDATE users SET email = ? WHERE id = ?")->execute([$email, $userId]);
            unset($_SESSION['need_email']);

            $stmt = $pdo->prepare("SELECT institute_id, department_id FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $u = $stmt->fetch();

            if (empty($u['institute_id']) || empty($u['department_id'])) {
                $_SESSION['need_department'] = true;
                header('Location: fill_department.php');
                exit;
            }
            header('Location: dashboard.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Заполните email — АИС-расписание.РФ</title>
    <link rel="icon" type="image/svg+xml" href="assets/img/logo.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<style>
html, body { margin: 0; padding: 0; min-height: 100vh; background: #f5f5f7; font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", "Segoe UI", Roboto, sans-serif; }
.fill-page { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 40px 20px; background: linear-gradient(180deg, #fff 0%, #f5f5f7 100%); }
.fill-container { width: 100%; max-width: 460px; }
.fill-logo { width: 80px; height: 80px; margin: 0 auto 24px; animation: logoFloat 5s ease-in-out infinite; }
.fill-logo img { width: 100%; height: 100%; object-fit: contain; }
@keyframes logoFloat { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-10px); } }
.fill-title { font-size: 1.7rem; font-weight: 700; text-align: center; margin: 0 0 8px; }
.fill-subtitle { text-align: center; color: #86868b; font-size: 0.95rem; margin: 0 0 32px; }
.fill-card { background: #fff; border-radius: 24px; padding: 32px; box-shadow: 0 4px 12px rgba(0,0,0,0.04), 0 30px 80px rgba(0,0,0,0.1); border: 1px solid #e5e5ea; }
.form-label { font-size: 0.85rem; font-weight: 600; margin-bottom: 6px; display: block; }
.form-control { padding: 14px 18px; border-radius: 12px; border: 1.5px solid #e5e5ea; background: #fafafc; font-size: 1rem; font-family: inherit; width: 100%; }
.form-control:focus { border-color: #0071e3; background: #fff; box-shadow: 0 0 0 4px rgba(0,113,227,0.12); outline: none; }
.form-control:disabled { background: #f5f5f7; color: #86868b; }
.btn-primary-gradient { width: 100%; padding: 15px; border: none; border-radius: 12px; background: linear-gradient(135deg, #0071e3 0%, #5e5ce6 100%); color: #fff; font-size: 1rem; font-weight: 600; font-family: inherit; cursor: pointer; transition: all 0.25s; display: inline-flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 8px 20px rgba(0,113,227,0.25); margin-top: 8px; }
.btn-primary-gradient:hover { transform: translateY(-2px); box-shadow: 0 14px 30px rgba(0,113,227,0.4); }
.alert { border-radius: 12px; border: none; padding: 12px 16px; font-size: 0.9rem; display: flex; align-items: center; gap: 10px; margin-bottom: 16px; }
.fill-info { background: rgba(0,113,227,0.06); border: 1px solid rgba(0,113,227,0.15); border-radius: 12px; padding: 12px 16px; font-size: 0.82rem; color: #084298; margin-bottom: 20px; display: flex; gap: 10px; }
.fill-footer { text-align: center; margin-top: 24px; font-size: 0.82rem; color: #86868b; }
.fill-footer a { color: #86868b; text-decoration: none; }
</style>
</head>
<body>

<div class="fill-page">
    <div class="fill-container">
        <div class="fill-logo"><img src="assets/img/logo.svg" alt="АИС-расписание.РФ"></div>
        <h1 class="fill-title">Требуется email</h1>
        <p class="fill-subtitle">Нужен для восстановления пароля</p>

        <div class="fill-card">
            <?php if ($error): ?>
                <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill fs-5"></i><div><?= htmlspecialchars($error) ?></div></div>
            <?php endif; ?>

            <div class="fill-info"><i class="bi bi-info-circle-fill"></i><div>Укажите email — на него придёт ссылка для восстановления доступа, если забудете пароль.</div></div>

            <form method="post" autocomplete="off">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label">Логин</label>
                    <input type="text" class="form-control" disabled value="<?= htmlspecialchars($user['login']) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Email <span style="color:#ff453a;">*</span></label>
                    <input type="email" name="email" class="form-control" required autofocus value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="ivanov@kuzstu.ru">
                </div>
                <button type="submit" class="btn-primary-gradient"><i class="bi bi-check-circle-fill"></i> Сохранить и продолжить</button>
            </form>

            <div class="fill-footer"><a href="logout.php"><i class="bi bi-box-arrow-right"></i> Выйти</a></div>
        </div>
    </div>
</div>

</body>
</html>