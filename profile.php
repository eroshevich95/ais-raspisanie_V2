<?php
// profile.php

$pageTitle = 'Профиль';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/departments.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/session_boot.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/csrf.php';

sendSecurityHeaders();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];

// AJAX: сохранение
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_profile') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_verify(true);

    $email = trim($_POST['email'] ?? '');
    $instituteId    = trim($_POST['institute_id'] ?? '');
    $instituteName  = trim($_POST['institute_name'] ?? '');
    $departmentId   = trim($_POST['department_id'] ?? '');
    $departmentName = trim($_POST['department_name'] ?? '');

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['ok' => false, 'error' => 'Некорректный email']);
        exit;
    }

    try {
        if ($email !== '') {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$email, $userId]);
            if ($stmt->fetch()) {
                echo json_encode(['ok' => false, 'error' => 'Email уже используется']);
                exit;
            }
        }

        if ($instituteId !== '' && $departmentId !== '') {
            $data = getKuzstuDepartments();
            $foundInst = $data['institutes'][$instituteId] ?? '';
            if ($foundInst === '') {
                echo json_encode(['ok' => false, 'error' => 'Институт не найден']);
                exit;
            }
            $instituteName = $foundInst;

            $foundDept = null;
            foreach (($data['departments'][$instituteId] ?? []) as $d) {
                if ($d['id'] === $departmentId) { $foundDept = $d; break; }
            }
            if (!$foundDept) {
                echo json_encode(['ok' => false, 'error' => 'Кафедра не найдена']);
                exit;
            }
            $departmentName = $foundDept['name'];
        }

        $stmtOld = $pdo->prepare("SELECT email, institute_name, department_name FROM users WHERE id = ?");
        $stmtOld->execute([$userId]);
        $oldUser = $stmtOld->fetch();

        $pdo->prepare("
            UPDATE users
            SET email = ?, institute_id = ?, institute_name = ?,
                department_id = ?, department_name = ?, department = ?
            WHERE id = ?
        ")->execute([
            $email ?: null,
            $instituteId  ?: null,
            $instituteName ?: null,
            $departmentId ?: null,
            $departmentName ?: null,
            $departmentName ?: null,
            $userId,
        ]);

        $_SESSION['institute_name']  = $instituteName;
        $_SESSION['department_name'] = $departmentName;

        $changes = [];
        if (($oldUser['email'] ?? '') !== $email) $changes[] = 'email';
        if (($oldUser['institute_name'] ?? '') !== $instituteName) $changes[] = 'институт';
        if (($oldUser['department_name'] ?? '') !== $departmentName) $changes[] = 'кафедра';

        if (!empty($changes)) {
            $oldParts = $newParts = [];
            if (in_array('email', $changes)) {
                $oldParts[] = 'Email: ' . ($oldUser['email'] ?: '—');
                $newParts[] = 'Email: ' . ($email ?: '—');
            }
            if (in_array('институт', $changes)) {
                $oldParts[] = 'Институт: ' . ($oldUser['institute_name'] ?: '—');
                $newParts[] = 'Институт: ' . ($instituteName ?: '—');
            }
            if (in_array('кафедра', $changes)) {
                $oldParts[] = 'Кафедра: ' . ($oldUser['department_name'] ?: '—');
                $newParts[] = 'Кафедра: ' . ($departmentName ?: '—');
            }

            auditLog($pdo, 'profile.update', [
                'entity_type' => 'user', 'entity_id' => $userId,
                'description' => 'Изменены данные профиля: ' . implode(', ', $changes),
                'old_value'   => implode(' · ', $oldParts),
                'new_value'   => implode(' · ', $newParts),
            ]);
        }

        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        error_log('save_profile error: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'Ошибка сервера']);
    }
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$u = $stmt->fetch();

if (!$u) { header('Location: logout.php'); exit; }

