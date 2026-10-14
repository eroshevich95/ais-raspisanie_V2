<?php
// session.php

$pageTitle = 'Сессия';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/session_helpers.php';
require_once __DIR__ . '/includes/access_control.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/session_boot.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/csrf.php';

sendSecurityHeaders();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId   = (int)$_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'teacher';
$isAdmin  = in_array($userRole, ['head_of_department', 'administration', 'admin']);
$canSelectTeacher = $isAdmin;

$scope             = getAccessScope($pdo, $userId);
$allowedTeacherIds = getAllowedTeacherIds($pdo, $scope);
$allowedGroupIds   = getAllowedGroupIds($pdo, $scope, $allowedTeacherIds);
$scopeInfo         = scopeLabel($scope);

// AJAX: предметы преподавателя
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'teacher_disciplines') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_verify(true);

    $teacherId = (int)($_POST['teacher_id'] ?? 0);
    if ($allowedTeacherIds !== null && !in_array($teacherId, $allowedTeacherIds)) {
        echo json_encode(['ok' => false, 'error' => 'Нет доступа']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT DISTINCT discipline FROM lessons WHERE user_id = ? ORDER BY discipline");
        $stmt->execute([$teacherId]);
        echo json_encode(['ok' => true, 'disciplines' => $stmt->fetchAll(PDO::FETCH_COLUMN)]);
    } catch (Throwable $e) {
        error_log('teacher_disciplines error: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'Ошибка сервера']);
    }
    exit;
}

// AJAX: создание ведомости
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_sheet') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_verify(true);

    $groupId     = (int)($_POST['group_id'] ?? 0);
    $discipline  = trim($_POST['discipline'] ?? '');
    $sessionType = $_POST['session_type'] ?? 'zachet';
    $semester    = trim($_POST['semester'] ?? getCurrentSemester());
    $minAtt      = max(0, min(100, (int)($_POST['min_attendance_pct'] ?? 50)));
    $minWorks    = max(0, min(100, (int)($_POST['min_works_pct'] ?? 80)));
    $teacherId   = (int)($_POST['teacher_id'] ?? 0);

    $hoursTotal    = ($_POST['hours_total']    ?? '') !== '' ? (int)$_POST['hours_total']    : null;
    $hoursLecture  = ($_POST['hours_lecture']  ?? '') !== '' ? (int)$_POST['hours_lecture']  : null;
    $hoursPractice = ($_POST['hours_practice'] ?? '') !== '' ? (int)$_POST['hours_practice'] : null;
    $hoursLab      = ($_POST['hours_lab']      ?? '') !== '' ? (int)$_POST['hours_lab']      : null;

    if (!$groupId || $discipline === '') {
        echo json_encode(['ok' => false, 'error' => 'Заполните группу и предмет']);
        exit;
    }
    if (!in_array($sessionType, ['zachet', 'exam', 'diff_zachet'])) {
        echo json_encode(['ok' => false, 'error' => 'Некорректный тип']);
        exit;
    }
    if (!preg_match('/^\d{4}-\d{4}-[12]$/', $semester)) {
        echo json_encode(['ok' => false, 'error' => 'Некорректный семестр']);
        exit;
    }
    if ($allowedGroupIds !== null && !in_array($groupId, $allowedGroupIds)) {
        echo json_encode(['ok' => false, 'error' => 'Нет доступа к группе']);
        exit;
    }

    $ownerId = $userId;
    if ($canSelectTeacher) {
        if ($teacherId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Выберите преподавателя']);
            exit;
        }
        if ($allowedTeacherIds !== null && !in_array($teacherId, $allowedTeacherIds)) {
            echo json_encode(['ok' => false, 'error' => 'Нет доступа к преподавателю']);
            exit;
        }
        $ownerId = $teacherId;
    }

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM lessons WHERE user_id = ? AND discipline = ?");
        $stmt->execute([$ownerId, $discipline]);
        if ((int)$stmt->fetchColumn() === 0) {
            echo json_encode(['ok' => false, 'error' => 'У преподавателя нет такого предмета']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT id FROM session_sheets
            WHERE user_id = ? AND group_id = ? AND discipline = ?
              AND session_type = ? AND semester = ?
        ");
        $stmt->execute([$ownerId, $groupId, $discipline, $sessionType, $semester]);
        $existing = $stmt->fetchColumn();
        if ($existing) {
            echo json_encode(['ok' => false, 'error' => 'Такая ведомость уже существует', 'id' => (int)$existing]);
            exit;
        }

        $pdo->prepare("
            INSERT INTO session_sheets
                (user_id, group_id, discipline, session_type, semester,
                 hours_total, hours_lecture, hours_practice, hours_lab,
                 min_attendance_pct, min_works_pct)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $ownerId, $groupId, $discipline, $sessionType, $semester,
            $hoursTotal, $hoursLecture, $hoursPractice, $hoursLab,
            $minAtt, $minWorks
        ]);
        $id = (int)$pdo->lastInsertId();

        $grpStmt = $pdo->prepare("SELECT name FROM groups_table WHERE id = ?");
        $grpStmt->execute([$groupId]);
        $groupName = $grpStmt->fetchColumn() ?: '';

        $types = sessionTypes();
        $typeLabel = $types[$sessionType]['label'] ?? $sessionType;

        auditLog($pdo, 'session.create', [
            'entity_type' => 'session_sheet', 'entity_id' => $id,
            'description' => "Создана ведомость: {$discipline} · {$groupName} · {$typeLabel}",
            'new_value'   => semesterLabel($semester) . " · пос. {$minAtt}%, раб. {$minWorks}%",
        ]);

        echo json_encode(['ok' => true, 'id' => $id]);
    } catch (Throwable $e) {
        error_log('create_sheet error: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'Ошибка сервера']);
    }
    exit;
}

// AJAX: удаление
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_sheet') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_verify(true);

    $sheetId = (int)($_POST['sheet_id'] ?? 0);
    try {
        $stmt = $pdo->prepare("
            SELECT s.*, g.name AS group_name
            FROM session_sheets s
            LEFT JOIN groups_table g ON s.group_id = g.id
            WHERE s.id = ?
        ");
        $stmt->execute([$sheetId]);
        $sheetInfo = $stmt->fetch();

        if (!$sheetInfo) {
            echo json_encode(['ok' => false, 'error' => 'Ведомость не найдена']);
            exit;
        }
        if (!$isAdmin && (int)$sheetInfo['user_id'] !== $userId) {
            echo json_encode(['ok' => false, 'error' => 'Нет прав']);
            exit;
        }
        if ($allowedTeacherIds !== null && !in_array((int)$sheetInfo['user_id'], $allowedTeacherIds)) {
            echo json_encode(['ok' => false, 'error' => 'Нет доступа']);
            exit;
        }

        $pdo->prepare("DELETE FROM session_sheets WHERE id = ?")->execute([$sheetId]);

        $types = sessionTypes();
        $typeLabel = $types[$sheetInfo['session_type']]['label'] ?? $sheetInfo['session_type'];

        auditLog($pdo, 'session.delete', [
            'entity_type' => 'session_sheet', 'entity_id' => $sheetId,
            'description' => "Удалена ведомость: {$sheetInfo['discipline']} · " . ($sheetInfo['group_name'] ?? '') . " · {$typeLabel}",
        ]);

        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        error_log('delete_sheet error: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'Ошибка сервера']);
    }
    exit;
}

// Фильтры
$semester      = trim($_GET['semester'] ?? getCurrentSemester());
$groupId       = (int)($_GET['group_id'] ?? 0);
$discipline    = trim($_GET['discipline'] ?? '');
$sessionType   = $_GET['session_type'] ?? '';
$status        = $_GET['status'] ?? '';
$teacherFilter = (int)($_GET['teacher_id'] ?? 0);

if ($groupId > 0 && $allowedGroupIds !== null && !in_array($groupId, $allowedGroupIds)) $groupId = 0;
if ($teacherFilter > 0 && $allowedTeacherIds !== null && !in_array($teacherFilter, $allowedTeacherIds)) $teacherFilter = 0;

// Справочники
if ($allowedGroupIds === null) {
    $groups = $pdo->query("SELECT id, name FROM groups_table ORDER BY name")->fetchAll();
} elseif (empty($allowedGroupIds)) {
    $groups = [];
} else {
    $in = implode(',', array_fill(0, count($allowedGroupIds), '?'));
    $stmt = $pdo->prepare("SELECT id, name FROM groups_table WHERE id IN ($in) ORDER BY name");
    $stmt->execute($allowedGroupIds);
    $groups = $stmt->fetchAll();
}

$disciplines = [];
$discWhere = [];
$discParams = [];
$blockDisc = false;
if ($allowedTeacherIds !== null) {
    if (empty($allowedTeacherIds)) $blockDisc = true;
    else {
        $in = implode(',', array_fill(0, count($allowedTeacherIds), '?'));
        $discWhere[] = "user_id IN ($in)";
        foreach ($allowedTeacherIds as $t) $discParams[] = $t;
    }
}
if (!$blockDisc && $allowedGroupIds !== null) {
    if (empty($allowedGroupIds)) $blockDisc = true;
    else {
        $in = implode(',', array_fill(0, count($allowedGroupIds), '?'));
        $discWhere[] = "group_id IN ($in)";
        foreach ($allowedGroupIds as $g) $discParams[] = $g;
    }
}
if (!$blockDisc) {
    $sql = "SELECT DISTINCT discipline FROM lessons";
    if ($discWhere) $sql .= " WHERE " . implode(' AND ', $discWhere);
    $sql .= " ORDER BY discipline";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($discParams);
    $disciplines = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

$teachersForSelect = [];
if ($canSelectTeacher) {
    if ($userRole === 'head_of_department' && !empty($scope['department_name'])) {
        $stmt = $pdo->prepare("SELECT id, full_name, department_name FROM users WHERE is_active = 1 AND (department_name = ? OR department = ?) ORDER BY full_name");
        $stmt->execute([$scope['department_name'], $scope['department_name']]);
        $teachersForSelect = $stmt->fetchAll();
    } elseif ($userRole === 'administration' && !empty($scope['institute_name'])) {
        $stmt = $pdo->prepare("SELECT id, full_name, department_name FROM users WHERE is_active = 1 AND institute_name = ? ORDER BY full_name");
        $stmt->execute([$scope['institute_name']]);
        $teachersForSelect = $stmt->fetchAll();
    } else {
        $stmt = $pdo->query("SELECT id, full_name, department_name FROM users WHERE is_active = 1 ORDER BY full_name");
        $teachersForSelect = $stmt->fetchAll();
    }
}

// Список семестров
$semestersList = [getCurrentSemester()];
$fromDb = $pdo->query("SELECT DISTINCT semester FROM session_sheets")->fetchAll(PDO::FETCH_COLUMN);
foreach ($fromDb as $sem) if (!in_array($sem, $semestersList)) $semestersList[] = $sem;
usort($semestersList, fn($a, $b) => strcmp($b, $a));
if (!in_array($semester, $semestersList)) array_unshift($semestersList, $semester);

// Выборка ведомостей
$where = ["s.semester = ?"];
$params = [$semester];

if ($allowedTeacherIds !== null) {
    if (empty($allowedTeacherIds)) $where[] = "1 = 0";
    else {
        $in = implode(',', array_fill(0, count($allowedTeacherIds), '?'));
        $where[] = "s.user_id IN ($in)";
        foreach ($allowedTeacherIds as $t) $params[] = $t;
    }
}
if ($allowedGroupIds !== null) {
    if (empty($allowedGroupIds)) $where[] = "1 = 0";
    else {
        $in = implode(',', array_fill(0, count($allowedGroupIds), '?'));
        $where[] = "s.group_id IN ($in)";
        foreach ($allowedGroupIds as $g) $params[] = $g;
    }
}
if ($groupId > 0)       { $where[] = "s.group_id = ?";   $params[] = $groupId; }
if ($discipline !== '') { $where[] = "s.discipline = ?"; $params[] = $discipline; }
if ($teacherFilter > 0) { $where[] = "s.user_id = ?";    $params[] = $teacherFilter; }
if ($sessionType !== '' && in_array($sessionType, ['zachet','exam','diff_zachet'])) { $where[] = "s.session_type = ?"; $params[] = $sessionType; }
if ($status !== '' && in_array($status, ['open','closed'])) { $where[] = "s.status = ?"; $params[] = $status; }

$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT
        s.*,
        g.name AS group_name,
        u.full_name AS teacher_name,
        (SELECT COUNT(*) FROM students st WHERE st.group_id = s.group_id) AS students_cnt,
        (SELECT COUNT(*) FROM session_grades sg WHERE sg.sheet_id = s.id AND sg.grade IS NOT NULL) AS graded_cnt,
        (SELECT COUNT(*) FROM session_grades sg WHERE sg.sheet_id = s.id AND sg.is_allowed = 1) AS allowed_cnt,
        (SELECT COUNT(*) FROM session_grades sg WHERE sg.sheet_id = s.id AND sg.is_allowed = 0) AS not_allowed_cnt
    FROM session_sheets s
    LEFT JOIN groups_table g ON s.group_id = g.id
    LEFT JOIN users u ON s.user_id = u.id
    WHERE $whereSql
    ORDER BY s.status ASC, s.created_at DESC
");
$stmt->execute($params);
$sheets = $stmt->fetchAll();

$byDiscipline = [];
foreach ($sheets as $s) $byDiscipline[$s['discipline']][] = $s;

$types = sessionTypes();

require_once __DIR__ . '/includes/header.php';
?>

<style>
.ss-header { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
.ss-header h2 { font-size: 1.7rem; font-weight: 700; letter-spacing: -0.02em; margin: 0 0 6px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.ss-header p { color: #86868b; margin: 0; font-size: 0.95rem; }
.ss-scope-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; background: rgba(255,159,10,0.15); color: #b25c00; }

.ss-filter { background: #fff; border-radius: 20px; border: 1px solid #eaeaea; box-shadow: 0 4px 16px rgba(0,0,0,0.04); padding: 20px 24px; margin-bottom: 20px; }
.ss-filter-row { display: grid; grid-template-columns: 1.5fr 1.5fr 2fr 1.5fr 1fr auto; gap: 12px; align-items: end; }
.ss-filter-row--wide { grid-template-columns: 1.5fr 1.5fr 2fr 1.5fr 1.5fr 1fr auto; }
@media (max-width: 1200px) { .ss-filter-row, .ss-filter-row--wide { grid-template-columns: 1fr 1fr 1fr; } }
@media (max-width: 700px)  { .ss-filter-row, .ss-filter-row--wide { grid-template-columns: 1fr 1fr; } }
.ss-filter-label { font-size: 0.75rem; font-weight: 700; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.3px; }
.ss-filter-label i { color: #0071e3; margin-right: 4px; }
.ss-select { width: 100%; padding: 10px 14px; border-radius: 10px; border: 1.5px solid #e5e5ea; background: #fff; font-size: 0.88rem; font-family: inherit; outline: none; transition: all 0.2s; }
.ss-select:focus { border-color: #0071e3; box-shadow: 0 0 0 4px rgba(0,113,227,0.12); }
.ss-submit-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 10px; background: linear-gradient(135deg, #0071e3 0%, #5e5ce6 100%); color: #fff; border: none; font-size: 0.88rem; font-weight: 600; cursor: pointer; font-family: inherit; transition: all 0.25s; box-shadow: 0 6px 16px rgba(0,113,227,0.25); white-space: nowrap; }
.ss-submit-btn:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(0,113,227,0.4); }

.ss-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 20px; }
.ss-stat { background: #fff; border-radius: 18px; padding: 18px 20px; border: 1px solid #eaeaea; box-shadow: 0 4px 16px rgba(0,0,0,0.04); position: relative; overflow: hidden; }
.ss-stat::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: var(--ss-color, #0071e3); }
.ss-stat-icon { width: 36px; height: 36px; border-radius: 10px; background: var(--ss-bg, rgba(0,113,227,0.1)); color: var(--ss-color, #0071e3); display: flex; align-items: center; justify-content: center; font-size: 1.1rem; margin-bottom: 10px; }
.ss-stat-value { font-size: 1.8rem; font-weight: 700; line-height: 1; letter-spacing: -0.02em; margin-bottom: 6px; }
.ss-stat-label { font-size: 0.8rem; color: #86868b; font-weight: 500; }

.ss-group-card { background: #fff; border-radius: 20px; border: 1px solid #eaeaea; box-shadow: 0 4px 16px rgba(0,0,0,0.04); margin-bottom: 20px; overflow: hidden; }
.ss-group-header { padding: 16px 24px; background: linear-gradient(135deg, #fafafc 0%, #fff 100%); border-bottom: 1px solid #f0f0f2; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
.ss-group-title { font-size: 1rem; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 8px; }
.ss-group-title i { color: #0071e3; }
.ss-group-badge { font-size: 0.72rem; font-weight: 600; color: #86868b; background: #f5f5f7; padding: 4px 12px; border-radius: 20px; }

.ss-sheet-row { display: grid; grid-template-columns: 1fr auto auto auto auto; gap: 14px; padding: 16px 24px; border-bottom: 1px solid #f5f5f7; align-items: center; transition: all 0.2s; text-decoration: none; color: inherit; }
.ss-sheet-row:last-child { border-bottom: none; }
.ss-sheet-row:hover { background: #fafafc; color: inherit; }
.ss-sheet-row--closed { background: rgba(48,209,88,0.04); }
.ss-sheet-row--closed:hover { background: rgba(48,209,88,0.08); }
.ss-sheet-title { font-size: 0.95rem; font-weight: 700; color: #1d1d1f; margin-bottom: 4px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.ss-sheet-meta { font-size: 0.78rem; color: #86868b; display: flex; gap: 10px; flex-wrap: wrap; }
.ss-sheet-meta i { color: #0071e3; }
.ss-type { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 20px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; white-space: nowrap; }
.ss-type--zachet      { background: rgba(48,209,88,0.15); color: #198754; }
.ss-type--exam        { background: rgba(255,69,58,0.15); color: #842029; }
.ss-type--diff_zachet { background: rgba(255,159,10,0.15); color: #b25c00; }
.ss-status { display: inline-flex; align-items: center; gap: 5px; padding: 4px 12px; border-radius: 20px; font-size: 0.72rem; font-weight: 700; white-space: nowrap; }
.ss-status--open   { background: rgba(0,113,227,0.12); color: #0071e3; }
.ss-status--closed { background: rgba(48,209,88,0.15); color: #198754; }
.ss-numbers { display: flex; gap: 8px; align-items: center; }
.ss-num { display: inline-flex; align-items: center; gap: 4px; font-size: 0.78rem; font-weight: 700; padding: 4px 10px; border-radius: 20px; white-space: nowrap; }
.ss-num--yes { background: rgba(48,209,88,0.12); color: #198754; }
.ss-num--no  { background: rgba(255,69,58,0.12); color: #842029; }
.ss-num--graded { background: rgba(191,90,242,0.12); color: #bf5af2; }
.ss-num--total { background: rgba(0,113,227,0.1); color: #0071e3; }
.ss-progress { width: 100px; height: 6px; background: #f0f0f2; border-radius: 3px; overflow: hidden; flex-shrink: 0; }
.ss-progress-fill { height: 100%; background: linear-gradient(90deg, #0071e3, #5e5ce6); transition: width 0.4s; }
.ss-delete-btn { width: 36px; height: 36px; border-radius: 10px; border: 1.5px solid #e5e5ea; background: #fff; color: #86868b; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; flex-shrink: 0; }
.ss-delete-btn:hover { background: #ff453a; color: #fff; border-color: #ff453a; transform: translateY(-2px); }

.ss-empty { padding: 60px 24px; text-align: center; color: #86868b; background: #fff; border-radius: 20px; border: 1px solid #eaeaea; }
.ss-empty i { font-size: 3rem; opacity: 0.3; display: block; margin-bottom: 12px; }
.ss-empty h4 { font-size: 1.1rem; font-weight: 700; color: #1d1d1f; margin: 0 0 8px; }

.ss-create-btn { display: inline-flex; align-items: center; gap: 8px; padding: 11px 22px; border-radius: 12px; background: linear-gradient(135deg, #0071e3 0%, #5e5ce6 100%); color: #fff; border: none; font-size: 0.88rem; font-weight: 600; cursor: pointer; font-family: inherit; transition: all 0.25s; box-shadow: 0 6px 16px rgba(0,113,227,0.3); white-space: nowrap; text-decoration: none; }
.ss-create-btn:hover { color: #fff; transform: translateY(-2px); box-shadow: 0 12px 28px rgba(0,113,227,0.45); }

.ss-modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(10px); z-index: 10000; display: none; align-items: center; justify-content: center; padding: 24px; }
.ss-modal-backdrop.show { display: flex; }
.ss-modal { background: #fff; border-radius: 24px; width: 100%; max-width: 560px; max-height: 90vh; overflow-y: auto; box-shadow: 0 40px 100px rgba(0,0,0,0.4); animation: ssModalIn 0.4s cubic-bezier(0.16,1,0.3,1); }
@keyframes ssModalIn { from { opacity: 0; transform: scale(0.9) translateY(20px); } to { opacity: 1; transform: scale(1) translateY(0); } }
.ss-modal-header { padding: 20px 24px; border-bottom: 1px solid #f0f0f2; display: flex; justify-content: space-between; align-items: center; gap: 12px; background: linear-gradient(135deg, #fafafc 0%, #fff 100%); border-radius: 24px 24px 0 0; }
.ss-modal-header h5 { margin: 0; font-weight: 700; font-size: 1.1rem; display: flex; align-items: center; gap: 8px; }
.ss-modal-header h5 i { color: #0071e3; }
.ss-modal-close { width: 36px; height: 36px; border-radius: 50%; background: #f5f5f7; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; }
.ss-modal-close:hover { background: #0071e3; color: #fff; transform: rotate(90deg); }
.ss-modal-body { padding: 20px 24px; }
.ss-modal-footer { padding: 16px 24px; border-top: 1px solid #f0f0f2; display: flex; justify-content: flex-end; gap: 10px; background: #fafafc; border-radius: 0 0 24px 24px; }
.ss-form-group { margin-bottom: 16px; }
.ss-form-label { font-size: 0.8rem; font-weight: 700; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.3px; display: block; }
.ss-form-label i { color: #0071e3; margin-right: 4px; }
.ss-form-input, .ss-form-select { width: 100%; padding: 11px 14px; border-radius: 10px; border: 1.5px solid #e5e5ea; background: #fff; font-size: 0.9rem; font-family: inherit; outline: none; transition: all 0.2s; }
.ss-form-input:focus, .ss-form-select:focus { border-color: #0071e3; box-shadow: 0 0 0 4px rgba(0,113,227,0.12); }
.ss-form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.ss-form-hint { font-size: 0.75rem; color: #86868b; margin-top: 4px; }
.ss-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 10px; font-size: 0.88rem; font-weight: 600; cursor: pointer; font-family: inherit; transition: all 0.2s; border: none; white-space: nowrap; }
.ss-btn--primary { background: linear-gradient(135deg, #0071e3 0%, #5e5ce6 100%); color: #fff; box-shadow: 0 6px 16px rgba(0,113,227,0.25); }
.ss-btn--primary:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(0,113,227,0.4); }
.ss-btn--secondary { background: #f5f5f7; color: #495057; border: 1.5px solid #e5e5ea; }
.ss-btn--secondary:hover { background: #fff; border-color: #0071e3; color: #0071e3; }

[data-theme="dark"] .ss-filter, [data-theme="dark"] .ss-stat,
[data-theme="dark"] .ss-group-card, [data-theme="dark"] .ss-empty,
[data-theme="dark"] .ss-modal { background: #1c1c1e; border-color: #2c2c2e; }
[data-theme="dark"] .ss-header h2, [data-theme="dark"] .ss-filter-label,
[data-theme="dark"] .ss-stat-value, [data-theme="dark"] .ss-group-title,
[data-theme="dark"] .ss-sheet-title, [data-theme="dark"] .ss-empty h4 { color: #f5f5f7; }
[data-theme="dark"] .ss-select, [data-theme="dark"] .ss-form-input,
[data-theme="dark"] .ss-form-select { background: #2c2c2e; border-color: #3c3c3e; color: #f5f5f7; }
[data-theme="dark"] .ss-group-header, [data-theme="dark"] .ss-modal-header { background: linear-gradient(135deg, #2c2c2e 0%, #1c1c1e 100%); border-color: #3c3c3e; }
[data-theme="dark"] .ss-sheet-row { border-color: #2c2c2e; }
[data-theme="dark"] .ss-sheet-row:hover { background: #2c2c2e; color: inherit; }
[data-theme="dark"] .ss-sheet-row--closed { background: rgba(48,209,88,0.08); }
[data-theme="dark"] .ss-group-badge { background: #2c2c2e; color: #a1a1a6; }
[data-theme="dark"] .ss-modal-close { background: #2c2c2e; color: #f5f5f7; }
[data-theme="dark"] .ss-modal-footer { background: #141416; border-color: #2c2c2e; }
[data-theme="dark"] .ss-delete-btn { background: #2c2c2e; border-color: #3c3c3e; color: #a1a1a6; }
[data-theme="dark"] .ss-btn--secondary { background: #2c2c2e; color: #f5f5f7; border-color: #3c3c3e; }
</style>

<div class="ss-header">
    <div>
        <h2>
            <i class="bi bi-mortarboard-fill"></i>
            Сессия
            <span class="ss-scope-badge"><i class="bi <?= $scopeInfo[2] ?>"></i> <?= htmlspecialchars($scopeInfo[0]) ?></span>
        </h2>
        <p><i class="bi bi-info-circle"></i> Ведомости зачётов и экзаменов · <?= htmlspecialchars(semesterLabel($semester)) ?></p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <button class="ss-create-btn" onclick="openCreateModal()">
            <i class="bi bi-plus-circle-fill"></i> Создать ведомость
        </button>
        <a href="dashboard.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Дашборд</a>
    </div>
</div>

<div class="ss-filter">
    <form method="get">
        <div class="ss-filter-row <?= $canSelectTeacher ? 'ss-filter-row--wide' : '' ?>">
            <div>
                <div class="ss-filter-label"><i class="bi bi-calendar-range"></i> Семестр</div>
                <select name="semester" class="ss-select" onchange="this.form.submit()">
                    <?php foreach ($semestersList as $sem): ?>
                        <option value="<?= htmlspecialchars($sem) ?>" <?= $semester === $sem ? 'selected' : '' ?>><?= htmlspecialchars(semesterLabel($sem)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <div class="ss-filter-label"><i class="bi bi-people"></i> Группа</div>
                <select name="group_id" class="ss-select">
                    <option value="">— Все —</option>
                    <?php foreach ($groups as $g): ?>
                        <option value="<?= $g['id'] ?>" <?= $groupId == $g['id'] ? 'selected' : '' ?>><?= htmlspecialchars($g['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <div class="ss-filter-label"><i class="bi bi-book"></i> Предмет</div>
                <select name="discipline" class="ss-select">
                    <option value="">— Все —</option>
                    <?php foreach ($disciplines as $d): ?>
                        <option value="<?= htmlspecialchars($d) ?>" <?= $discipline === $d ? 'selected' : '' ?>><?= htmlspecialchars($d) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($canSelectTeacher): ?>
            <div>
                <div class="ss-filter-label"><i class="bi bi-person-badge"></i> Преподаватель</div>
                <select name="teacher_id" class="ss-select">
                    <option value="">— Все —</option>
                    <?php foreach ($teachersForSelect as $t): ?>
                        <option value="<?= (int)$t['id'] ?>" <?= $teacherFilter == $t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div>
                <div class="ss-filter-label"><i class="bi bi-tag"></i> Тип</div>
                <select name="session_type" class="ss-select">
                    <option value="">— Все —</option>
                    <?php foreach ($types as $key => $t): ?>
                        <option value="<?= $key ?>" <?= $sessionType === $key ? 'selected' : '' ?>><?= $t['label'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <div class="ss-filter-label"><i class="bi bi-circle-fill"></i> Статус</div>
                <select name="status" class="ss-select">
                    <option value="">— Все —</option>
                    <option value="open"   <?= $status === 'open'   ? 'selected' : '' ?>>Открыта</option>
                    <option value="closed" <?= $status === 'closed' ? 'selected' : '' ?>>Закрыта</option>
                </select>
            </div>
            <div>
                <button type="submit" class="ss-submit-btn"><i class="bi bi-search"></i> Показать</button>
            </div>
        </div>
    </form>
</div>

<?php
$totalSheets = count($sheets);
$openSheets = $closedSheets = 0;
$totalGraded = 0;
foreach ($sheets as $s) {
    if ($s['status'] === 'open') $openSheets++; else $closedSheets++;
    $totalGraded += (int)$s['graded_cnt'];
}
?>
<?php if ($totalSheets > 0): ?>
    <div class="ss-stats">
        <div class="ss-stat" style="--ss-color:#0071e3; --ss-bg:rgba(0,113,227,0.1);">
            <div class="ss-stat-icon"><i class="bi bi-file-earmark-text-fill"></i></div>
            <div class="ss-stat-value"><?= $totalSheets ?></div>
            <div class="ss-stat-label">Ведомостей</div>
        </div>
        <div class="ss-stat" style="--ss-color:#ff9f0a; --ss-bg:rgba(255,159,10,0.1);">
            <div class="ss-stat-icon"><i class="bi bi-unlock-fill"></i></div>
            <div class="ss-stat-value"><?= $openSheets ?></div>
            <div class="ss-stat-label">Открытых</div>
        </div>
        <div class="ss-stat" style="--ss-color:#30d158; --ss-bg:rgba(48,209,88,0.1);">
            <div class="ss-stat-icon"><i class="bi bi-lock-fill"></i></div>
            <div class="ss-stat-value"><?= $closedSheets ?></div>
            <div class="ss-stat-label">Закрытых</div>
        </div>
        <div class="ss-stat" style="--ss-color:#bf5af2; --ss-bg:rgba(191,90,242,0.1);">
            <div class="ss-stat-icon"><i class="bi bi-clipboard-check-fill"></i></div>
            <div class="ss-stat-value"><?= $totalGraded ?></div>
            <div class="ss-stat-label">Оценок выставлено</div>
        </div>
    </div>
<?php endif; ?>

<?php if (empty($sheets)): ?>
    <div class="ss-empty">
        <i class="bi bi-file-earmark-plus"></i>
        <h4>Ведомостей пока нет</h4>
        <p>Нажмите «Создать ведомость», чтобы добавить первую</p>
    </div>
<?php else: foreach ($byDiscipline as $disc => $list): ?>
    <div class="ss-group-card">
        <div class="ss-group-header">
            <h3 class="ss-group-title"><i class="bi bi-book"></i> <?= htmlspecialchars($disc) ?></h3>
            <span class="ss-group-badge"><?= count($list) ?> <?= count($list) === 1 ? 'ведомость' : 'ведомостей' ?></span>
        </div>

        <?php foreach ($list as $s):
            $t = $types[$s['session_type']] ?? $types['zachet'];
            $studentsCnt = (int)$s['students_cnt'];
            $gradedCnt   = (int)$s['graded_cnt'];
            $allowedCnt  = (int)$s['allowed_cnt'];
            $notAllowed  = (int)$s['not_allowed_cnt'];
            $progressPct = $studentsCnt > 0 ? round($gradedCnt / $studentsCnt * 100) : 0;
            $rowClass = 'ss-sheet-row' . ($s['status'] === 'closed' ? ' ss-sheet-row--closed' : '');
            $canDelete = $isAdmin
                || ($allowedTeacherIds !== null && in_array((int)$s['user_id'], $allowedTeacherIds))
                || ((int)$s['user_id'] === $userId);
        ?>
            <a href="session_sheet.php?id=<?= $s['id'] ?>" class="<?= $rowClass ?>">
                <div>
                    <div class="ss-sheet-title">
                        <span class="ss-type ss-type--<?= $s['session_type'] ?>"><i class="bi <?= $t['icon'] ?>"></i> <?= $t['label'] ?></span>
                        <span><?= htmlspecialchars($s['group_name']) ?></span>
                        <?php if (!empty($s['teacher_name'])): ?>
                            <span style="color:#86868b; font-weight:500; font-size:0.85rem;">· <?= htmlspecialchars($s['teacher_name']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="ss-sheet-meta">
                        <span><i class="bi bi-people-fill"></i> <?= $studentsCnt ?> студ.</span>
                        <span><i class="bi bi-sliders"></i> пос. ≥<?= $s['min_attendance_pct'] ?>%, раб. ≥<?= $s['min_works_pct'] ?>%</span>
                    </div>
                </div>
                <div class="ss-numbers">
                    <?php if ($allowedCnt > 0): ?><span class="ss-num ss-num--yes" title="Допущено"><i class="bi bi-check-circle-fill"></i> <?= $allowedCnt ?></span><?php endif; ?>
                    <?php if ($notAllowed > 0): ?><span class="ss-num ss-num--no" title="Не допущено"><i class="bi bi-x-circle-fill"></i> <?= $notAllowed ?></span><?php endif; ?>
                    <?php if ($gradedCnt > 0): ?>
                        <span class="ss-num ss-num--graded"><i class="bi bi-clipboard-check-fill"></i> <?= $gradedCnt ?>/<?= $studentsCnt ?></span>
                    <?php else: ?>
                        <span class="ss-num ss-num--total"><i class="bi bi-people-fill"></i> <?= $studentsCnt ?></span>
                    <?php endif; ?>
                </div>
                <div class="ss-progress" title="Заполнено: <?= $progressPct ?>%">
                    <div class="ss-progress-fill" style="width: <?= $progressPct ?>%;"></div>
                </div>
                <span class="ss-status ss-status--<?= $s['status'] ?>">
                    <?php if ($s['status'] === 'closed'): ?><i class="bi bi-lock-fill"></i> Закрыта
                    <?php else: ?><i class="bi bi-unlock-fill"></i> Открыта<?php endif; ?>
                </span>
                <?php if ($canDelete): ?>
                    <button type="button" class="ss-delete-btn"
                            onclick="event.preventDefault();event.stopPropagation();deleteSheet(<?= $s['id'] ?>, '<?= htmlspecialchars(addslashes($disc)) ?>', '<?= htmlspecialchars(addslashes($s['group_name'])) ?>')"
                            title="Удалить">
                        <i class="bi bi-trash"></i>
                    </button>
                <?php else: ?>
                    <span style="width:36px;"></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
<?php endforeach; endif; ?>

<!-- Модалка создания -->
<div class="ss-modal-backdrop" id="createModal">
    <div class="ss-modal">
        <div class="ss-modal-header">
            <h5><i class="bi bi-plus-circle-fill"></i> Создать ведомость</h5>
            <button class="ss-modal-close" onclick="closeCreateModal()"><i class="bi bi-x-lg"></i></button>
        </div>

        <form id="createForm" onsubmit="submitCreate(event)">
            <div class="ss-modal-body">
                <?php if ($canSelectTeacher): ?>
                <div class="ss-form-group">
                    <label class="ss-form-label"><i class="bi bi-person-badge"></i> Преподаватель *</label>
                    <select name="teacher_id" id="createTeacherSelect" class="ss-form-select" required onchange="onTeacherChange(this.value)">
                        <option value="">— Выберите —</option>
                        <?php foreach ($teachersForSelect as $t): ?>
                            <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['full_name']) ?><?= !empty($t['department_name']) ? ' · ' . htmlspecialchars($t['department_name']) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="ss-form-group">
                    <label class="ss-form-label"><i class="bi bi-calendar-range"></i> Семестр *</label>
                    <select name="semester" class="ss-form-select" required>
                        <?php foreach ($semestersList as $sem): ?>
                            <option value="<?= htmlspecialchars($sem) ?>" <?= $semester === $sem ? 'selected' : '' ?>><?= htmlspecialchars(semesterLabel($sem)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="ss-form-group">
                    <label class="ss-form-label"><i class="bi bi-book"></i> Предмет *</label>
                    <select name="discipline" id="createDisciplineSelect" class="ss-form-select" required <?= $canSelectTeacher ? 'disabled' : '' ?>>
                        <option value=""><?= $canSelectTeacher ? '— Сначала выберите преподавателя —' : '— Выберите —' ?></option>
                        <?php if (!$canSelectTeacher): foreach ($disciplines as $d): ?>
                            <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></option>
                        <?php endforeach; endif; ?>
                    </select>
                </div>

                <div class="ss-form-group">
                    <label class="ss-form-label"><i class="bi bi-people"></i> Группа *</label>
                    <select name="group_id" class="ss-form-select" required>
                        <option value="">— Выберите —</option>
                        <?php foreach ($groups as $g): ?>
                            <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="ss-form-group">
                    <label class="ss-form-label"><i class="bi bi-tag"></i> Тип сессии *</label>
                    <select name="session_type" class="ss-form-select" required>
                        <?php foreach ($types as $key => $t): ?>
                            <option value="<?= $key ?>"><?= $t['label'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="ss-form-row">
                    <div class="ss-form-group">
                        <label class="ss-form-label"><i class="bi bi-sliders"></i> Мин. посещаемость</label>
                        <input type="number" name="min_attendance_pct" class="ss-form-input" value="50" min="0" max="100">
                    </div>
                    <div class="ss-form-group">
                        <label class="ss-form-label"><i class="bi bi-sliders"></i> Мин. работ</label>
                        <input type="number" name="min_works_pct" class="ss-form-input" value="80" min="0" max="100">
                    </div>
                </div>
            </div>
            <div class="ss-modal-footer">
                <button type="button" class="ss-btn ss-btn--secondary" onclick="closeCreateModal()">Отмена</button>
                <button type="submit" class="ss-btn ss-btn--primary"><i class="bi bi-check-circle-fill"></i> Создать</button>
            </div>
        </form>
    </div>
</div>

<script>
const CSRF = <?= json_encode(csrf_token()) ?>;

async function onTeacherChange(teacherId) {
    const discSel = document.getElementById('createDisciplineSelect');
    if (!discSel) return;
    discSel.innerHTML = '<option value="">— Загрузка... —</option>';
    discSel.disabled = true;
    if (!teacherId) { discSel.innerHTML = '<option value="">— Сначала выберите преподавателя —</option>'; return; }

    try {
        const fd = new FormData();
        fd.append('action', 'teacher_disciplines');
        fd.append('teacher_id', teacherId);
        fd.append('csrf', CSRF);
        const res = await fetch('session.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.ok) { discSel.innerHTML = '<option value="">— Ошибка —</option>'; return; }
        if (!data.disciplines.length) { discSel.innerHTML = '<option value="">— У преподавателя нет предметов —</option>'; return; }
        discSel.innerHTML = '<option value="">— Выберите предмет —</option>' + data.disciplines.map(d => `<option value="${escapeHtml(d)}">${escapeHtml(d)}</option>`).join('');
        discSel.disabled = false;
    } catch (e) { discSel.innerHTML = '<option value="">— Ошибка сети —</option>'; }
}

function escapeHtml(s) {
    return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function openCreateModal() {
    document.getElementById('createModal').classList.add('show');
    document.body.style.overflow = 'hidden';
    <?php if ($canSelectTeacher): ?>
    const discSel = document.getElementById('createDisciplineSelect');
    if (discSel) { discSel.innerHTML = '<option value="">— Сначала выберите преподавателя —</option>'; discSel.disabled = true; }
    const tSel = document.getElementById('createTeacherSelect');
    if (tSel) tSel.value = '';
    <?php endif; ?>
}
function closeCreateModal() {
    document.getElementById('createModal').classList.remove('show');
    document.body.style.overflow = '';
}
document.getElementById('createModal').addEventListener('click', e => { if (e.target.id === 'createModal') closeCreateModal(); });

function submitCreate(e) {
    e.preventDefault();
    const form = e.target;
    const fd = new FormData(form);
    fd.append('action', 'create_sheet');
    fd.append('csrf', CSRF);
    const btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;
    fetch('session.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            if (!data.ok) { alert('Ошибка: ' + (data.error || 'не удалось')); return; }
            window.location.href = 'session_sheet.php?id=' + data.id;
        })
        .catch(() => { btn.disabled = false; alert('Ошибка сети'); });
}

function deleteSheet(id, discipline, groupName) {
    if (!confirm('Удалить ведомость?\n\n' + discipline + ' · ' + groupName + '\n\nВсе оценки будут потеряны.')) return;
    const fd = new FormData();
    fd.append('action', 'delete_sheet');
    fd.append('sheet_id', id);
    fd.append('csrf', CSRF);
    fetch('session.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.ok) { alert('Ошибка: ' + (data.error || 'не удалось')); return; }
            location.reload();
        })
        .catch(() => alert('Ошибка сети'));
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>