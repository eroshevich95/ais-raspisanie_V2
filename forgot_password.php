<?php
// forgot_password.php

require_once __DIR__ . '/includes/session_boot.php';
require_once __DIR__ . '/includes/security.php';
sendSecurityHeaders();
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/rate_limit.php';
require_once __DIR__ . '/config/database.php';

$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $ip = clientIp();
    if (isRateLimited($pdo, 'forgot_password', 'ip', $ip, 3, 30)) {
        $error = 'Слишком много запросов. Подождите 30 минут.';
    } else {
        $login       = trim($_POST['login'] ?? '');
        $email       = trim($_POST['email'] ?? '');
        $newPassword = $_POST['new_password'] ?? '';
        $confirm     = $_POST['confirm_password'] ?? '';

        if (!$login || !$email || !$newPassword) {
            $error = 'Заполните все поля';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Некорректный email';
        } elseif (mb_strlen($newPassword) < 6) {
            $error = 'Пароль минимум 6 символов';
        } elseif ($newPassword !== $confirm) {
            $error = 'Пароли не совпадают';
        } else {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE login = ? AND email = ?");
            $stmt->execute([$login, $email]);
            $user = $stmt->fetch();

            if (!$user) {
                recordFailedAttempt($pdo, 'forgot_password', 'ip', $ip);
                $error = 'Логин и email не совпадают';
            } else {
                $stmt = $pdo->prepare("SELECT id FROM password_reset_requests WHERE user_id = ? AND status = 'pending'");
                $stmt->execute([$user['id']]);
                if ($stmt->fetch()) {
                    $error = 'У вас уже есть активный запрос. Дождитесь обработки администратором.';
                } else {
                    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $pdo->prepare("INSERT INTO password_reset_requests (user_id, login, email, new_password_hash) VALUES (?, ?, ?, ?)")
                        ->execute([$user['id'], $login, $email, $hash]);

                    clearAttempts($pdo, 'forgot_password', 'ip', $ip);
                    $message = 'Запрос отправлен. Администратор проверит его и пришлёт ссылку для смены пароля.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Восстановление пароля — АИС-расписание.РФ</title>
    <link rel="icon" type="image/svg+xml" href="assets/img/logo.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card shadow-sm" style="border-radius:20px; border:none;">
                <div class="card-body p-4">
                    <h3 class="mb-3 text-center"><i class="bi bi-key"></i> Восстановление пароля</h3>

                    <div class="alert alert-info small">
                        <i class="bi bi-info-circle"></i>
                        Укажите логин, email и новый пароль. Администратор проверит данные и вышлет ссылку для подтверждения.
                    </div>

                    <?php if ($message): ?>
                        <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
                    <?php endif; ?>
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <?php if (!$message): ?>
                        <form method="post" autocomplete="off">
                            <?= csrf_field() ?>
                            <div class="mb-3">
                                <label class="form-label">Логин</label>
                                <input type="text" name="login" class="form-control" required value="<?= htmlspecialchars($_POST['login'] ?? '') ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Новый пароль</label>
                                <input type="password" name="new_password" class="form-control" required minlength="6">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Повторите пароль</label>
                                <input type="password" name="confirm_password" class="form-control" required minlength="6">
                            </div>
                            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-send"></i> Отправить запрос</button>
                        </form>
                    <?php endif; ?>

                    <hr class="my-3">
                    <div class="text-center small"><a href="login.php">← Вернуться ко входу</a></div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>