$departmentsData = getKuzstuDepartments();
$institutes      = $departmentsData['institutes'];
$departments     = $departmentsData['departments'];

// Статистика
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM (
        SELECT 1 FROM lessons WHERE user_id = ?
        GROUP BY lesson_date, lesson_number, discipline, audience, subgroup
    ) AS uniq
");
$stmt->execute([$userId]);
$totalLessons = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(a.id) AS total, COALESCE(SUM(a.is_present), 0) AS present
    FROM attendance a JOIN lessons l ON a.lesson_id = l.id
    WHERE l.user_id = ?
");
$stmt->execute([$userId]);
$att = $stmt->fetch();
$attTotal = (int)($att['total'] ?? 0);
$attPresent = (int)($att['present'] ?? 0);
$attPct = $attTotal > 0 ? round($attPresent / $attTotal * 100) : 0;

$stmt = $pdo->prepare("SELECT COUNT(DISTINCT group_id) FROM lessons WHERE user_id = ? AND group_id IS NOT NULL");
$stmt->execute([$userId]);
$groupsCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT s.id) FROM students s
    WHERE s.group_id IN (SELECT DISTINCT group_id FROM lessons WHERE user_id = ?)
");
$stmt->execute([$userId]);
$studentsCount = (int)$stmt->fetchColumn();

$nameParts = explode(' ', $u['full_name'] ?? '');
$initials = '';
if (!empty($nameParts[0])) $initials .= mb_strtoupper(mb_substr($nameParts[0], 0, 1));
if (!empty($nameParts[1])) $initials .= mb_strtoupper(mb_substr($nameParts[1], 0, 1));

$roles = [
    'teacher'            => ['Преподаватель', 'info'],
    'head_of_department' => ['Зав. кафедрой', 'warning'],
    'administration'     => ['Администрация', 'success'],
    'admin'              => ['Администратор', 'danger'],
];
$role = $roles[$u['role']] ?? ['Пользователь', 'secondary'];

require_once __DIR__ . '/includes/header.php';
?>

