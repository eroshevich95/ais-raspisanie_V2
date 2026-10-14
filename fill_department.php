<?php
// fill_department.php

require_once __DIR__ . '/includes/session_boot.php';
require_once __DIR__ . '/includes/security.php';
sendSecurityHeaders();
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/departments.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT email, login, institute_id, department_id FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) { header('Location: logout.php'); exit; }

if (empty($user['email'])) {
    header('Location: fill_email.php');
    exit;
}

if (!empty($user['institute_id']) && !empty($user['department_id'])) {
    unset($_SESSION['need_department']);
    header('Location: dashboard.php');
    exit;
}

$departmentsData = getKuzstuDepartments();
$institutes      = $departmentsData['institutes'];
$departments     = $departmentsData['departments'];

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $instituteId   = trim($_POST['institute_id'] ?? '');
    $departmentId  = trim($_POST['department_id'] ?? '');
    $departmentMan = trim($_POST['department_manual'] ?? '');
    $manualMode    = !empty($departmentMan);

    if ($manualMode) {
        if (mb_strlen($departmentMan) < 3) {
            $error = 'Введите название кафедры (минимум 3 символа)';
        } else {
            $pdo->prepare("UPDATE users SET department_name = ?, department = ?, institute_id = NULL, institute_name = NULL, department_id = NULL WHERE id = ?")
                ->execute([$departmentMan, $departmentMan, $userId]);

            unset($_SESSION['need_department']);
            $_SESSION['department_name'] = $departmentMan;
            $_SESSION['institute_name']  = '';
            header('Location: dashboard.php');
            exit;
        }
    } else {
        if ($instituteId === '') $error = 'Выберите институт';
        elseif ($departmentId === '') $error = 'Выберите кафедру';
        else {
            $instituteName = $institutes[$instituteId] ?? '';
            if ($instituteName === '') $error = 'Институт не найден';
            else {
                $foundDept = null;
                foreach (($departments[$instituteId] ?? []) as $d) {
                    if ($d['id'] === $departmentId) { $foundDept = $d; break; }
                }
                if (!$foundDept) $error = 'Кафедра не найдена';
                else {
                    $pdo->prepare("UPDATE users SET institute_id = ?, institute_name = ?, department_id = ?, department_name = ?, department = ? WHERE id = ?")
                        ->execute([$instituteId, $instituteName, $departmentId, $foundDept['name'], $foundDept['name'], $userId]);

                    unset($_SESSION['need_department']);
                    $_SESSION['institute_name']  = $instituteName;
                    $_SESSION['department_name'] = $foundDept['name'];
                    header('Location: dashboard.php');
                    exit;
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
    <title>Заполните подразделение — АИС-расписание.РФ</title>
    <link rel="icon" type="image/svg+xml" href="assets/img/logo.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<style>
html, body { margin: 0; padding: 0; min-height: 100vh; background: #f5f5f7; font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", "Segoe UI", Roboto, sans-serif; }
.fill-page { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 40px 20px; background: linear-gradient(180deg, #fff 0%, #f5f5f7 100%); }
.fill-container { width: 100%; max-width: 560px; }
.fill-logo { width: 80px; height: 80px; margin: 0 auto 24px; animation: logoFloat 5s ease-in-out infinite; }
.fill-logo img { width: 100%; height: 100%; object-fit: contain; }
@keyframes logoFloat { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-10px); } }
.fill-title { font-size: 1.7rem; font-weight: 700; text-align: center; margin: 0 0 8px; }
.fill-subtitle { text-align: center; color: #86868b; font-size: 0.95rem; margin: 0 0 32px; }
.fill-card { background: #fff; border-radius: 24px; padding: 32px; box-shadow: 0 4px 12px rgba(0,0,0,0.04), 0 30px 80px rgba(0,0,0,0.1); border: 1px solid #e5e5ea; }
.form-label { font-size: 0.85rem; font-weight: 600; margin-bottom: 6px; display: block; }
.form-control, .form-select { padding: 14px 18px; border-radius: 12px; border: 1.5px solid #e5e5ea; background: #fafafc; font-size: 1rem; font-family: inherit; width: 100%; }
.form-control:focus, .form-select:focus { border-color: #0071e3; background: #fff; box-shadow: 0 0 0 4px rgba(0,113,227,0.12); outline: none; }
.form-control:disabled { background: #f5f5f7; color: #86868b; }
.btn-primary-gradient { width: 100%; padding: 15px; border: none; border-radius: 12px; background: linear-gradient(135deg, #0071e3 0%, #5e5ce6 100%); color: #fff; font-size: 1rem; font-weight: 600; font-family: inherit; cursor: pointer; transition: all 0.25s; display: inline-flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 8px 20px rgba(0,113,227,0.25); margin-top: 8px; }
.btn-primary-gradient:hover { transform: translateY(-2px); box-shadow: 0 14px 30px rgba(0,113,227,0.4); }
.alert { border-radius: 12px; border: none; padding: 12px 16px; font-size: 0.9rem; display: flex; align-items: center; gap: 10px; margin-bottom: 16px; }
.info-block { background: rgba(0,113,227,0.06); border: 1px solid rgba(0,113,227,0.15); border-radius: 12px; padding: 14px 18px; font-size: 0.85rem; display: flex; gap: 10px; margin-bottom: 20px; }
.manual-toggle { margin-top: 16px; padding: 12px 16px; background: #fafafc; border-radius: 12px; border: 1px solid #e5e5ea; display: flex; align-items: center; gap: 10px; }
.manual-toggle input { width: 18px; height: 18px; accent-color: #0071e3; flex-shrink: 0; }
.manual-toggle label { font-size: 0.85rem; cursor: pointer; margin: 0; }
.d-none { display: none !important; }
.fill-footer { text-align: center; margin-top: 24px; font-size: 0.82rem; color: #86868b; }
.fill-footer a { color: #86868b; text-decoration: none; }
</style>
</head>
<body>

<div class="fill-page">
    <div class="fill-container">
        <div class="fill-logo"><img src="assets/img/logo.svg" alt="АИС-расписание.РФ"></div>
        <h1 class="fill-title">Укажите подразделение</h1>
        <p class="fill-subtitle">Институт и кафедра нужны для отчётов и фильтров</p>

        <div class="fill-card">
            <?php if ($error): ?>
                <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill fs-5"></i><div><?= htmlspecialchars($error) ?></div></div>
            <?php endif; ?>

            <div class="info-block"><i class="bi bi-info-circle-fill"></i><div>Список институтов и кафедр подтягивается с портала КузГТУ. Если вашей кафедры нет — отметьте галочку «ввести вручную».</div></div>

            <form method="post" autocomplete="off">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label">Логин</label>
                    <input type="text" class="form-control" disabled value="<?= htmlspecialchars($user['login']) ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label">Институт <span style="color:#ff453a;">*</span></label>
                    <select name="institute_id" id="instituteSelect" class="form-select" required>
                        <option value="">— Выберите институт —</option>
                        <?php foreach ($institutes as $id => $name): ?>
                            <option value="<?= htmlspecialchars($id) ?>"><?= htmlspecialchars($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3 d-none" id="departmentBlock">
                    <label class="form-label">Кафедра <span style="color:#ff453a;">*</span></label>
                    <select name="department_id" id="departmentSelect" class="form-select" required>
                        <option value="">— Сначала выберите институт —</option>
                    </select>
                </div>

                <div class="manual-toggle">
                    <input type="checkbox" id="manualDeptToggle">
                    <label for="manualDeptToggle">Моей кафедры нет в списке — ввести вручную</label>
                </div>
                <div class="mt-2 d-none" id="manualDeptBlock">
                    <input type="text" name="department_manual" id="departmentManual" class="form-control" placeholder="Название кафедры">
                </div>

                <button type="submit" class="btn-primary-gradient"><i class="bi bi-check-circle-fill"></i> Сохранить и продолжить</button>
            </form>

            <div class="fill-footer"><a href="logout.php"><i class="bi bi-box-arrow-right"></i> Выйти</a></div>
        </div>
    </div>
</div>

<script>
const DEPARTMENTS_DATA = <?= json_encode($departments, JSON_UNESCAPED_UNICODE) ?>;
const instituteSelect  = document.getElementById('instituteSelect');
const departmentBlock  = document.getElementById('departmentBlock');
const departmentSelect = document.getElementById('departmentSelect');
const manualDeptToggle = document.getElementById('manualDeptToggle');
const manualDeptBlock  = document.getElementById('manualDeptBlock');

instituteSelect.addEventListener('change', () => {
    const instId = instituteSelect.value;
    departmentSelect.innerHTML = '<option value="">— Выберите кафедру —</option>';
    if (!instId) { departmentBlock.classList.add('d-none'); return; }
    const list = DEPARTMENTS_DATA[instId] || [];
    if (!list.length) { departmentBlock.classList.add('d-none'); return; }
    list.forEach(d => {
        const opt = document.createElement('option');
        opt.value = d.id;
        opt.textContent = d.label || d.name;
        departmentSelect.appendChild(opt);
    });
    departmentBlock.classList.remove('d-none');
});

manualDeptToggle.addEventListener('change', () => {
    if (manualDeptToggle.checked) {
        manualDeptBlock.classList.remove('d-none');
        departmentBlock.classList.add('d-none');
        departmentSelect.removeAttribute('required');
        instituteSelect.removeAttribute('required');
    } else {
        manualDeptBlock.classList.add('d-none');
        departmentSelect.setAttribute('required', 'required');
        instituteSelect.setAttribute('required', 'required');
        if (instituteSelect.value) instituteSelect.dispatchEvent(new Event('change'));
    }
});
</script>

</body>
</html>