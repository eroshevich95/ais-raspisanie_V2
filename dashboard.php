<?php
// dashboard.php — временная заглушка

$pageTitle = 'Главная';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/header.php';
?>

<div style="max-width:720px; margin:60px auto; text-align:center; padding:40px; background:#fff; border-radius:24px; box-shadow:0 8px 32px rgba(0,0,0,0.06);">
    <div style="width:80px;height:80px;margin:0 auto 24px;border-radius:20px;background:linear-gradient(135deg,#30d158 0%,#0071e3 100%);display:flex;align-items:center;justify-content:center;font-size:2.2rem;color:#fff;">
        <i class="bi bi-check-circle-fill"></i>
    </div>
    <h1 style="font-size:1.7rem; font-weight:700; letter-spacing:-0.02em; margin:0 0 12px;">
        Система работает
    </h1>
    <p style="color:#86868b; margin:0 0 24px; font-size:1rem; line-height:1.5;">
        Вход выполнен. Каркас проекта собран корректно.<br>
        Дальше подключим остальные страницы.
    </p>
    <div style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap;">
        <a href="logout.php" style="display:inline-flex;align-items:center;gap:8px;padding:12px 24px;border-radius:12px;background:#f5f5f7;color:#495057;text-decoration:none;font-weight:600;border:1.5px solid #e5e5ea;">
            <i class="bi bi-box-arrow-right"></i> Выйти
        </a>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>