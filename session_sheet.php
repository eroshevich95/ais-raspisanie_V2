<?php
// session_sheet.php

$pageTitle = 'Ведомость';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/session_helpers.php';
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

// AJAX: сохранение поля
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_grade') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_verify(true);

    $sheetId   = (int)($_POST['sheet_id'] ?? 0);
    $studentId = (int)($_POST['student_id'] ?? 0);
    $field     = $_POST['field'] ?? '';
    $value     = $_POST['value'] ?? '';

    if (!$sheetId || !$studentId) { echo json_encode(['ok' => false, 'error' => 'Нет данных']); exit; }

    $stmt = $pdo->prepare("SELECT s.*, g.name AS group_name FROM session_sheets s LEFT JOIN groups_table g ON s.group_id = g.id WHERE s.id = ?");
    $stmt->execute([$sheetId]);
    $sheet = $stmt->fetch();
    if (!$sheet) { echo json_encode(['ok' => false, 'error' => 'Ведомость не найдена']); exit; }

    $stmt = $pdo->prepare("SELECT full_name FROM students WHERE id = ?");
    $stmt->execute([$studentId]);
    $studentName = $stmt->fetchColumn() ?: ('Студент #' . $studentId);

    if (!$isAdmin && (int)$sheet['user_id'] !== $userId) { echo json_encode(['ok' => false, 'error' => 'Нет прав']); exit; }
    if ($sheet['status'] === 'closed') { echo json_encode(['ok' => false, 'error' => 'Ведомость закрыта']); exit; }

    try {
        $pdo->prepare("INSERT IGNORE INTO session_grades (sheet_id, student_id) VALUES (?, ?)")->execute([$sheetId, $studentId]);

        $stmt = $pdo->prepare("SELECT * FROM session_grades WHERE sheet_id = ? AND student_id = ?");
        $stmt->execute([$sheetId, $studentId]);
        $oldGrade = $stmt->fetch();

        $oldVal = $newVal = null;
        $action = 'session.grade';
        $sheetInfo = $sheet['discipline'] . ' · ' . ($sheet['group_name'] ?? '');

        switch ($field) {
            case 'is_allowed':
                $v = $value === '' ? null : (int)$value;
                if ($v !== null && !in_array($v, [0, 1])) { echo json_encode(['ok' => false, 'error' => 'Неверное значение']); exit; }
                $oldVal = $oldGrade['is_allowed'] === null ? 'авто' : ((int)$oldGrade['is_allowed'] === 1 ? 'допущен' : 'не допущен');
                $newVal = $v === null ? 'авто' : ($v === 1 ? 'допущен' : 'не допущен');
                $pdo->prepare("UPDATE session_grades SET is_allowed = ? WHERE sheet_id = ? AND student_id = ?")->execute([$v, $sheetId, $studentId]);
                $description = "{$sheetInfo} — {$studentName} · вердикт";
                $action = 'session.allow';
                break;

            case 'grade':
                $allowed = allowedGrades($sheet['session_type']);
                if ($value !== '' && !isset($allowed[$value])) { echo json_encode(['ok' => false, 'error' => 'Недопустимая оценка']); exit; }
                $v = $value === '' ? null : $value;
                $oldVal = $oldGrade['grade'] ?: '— не выставлена';
                $newVal = $v ?: '— снята';
                $date = $v !== null ? date('Y-m-d') : null;
                $pdo->prepare("UPDATE session_grades SET grade = ?, grade_date = ? WHERE sheet_id = ? AND student_id = ?")->execute([$v, $date, $sheetId, $studentId]);
                $description = "{$sheetInfo} — {$studentName} · оценка";
                break;

            case 'comment':
                $v = mb_substr(trim($value), 0, 2000);
                $v = $v === '' ? null : $v;
                $oldVal = $oldGrade['comment'] ?: '— нет';
                $newVal = $v ?: '— удалён';
                $pdo->prepare("UPDATE session_grades SET comment = ? WHERE sheet_id = ? AND student_id = ?")->execute([$v, $sheetId, $studentId]);
                $description = "{$sheetInfo} — {$studentName} · комментарий";
                $action = 'session.comment';
                break;

            default:
                echo json_encode(['ok' => false, 'error' => 'Неизвестное поле']); exit;
        }

        if ($oldVal !== $newVal) {
            auditLog($pdo, $action, [
                'entity_type' => 'session_sheet', 'entity_id' => $sheetId,
                'description' => $description,
                'old_value'   => is_string($oldVal) ? mb_substr($oldVal, 0, 200) : $oldVal,
                'new_value'   => is_string($newVal) ? mb_substr($newVal, 0, 200) : $newVal,
            ]);
        }

        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        error_log('save_grade error: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'Ошибка сервера']);
    }
    exit;
}