<style>
.prof-header { display: flex; align-items: center; gap: 20px; background: #fff; border-radius: 20px; border: 1px solid #eaeaea; box-shadow: 0 4px 16px rgba(0,0,0,0.04); padding: 24px 28px; margin-bottom: 20px; flex-wrap: wrap; }
.prof-avatar { width: 80px; height: 80px; border-radius: 50%; background: linear-gradient(135deg, #0071e3 0%, #5e5ce6 100%); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.9rem; font-weight: 800; flex-shrink: 0; box-shadow: 0 8px 24px rgba(0,113,227,0.3); }
.prof-name { font-size: 1.4rem; font-weight: 700; letter-spacing: -0.02em; margin: 0 0 6px; }
.prof-subtitle { font-size: 0.9rem; color: #86868b; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 10px; }
.prof-subtitle i { color: #0071e3; }

.prof-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 20px; }
.prof-stat { background: #fff; border-radius: 18px; padding: 18px 20px; border: 1px solid #eaeaea; box-shadow: 0 4px 16px rgba(0,0,0,0.04); position: relative; overflow: hidden; }
.prof-stat::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: var(--ps-color, #0071e3); }
.prof-stat-icon { width: 34px; height: 34px; border-radius: 10px; background: var(--ps-bg, rgba(0,113,227,0.1)); color: var(--ps-color, #0071e3); display: flex; align-items: center; justify-content: center; font-size: 1rem; margin-bottom: 10px; }
.prof-stat-value { font-size: 1.7rem; font-weight: 700; line-height: 1; margin-bottom: 6px; }
.prof-stat-label { font-size: 0.78rem; color: #86868b; }

.prof-card { background: #fff; border-radius: 20px; border: 1px solid #eaeaea; box-shadow: 0 4px 16px rgba(0,0,0,0.04); overflow: hidden; margin-bottom: 20px; }
.prof-card-header { padding: 16px 24px; background: linear-gradient(135deg, #fafafc 0%, #fff 100%); border-bottom: 1px solid #f0f0f2; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
.prof-card-title { font-size: 1rem; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 8px; }
.prof-card-title i { color: #0071e3; }
.prof-card-body { padding: 20px 24px; }
.prof-row { display: grid; grid-template-columns: 200px 1fr; gap: 12px; padding: 12px 0; border-bottom: 1px solid #f5f5f7; font-size: 0.92rem; }
.prof-row:last-child { border-bottom: none; }
.prof-row .label { color: #86868b; font-weight: 500; display: flex; align-items: center; gap: 6px; }
.prof-row .label i { color: #0071e3; font-size: 1rem; }
.prof-row .value { color: #1d1d1f; font-weight: 600; word-break: break-word; }

.prof-edit-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 10px; background: linear-gradient(135deg, #0071e3 0%, #5e5ce6 100%); color: #fff; border: none; font-size: 0.82rem; font-weight: 600; cursor: pointer; font-family: inherit; transition: all 0.2s; box-shadow: 0 4px 12px rgba(0,113,227,0.25); }
.prof-edit-btn:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(0,113,227,0.4); color: #fff; }

.prof-links { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin-bottom: 20px; }
.prof-link { display: flex; align-items: center; gap: 14px; padding: 16px 20px; background: #fff; border-radius: 16px; border: 1px solid #eaeaea; box-shadow: 0 4px 16px rgba(0,0,0,0.04); text-decoration: none; color: inherit; transition: all 0.2s; }
.prof-link:hover { border-color: #0071e3; transform: translateY(-2px); box-shadow: 0 12px 24px rgba(0,113,227,0.15); color: inherit; }
.prof-link-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.15rem; flex-shrink: 0; }
.prof-link-title { font-size: 0.92rem; font-weight: 700; margin-bottom: 2px; }
.prof-link-desc { font-size: 0.78rem; color: #86868b; }

.prof-modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(10px); z-index: 10000; display: none; align-items: center; justify-content: center; padding: 24px; }
.prof-modal-backdrop.show { display: flex; }
.prof-modal { background: #fff; border-radius: 24px; width: 100%; max-width: 520px; max-height: 90vh; overflow-y: auto; box-shadow: 0 40px 100px rgba(0,0,0,0.4); animation: profModalIn 0.4s cubic-bezier(0.16,1,0.3,1); }
@keyframes profModalIn { from { opacity: 0; transform: scale(0.9) translateY(20px); } to { opacity: 1; transform: scale(1) translateY(0); } }
.prof-modal-header { padding: 20px 24px; border-bottom: 1px solid #f0f0f2; display: flex; justify-content: space-between; align-items: center; gap: 12px; background: linear-gradient(135deg, #fafafc 0%, #fff 100%); border-radius: 24px 24px 0 0; }
.prof-modal-header h5 { margin: 0; font-weight: 700; font-size: 1.1rem; display: flex; align-items: center; gap: 8px; }
.prof-modal-header h5 i { color: #0071e3; }
.prof-modal-close { width: 36px; height: 36px; border-radius: 50%; background: #f5f5f7; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; }
.prof-modal-close:hover { background: #0071e3; color: #fff; transform: rotate(90deg); }
.prof-modal-body { padding: 20px 24px; }
.prof-modal-footer { padding: 16px 24px; border-top: 1px solid #f0f0f2; display: flex; justify-content: flex-end; gap: 10px; background: #fafafc; border-radius: 0 0 24px 24px; }
.prof-form-group { margin-bottom: 16px; }
.prof-form-label { font-size: 0.8rem; font-weight: 700; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.3px; display: block; }
.prof-form-label i { color: #0071e3; margin-right: 4px; }
.prof-form-input, .prof-form-select { width: 100%; padding: 11px 14px; border-radius: 10px; border: 1.5px solid #e5e5ea; background: #fff; font-size: 0.92rem; font-family: inherit; outline: none; transition: all 0.2s; }
.prof-form-input:focus, .prof-form-select:focus { border-color: #0071e3; box-shadow: 0 0 0 4px rgba(0,113,227,0.12); }
.prof-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 10px; font-size: 0.88rem; font-weight: 600; cursor: pointer; font-family: inherit; transition: all 0.2s; border: none; }
.prof-btn--primary { background: linear-gradient(135deg, #0071e3 0%, #5e5ce6 100%); color: #fff; box-shadow: 0 6px 16px rgba(0,113,227,0.25); }
.prof-btn--primary:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(0,113,227,0.4); }
.prof-btn--secondary { background: #f5f5f7; color: #495057; border: 1.5px solid #e5e5ea; }
.prof-btn--secondary:hover { background: #fff; border-color: #0071e3; color: #0071e3; }

[data-theme="dark"] .prof-header, [data-theme="dark"] .prof-stat,
[data-theme="dark"] .prof-card, [data-theme="dark"] .prof-link,
[data-theme="dark"] .prof-modal { background: #1c1c1e; border-color: #2c2c2e; }
[data-theme="dark"] .prof-name, [data-theme="dark"] .prof-stat-value,
[data-theme="dark"] .prof-card-title, [data-theme="dark"] .prof-row .value,
[data-theme="dark"] .prof-link-title, [data-theme="dark"] .prof-form-label { color: #f5f5f7; }
[data-theme="dark"] .prof-card-header, [data-theme="dark"] .prof-modal-header { background: linear-gradient(135deg, #2c2c2e 0%, #1c1c1e 100%); border-color: #3c3c3e; }
[data-theme="dark"] .prof-row { border-color: #2c2c2e; }
[data-theme="dark"] .prof-form-input, [data-theme="dark"] .prof-form-select { background: #2c2c2e; border-color: #3c3c3e; color: #f5f5f7; }
[data-theme="dark"] .prof-modal-close { background: #2c2c2e; color: #f5f5f7; }
[data-theme="dark"] .prof-modal-footer { background: #141416; border-color: #2c2c2e; }
[data-theme="dark"] .prof-btn--secondary { background: #2c2c2e; color: #f5f5f7; border-color: #3c3c3e; }
</style>

<div class="prof-header">
    <div class="prof-avatar"><?= htmlspecialchars($initials ?: 'U') ?></div>
    <div style="flex:1; min-width:250px;">
        <h1 class="prof-name"><?= htmlspecialchars($u['full_name']) ?></h1>
        <div class="prof-subtitle">
            <span class="badge bg-<?= $role[1] ?>"><i class="bi bi-shield-check"></i> <?= htmlspecialchars($role[0]) ?></span>
            <?php if (!empty($u['institute_name'])): ?><span><i class="bi bi-building"></i> <?= htmlspecialchars($u['institute_name']) ?></span><?php endif; ?>
            <?php if (!empty($u['department_name'])): ?><span><i class="bi bi-diagram-3"></i> <?= htmlspecialchars($u['department_name']) ?></span><?php endif; ?>
        </div>
    </div>
    <button class="prof-edit-btn" onclick="openEditModal()"><i class="bi bi-pencil"></i> Редактировать</button>
</div>

<div class="prof-stats">
    <div class="prof-stat" style="--ps-color:#0071e3; --ps-bg:rgba(0,113,227,0.1);"><div class="prof-stat-icon"><i class="bi bi-calendar-week"></i></div><div class="prof-stat-value"><?= $totalLessons ?></div><div class="prof-stat-label">Пар проведено</div></div>
    <div class="prof-stat" style="--ps-color:#30d158; --ps-bg:rgba(48,209,88,0.1);"><div class="prof-stat-icon"><i class="bi bi-people-fill"></i></div><div class="prof-stat-value"><?= $attPct ?>%</div><div class="prof-stat-label">Средняя посещаемость</div></div>
    <div class="prof-stat" style="--ps-color:#bf5af2; --ps-bg:rgba(191,90,242,0.1);"><div class="prof-stat-icon"><i class="bi bi-people"></i></div><div class="prof-stat-value"><?= $groupsCount ?></div><div class="prof-stat-label">Групп веду</div></div>
    <div class="prof-stat" style="--ps-color:#ff9f0a; --ps-bg:rgba(255,159,10,0.1);"><div class="prof-stat-icon"><i class="bi bi-person-badge"></i></div><div class="prof-stat-value"><?= $studentsCount ?></div><div class="prof-stat-label">Студентов всего</div></div>
</div>

<div class="prof-card">
    <div class="prof-card-header"><h3 class="prof-card-title"><i class="bi bi-person-vcard"></i> Личная информация</h3></div>
    <div class="prof-card-body">
        <div class="prof-row"><div class="label"><i class="bi bi-person"></i> ФИО</div><div class="value"><?= htmlspecialchars($u['full_name']) ?></div></div>
        <div class="prof-row"><div class="label"><i class="bi bi-key"></i> Логин</div><div class="value"><code><?= htmlspecialchars($u['login']) ?></code></div></div>
        <div class="prof-row"><div class="label"><i class="bi bi-envelope"></i> Email</div><div class="value"><?php if (!empty($u['email'])): ?><a href="mailto:<?= htmlspecialchars($u['email']) ?>" style="color:#0071e3;text-decoration:none;"><?= htmlspecialchars($u['email']) ?></a><?php else: ?><span style="color:#ff453a;">Не заполнен</span><?php endif; ?></div></div>
        <div class="prof-row"><div class="label"><i class="bi bi-building"></i> Институт</div><div class="value"><?= !empty($u['institute_name']) ? htmlspecialchars($u['institute_name']) : '<span style="color:#86868b;">Не указан</span>' ?></div></div>
        <div class="prof-row"><div class="label"><i class="bi bi-diagram-3"></i> Кафедра</div><div class="value"><?= !empty($u['department_name']) ? htmlspecialchars($u['department_name']) : (!empty($u['department']) ? htmlspecialchars($u['department']) : '<span style="color:#86868b;">Не указана</span>') ?></div></div>
        <div class="prof-row"><div class="label"><i class="bi bi-shield-check"></i> Роль</div><div class="value"><?= htmlspecialchars($role[0]) ?></div></div>
        <?php if (!empty($u['api_teacher_id'])): ?>
            <div class="prof-row"><div class="label"><i class="bi bi-link-45deg"></i> ID на портале</div><div class="value"><code><?= htmlspecialchars($u['api_teacher_id']) ?></code></div></div>
        <?php endif; ?>
    </div>
</div>

<div class="prof-links">
    <a href="change_password.php" class="prof-link">
        <div class="prof-link-icon" style="background:rgba(255,69,58,0.1);color:#ff453a;"><i class="bi bi-shield-lock"></i></div>
        <div><div class="prof-link-title">Сменить пароль</div><div class="prof-link-desc">Обновить пароль для входа</div></div>
    </a>
    <a href="session.php" class="prof-link">
        <div class="prof-link-icon" style="background:rgba(48,209,88,0.1);color:#198754;"><i class="bi bi-mortarboard"></i></div>
        <div><div class="prof-link-title">Мои ведомости</div><div class="prof-link-desc">Зачёты и экзамены</div></div>
    </a>
    <a href="debtors.php" class="prof-link">
        <div class="prof-link-icon" style="background:rgba(255,159,10,0.1);color:#b25c00;"><i class="bi bi-exclamation-triangle"></i></div>
        <div><div class="prof-link-title">Должники</div><div class="prof-link-desc">Студенты с пропусками</div></div>
    </a>
</div>

<div class="prof-modal-backdrop" id="editModal">
    <div class="prof-modal">
        <div class="prof-modal-header">
            <h5><i class="bi bi-pencil"></i> Редактировать профиль</h5>
            <button class="prof-modal-close" onclick="closeEditModal()"><i class="bi bi-x-lg"></i></button>
        </div>
        <form onsubmit="saveProfile(event)">
            <div class="prof-modal-body">
                <div class="prof-form-group">
                    <label class="prof-form-label"><i class="bi bi-envelope"></i> Email</label>
                    <input type="email" name="email" class="prof-form-input" value="<?= htmlspecialchars($u['email'] ?? '') ?>" placeholder="ivanov@kuzstu.ru" required>
                </div>
                <div class="prof-form-group">
                    <label class="prof-form-label"><i class="bi bi-building"></i> Институт</label>
                    <select name="institute_id" id="instituteSelect" class="prof-form-select">
                        <option value="">— Не указан —</option>
                        <?php foreach ($institutes as $id => $name): ?>
                            <option value="<?= htmlspecialchars($id) ?>" <?= ($u['institute_id'] ?? '') == $id ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="prof-form-group">
                    <label class="prof-form-label"><i class="bi bi-diagram-3"></i> Кафедра</label>
                    <select name="department_id" id="departmentSelect" class="prof-form-select">
                        <option value="">— Сначала выберите институт —</option>
                    </select>
                </div>
            </div>
            <div class="prof-modal-footer">
                <button type="button" class="prof-btn prof-btn--secondary" onclick="closeEditModal()">Отмена</button>
                <button type="submit" class="prof-btn prof-btn--primary"><i class="bi bi-check-circle-fill"></i> Сохранить</button>
            </div>
        </form>
    </div>
</div>

<script>
const DEPARTMENTS_DATA = <?= json_encode($departments, JSON_UNESCAPED_UNICODE) ?>;
const CURRENT_DEPT_ID = <?= json_encode($u['department_id'] ?? '') ?>;
const CSRF = <?= json_encode(csrf_token()) ?>;

function openEditModal() {
    document.getElementById('editModal').classList.add('show');
    document.body.style.overflow = 'hidden';
    const instSel = document.getElementById('instituteSelect');
    if (instSel.value) loadDepartments(instSel.value);
}
function closeEditModal() {
    document.getElementById('editModal').classList.remove('show');
    document.body.style.overflow = '';
}
document.getElementById('editModal').addEventListener('click', e => { if (e.target.id === 'editModal') closeEditModal(); });

function loadDepartments(instId) {
    const depSel = document.getElementById('departmentSelect');
    depSel.innerHTML = '<option value="">— Выберите кафедру —</option>';
    if (!instId) return;
    const list = DEPARTMENTS_DATA[instId] || [];
    list.forEach(d => {
        const opt = document.createElement('option');
        opt.value = d.id;
        opt.textContent = d.label || d.name;
        if (d.id === CURRENT_DEPT_ID) opt.selected = true;
        depSel.appendChild(opt);
    });
}
document.getElementById('instituteSelect').addEventListener('change', e => loadDepartments(e.target.value));

function saveProfile(e) {
    e.preventDefault();
    const form = e.target;
    const fd = new FormData(form);
    fd.append('action', 'save_profile');
    fd.append('csrf', CSRF);

    const instId = fd.get('institute_id') || '';
    const depId  = fd.get('department_id') || '';
    if (instId && depId) {
        const instSel = document.getElementById('instituteSelect');
        const depSel  = document.getElementById('departmentSelect');
        fd.append('institute_name', instSel.options[instSel.selectedIndex].textContent.trim());
        fd.append('department_name', depSel.options[depSel.selectedIndex].textContent.trim());
    }

    fetch('profile.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.ok) { alert('Ошибка: ' + (data.error || 'не удалось')); return; }
            closeEditModal();
            location.reload();
        })
        .catch(() => alert('Ошибка сети'));
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>