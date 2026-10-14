<?php
// reset_password.php

require_once __DIR__ . '/includes/session_boot.php';
require_once __DIR__ . '/config/database.php';

$token = trim($_GET['token'] ?? '');
$error = '';

if (!$token) {
    $error = 'Ссылка недействительна';
} else {
    $stmt = $pdo->prepare("SELECT * FROM password_reset_requests WHERE token = ? AND status = 'approved'");
    $stmt->execute([$token]);
    $request = $stmt->fetch();

    if (!$request) {
        $stmt = $pdo->prepare("SELECT status FROM password_reset_requests WHERE token = ?");
        $stmt->execute([$token]);
        $existing = $stmt->fetch();

        if ($existing && $existing['status'] === 'used') {
            header('Location: login.php?reset=used');
            exit;
        }
        $error = 'Ссылка недействительна или истекла';
    } else {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
                ->execute([$request['new_password_hash'], $request['user_id']]);

            $pdo->prepare("UPDATE password_reset_requests SET status = 'used', used_at = NOW() WHERE id = ?")
                ->execute([$request['id']]);

            $pdo->commit();
            header('Location: login.php?reset=success');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('reset_password error: ' . $e->getMessage());
            $error = 'Ошибка сервера';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Смена пароля</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card shadow-sm" style="border-radius:20px; border:none;">
                <div class="card-body p-4 text-center">
                    <?php if ($error): ?>
                        <i class="bi bi-x-circle text-danger" style="font-size:3rem;"></i>
                        <h4 class="mt-2">Ошибка</h4>
                        <p class="text-muted"><?= htmlspecialchars($error) ?></p>
                        <a href="login.php" class="btn btn-primary">Ко входу</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>