// AJAX: массовое действие
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_action') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_verify(true);

    $sheetId = (int)($_POST['sheet_id'] ?? 0);
    $bulk    = $_POST['bulk'] ?? '';
    $value   = $_POST['value'] ?? '';

    $stmt = $pdo->prepare("SELECT s.*, g.name AS group_name FROM session_sheets s LEFT JOIN groups_table g ON s.group_id = g.id WHERE s.id = ?");
    $stmt->execute([$sheetId]);
    $sheet = $stmt->fetch();
    if (!$sheet) { echo json_encode(['ok' => false, 'error' => 'Не найдено']); exit; }
    if (!$isAdmin && (int)$sheet['user_id'] !== $userId) { echo json_encode(['ok' => false, 'error' => 'Нет прав']); exit; }
    if ($sheet['status'] === 'closed') { echo json_encode(['ok' => false, 'error' => 'Ведомость закрыта']); exit; }

    try {
        $stmt = $pdo->prepare("SELECT id FROM students WHERE group_id = ?");
        $stmt->execute([$sheet['group_id']]);
        $studentIds = array_column($stmt->fetchAll(), 'id');
        if (empty($studentIds)) { echo json_encode(['ok' => false, 'error' => 'Нет студентов']); exit; }

        $ins = $pdo->prepare("INSERT IGNORE INTO session_grades (sheet_id, student_id) VALUES (?, ?)");
        foreach ($studentIds as $sid) $ins->execute([$sheetId, $sid]);

        $in = implode(',', array_fill(0, count($studentIds), '?'));
        $sheetInfo = $sheet['discipline'] . ' · ' . ($sheet['group_name'] ?? '');
        $description = $newVal = $oldVal = null;

        switch ($bulk) {
            case 'allow_all':
                $pdo->prepare("UPDATE session_grades SET is_allowed = 1 WHERE sheet_id = ? AND student_id IN ($in)")->execute(array_merge([$sheetId], $studentIds));
                $description = "{$sheetInfo} · все допущены (массово)";
                $newVal = 'все допущены';
                break;
            case 'reset_allow':
                $pdo->prepare("UPDATE session_grades SET is_allowed = NULL WHERE sheet_id = ? AND student_id IN ($in)")->execute(array_merge([$sheetId], $studentIds));
                $description = "{$sheetInfo} · сброс вердикта";
                $newVal = 'вердикт = авто';
                break;
            case 'grade_all':
                $allowed = allowedGrades($sheet['session_type']);
                if (!isset($allowed[$value])) { echo json_encode(['ok' => false, 'error' => 'Недопустимая оценка']); exit; }
                $pdo->prepare("UPDATE session_grades SET grade = ?, grade_date = CURDATE() WHERE sheet_id = ? AND student_id IN ($in)")->execute(array_merge([$value, $sheetId], $studentIds));
                $description = "{$sheetInfo} · массовая оценка: «{$allowed[$value]}»";
                $newVal = 'все: ' . $allowed[$value];
                break;
            case 'reset_grades':
                $pdo->prepare("UPDATE session_grades SET grade = NULL, grade_date = NULL WHERE sheet_id = ? AND student_id IN ($in)")->execute(array_merge([$sheetId], $studentIds));
                $description = "{$sheetInfo} · сброшены все оценки";
                $oldVal = 'были оценки'; $newVal = 'нет оценок';
                break;
            default:
                echo json_encode(['ok' => false, 'error' => 'Неизвестное действие']); exit;
        }

        auditLog($pdo, 'session.bulk', [
            'entity_type' => 'session_sheet', 'entity_id' => $sheetId,
            'description' => $description, 'old_value' => $oldVal, 'new_value' => $newVal,
        ]);
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        error_log('bulk_action error: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'Ошибка сервера']);
    }
    exit;
}

// AJAX: закрытие/открытие
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_status') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_verify(true);

    $sheetId = (int)($_POST['sheet_id'] ?? 0);
    $newStatus = $_POST['status'] ?? '';
    if (!in_array($newStatus, ['open','closed'])) { echo json_encode(['ok' => false, 'error' => 'Неверный статус']); exit; }

    $stmt = $pdo->prepare("SELECT s.*, g.name AS group_name FROM session_sheets s LEFT JOIN groups_table g ON s.group_id = g.id WHERE s.id = ?");
    $stmt->execute([$sheetId]);
    $sheet = $stmt->fetch();
    if (!$sheet) { echo json_encode(['ok' => false, 'error' => 'Не найдено']); exit; }
    if (!$isAdmin && (int)$sheet['user_id'] !== $userId) { echo json_encode(['ok' => false, 'error' => 'Нет прав']); exit; }

    try {
        if ($newStatus === 'closed') {
            $pdo->prepare("UPDATE session_sheets SET status = 'closed', closed_at = NOW() WHERE id = ?")->execute([$sheetId]);
            auditLog($pdo, 'session.close', [
                'entity_type' => 'session_sheet', 'entity_id' => $sheetId,
                'description' => "{$sheet['discipline']} · " . ($sheet['group_name'] ?? '') . " · ведомость закрыта",
                'old_value' => 'открыта', 'new_value' => 'закрыта',
            ]);
        } else {
            $pdo->prepare("UPDATE session_sheets SET status = 'open', closed_at = NULL WHERE id = ?")->execute([$sheetId]);
            auditLog($pdo, 'session.reopen', [
                'entity_type' => 'session_sheet', 'entity_id' => $sheetId,
                'description' => "{$sheet['discipline']} · " . ($sheet['group_name'] ?? '') . " · ведомость открыта заново",
                'old_value' => 'закрыта', 'new_value' => 'открыта',
            ]);
        }
        echo json_encode(['ok' => true, 'status' => $newStatus]);
    } catch (Throwable $e) {
        error_log('toggle_status error: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'Ошибка сервера']);
    }
    exit;
}

