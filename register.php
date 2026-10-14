<?php
// register.php

require_once __DIR__ . '/includes/session_boot.php';
require_once __DIR__ . '/includes/security.php';
sendSecurityHeaders();
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/config/database.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Регистрация — АИС-расписание.РФ</title>
    <link rel="icon" type="image/svg+xml" href="assets/img/logo.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root {
    --ap-bg: #f5f5f7; --ap-card: #ffffff; --ap-dark: #1d1d1f;
    --ap-gray: #86868b; --ap-border: #e5e5ea;
    --ap-accent: #0071e3; --ap-accent-hover: #0077ed;
    --ap-green: #30d158; --ap-purple: #bf5af2;
    --ap-glow-1: rgba(0,113,227,0.08); --ap-glow-2: rgba(191,90,242,0.08);
}
html, body { margin: 0; padding: 0; min-height: 100vh; background: var(--ap-bg); color: var(--ap-dark); font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", "Segoe UI", Roboto, sans-serif; }
.register-page { min-height: 100vh; padding: 40px 20px; position: relative; overflow: hidden; background: linear-gradient(180deg, #fff 0%, #f5f5f7 100%); }
.register-page::before { content: ''; position: absolute; inset: -50%; background: radial-gradient(circle at 20% 30%, var(--ap-glow-1) 0%, transparent 40%), radial-gradient(circle at 80% 70%, var(--ap-glow-2) 0%, transparent 40%); animation: auroraMove 20s ease-in-out infinite; pointer-events: none; z-index: 0; }
@keyframes auroraMove { 0%,100% { transform: translate(0,0) scale(1); } 33% { transform: translate(5%,-5%) scale(1.1); } 66% { transform: translate(-5%,5%) scale(0.95); } }
.register-container { max-width: 560px; margin: 0 auto; position: relative; z-index: 1; animation: fadeUpIn 0.7s cubic-bezier(0.16,1,0.3,1); }
@keyframes fadeUpIn { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
.register-logo { width: 88px; height: 88px; margin: 0 auto 24px; animation: logoFloat 5s ease-in-out infinite; filter: drop-shadow(0 20px 40px rgba(0,113,227,0.25)); }
.register-logo img { width: 100%; height: 100%; object-fit: contain; }
@keyframes logoFloat { 0%, 100% { transform: translateY(0) scale(1); } 50% { transform: translateY(-10px) scale(1.02); } }
.register-title { font-size: 1.9rem; font-weight: 700; letter-spacing: -0.02em; text-align: center; margin: 0 0 8px; }
.register-subtitle { text-align: center; color: var(--ap-gray); font-size: 0.95rem; margin: 0 0 32px; }
.register-card { background: var(--ap-card); border-radius: 24px; padding: 32px; box-shadow: 0 4px 12px rgba(0,0,0,0.04), 0 30px 80px rgba(0,0,0,0.1); border: 1px solid var(--ap-border); }

.step-indicator { display: flex; align-items: center; justify-content: center; gap: 8px; margin-bottom: 28px; }
.step-dot { width: 36px; height: 36px; border-radius: 50%; background: var(--ap-border); color: var(--ap-gray); font-size: 0.85rem; font-weight: 700; display: flex; align-items: center; justify-content: center; transition: all 0.3s; flex-shrink: 0; }
.step-dot.active { background: linear-gradient(135deg, #0071e3 0%, #5e5ce6 100%); color: #fff; box-shadow: 0 8px 20px rgba(0,113,227,0.35); transform: scale(1.1); }
.step-dot.done { background: var(--ap-green); color: #fff; }
.step-line { flex: 1; max-width: 60px; height: 2px; background: var(--ap-border); border-radius: 2px; transition: background 0.3s; }
.step-line.done { background: var(--ap-green); }

.step-title { font-size: 1.15rem; font-weight: 600; margin: 0 0 6px; letter-spacing: -0.01em; }
.step-desc { font-size: 0.88rem; color: var(--ap-gray); margin: 0 0 20px; }

.form-label { font-size: 0.85rem; font-weight: 600; margin-bottom: 6px; display: block; }
.form-control, .form-select { padding: 14px 18px; border-radius: 12px; border: 1.5px solid var(--ap-border); background: #fafafc; font-size: 1rem; font-family: inherit; transition: all 0.2s; width: 100%; }
.form-control:focus, .form-select:focus { border-color: var(--ap-accent); background: #fff; box-shadow: 0 0 0 4px rgba(0,113,227,0.12); outline: none; }
.form-text { font-size: 0.78rem; color: var(--ap-gray); margin-top: 6px; }

.input-group { display: flex; gap: 8px; }
.input-group input { flex: 1; }
.input-group button { padding: 14px 24px; border: none; border-radius: 12px; background: linear-gradient(135deg, #0071e3 0%, #5e5ce6 100%); color: #fff; font-weight: 600; font-family: inherit; cursor: pointer; transition: all 0.25s; white-space: nowrap; display: inline-flex; align-items: center; gap: 6px; }
.input-group button:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(0,113,227,0.35); }
.input-group button:disabled { opacity: 0.6; cursor: wait; transform: none; }

.btn-primary-gradient { width: 100%; padding: 15px; border: none; border-radius: 12px; background: linear-gradient(135deg, #0071e3 0%, #5e5ce6 100%); color: #fff; font-size: 1rem; font-weight: 600; font-family: inherit; cursor: pointer; transition: all 0.25s; display: inline-flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 8px 20px rgba(0,113,227,0.25); }
.btn-primary-gradient:hover { transform: translateY(-2px); box-shadow: 0 14px 30px rgba(0,113,227,0.4); }
.btn-primary-gradient:disabled { opacity: 0.6; cursor: wait; transform: none; }

.btn-outline { padding: 12px 24px; border: 1.5px solid var(--ap-border); border-radius: 12px; background: #fff; color: var(--ap-dark); font-weight: 500; font-family: inherit; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 8px; }
.btn-outline:hover { border-color: var(--ap-accent); color: var(--ap-accent); }

.alert { border-radius: 12px; border: none; padding: 12px 16px; font-size: 0.9rem; display: flex; align-items: center; gap: 10px; margin-bottom: 16px; }

#teachersList { max-height: 320px; overflow-y: auto; border-radius: 12px; border: 1px solid var(--ap-border); margin-top: 12px; }
.teacher-item { padding: 14px 18px; border-bottom: 1px solid var(--ap-border); cursor: pointer; transition: all 0.2s; display: flex; justify-content: space-between; align-items: center; gap: 12px; }
.teacher-item:last-child { border-bottom: none; }
.teacher-item:hover { background: rgba(0,113,227,0.06); padding-left: 22px; }
.teacher-item-name { font-weight: 600; font-size: 0.95rem; margin-bottom: 2px; }
.teacher-item-meta { font-size: 0.78rem; color: var(--ap-gray); }

.selected-teacher { background: linear-gradient(135deg, rgba(0,113,227,0.08) 0%, rgba(191,90,242,0.08) 100%); border: 1px solid rgba(0,113,227,0.2); border-radius: 12px; padding: 16px 20px; display: flex; align-items: center; gap: 12px; margin-bottom: 20px; }
.selected-teacher i { color: var(--ap-accent); font-size: 1.4rem; }

.step-actions { display: flex; gap: 12px; margin-top: 24px; }
.step-actions .btn-primary-gradient { flex: 1; }
.step-actions .btn-outline { flex-shrink: 0; padding: 14px 18px; }

.d-none { display: none !important; }

.login-links { text-align: center; margin-top: 24px; font-size: 0.88rem; color: var(--ap-gray); }
.login-links a { color: var(--ap-accent); text-decoration: none; font-weight: 500; }
.login-links a:hover { text-decoration: underline; }
</style>
</head>
<body>

<div class="register-page">
    <div class="register-container">

        <div class="register-logo"><img src="assets/img/logo.svg" alt="АИС-расписание.РФ"></div>

        <h1 class="register-title">Регистрация</h1>
        <p class="register-subtitle">Для преподавателей КузГТУ</p>

        <div class="step-indicator">
            <div class="step-dot active" id="stepDot1">1</div>
            <div class="step-line" id="stepLine1"></div>
            <div class="step-dot" id="stepDot2">2</div>
            <div class="step-line" id="stepLine2"></div>
            <div class="step-dot" id="stepDot3">3</div>
        </div>

        <div class="register-card">
            <div id="alertBox"></div>

            <!-- ШАГ 1: поиск -->
            <div id="step1">
                <h2 class="step-title">Найдём вас в базе</h2>
                <p class="step-desc">Введите вашу фамилию — найдём в справочнике портала КузГТУ.</p>

                <div class="input-group">
                    <input type="text" id="lastName" class="form-control" placeholder="Например: Иванов" autocomplete="off" autofocus>
                    <button type="button" id="searchBtn"><i class="bi bi-search"></i> Найти</button>
                </div>

                <div class="form-text"><i class="bi bi-info-circle"></i> Данные берутся с официального портала <strong>portal.kuzstu.ru</strong></div>

                <div id="teachersList" class="d-none"></div>
            </div>

            <!-- ШАГ 2: институт и кафедра -->
            <div id="step2" class="d-none">
                <h2 class="step-title">Выберите подразделение</h2>
                <p class="step-desc">Институт и кафедра — нужны для отчётов и фильтров.</p>

                <div class="selected-teacher" id="selectedTeacherStep2"></div>

                <div class="mb-3">
                    <label class="form-label">Институт <span style="color:#ff453a;">*</span></label>
                    <select id="instituteSelect" class="form-select" required>
                        <option value="">— Загрузка... —</option>
                    </select>
                </div>

                <div class="mb-3 d-none" id="departmentBlock">
                    <label class="form-label">Кафедра <span style="color:#ff453a;">*</span></label>
                    <select id="departmentSelect" class="form-select" required>
                        <option value="">— Сначала выберите институт —</option>
                    </select>
                </div>

                <div class="step-actions">
                    <button type="button" class="btn-primary-gradient" id="toStep3Btn" disabled>
                        Далее <i class="bi bi-arrow-right"></i>
                    </button>
                    <button type="button" class="btn-outline" onclick="goToStep(1)"><i class="bi bi-arrow-left"></i></button>
                </div>
            </div>

            <!-- ШАГ 3: логин, email, пароль -->
            <div id="step3" class="d-none">
                <h2 class="step-title">Создайте аккаунт</h2>
                <p class="step-desc">Придумайте логин и пароль для входа в систему.</p>

                <div class="selected-teacher" id="selectedTeacherStep3"></div>

                <form id="registerForm" autocomplete="off">
                    <div class="mb-3">
                        <label class="form-label">Логин <span style="color:#ff453a;">*</span></label>
                        <input type="text" name="login" id="login" class="form-control" required minlength="4" pattern="[a-zA-Z0-9_.\-]+" placeholder="ivanov">
                        <div class="form-text">Латиница, цифры, _ . - (мин. 4 символа)</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email <span style="color:#ff453a;">*</span></label>
                        <input type="email" name="email" id="email" class="form-control" required placeholder="ivanov@kuzstu.ru">
                        <div class="form-text">Нужен для восстановления пароля</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Пароль <span style="color:#ff453a;">*</span></label>
                        <input type="password" name="password" id="password" class="form-control" required minlength="6" placeholder="Минимум 6 символов">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Повторите пароль <span style="color:#ff453a;">*</span></label>
                        <input type="password" name="password_confirm" id="passwordConfirm" class="form-control" required minlength="6">
                    </div>

                    <div class="step-actions">
                        <button type="submit" class="btn-primary-gradient" id="submitBtn">
                            <i class="bi bi-check-circle"></i> Зарегистрироваться
                        </button>
                        <button type="button" class="btn-outline" onclick="goToStep(2)"><i class="bi bi-arrow-left"></i></button>
                    </div>
                </form>
            </div>

            <div class="login-links">
                <a href="login.php"><i class="bi bi-box-arrow-in-right"></i> Уже есть аккаунт?</a>
            </div>
        </div>

    </div>
</div>

<script>
const CSRF = <?= json_encode(csrf_token()) ?>;

// Стейт
const state = {
    teacher: null,
    instituteId: '',
    instituteName: '',
    departmentId: '',
    departmentName: '',
    institutesLoaded: false,
};

// Элементы
const alertBox = document.getElementById('alertBox');
const lastNameInput = document.getElementById('lastName');
const searchBtn = document.getElementById('searchBtn');
const teachersList = document.getElementById('teachersList');

// ---------- Утилиты ----------
function showAlert(message, type = 'danger') {
    const icons = { danger: 'exclamation-triangle-fill', success: 'check-circle-fill', warning: 'info-circle-fill' };
    alertBox.innerHTML = `<div class="alert alert-${type} mb-3"><i class="bi bi-${icons[type] || 'info-circle-fill'} fs-5"></i><div>${message}</div></div>`;
}

function clearAlert() { alertBox.innerHTML = ''; }

function escapeHtml(s) {
    return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function goToStep(step) {
    clearAlert();

    document.getElementById('step1').classList.toggle('d-none', step !== 1);
    document.getElementById('step2').classList.toggle('d-none', step !== 2);
    document.getElementById('step3').classList.toggle('d-none', step !== 3);

    // Индикатор
    for (let i = 1; i <= 3; i++) {
        const dot = document.getElementById('stepDot' + i);
        dot.classList.remove('active', 'done');
        if (i < step) { dot.classList.add('done'); dot.textContent = '✓'; }
        else if (i === step) { dot.classList.add('active'); dot.textContent = i; }
        else { dot.textContent = i; }
    }
    document.getElementById('stepLine1').classList.toggle('done', step > 1);
    document.getElementById('stepLine2').classList.toggle('done', step > 2);

    if (step === 2) loadInstitutes();
}

// ---------- ШАГ 1: поиск преподавателя ----------
async function searchTeachers() {
    const lastName = lastNameInput.value.trim();
    if (lastName.length < 2) { showAlert('Введите минимум 2 символа'); return; }

    searchBtn.disabled = true;
    searchBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Поиск...';
    teachersList.classList.add('d-none');
    teachersList.innerHTML = '';
    clearAlert();

    try {
        const res = await fetch(`api/search_teacher.php?last_name=${encodeURIComponent(lastName)}`);
        const data = await res.json();

        if (data.error) {
            showAlert(data.error + (data.hint ? '<br><small>' + data.hint + '</small>' : ''));
            return;
        }

        if (!Array.isArray(data) || !data.length) {
            showAlert('Преподаватели не найдены', 'warning');
            return;
        }

        teachersList.innerHTML = '';
        data.forEach(t => {
            const item = document.createElement('div');
            item.className = 'teacher-item';
            item.innerHTML = `
                <div>
                    <div class="teacher-item-name">${escapeHtml(t.full_name)}</div>
                    <div class="teacher-item-meta">ID: ${escapeHtml(t.id)}</div>
                </div>
                <i class="bi bi-arrow-right"></i>
            `;
            item.addEventListener('click', () => selectTeacher(t));
            teachersList.appendChild(item);
        });
        teachersList.classList.remove('d-none');
    } catch (e) {
        showAlert('Ошибка запроса: ' + e.message);
    } finally {
        searchBtn.disabled = false;
        searchBtn.innerHTML = '<i class="bi bi-search"></i> Найти';
    }
}

function selectTeacher(t) {
    state.teacher = t;

    const html = `
        <i class="bi bi-person-check-fill"></i>
        <div>
            <div style="font-weight:600;">${escapeHtml(t.full_name)}</div>
            <div style="font-size:0.78rem;color:var(--ap-gray);">ID на портале: ${escapeHtml(t.id)}</div>
        </div>
    `;
    document.getElementById('selectedTeacherStep2').innerHTML = html;
    document.getElementById('selectedTeacherStep3').innerHTML = html;

    goToStep(2);
}

// ---------- ШАГ 2: институт + кафедра ----------
async function loadInstitutes() {
    if (state.institutesLoaded) return;

    const sel = document.getElementById('instituteSelect');
    sel.innerHTML = '<option value="">— Загрузка... —</option>';

    try {
        const res = await fetch('api/departments.php?type=institutes');
        const data = await res.json();

        if (data.error) { showAlert('Ошибка загрузки институтов: ' + data.error); return; }
        if (!Array.isArray(data)) { showAlert('Некорректный ответ API'); return; }

        // Сортируем по abbr/name
        data.sort((a, b) => (a.name || '').localeCompare(b.name || '', 'ru'));

        sel.innerHTML = '<option value="">— Выберите институт —</option>';
        data.forEach(inst => {
            const label = inst.abbr ? `${inst.abbr} — ${inst.name.trim()}` : inst.name.trim();
            const opt = document.createElement('option');
            opt.value = inst.id;
            opt.textContent = label;
            sel.appendChild(opt);
        });

        state.institutesLoaded = true;
    } catch (e) {
        showAlert('Ошибка сети: ' + e.message);
    }
}

async function loadDepartments(instituteId) {
    const block = document.getElementById('departmentBlock');
    const sel = document.getElementById('departmentSelect');

    if (!instituteId) {
        block.classList.add('d-none');
        state.departmentId = '';
        state.departmentName = '';
        checkStep2Complete();
        return;
    }

    block.classList.remove('d-none');
    sel.innerHTML = '<option value="">— Загрузка... —</option>';

    try {
        const res = await fetch('api/departments.php?type=departments&institute=' + encodeURIComponent(instituteId));
        const data = await res.json();

        if (data.error) { showAlert('Ошибка загрузки кафедр: ' + data.error); return; }
        if (!Array.isArray(data) || !data.length) {
            sel.innerHTML = '<option value="">— Кафедры не найдены —</option>';
            return;
        }

        data.sort((a, b) => (a.name || '').localeCompare(b.name || '', 'ru'));

        sel.innerHTML = '<option value="">— Выберите кафедру —</option>';
        data.forEach(dep => {
            const label = dep.abbr ? `${dep.abbr} — ${dep.name}` : dep.name;
            const opt = document.createElement('option');
            opt.value = dep.id;
            opt.textContent = label;
            sel.appendChild(opt);
        });
    } catch (e) {
        showAlert('Ошибка сети: ' + e.message);
    }
}

function checkStep2Complete() {
    const btn = document.getElementById('toStep3Btn');
    btn.disabled = !(state.instituteId && state.departmentId);
}

document.getElementById('instituteSelect').addEventListener('change', async (e) => {
    state.instituteId = e.target.value;
    state.instituteName = e.target.options[e.target.selectedIndex].textContent.trim();
    state.departmentId = '';
    state.departmentName = '';
    checkStep2Complete();
    await loadDepartments(state.instituteId);
});

document.getElementById('departmentSelect').addEventListener('change', (e) => {
    state.departmentId = e.target.value;
    state.departmentName = e.target.options[e.target.selectedIndex].textContent.trim();
    checkStep2Complete();
});

document.getElementById('toStep3Btn').addEventListener('click', () => {
    if (!state.instituteId || !state.departmentId) {
        showAlert('Выберите институт и кафедру');
        return;
    }
    goToStep(3);
});

// ---------- ШАГ 3: отправка формы ----------
document.getElementById('registerForm').addEventListener('submit', async (e) => {
    e.preventDefault();

    const pwd  = document.getElementById('password').value;
    const pwd2 = document.getElementById('passwordConfirm').value;
    if (pwd !== pwd2) { showAlert('Пароли не совпадают'); return; }

    if (!state.teacher || !state.instituteId || !state.departmentId) {
        showAlert('Заполните все шаги'); return;
    }

    const payload = {
        teacher_id:      state.teacher.id,
        full_name:       state.teacher.full_name,
        last_name:       state.teacher.last_name,
        first_name:      state.teacher.first_name,
        middle_name:     state.teacher.middle_name,
        institute_id:    state.instituteId,
        institute_name:  state.instituteName,
        department_id:   state.departmentId,
        department_name: state.departmentName,
        login:           document.getElementById('login').value.trim(),
        email:           document.getElementById('email').value.trim(),
        password:        pwd,
        password_confirm: pwd2,
        csrf:            CSRF,
    };

    const submitBtn = document.getElementById('submitBtn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Отправка...';

    try {
        const res = await fetch('api/register.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload),
        });
        const data = await res.json();

        if (data.success) {
            showAlert('Регистрация успешна! Перенаправление...', 'success');
            setTimeout(() => window.location.href = data.redirect, 800);
        } else {
            showAlert(data.error || 'Неизвестная ошибка');
        }
    } catch (e) {
        showAlert('Ошибка отправки: ' + e.message);
    } finally {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="bi bi-check-circle"></i> Зарегистрироваться';
    }
});

// ---------- Слушатели ----------
searchBtn.addEventListener('click', searchTeachers);
lastNameInput.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); searchTeachers(); } });
</script>

</body>
</html>