<?php
// includes/header.php

require_once __DIR__ . '/session_boot.php';
require_once __DIR__ . '/security.php';
sendSecurityHeaders();
require_once __DIR__ . '/csrf.php';

if (!isset($pdo)) {
    require_once __DIR__ . '/../config/database.php';
}
require_once __DIR__ . '/maintenance.php';
checkMaintenanceMode($pdo);

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$currentPage = basename($_SERVER['PHP_SELF'], '.php');

$fillPages = ['fill_email', 'fill_department', 'logout', 'login'];
if (!in_array($currentPage, $fillPages)) {
    if (!isset($_SESSION['_profile_checked'])) {
        try {
            $stmt = $pdo->prepare("SELECT email, institute_id, department_id FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $chk = $stmt->fetch();
            if ($chk) {
                $_SESSION['_has_email']      = !empty($chk['email']);
                $_SESSION['_has_department'] = !empty($chk['institute_id']) && !empty($chk['department_id']);
            }
            $_SESSION['_profile_checked'] = true;
        } catch (Throwable $e) {
            error_log('profile check error: ' . $e->getMessage());
            $_SESSION['_profile_checked'] = true;
        }
    }

    if (empty($_SESSION['_has_email'])) {
        $_SESSION['need_email'] = true;
        header('Location: ' . (strpos($_SERVER['PHP_SELF'], '/admin/') !== false ? '../' : '') . 'fill_email.php');
        exit;
    }
    if (empty($_SESSION['_has_department'])) {
        $_SESSION['need_department'] = true;
        header('Location: ' . (strpos($_SERVER['PHP_SELF'], '/admin/') !== false ? '../' : '') . 'fill_department.php');
        exit;
    }
}

$userRole    = $_SESSION['role'] ?? 'teacher';
$canCheck    = in_array($userRole, ['head_of_department', 'administration', 'admin']);
$isAdmin     = $userRole === 'admin';

$nameParts = explode(' ', $_SESSION['user_name'] ?? '');
$shortName = $nameParts[0] ?? '';
if (isset($nameParts[1])) $shortName .= ' ' . mb_substr($nameParts[1], 0, 1) . '.';
if (isset($nameParts[2])) $shortName .= mb_substr($nameParts[2], 0, 1) . '.';

$initials = '';
if (!empty($nameParts[0])) $initials .= mb_strtoupper(mb_substr($nameParts[0], 0, 1));
if (!empty($nameParts[1])) $initials .= mb_strtoupper(mb_substr($nameParts[1], 0, 1));

$userInstitute  = $_SESSION['institute_name']  ?? '';
$userDepartment = $_SESSION['department_name'] ?? $_SESSION['department'] ?? '';

if (($userInstitute === '' || $userDepartment === '') && !empty($_SESSION['user_id'])) {
    try {
        $stmt = $pdo->prepare("SELECT institute_name, department_name, department FROM users WHERE id = ?");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $u = $stmt->fetch();
        if ($u) {
            $userInstitute  = $u['institute_name']  ?? '';
            $userDepartment = $u['department_name'] ?? $u['department'] ?? '';
            $_SESSION['institute_name']  = $userInstitute;
            $_SESSION['department_name'] = $userDepartment;
        }
    } catch (Throwable $e) {
        error_log('user info load error: ' . $e->getMessage());
    }
}

$userSubtitle = '';
if ($userDepartment !== '') {
    $deptShort = $userDepartment;
    if (mb_strlen($deptShort) > 40) $deptShort = mb_substr($deptShort, 0, 38) . '…';
    $userSubtitle = $deptShort;
    if ($userInstitute !== '') {
        $instShort = $userInstitute;
        if (strpos($instShort, '—') !== false) $instShort = trim(explode('—', $instShort)[0]);
        elseif (mb_strlen($instShort) > 20) $instShort = mb_substr($instShort, 0, 18) . '…';
        $userSubtitle .= ' · ' . $instShort;
    }
} elseif ($userInstitute !== '') {
    $userSubtitle = $userInstitute;
}

$roleLabels = [
    'teacher'            => ['Преподаватель', 'info'],
    'head_of_department' => ['Зав. кафедрой', 'warning'],
    'administration'     => ['Администрация', 'success'],
    'admin'              => ['Администратор', 'danger'],
];
$roleLabel = $roleLabels[$userRole] ?? ['Пользователь', 'secondary'];

$basePath = (strpos($_SERVER['PHP_SELF'], '/admin/') !== false) ? '../' : '';

$unmarkedCount = 0;
$unmarkedList  = [];

try {
    require_once __DIR__ . '/notifications.php';
    $unmarkedCount = countUnmarkedLessons($pdo, (int)$_SESSION['user_id'], 14);
    $unmarkedList  = getUnmarkedLessons($pdo, (int)$_SESSION['user_id'], 14, 5);
} catch (Throwable $e) {
    error_log('Unmarked notifications error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ru" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= csrf_meta() ?>
    <title><?= $pageTitle ? $pageTitle . ' — АИС-расписание.РФ' : 'АИС-расписание.РФ' ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= $basePath ?>assets/img/logo.svg?v=2">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= $basePath ?>assets/css/style.css" rel="stylesheet">
    <style>
        .avatar-circle {
            width: 36px; height: 36px; border-radius: 50%;
            background: linear-gradient(135deg, #fff 0%, #e7f1ff 100%);
            color: #0071e3; display: inline-flex; align-items: center; justify-content: center;
            font-size: 0.82rem; font-weight: 800; letter-spacing: -0.02em;
            flex-shrink: 0; box-shadow: 0 2px 8px rgba(0,0,0,0.15); user-select: none;
        }
        .user-card-head { padding: 14px 16px; border-bottom: 1px solid #f0f0f2; min-width: 240px; }
        .user-card-head .uname { font-weight: 700; font-size: 0.92rem; color: #1d1d1f; line-height: 1.25; }
        .user-card-head .udept { font-size: 0.76rem; color: #86868b; margin-top: 4px; line-height: 1.3; word-break: break-word; }
        .user-card-head .urole { display: inline-flex; align-items: center; gap: 4px; margin-top: 8px; }
        .theme-toggle-btn {
            width: 36px; height: 36px; border-radius: 50%;
            background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.2);
            color: #fff; cursor: pointer; display: inline-flex; align-items: center;
            justify-content: center; font-size: 1rem; transition: all 0.2s;
        }
        .theme-toggle-btn:hover { background: rgba(255,255,255,0.28); transform: scale(1.05); }
        .nav-link i { font-size: 1.05rem; }
        @media (min-width: 992px) { .nav-link i { font-size: 1.15rem; } }
        .notif-btn {
            position: relative; width: 36px; height: 36px; border-radius: 50%;
            background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.2);
            color: #fff; cursor: pointer; display: inline-flex; align-items: center;
            justify-content: center; font-size: 1rem; transition: all 0.2s; text-decoration: none;
        }
        .notif-btn:hover { background: rgba(255,255,255,0.28); transform: scale(1.05); color: #fff; }
        .notif-badge {
            position: absolute; top: -2px; right: -2px; min-width: 18px; height: 18px;
            padding: 0 5px; background: #ff453a; color: #fff; font-size: 0.65rem;
            font-weight: 700; border-radius: 10px; display: flex; align-items: center;
            justify-content: center; box-shadow: 0 0 0 2px #0071e3;
            animation: notifPulse 2s ease-in-out infinite;
        }
        @keyframes notifPulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.15); } }
        .notif-dropdown { min-width: 340px; max-width: 400px; padding: 0; border-radius: 16px; box-shadow: 0 20px 60px rgba(0,0,0,0.2); border: 1px solid #f0f0f2; overflow: hidden; }
        .notif-header { padding: 14px 18px; background: linear-gradient(135deg, #fef2f2 0%, #fff 100%); border-bottom: 1px solid #f0f0f2; display: flex; justify-content: space-between; align-items: center; }
        .notif-header-title { font-weight: 700; font-size: 0.92rem; color: #1d1d1f; display: flex; align-items: center; gap: 8px; }
        .notif-header-title i { color: #ff453a; }
        .notif-header-badge { background: #ff453a; color: #fff; font-size: 0.7rem; font-weight: 700; padding: 2px 8px; border-radius: 10px; }
        .notif-list { max-height: 400px; overflow-y: auto; }
        .notif-item { display: flex; align-items: center; gap: 12px; padding: 12px 18px; text-decoration: none; color: inherit; border-bottom: 1px solid #f5f5f7; transition: all 0.15s; }
        .notif-item:last-child { border-bottom: none; }
        .notif-item:hover { background: #fafafc; color: inherit; }
        .notif-item-date { display: inline-flex; flex-direction: column; align-items: center; justify-content: center; min-width: 44px; padding: 4px 8px; border-radius: 10px; background: linear-gradient(135deg, #fef2f2 0%, #fff 100%); border: 1px solid rgba(255,69,58,0.2); color: #842029; font-weight: 700; flex-shrink: 0; }
        .notif-item-date .d { font-size: 0.92rem; line-height: 1; }
        .notif-item-date .m { font-size: 0.6rem; text-transform: uppercase; margin-top: 2px; opacity: 0.7; }
        .notif-item-info { flex: 1; min-width: 0; }
        .notif-item-title { font-size: 0.85rem; font-weight: 600; color: #1d1d1f; margin-bottom: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .notif-item-meta { font-size: 0.72rem; color: #86868b; display: flex; gap: 8px; flex-wrap: wrap; }
        .notif-item-meta i { color: #0071e3; }
        .notif-item-arrow { color: #86868b; flex-shrink: 0; font-size: 0.9rem; }
        .notif-footer { padding: 10px 18px; background: #fafafc; border-top: 1px solid #f0f0f2; text-align: center; }
        .notif-footer a { font-size: 0.82rem; font-weight: 600; color: #0071e3; text-decoration: none; }
        .notif-footer a:hover { text-decoration: underline; }
        .notif-empty { padding: 32px 18px; text-align: center; color: #86868b; font-size: 0.85rem; }
        .notif-empty i { font-size: 2.2rem; color: #30d158; opacity: 0.5; display: block; margin-bottom: 8px; }

        [data-theme="dark"] body,
        [data-theme="dark"] .bg-light { background: #000 !important; color: #f5f5f7; }
        [data-theme="dark"] .navbar { background: #1c1c1e !important; }
        [data-theme="dark"] .navbar-brand,
        [data-theme="dark"] .navbar .nav-link { color: #f5f5f7 !important; }
        [data-theme="dark"] .navbar .nav-link:hover { color: #6ea8ff !important; }
        [data-theme="dark"] .navbar .nav-link.active { color: #fff !important; background: rgba(10,132,255,0.15); border-radius: 8px; }
        [data-theme="dark"] .avatar-circle { background: linear-gradient(135deg, #2c2c2e 0%, #3c3c3e 100%); color: #f5f5f7; box-shadow: 0 2px 8px rgba(0,0,0,0.4); }
        [data-theme="dark"] .dropdown-menu { background: #1c1c1e; border-color: #2c2c2e; color: #f5f5f7; box-shadow: 0 20px 60px rgba(0,0,0,0.6); }
        [data-theme="dark"] .dropdown-item { color: #f5f5f7; }
        [data-theme="dark"] .dropdown-item:hover,
        [data-theme="dark"] .dropdown-item:focus { background: #2c2c2e; color: #fff; }
        [data-theme="dark"] .dropdown-header { color: #a1a1a6; }
        [data-theme="dark"] .dropdown-divider { border-color: #2c2c2e; }
        [data-theme="dark"] .user-card-head { border-color: #2c2c2e; }
        [data-theme="dark"] .user-card-head .uname { color: #f5f5f7; }
        [data-theme="dark"] .notif-dropdown { background: #1c1c1e; border-color: #2c2c2e; }
        [data-theme="dark"] .notif-header { background: linear-gradient(135deg, rgba(255,69,58,0.15) 0%, #1c1c1e 100%); border-color: #2c2c2e; }
        [data-theme="dark"] .notif-header-title { color: #f5f5f7; }
        [data-theme="dark"] .notif-item { border-color: #2c2c2e; }
        [data-theme="dark"] .notif-item:hover { background: #2c2c2e; color: inherit; }
        [data-theme="dark"] .notif-item-date { background: linear-gradient(135deg, rgba(255,69,58,0.15) 0%, #1c1c1e 100%); border-color: rgba(255,69,58,0.3); color: #ff7b6e; }
        [data-theme="dark"] .notif-item-title { color: #f5f5f7; }
        [data-theme="dark"] .notif-footer { background: #141416; border-color: #2c2c2e; }
        [data-theme="dark"] .notif-footer a { color: #6ea8ff; }
        [data-theme="dark"] .card,
        [data-theme="dark"] .dash-card,
        [data-theme="dark"] .prof-card,
        [data-theme="dark"] .prof-stat,
        [data-theme="dark"] .prof-header,
        [data-theme="dark"] .prof-link,
        [data-theme="dark"] .sched-lesson,
        [data-theme="dark"] .dash-stat { background: #1c1c1e !important; border-color: #2c2c2e !important; color: #f5f5f7; }
        [data-theme="dark"] .card-header,
        [data-theme="dark"] .card-footer { background: #2c2c2e !important; border-color: #3c3c3e !important; color: #f5f5f7; }
        [data-theme="dark"] h1, [data-theme="dark"] h2, [data-theme="dark"] h3,
        [data-theme="dark"] h4, [data-theme="dark"] h5, [data-theme="dark"] h6 { color: #f5f5f7; }
        [data-theme="dark"] .text-muted { color: #a1a1a6 !important; }
        [data-theme="dark"] .text-dark { color: #f5f5f7 !important; }
        [data-theme="dark"] a { color: #6ea8ff; }
        [data-theme="dark"] a:hover { color: #8ebbff; }
        [data-theme="dark"] .table { color: #f5f5f7; border-color: #2c2c2e; }
        [data-theme="dark"] .table thead th,
        [data-theme="dark"] .table-light { background: #2c2c2e !important; color: #a1a1a6 !important; border-color: #3c3c3e !important; }
        [data-theme="dark"] .table tbody td { border-color: #2c2c2e; color: #f5f5f7; }
        [data-theme="dark"] .table tbody tr:hover { background: #2c2c2e; }
        [data-theme="dark"] .form-control,
        [data-theme="dark"] .form-select { background: #2c2c2e; border-color: #3c3c3e; color: #f5f5f7; }
        [data-theme="dark"] .form-control:focus,
        [data-theme="dark"] .form-select:focus { background: #2c2c2e; border-color: #0a84ff; color: #f5f5f7; box-shadow: 0 0 0 4px rgba(10,132,255,0.15); }
        [data-theme="dark"] .form-control::placeholder { color: #6c6c70; }
        [data-theme="dark"] .form-label { color: #f5f5f7; }
        [data-theme="dark"] .form-text { color: #86868b; }
        [data-theme="dark"] .input-group-text { background: #2c2c2e; border-color: #3c3c3e; color: #a1a1a6; }
        [data-theme="dark"] .btn-outline-secondary { color: #a1a1a6; border-color: #3c3c3e; }
        [data-theme="dark"] .btn-outline-secondary:hover { background: #3c3c3e; color: #fff; }
        [data-theme="dark"] .btn-outline-primary { color: #6ea8ff; border-color: #0a84ff; }
        [data-theme="dark"] .btn-outline-primary:hover { background: #0a84ff; color: #fff; }
        [data-theme="dark"] .btn-light { background: #2c2c2e; color: #f5f5f7; border-color: #3c3c3e; }
        [data-theme="dark"] .alert { border-color: transparent; }
        [data-theme="dark"] .alert-info { background: rgba(10,132,255,0.15); color: #8ebbff; border-color: rgba(10,132,255,0.3); }
        [data-theme="dark"] .alert-success { background: rgba(48,209,88,0.15); color: #6ee7a3; border-color: rgba(48,209,88,0.3); }
        [data-theme="dark"] .alert-warning { background: rgba(255,159,10,0.15); color: #ffb74d; border-color: rgba(255,159,10,0.3); }
        [data-theme="dark"] .alert-danger { background: rgba(255,69,58,0.15); color: #ff7b6e; border-color: rgba(255,69,58,0.3); }
        [data-theme="dark"] .alert-light { background: #2c2c2e; color: #f5f5f7; border-color: #3c3c3e; }
        [data-theme="dark"] .modal-content { background: #1c1c1e; border-color: #2c2c2e; color: #f5f5f7; }
        [data-theme="dark"] .modal-header,
        [data-theme="dark"] .modal-footer { border-color: #2c2c2e; }
        [data-theme="dark"] .btn-close { filter: invert(1) brightness(2); }
        [data-theme="dark"] code { background: rgba(10,132,255,0.15); color: #6ea8ff; }
        [data-theme="dark"] pre { background: #1c1c1e !important; color: #f5f5f7; border-color: #2c2c2e; }
        [data-theme="dark"] hr { border-color: #2c2c2e; }
        [data-theme="dark"] .border,
        [data-theme="dark"] .border-top,
        [data-theme="dark"] .border-bottom { border-color: #2c2c2e !important; }
        [data-theme="dark"] .list-group-item { background: #1c1c1e; border-color: #2c2c2e; color: #f5f5f7; }
        [data-theme="dark"] .list-group-item:hover { background: #2c2c2e; }
        [data-theme="dark"] .breadcrumb { background: transparent; }
        [data-theme="dark"] .breadcrumb-item + .breadcrumb-item::before { color: #6c6c70; }
        [data-theme="dark"] .nav-tabs .nav-link { color: #a1a1a6; }
        [data-theme="dark"] .nav-tabs .nav-link.active { background: #1c1c1e; border-color: #2c2c2e #2c2c2e #1c1c1e; color: #f5f5f7; }
    </style>
    <script>
        (function() {
            try {
                var t = localStorage.getItem('ap-theme');
                if (t === 'dark' || t === 'light') {
                    document.documentElement.setAttribute('data-theme', t);
                } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    document.documentElement.setAttribute('data-theme', 'dark');
                }
            } catch (e) {}
        })();
    </script>
    <link rel="manifest" href="<?= $basePath ?>manifest.json">
    <meta name="theme-color" content="#0071e3">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="АИС-Расписание">
    <link rel="apple-touch-icon" href="<?= $basePath ?>assets/icons/icon-192.png">
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm sticky-top">
    <div class="container-fluid px-3">

        <a class="navbar-brand d-flex align-items-center"
           href="<?= $basePath ?>dashboard.php"
           data-bs-toggle="tooltip" data-bs-placement="bottom"
           title="АИС-расписание.РФ — на главную">
            <img src="<?= $basePath ?>assets/img/logo.svg"
                 alt="АИС-расписание.РФ"
                 style="width: 32px; height: 32px; object-fit: contain; margin-right: 8px;">
            <span class="d-none d-sm-inline">АИС-расписание.РФ</span>
        </a>

        <button class="navbar-toggler border-0" type="button"
                data-bs-toggle="collapse" data-bs-target="#mainNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">

                <li class="nav-item">
                    <a class="nav-link <?= $currentPage==='dashboard'?'active':'' ?>"
                       href="<?= $basePath ?>dashboard.php"
                       data-bs-toggle="tooltip" title="Главная — сводка, занятия на сегодня">
                        <i class="bi bi-house-door"></i>
                        <span class="d-lg-none ms-1">Главная</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link <?= $currentPage==='schedule'?'active':'' ?>"
                       href="<?= $basePath ?>schedule.php"
                       data-bs-toggle="tooltip" title="Расписание — занятия на неделю">
                        <i class="bi bi-calendar-week"></i>
                        <span class="d-lg-none ms-1">Расписание</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link <?= $currentPage==='attendance'?'active':'' ?>"
                       href="<?= $basePath ?>attendance.php"
                       data-bs-toggle="tooltip" title="Посещаемость">
                        <i class="bi bi-check2-square"></i>
                        <span class="d-lg-none ms-1">Посещаемость</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link <?= $currentPage==='works'?'active':'' ?>"
                       href="<?= $basePath ?>works.php"
                       data-bs-toggle="tooltip" title="Работы">
                        <i class="bi bi-journal-check"></i>
                        <span class="d-lg-none ms-1">Работы</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link <?= $currentPage==='reports'?'active':'' ?>"
                       href="<?= $basePath ?>reports.php"
                       data-bs-toggle="tooltip" title="Отчёты">
                        <i class="bi bi-bar-chart"></i>
                        <span class="d-lg-none ms-1">Отчёты</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link <?= $currentPage==='session' || $currentPage==='session_sheet' ?'active':'' ?>"
                       href="<?= $basePath ?>session.php"
                       data-bs-toggle="tooltip" title="Сессия — ведомости">
                        <i class="bi bi-mortarboard"></i>
                        <span class="d-lg-none ms-1">Сессия</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link <?= $currentPage==='debtors'?'active':'' ?>"
                       href="<?= $basePath ?>debtors.php"
                       data-bs-toggle="tooltip" title="Должники">
                        <i class="bi bi-exclamation-triangle"></i>
                        <span class="d-lg-none ms-1">Должники</span>
                    </a>
                </li>

                <?php if ($canCheck): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $currentPage==='check_reports'?'active':'' ?>"
                           href="<?= $basePath ?>check_reports.php"
                           data-bs-toggle="tooltip" title="Проверка отчётов">
                            <i class="bi bi-clipboard-check"></i>
                            <span class="d-lg-none ms-1">Проверка</span>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>

            <ul class="navbar-nav ms-auto align-items-lg-center">

                <li class="nav-item dropdown me-lg-2 mb-2 mb-lg-0">
                    <a class="notif-btn" href="#" role="button"
                       data-bs-toggle="dropdown" aria-expanded="false"
                       data-bs-toggle="tooltip" title="Уведомления — не отмеченные занятия">
                        <i class="bi bi-bell-fill"></i>
                        <?php if ($unmarkedCount > 0): ?>
                            <span class="notif-badge"><?= $unmarkedCount > 99 ? '99+' : $unmarkedCount ?></span>
                        <?php endif; ?>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end notif-dropdown">
                        <div class="notif-header">
                            <div class="notif-header-title">
                                <i class="bi bi-bell-fill"></i>
                                Не отмечена посещаемость
                            </div>
                            <?php if ($unmarkedCount > 0): ?>
                                <span class="notif-header-badge"><?= $unmarkedCount ?></span>
                            <?php endif; ?>
                        </div>

                        <?php if (empty($unmarkedList)): ?>
                            <div class="notif-empty">
                                <i class="bi bi-check-circle-fill"></i>
                                <div>Все занятия отмечены! 🎉</div>
                            </div>
                        <?php else: ?>
                            <div class="notif-list">
                                <?php foreach ($unmarkedList as $lesson):
                                    $dateObj = new DateTime($lesson['lesson_date']);
                                    $monthNames = ['','янв','фев','мар','апр','май','июн','июл','авг','сен','окт','ноя','дек'];
                                ?>
                                    <a href="<?= $basePath ?>attendance.php?lesson_id=<?= $lesson['id'] ?>" class="notif-item">
                                        <div class="notif-item-date">
                                            <span class="d"><?= $dateObj->format('d') ?></span>
                                            <span class="m"><?= $monthNames[(int)$dateObj->format('n')] ?></span>
                                        </div>
                                        <div class="notif-item-info">
                                            <div class="notif-item-title"><?= htmlspecialchars($lesson['discipline']) ?></div>
                                            <div class="notif-item-meta">
                                                <?php if (!empty($lesson['group_name'])): ?>
                                                    <span><i class="bi bi-people-fill"></i> <?= htmlspecialchars($lesson['group_name']) ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($lesson['lesson_time'])): ?>
                                                    <span><i class="bi bi-clock"></i> <?= substr($lesson['lesson_time'], 0, 5) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <i class="bi bi-chevron-right notif-item-arrow"></i>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                            <div class="notif-footer">
                                <a href="<?= $basePath ?>notifications.php">
                                    Показать все <i class="bi bi-arrow-right"></i>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </li>

                <li class="nav-item me-lg-2 mb-2 mb-lg-0">
                    <button type="button" class="theme-toggle-btn" id="themeToggle" title="Светлая / тёмная тема">
                        <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
                    </button>
                </li>

                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button"
                       data-bs-toggle="dropdown" data-bs-toggle="tooltip" title="Дополнительные разделы">
                        <i class="bi bi-three-dots"></i>
                        <span class="d-lg-none ms-1">Ещё</span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow">
                        <li><h6 class="dropdown-header">Управление</h6></li>
                        <li>
                            <a class="dropdown-item" href="<?= $basePath ?>subgroups.php">
                                <i class="bi bi-diagram-3 text-primary"></i> Подгруппы
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="<?= $basePath ?>upload_students.php">
                                <i class="bi bi-file-earmark-excel text-success"></i> Импорт студентов
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="<?= $basePath ?>groups.php">
                                <i class="bi bi-people text-primary"></i> Группы
                            </a>
                        </li>

                        <li><hr class="dropdown-divider"></li>
                        <li><h6 class="dropdown-header">Помощь</h6></li>
                        <li>
                            <a class="dropdown-item" href="<?= $basePath ?>docs.php">
                                <i class="bi bi-book text-info"></i> Документация
                            </a>
                        </li>

                        <li><hr class="dropdown-divider"></li>
                        <li><h6 class="dropdown-header">Аккаунт</h6></li>
                        <li>
                            <a class="dropdown-item" href="<?= $basePath ?>profile.php">
                                <i class="bi bi-person-gear"></i> Профиль
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="<?= $basePath ?>change_password.php">
                                <i class="bi bi-key"></i> Сменить пароль
                            </a>
                        </li>

                        <?php if ($isAdmin): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><h6 class="dropdown-header">Администрация</h6></li>
                            <li>
                                <a class="dropdown-item" href="<?= $basePath ?>audit.php">
                                    <i class="bi bi-clock-history text-primary"></i> Журнал действий
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item text-danger fw-bold" href="<?= $basePath ?>admin/index.php">
                                    <i class="bi bi-shield-lock"></i> Админ-панель
                                </a>
                            </li>
                        <?php endif; ?>

                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item text-danger" href="<?= $basePath ?>logout.php">
                                <i class="bi bi-box-arrow-right"></i> Выйти
                            </a>
                        </li>
                    </ul>
                </li>

                <li class="nav-item dropdown ms-lg-2">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#"
                       role="button" data-bs-toggle="dropdown"
                       data-bs-toggle="tooltip" title="<?= htmlspecialchars($_SESSION['user_name']) ?> — <?= $roleLabel[0] ?>">
                        <div class="avatar-circle me-2"><?= htmlspecialchars($initials ?: 'U') ?></div>
                        <div class="d-none d-lg-block text-start lh-1">
                            <div class="small fw-bold"><?= htmlspecialchars($shortName) ?></div>
                            <div class="small opacity-75" style="font-size: 0.7rem;">
                                <?= htmlspecialchars($roleLabel[0]) ?>
                            </div>
                        </div>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow">
                        <li class="user-card-head">
                            <div class="uname"><?= htmlspecialchars($_SESSION['user_name']) ?></div>
                            <?php if ($userSubtitle !== ''): ?>
                                <div class="udept">
                                    <i class="bi bi-building"></i> <?= htmlspecialchars($userSubtitle) ?>
                                </div>
                            <?php endif; ?>
                            <div class="urole">
                                <span class="badge bg-<?= $roleLabel[1] ?>">
                                    <i class="bi bi-shield-check"></i> <?= htmlspecialchars($roleLabel[0]) ?>
                                </span>
                            </div>
                        </li>
                        <li>
                            <a class="dropdown-item" href="<?= $basePath ?>profile.php">
                                <i class="bi bi-person"></i> Профиль
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="<?= $basePath ?>change_password.php">
                                <i class="bi bi-key"></i> Сменить пароль
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item text-danger" href="<?= $basePath ?>logout.php">
                                <i class="bi bi-box-arrow-right"></i> Выйти
                            </a>
                        </li>
                    </ul>
                </li>

            </ul>
        </div>
    </div>
</nav>

<?php
require_once __DIR__ . '/messages.php';
echo renderMessagesStyles();
?>

<main class="container py-4">

<?php
try {
    if (!isset($pdo)) {
        require_once __DIR__ . '/../config/database.php';
    }
    $__section = $currentPage ?? 'dashboard';

    $__sectionMap = [
        'index'      => 'dashboard',
        'group_view' => 'groups',
    ];
    if (isset($__sectionMap[$__section])) $__section = $__sectionMap[$__section];

    echo renderMessages($pdo, $__section);
    echo renderMessagesScript();
} catch (Throwable $e) {
    error_log('site_messages render error: ' . $e->getMessage());
}
?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const initTooltips = () => {
        if (typeof bootstrap === 'undefined') return;
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el =>
            new bootstrap.Tooltip(el, { trigger: 'hover', delay: { show: 400, hide: 100 } })
        );
    };
    initTooltips();
    setTimeout(initTooltips, 100);

    const icon = document.getElementById('themeIcon');
    const updateIcon = () => {
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        if (icon) icon.className = isDark ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
    };
    updateIcon();

    const tBtn = document.getElementById('themeToggle');
    if (tBtn) {
        tBtn.addEventListener('click', () => {
            const cur = document.documentElement.getAttribute('data-theme') || 'light';
            const next = cur === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            try { localStorage.setItem('ap-theme', next); } catch (e) {}
            updateIcon();
        });
    }
});
</script>