// Загрузка
$sheetId = (int)($_GET['id'] ?? 0);
if (!$sheetId) { header('Location: session.php'); exit; }

$stmt = $pdo->prepare("
    SELECT s.*, g.name AS group_name, u.full_name AS teacher_name
    FROM session_sheets s
    LEFT JOIN groups_table g ON s.group_id = g.id
    LEFT JOIN users u ON s.user_id = u.id
    WHERE s.id = ?
");
$stmt->execute([$sheetId]);
$sheet = $stmt->fetch();

if (!$sheet) {
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="alert alert-danger">Ведомость не найдена</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$canEdit = $isAdmin || (int)$sheet['user_id'] === $userId;
$isClosed = $sheet['status'] === 'closed';

$stmt = $pdo->prepare("SELECT id, full_name, zachetka, subgroup FROM students WHERE group_id = ? ORDER BY full_name");
$stmt->execute([$sheet['group_id']]);
$students = $stmt->fetchAll();

[$from, $to] = semesterDateRange($sheet['semester']);

$stmt = $pdo->prepare("SELECT * FROM session_grades WHERE sheet_id = ?");
$stmt->execute([$sheetId]);
$existingGrades = [];
foreach ($stmt->fetchAll() as $g) $existingGrades[$g['student_id']] = $g;

$rows = [];
foreach ($students as $s) {
    $stats = calcStudentSessionStats($pdo, (int)$s['id'], (int)$sheet['group_id'], $sheet['discipline'], (int)$sheet['user_id'], $from, $to);
    $auto = autoVerdict($stats['attendance_pct'], $stats['works_pct'], (int)$sheet['min_attendance_pct'], (int)$sheet['min_works_pct']);
    $saved = $existingGrades[$s['id']] ?? null;
    $rows[] = [
        'student'      => $s,
        'stats'        => $stats,
        'auto_allowed' => $auto,
        'is_allowed'   => $saved['is_allowed'] ?? null,
        'grade'        => $saved['grade'] ?? null,
        'grade_date'   => $saved['grade_date'] ?? null,
        'comment'      => $saved['comment'] ?? null,
    ];
}

$totalStudents = count($rows);
$allowedCnt = $notAllowedCnt = $gradedCnt = 0;
foreach ($rows as $r) {
    $eff = $r['is_allowed'] !== null ? (int)$r['is_allowed'] : $r['auto_allowed'];
    if ($eff === 1) $allowedCnt++;
    elseif ($eff === 0) $notAllowedCnt++;
    if (!empty($r['grade'])) $gradedCnt++;
}

$types      = sessionTypes();
$type       = $types[$sheet['session_type']] ?? $types['zachet'];
$gradesList = allowedGrades($sheet['session_type']);

require_once __DIR__ . '/includes/header.php';
?>

<style>
.sh-header { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
.sh-back { display: inline-flex; align-items: center; gap: 6px; color: #86868b; font-size: 0.85rem; font-weight: 600; text-decoration: none; margin-bottom: 8px; }
.sh-back:hover { color: #0071e3; }
.sh-header h2 { font-size: 1.7rem; font-weight: 700; letter-spacing: -0.02em; margin: 0 0 6px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.sh-header-meta { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; color: #86868b; font-size: 0.88rem; }
.sh-header-meta i { color: #0071e3; }
.sh-type { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 20px; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; }
.sh-type--zachet      { background: rgba(48,209,88,0.15); color: #198754; }
.sh-type--exam        { background: rgba(255,69,58,0.15); color: #842029; }
.sh-type--diff_zachet { background: rgba(255,159,10,0.15); color: #b25c00; }
.sh-status { display: inline-flex; align-items: center; gap: 5px; padding: 4px 12px; border-radius: 20px; font-size: 0.72rem; font-weight: 700; }
.sh-status--open   { background: rgba(0,113,227,0.12); color: #0071e3; }
.sh-status--closed { background: rgba(48,209,88,0.15); color: #198754; }
.sh-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.sh-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 12px; font-size: 0.85rem; font-weight: 600; cursor: pointer; font-family: inherit; transition: all 0.2s; border: none; text-decoration: none; white-space: nowrap; }
.sh-btn--primary { background: linear-gradient(135deg, #0071e3 0%, #5e5ce6 100%); color: #fff; box-shadow: 0 6px 16px rgba(0,113,227,0.3); }
.sh-btn--primary:hover { color: #fff; transform: translateY(-2px); }
.sh-btn--success { background: linear-gradient(135deg, #30d158 0%, #198754 100%); color: #fff; box-shadow: 0 6px 16px rgba(48,209,88,0.3); }
.sh-btn--success:hover { color: #fff; transform: translateY(-2px); }
.sh-btn--warning { background: linear-gradient(135deg, #ff9f0a 0%, #b25c00 100%); color: #fff; box-shadow: 0 6px 16px rgba(255,159,10,0.3); }
.sh-btn--warning:hover { color: #fff; transform: translateY(-2px); }
.sh-btn--secondary { background: #f5f5f7; color: #495057; border: 1.5px solid #e5e5ea; }
.sh-btn--secondary:hover { background: #fff; border-color: #0071e3; color: #0071e3; }

.sh-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 14px; margin-bottom: 20px; }
.sh-stat { background: #fff; border-radius: 18px; padding: 18px 20px; border: 1px solid #eaeaea; box-shadow: 0 4px 16px rgba(0,0,0,0.04); position: relative; overflow: hidden; }
.sh-stat::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: var(--sh-color, #0071e3); }
.sh-stat-icon { width: 36px; height: 36px; border-radius: 10px; background: var(--sh-bg, rgba(0,113,227,0.1)); color: var(--sh-color, #0071e3); display: flex; align-items: center; justify-content: center; font-size: 1.1rem; margin-bottom: 10px; }
.sh-stat-value { font-size: 1.8rem; font-weight: 700; line-height: 1; letter-spacing: -0.02em; margin-bottom: 6px; }
.sh-stat-label { font-size: 0.8rem; color: #86868b; font-weight: 500; }

.sh-info { padding: 14px 20px; background: rgba(0,113,227,0.06); border: 1px solid rgba(0,113,227,0.15); border-radius: 14px; font-size: 0.85rem; color: #084298; margin-bottom: 20px; display: flex; align-items: flex-start; gap: 10px; line-height: 1.5; }

.sh-bulk { background: #fff; border-radius: 16px; border: 1px solid #eaeaea; box-shadow: 0 4px 16px rgba(0,0,0,0.04); padding: 14px 20px; margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
.sh-bulk-label { font-size: 0.78rem; font-weight: 700; text-transform: uppercase; display: flex; align-items: center; gap: 6px; margin-right: 8px; }
.sh-bulk-label i { color: #0071e3; }

.sh-table-wrap { background: #fff; border-radius: 20px; border: 1px solid #eaeaea; box-shadow: 0 4px 16px rgba(0,0,0,0.04); overflow-x: auto; }
.sh-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.sh-table thead th { background: #fafafc; padding: 12px 14px; text-align: left; font-size: 0.68rem; font-weight: 700; text-transform: uppercase; color: #86868b; border-bottom: 1px solid #f0f0f2; white-space: nowrap; position: sticky; top: 0; z-index: 1; }
.sh-table thead th.center { text-align: center; }
.sh-table tbody td { padding: 10px 14px; border-bottom: 1px solid #f5f5f7; vertical-align: middle; }
.sh-table tbody tr:last-child td { border-bottom: none; }
.sh-table tbody tr:hover { background: #fafafc; }
.sh-table tbody tr.sh-row-not-allowed { background: rgba(255,69,58,0.05); }
.sh-table .num { text-align: center; }
.sh-table .student-name { font-weight: 600; font-size: 0.88rem; }
.sh-table code { background: rgba(0,113,227,0.08); color: #0071e3; padding: 2px 8px; border-radius: 6px; font-size: 0.72rem; font-family: "SF Mono", Monaco, monospace; }

.sh-progress { display: inline-flex; align-items: center; gap: 6px; min-width: 80px; }
.sh-progress-bar { flex: 1; height: 6px; background: #f0f0f2; border-radius: 3px; overflow: hidden; min-width: 40px; }
.sh-progress-fill { height: 100%; border-radius: 3px; transition: width 0.3s; }
.sh-progress-fill--success { background: #30d158; }
.sh-progress-fill--warning { background: #ff9f0a; }
.sh-progress-fill--danger  { background: #ff453a; }
.sh-progress-text { font-size: 0.72rem; font-weight: 700; min-width: 34px; text-align: right; }

.sh-sg { display: inline-flex; align-items: center; justify-content: center; min-width: 22px; height: 22px; border-radius: 6px; font-weight: 700; font-size: 0.72rem; padding: 0 6px; }
.sh-sg--1 { background: rgba(0,113,227,0.15); color: #0071e3; }
.sh-sg--2 { background: rgba(191,90,242,0.15); color: #bf5af2; }

.sh-allow-select, .sh-grade-select, .sh-comment-input { padding: 6px 10px; border-radius: 8px; border: 1.5px solid #e5e5ea; background: #fff; font-size: 0.8rem; font-family: inherit; outline: none; transition: all 0.2s; width: 100%; }
.sh-allow-select, .sh-grade-select { cursor: pointer; font-weight: 600; min-width: 130px; }
.sh-comment-input { min-width: 140px; }
.sh-allow-select:focus, .sh-grade-select:focus, .sh-comment-input:focus { border-color: #0071e3; box-shadow: 0 0 0 4px rgba(0,113,227,0.12); }
.sh-allow-select--yes { background: rgba(48,209,88,0.1); color: #198754; border-color: rgba(48,209,88,0.3); }
.sh-allow-select--no  { background: rgba(255,69,58,0.1); color: #842029; border-color: rgba(255,69,58,0.3); }
.sh-allow-select--auto-yes { background: rgba(48,209,88,0.05); color: #198754; border-color: rgba(48,209,88,0.2); font-style: italic; }
.sh-allow-select--auto-no  { background: rgba(255,69,58,0.05); color: #842029; border-color: rgba(255,69,58,0.2); font-style: italic; }
.sh-grade-select--5 { background: rgba(48,209,88,0.1); color: #198754; border-color: rgba(48,209,88,0.3); }
.sh-grade-select--4 { background: rgba(0,113,227,0.1); color: #0071e3; border-color: rgba(0,113,227,0.3); }
.sh-grade-select--3 { background: rgba(255,159,10,0.1); color: #b25c00; border-color: rgba(255,159,10,0.3); }
.sh-grade-select--2 { background: rgba(255,69,58,0.1); color: #842029; border-color: rgba(255,69,58,0.3); }

.sh-legend { display: flex; gap: 16px; flex-wrap: wrap; padding: 12px 20px; background: #fafafc; border-top: 1px solid #f0f0f2; font-size: 0.78rem; color: #86868b; }
.sh-legend-item { display: inline-flex; align-items: center; gap: 6px; }
.sh-legend-dot { width: 10px; height: 10px; border-radius: 50%; }

[data-theme="dark"] .sh-stat, [data-theme="dark"] .sh-bulk,
[data-theme="dark"] .sh-table-wrap { background: #1c1c1e; border-color: #2c2c2e; }
[data-theme="dark"] .sh-header h2, [data-theme="dark"] .sh-stat-value,
[data-theme="dark"] .sh-bulk-label, [data-theme="dark"] .sh-table .student-name { color: #f5f5f7; }
[data-theme="dark"] .sh-table thead th { background: #2c2c2e; border-color: #3c3c3e; color: #a1a1a6; }
[data-theme="dark"] .sh-table tbody td { border-color: #2c2c2e; }
[data-theme="dark"] .sh-table tbody tr:hover { background: #2c2c2e; }
[data-theme="dark"] .sh-progress-bar { background: #3c3c3e; }
[data-theme="dark"] .sh-allow-select, [data-theme="dark"] .sh-grade-select,
[data-theme="dark"] .sh-comment-input { background: #2c2c2e; border-color: #3c3c3e; color: #f5f5f7; }
[data-theme="dark"] .sh-legend { background: #141416; border-color: #2c2c2e; }
[data-theme="dark"] .sh-btn--secondary { background: #2c2c2e; color: #f5f5f7; border-color: #3c3c3e; }
</style>

<div class="sh-header">
    <div style="flex:1; min-width:250px;">
        <a href="session.php?semester=<?= urlencode($sheet['semester']) ?>" class="sh-back"><i class="bi bi-arrow-left"></i> Все ведомости</a>
        <h2>
            <?= htmlspecialchars($sheet['discipline']) ?>
            <span class="sh-type sh-type--<?= $sheet['session_type'] ?>"><i class="bi <?= $type['icon'] ?>"></i> <?= $type['label'] ?></span>
            <?php if ($isClosed): ?>
                <span class="sh-status sh-status--closed"><i class="bi bi-lock-fill"></i> Закрыта</span>
            <?php else: ?>
                <span class="sh-status sh-status--open"><i class="bi bi-unlock-fill"></i> Открыта</span>
            <?php endif; ?>
        </h2>
        <div class="sh-header-meta">
            <span><i class="bi bi-people-fill"></i> <?= htmlspecialchars($sheet['group_name']) ?></span>
            <?php if ($isAdmin): ?><span><i class="bi bi-person-badge"></i> <?= htmlspecialchars($sheet['teacher_name']) ?></span><?php endif; ?>
            <span><i class="bi bi-calendar-range"></i> <?= htmlspecialchars(semesterLabel($sheet['semester'])) ?></span>
        </div>
    </div>
    <div class="sh-actions">
        <a href="session_print.php?id=<?= $sheetId ?>" target="_blank" class="sh-btn sh-btn--secondary"><i class="bi bi-printer"></i> Печать</a>
        <?php if ($canEdit && !$isClosed): ?>
            <button class="sh-btn sh-btn--success" onclick="toggleStatus('closed')"><i class="bi bi-lock-fill"></i> Закрыть</button>
        <?php elseif ($canEdit && $isClosed): ?>
            <button class="sh-btn sh-btn--warning" onclick="toggleStatus('open')"><i class="bi bi-unlock-fill"></i> Открыть заново</button>
        <?php endif; ?>
    </div>
</div>

<div class="sh-stats">
    <div class="sh-stat" style="--sh-color:#0071e3; --sh-bg:rgba(0,113,227,0.1);"><div class="sh-stat-icon"><i class="bi bi-people-fill"></i></div><div class="sh-stat-value"><?= $totalStudents ?></div><div class="sh-stat-label">Всего студентов</div></div>
    <div class="sh-stat" style="--sh-color:#30d158; --sh-bg:rgba(48,209,88,0.1);"><div class="sh-stat-icon"><i class="bi bi-check-circle-fill"></i></div><div class="sh-stat-value"><?= $allowedCnt ?></div><div class="sh-stat-label">Допущено</div></div>
    <div class="sh-stat" style="--sh-color:#ff453a; --sh-bg:rgba(255,69,58,0.1);"><div class="sh-stat-icon"><i class="bi bi-x-circle-fill"></i></div><div class="sh-stat-value"><?= $notAllowedCnt ?></div><div class="sh-stat-label">Не допущено</div></div>
    <div class="sh-stat" style="--sh-color:#bf5af2; --sh-bg:rgba(191,90,242,0.1);"><div class="sh-stat-icon"><i class="bi bi-clipboard-check-fill"></i></div><div class="sh-stat-value"><?= $gradedCnt ?>/<?= $totalStudents ?></div><div class="sh-stat-label">Оценок выставлено</div></div>
</div>

<?php if (!$isClosed && $canEdit): ?>
    <div class="sh-info">
        <i class="bi bi-info-circle-fill"></i>
        <div><b>Автодопуск</b> по всем занятиям предмета за семестр (пос. ≥ <?= (int)$sheet['min_attendance_pct'] ?>%, раб. ≥ <?= (int)$sheet['min_works_pct'] ?>%). Любое значение можно переопределить вручную.</div>
    </div>
<?php endif; ?>

<?php if (!$isClosed && $canEdit): ?>
    <div class="sh-bulk">
        <div class="sh-bulk-label"><i class="bi bi-lightning-charge-fill"></i> Массово:</div>
        <button class="sh-btn sh-btn--secondary" onclick="bulkAction('allow_all')"><i class="bi bi-check-all"></i> Допустить всех</button>
        <button class="sh-btn sh-btn--secondary" onclick="bulkAction('reset_allow')"><i class="bi bi-arrow-counterclockwise"></i> Сбросить допуск</button>
        <div style="margin-left:auto; display:flex; gap:8px; align-items:center;">
            <select class="sh-grade-select" id="bulkGradeSelect" style="min-width:200px;">
                <option value="">— оценка всем —</option>
                <?php foreach ($gradesList as $key => $label): ?>
                    <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="sh-btn sh-btn--secondary" onclick="bulkGrade()"><i class="bi bi-check-circle-fill"></i> Поставить</button>
            <button class="sh-btn sh-btn--secondary" onclick="bulkAction('reset_grades')"><i class="bi bi-x-circle"></i> Сбросить</button>
        </div>
    </div>
<?php endif; ?>

<?php if (empty($rows)): ?>
    <div class="sh-table-wrap">
        <div style="padding: 60px 24px; text-align: center; color: #86868b;">
            <i class="bi bi-people" style="font-size: 3rem; opacity: 0.3; display: block; margin-bottom: 12px;"></i>
            <p>В группе нет студентов</p>
        </div>
    </div>
<?php else: ?>
    <div class="sh-table-wrap">
        <table class="sh-table">
            <thead>
            <tr>
                <th style="width:40px;">№</th>
                <th>Студент</th>
                <th style="width:100px;">Зачётка</th>
                <th class="center" style="width:60px;">Подгр.</th>
                <th style="width:110px;" class="center">Посещ.</th>
                <th style="width:110px;" class="center">Работы</th>
                <th style="width:160px;">Вердикт</th>
                <th style="width:200px;">Оценка</th>
                <th style="width:180px;">Комментарий</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $i => $r):
                $s = $r['student'];
                $stats = $r['stats'];
                $attPct = $stats['attendance_pct'];
                $wrkPct = $stats['works_pct'];

                $attClass = 'danger';
                if ($attPct !== null) {
                    if ($attPct >= (int)$sheet['min_attendance_pct']) $attClass = 'success';
                    elseif ($attPct >= 40) $attClass = 'warning';
                }
                $wrkClass = 'danger';
                if ($wrkPct !== null) {
                    if ($wrkPct >= (int)$sheet['min_works_pct']) $wrkClass = 'success';
                    elseif ($wrkPct >= 50) $wrkClass = 'warning';
                }

                $effective = $r['is_allowed'];
                $isManual = $effective !== null;
                $autoVal = $r['auto_allowed'];

                if ($effective === null && $autoVal === null) $allowClass = '';
                elseif (($isManual ? $effective : $autoVal) === 1) $allowClass = $isManual ? 'sh-allow-select--yes' : 'sh-allow-select--auto-yes';
                else $allowClass = $isManual ? 'sh-allow-select--no' : 'sh-allow-select--auto-no';

                $gradeClass = '';
                if ($r['grade'] === 'отлично' || $r['grade'] === 'зачтено') $gradeClass = 'sh-grade-select--5';
                elseif ($r['grade'] === 'хорошо') $gradeClass = 'sh-grade-select--4';
                elseif ($r['grade'] === 'удовлетворительно') $gradeClass = 'sh-grade-select--3';
                elseif (in_array($r['grade'], ['не удовлетворительно','не зачтено','не явился'])) $gradeClass = 'sh-grade-select--2';

                $rowClass = (($isManual ? $effective : $autoVal) === 0) ? 'sh-row-not-allowed' : '';
            ?>
                <tr class="<?= $rowClass ?>" data-student="<?= $s['id'] ?>">
                    <td class="num"><?= $i + 1 ?></td>
                    <td class="student-name"><?= htmlspecialchars($s['full_name']) ?></td>
                    <td><?php if (!empty($s['zachetka'])): ?><code><?= htmlspecialchars($s['zachetka']) ?></code><?php else: ?><span style="color:#86868b;">—</span><?php endif; ?></td>
                    <td class="num"><?php if (!empty($s['subgroup'])): ?><span class="sh-sg sh-sg--<?= (int)$s['subgroup'] ?>"><?= htmlspecialchars($s['subgroup']) ?></span><?php else: ?><span style="color:#86868b;">—</span><?php endif; ?></td>
                    <td class="num">
                        <?php if ($attPct === null): ?>
                            <span style="color:#86868b; font-size:0.8rem;">нет данных</span>
                        <?php else: ?>
                            <div class="sh-progress">
                                <div class="sh-progress-bar"><div class="sh-progress-fill sh-progress-fill--<?= $attClass ?>" style="width: <?= $attPct ?>%;"></div></div>
                                <span class="sh-progress-text" style="color: <?= $attClass === 'success' ? '#198754' : ($attClass === 'warning' ? '#b25c00' : '#842029') ?>;"><?= $attPct ?>%</span>
                            </div>
                            <div style="font-size:0.68rem; color:#86868b; text-align:center; margin-top:2px;"><?= $stats['present'] ?>/<?= $stats['total_marked'] ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="num">
                        <?php if ($wrkPct === null): ?>
                            <span style="color:#86868b; font-size:0.8rem;">нет данных</span>
                        <?php else: ?>
                            <div class="sh-progress">
                                <div class="sh-progress-bar"><div class="sh-progress-fill sh-progress-fill--<?= $wrkClass ?>" style="width: <?= $wrkPct ?>%;"></div></div>
                                <span class="sh-progress-text" style="color: <?= $wrkClass === 'success' ? '#198754' : ($wrkClass === 'warning' ? '#b25c00' : '#842029') ?>;"><?= $wrkPct ?>%</span>
                            </div>
                            <div style="font-size:0.68rem; color:#86868b; text-align:center; margin-top:2px;"><?= $stats['works_submitted'] ?>/<?= $stats['works_total'] ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <select class="sh-allow-select <?= $allowClass ?>" <?= (!$canEdit || $isClosed) ? 'disabled' : '' ?> onchange="saveField(<?= $s['id'] ?>, 'is_allowed', this.value, this)">
                            <option value="" <?= $effective === null ? 'selected' : '' ?>>Авто: <?php
                                if ($autoVal === 1) echo 'Допущен';
                                elseif ($autoVal === 0) echo 'Не допущен';
                                else echo 'нет данных';
                            ?></option>
                            <option value="1" <?= $effective === 1 ? 'selected' : '' ?>>Допущен ✓</option>
                            <option value="0" <?= $effective === 0 ? 'selected' : '' ?>>Не допущен ✗</option>
                        </select>
                    </td>
                    <td>
                        <select class="sh-grade-select <?= $gradeClass ?>" <?= (!$canEdit || $isClosed) ? 'disabled' : '' ?> onchange="saveField(<?= $s['id'] ?>, 'grade', this.value, this)">
                            <option value="">—</option>
                            <?php foreach ($gradesList as $key => $label): ?>
                                <option value="<?= htmlspecialchars($key) ?>" <?= $r['grade'] === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <input type="text" class="sh-comment-input" value="<?= htmlspecialchars($r['comment'] ?? '') ?>" placeholder="—" <?= (!$canEdit || $isClosed) ? 'disabled' : '' ?> onblur="saveField(<?= $s['id'] ?>, 'comment', this.value, this)">
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="sh-legend">
        <div class="sh-legend-item"><span class="sh-legend-dot" style="background:#30d158;"></span> Выше порога</div>
        <div class="sh-legend-item"><span class="sh-legend-dot" style="background:#ff9f0a;"></span> На грани</div>
        <div class="sh-legend-item"><span class="sh-legend-dot" style="background:#ff453a;"></span> Ниже порога</div>
        <div style="margin-left:auto; font-style:italic;">Изменения сохраняются автоматически и попадают в журнал</div>
    </div>
<?php endif; ?>

<script>
const SHEET_ID = <?= (int)$sheetId ?>;
const CAN_EDIT = <?= ($canEdit && !$isClosed) ? 'true' : 'false' ?>;
const CSRF = <?= json_encode(csrf_token()) ?>;

function saveField(studentId, field, value, el) {
    if (!CAN_EDIT) return;
    if (el) { el.style.opacity = '0.6'; el.style.pointerEvents = 'none'; }

    const fd = new FormData();
    fd.append('action', 'save_grade');
    fd.append('sheet_id', SHEET_ID);
    fd.append('student_id', studentId);
    fd.append('field', field);
    fd.append('value', value);
    fd.append('csrf', CSRF);

    fetch('session_sheet.php?id=' + SHEET_ID, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (el) { el.style.opacity = '1'; el.style.pointerEvents = ''; }
            if (!data.ok) { alert('Ошибка: ' + (data.error || 'не удалось')); return; }
            updateVisuals(studentId, field, value, el);
        })
        .catch(() => { if (el) { el.style.opacity = '1'; el.style.pointerEvents = ''; } alert('Ошибка сети'); });
}

function updateVisuals(studentId, field, value, el) {
    const row = document.querySelector(`tr[data-student="${studentId}"]`);
    if (!row) return;

    if (field === 'is_allowed' && el) {
        el.classList.remove('sh-allow-select--yes','sh-allow-select--no','sh-allow-select--auto-yes','sh-allow-select--auto-no');
        if (value === '1') el.classList.add('sh-allow-select--yes');
        else if (value === '0') el.classList.add('sh-allow-select--no');
        else {
            const autoOpt = el.querySelector('option[value=""]');
            if (autoOpt && autoOpt.textContent.includes('Допущен')) el.classList.add('sh-allow-select--auto-yes');
            else if (autoOpt && autoOpt.textContent.includes('Не допущен')) el.classList.add('sh-allow-select--auto-no');
        }
        let isNotAllowed = (value === '0');
        if (value === '' && el) {
            const autoOpt = el.querySelector('option[value=""]');
            if (autoOpt && autoOpt.textContent.includes('Не допущен')) isNotAllowed = true;
        }
        row.classList.toggle('sh-row-not-allowed', isNotAllowed);
    }

    if (field === 'grade' && el) {
        el.classList.remove('sh-grade-select--5','sh-grade-select--4','sh-grade-select--3','sh-grade-select--2');
        if (value === 'отлично' || value === 'зачтено') el.classList.add('sh-grade-select--5');
        else if (value === 'хорошо') el.classList.add('sh-grade-select--4');
        else if (value === 'удовлетворительно') el.classList.add('sh-grade-select--3');
        else if (['не удовлетворительно','не зачтено','не явился'].includes(value)) el.classList.add('sh-grade-select--2');
    }
}

function bulkAction(bulk) {
    if (!CAN_EDIT) return;
    const texts = {
        allow_all: 'Допустить ВСЕХ студентов?',
        reset_allow: 'Сбросить ручной допуск у всех?',
        reset_grades: 'Сбросить ВСЕ оценки?'
    };
    if (texts[bulk] && !confirm(texts[bulk])) return;

    const fd = new FormData();
    fd.append('action', 'bulk_action');
    fd.append('sheet_id', SHEET_ID);
    fd.append('bulk', bulk);
    fd.append('csrf', CSRF);

    fetch('session_sheet.php?id=' + SHEET_ID, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => { if (!d.ok) { alert('Ошибка: ' + (d.error || 'не удалось')); return; } location.reload(); })
        .catch(() => alert('Ошибка сети'));
}

function bulkGrade() {
    const sel = document.getElementById('bulkGradeSelect');
    const value = sel.value;
    if (!value) { alert('Выберите оценку'); return; }
    const label = sel.options[sel.selectedIndex].text;
    if (!confirm('Поставить «' + label + '» ВСЕМ?')) return;

    const fd = new FormData();
    fd.append('action', 'bulk_action');
    fd.append('sheet_id', SHEET_ID);
    fd.append('bulk', 'grade_all');
    fd.append('value', value);
    fd.append('csrf', CSRF);

    fetch('session_sheet.php?id=' + SHEET_ID, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => { if (!d.ok) { alert('Ошибка: ' + (d.error || 'не удалось')); return; } location.reload(); })
        .catch(() => alert('Ошибка сети'));
}

function toggleStatus(newStatus) {
    if (!CAN_EDIT) return;
    const text = newStatus === 'closed' ? 'Закрыть ведомость?' : 'Открыть заново?';
    if (!confirm(text)) return;

    const fd = new FormData();
    fd.append('action', 'toggle_status');
    fd.append('sheet_id', SHEET_ID);
    fd.append('status', newStatus);
    fd.append('csrf', CSRF);

    fetch('session_sheet.php?id=' + SHEET_ID, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => { if (!d.ok) { alert('Ошибка: ' + (d.error || 'не удалось')); return; } location.reload(); })
        .catch(() => alert('Ошибка сети'));
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>