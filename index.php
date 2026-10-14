<?php
// login.php

require_once __DIR__ . '/includes/session_boot.php';
require_once __DIR__ . '/includes/security.php';
sendSecurityHeaders();
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/rate_limit.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/audit.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $login    = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';
    $ip       = clientIp();

    if (random_int(1, 100) === 1) {
        cleanupOldAttempts($pdo);
    }

    if (empty($login) || empty($password)) {
        $error = 'Заполните все поля';
    } elseif (isRateLimited($pdo, 'login', 'ip', $ip, 5, 15)) {
        $error = 'Слишком много попыток входа. Подождите 15 минут.';
        auditLog($pdo, 'login.rate_limited', [
            'description' => 'Превышен лимит попыток входа с IP ' . $ip,
        ]);
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT id, login, password_hash, full_name, is_active, email, role,
                       institute_id, department_id
                FROM users WHERE login = ?
            ");
            $stmt->execute([$login]);
            $u = $stmt->fetch();

            if (!$u) {
                recordFailedAttempt($pdo, 'login', 'ip', $ip);
                $error = 'Неверный логин или пароль';
                auditLog($pdo, 'login.failed', [
                    'description' => 'Логин не найден: ' . mb_substr($login, 0, 100),
                ]);
            } elseif (!$u['is_active']) {
                recordFailedAttempt($pdo, 'login', 'ip', $ip);
                $error = 'Аккаунт заблокирован';
                auditLog($pdo, 'login.failed', [
                    'user_id'     => (int)$u['id'],
                    'user_name'   => $u['full_name'],
                    'description' => 'Попытка входа в заблокированный аккаунт',
                ]);
            } elseif (!password_verify($password, $u['password_hash'])) {
                recordFailedAttempt($pdo, 'login', 'ip', $ip);
                $error = 'Неверный логин или пароль';
                auditLog($pdo, 'login.failed', [
                    'user_id'     => (int)$u['id'],
                    'user_name'   => $u['full_name'],
                    'description' => 'Неудачная попытка входа: неверный пароль',
                ]);
            } else {
                clearAttempts($pdo, 'login', 'ip', $ip);
                regenerateSession();

                $_SESSION['user_id']    = $u['id'];
                $_SESSION['user_type']  = 'teacher';
                $_SESSION['user_name']  = $u['full_name'];
                $_SESSION['user_login'] = $u['login'];
                $_SESSION['role']       = $u['role'] ?? 'teacher';

                unset(
                    $_SESSION['_profile_checked'],
                    $_SESSION['_has_email'],
                    $_SESSION['_has_department'],
                    $_SESSION['_unmarked_cache_time'],
                    $_SESSION['_unmarked_count'],
                    $_SESSION['_unmarked_list']
                );

                $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")
                    ->execute([$u['id']]);

                auditLog($pdo, 'login.success', [
                    'description' => 'Вход в систему',
                ]);

                if (empty($u['email'])) {
                    $_SESSION['need_email'] = true;
                    header('Location: fill_email.php');
                    exit;
                }
                if (empty($u['institute_id']) || empty($u['department_id'])) {
                    $_SESSION['need_department'] = true;
                    header('Location: fill_department.php');
                    exit;
                }

                header('Location: dashboard.php');
                exit;
            }
        } catch (PDOException $e) {
            error_log('login DB error: ' . $e->getMessage());
            $error = 'Ошибка сервера. Попробуйте позже.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Вход — АИС-расписание.РФ</title>
    <link rel="icon" type="image/svg+xml" href="assets/img/logo.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root {
    --ap-bg: #f5f5f7; --ap-card: #ffffff; --ap-dark: #1d1d1f;
    --ap-gray: #86868b; --ap-border: #e5e5ea;
    --ap-accent: #0071e3; --ap-accent-hover: #0077ed;
    --ap-glow-1: rgba(0,113,227,0.08); --ap-glow-2: rgba(191,90,242,0.08);
}
html, body {
    margin: 0; padding: 0; min-height: 100vh;
    background: var(--ap-bg); color: var(--ap-dark);
    font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", "Segoe UI", Roboto, sans-serif;
}
.login-page {
    min-height: 100vh; display: flex; align-items: center; justify-content: center;
    padding: 40px 20px; position: relative; overflow: hidden;
    background: linear-gradient(180deg, #fff 0%, #f5f5f7 100%);
}
.login-page::before {
    content: ''; position: absolute; inset: -50%;
    background:
        radial-gradient(circle at 20% 30%, var(--ap-glow-1) 0%, transparent 40%),
        radial-gradient(circle at 80% 70%, var(--ap-glow-2) 0%, transparent 40%);
    animation: auroraMove 20s ease-in-out infinite;
    pointer-events: none; z-index: 0;
}
@keyframes auroraMove {
    0%,100% { transform: translate(0,0) scale(1); }
    33%     { transform: translate(5%,-5%) scale(1.1); }
    66%     { transform: translate(-5%,5%) scale(0.95); }
}
.login-container {
    width: 100%; max-width: 460px; position: relative; z-index: 1;
    animation: fadeUpIn 0.7s cubic-bezier(0.16,1,0.3,1);
}
@keyframes fadeUpIn { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
.login-logo {
    width: 88px; height: 88px; margin: 0 auto 24px;
    animation: logoFloat 5s ease-in-out infinite;
    filter: drop-shadow(0 20px 40px rgba(0,113,227,0.25));
}
.login-logo img { width: 100%; height: 100%; object-fit: contain; }
@keyframes logoFloat {
    0%, 100% { transform: translateY(0) scale(1); }
    50%      { transform: translateY(-10px) scale(1.02); }
}
.login-title { font-size: 1.9rem; font-weight: 700; letter-spacing: -0.02em; text-align: center; margin: 0 0 8px; }
.login-subtitle { text-align: center; color: var(--ap-gray); font-size: 0.95rem; margin: 0 0 32px; }
.login-card {
    background: var(--ap-card); border-radius: 24px; padding: 32px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.04), 0 30px 80px rgba(0,0,0,0.1);
    border: 1px solid var(--ap-border);
}
.form-label { font-size: 0.85rem; font-weight: 600; color: var(--ap-dark); margin-bottom: 6px; }
.form-control {
    padding: 14px 18px; border-radius: 12px; border: 1.5px solid var(--ap-border);
    background: #fafafc; font-size: 1rem; font-family: inherit; transition: all 0.2s;
}
.form-control:focus {
    border-color: var(--ap-accent); background: #fff;
    box-shadow: 0 0 0 4px rgba(0,113,227,0.12); outline: none;
}
.btn-login {
    width: 100%; padding: 15px; border: none; border-radius: 12px;
    background: linear-gradient(135deg, #0071e3 0%, #5e5ce6 100%);
    color: #fff; font-size: 1rem; font-weight: 600; font-family: inherit;
    cursor: pointer; transition: all 0.25s cubic-bezier(0.16,1,0.3,1);
    display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    box-shadow: 0 8px 20px rgba(0,113,227,0.25);
}
.btn-login:hover { transform: translateY(-2px); box-shadow: 0 14px 30px rgba(0,113,227,0.4); }
.alert {
    border-radius: 12px; border: none; padding: 12px 16px; font-size: 0.9rem;
    display: flex; align-items: center; gap: 10px;
    animation: shakeIn 0.4s ease;
}
@keyframes shakeIn {
    0% { transform: translateX(-8px); } 25% { transform: translateX(8px); }
    50% { transform: translateX(-4px); } 75% { transform: translateX(4px); }
    100% { transform: translateX(0); }
}
.login-links { text-align: center; margin-top: 24px; font-size: 0.88rem; color: var(--ap-gray); }
.login-links a { color: var(--ap-accent); text-decoration: none; font-weight: 500; transition: color 0.15s; }
.login-links a:hover { color: var(--ap-accent-hover); text-decoration: underline; }
.login-links .dot { margin: 0 10px; opacity: 0.5; }
.login-footer { text-align: center; margin-top: 32px; font-size: 0.8rem; color: var(--ap-gray); }
.login-footer a { color: var(--ap-gray); text-decoration: none; }
.login-footer a:hover { color: var(--ap-accent); }
.login-footer .dot { margin: 0 8px; opacity: 0.5; }
</style>
</head>
<body>

<div class="login-page">
    <div class="login-container">

        <div class="login-logo">
            <img src="assets/img/logo.svg" alt="АИС-расписание.РФ">
        </div>

        <h1 class="login-title">Вход в систему</h1>
        <p class="login-subtitle">АИС-расписание.РФ — учёт посещаемости</p>

        <div class="login-card">

            <?php if ($error): ?>
                <div class="alert alert-danger mb-3">
                    <i class="bi bi-exclamation-triangle-fill fs-5"></i>
                    <div><?= htmlspecialchars($error) ?></div>
                </div>
            <?php endif; ?>

            <?php if (!empty($_GET['reset']) && $_GET['reset'] === 'success'): ?>
                <div class="alert alert-success mb-3">
                    <i class="bi bi-check-circle-fill fs-5"></i>
                    <div>Пароль успешно изменён. Войдите с новым паролем.</div>
                </div>
            <?php endif; ?>

            <?php if (!empty($_GET['reset']) && $_GET['reset'] === 'used'): ?>
                <div class="alert alert-warning mb-3">
                    <i class="bi bi-info-circle-fill fs-5"></i>
                    <div>Ссылка уже была использована.</div>
                </div>
            <?php endif; ?>

            <form method="post" autocomplete="off">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label">Логин</label>
                    <input type="text" name="login" class="form-control" required autofocus
                           value="<?= htmlspecialchars($_POST['login'] ?? '') ?>"
                           placeholder="Ваш логин">
                </div>
                <div class="mb-3">
                    <label class="form-label">Пароль</label>
                    <input type="password" name="password" class="form-control" required
                           placeholder="Ваш пароль">
                </div>
                <button type="submit" class="btn-login">
                    <i class="bi bi-box-arrow-in-right"></i>
                    Войти
                </button>
            </form>

            <div class="login-links">
                <a href="forgot_password.php">
                    <i class="bi bi-key"></i> Забыли пароль?
                </a>
                <span class="dot">·</span>
                <a href="register.php">
                    <i class="bi bi-person-plus"></i> Регистрация преподавателя
                </a>
            </div>

        </div>

        <div class="login-footer">
            <a href="presentation.php">
                <i class="bi bi-stars"></i> О системе
            </a>
            <span class="dot">·</span>
            <a href="https://portal.kuzstu.ru" target="_blank" rel="noopener">
                <i class="bi bi-globe"></i> portal.kuzstu.ru
            </a>
        </div>

    </div>
</div>

</body>
</html>