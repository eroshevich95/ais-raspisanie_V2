<?php
// subgroups.php

$pageTitle = 'Подгруппы';
require_once __DIR__ . '/config/database.php';
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

// AJAX: сохранить подгруппу студента
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_subgroup') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_verify(true);

    $studentId = (int)($_POST['student_id'] ?? 0);
    $subgroup  = trim($_POST['subgroup'] ?? '');

    if (!$studentId) { echo json_encode(['ok' => false, 'error' => 'Нет ID']); exit; }
    if ($subgroup !== '' && !in_array($subgroup, ['1', '2'])) {
        echo json_encode(['ok' => false, 'error' => 'Недопустимая подгруппа']); exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT s.full_name, s.subgroup, g.name AS group_name FROM students s LEFT JOIN groups_table g ON s.group_id = g.id WHERE s.id = ?");
        $stmt->execute([$studentId]);
        $stud = $stmt->fetch();
        if (!$stud) { echo json_encode(['ok' => false, 'error' => 'Студент не найден']); exit; }

        $pdo->prepare("UPDATE students SET subgroup = ? WHERE id = ?")->execute([$subgroup ?: null, $studentId]);

        $oldVal = $stud['subgroup'] ?: 'без подгруппы';
        $newVal = $subgroup ?: 'без подгруппы';

        if ($oldVal !== $newVal) {
            auditLog($pdo, 'subgroup.update', [
                'entity_type' => 'student', 'entity_id' => $studentId,
                'description' => "{$stud['full_name']} (" . ($stud['group_name'] ?? '—') . ") · подгруппа",
                'old_value' => $oldVal, 'new_value' => $newVal,
            ]);
        }

        echo json_encode(['ok' => true, 'subgroup' => $subgroup]);
    } catch (Throwable $e) {
        error_log('set_subgroup error: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'Ошибка сервера']);
    }
    exit;
}

// AJAX: массовое назначение
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_set') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_verify(true);

    $groupId    = (int)($_POST['group_id'] ?? 0);
    $subgroup   = trim($_POST['subgroup'] ?? '');
    $studentIds = $_POST['student_ids'] ?? [];

    if (!$groupId || empty($studentIds)) { echo json_encode(['ok' => false, 'error' => 'Нет данных']); exit; }
    if ($subgroup !== '' && !in_array($subgroup, ['1', '2'])) {
        echo json_encode(['ok' => false, 'error' => 'Недопустимая подгруппа']); exit;
    }

    try {
        $in = implode(',', array_fill(0, count($studentIds), '?'));
        $params = array_merge([$subgroup ?: null, $groupId], array_map('intval', $studentIds));
        $stmt = $pdo->prepare("UPDATE students SET subgroup = ? WHERE group_id = ? AND id IN ($in)");
        $stmt->execute($params);
        $affected = $stmt->rowCount();

        $grpStmt = $pdo->prepare("SELECT name FROM groups_table WHERE id = ?");
        $grpStmt->execute([$groupId]);
        $groupName = $grpStmt->fetchColumn() ?: '';

        $subgroupLabel = $subgroup === '' ? 'без подгруппы' : "подгруппа {$subgroup}";

        auditLog($pdo, 'subgroups.bulk', [
            'entity_type' => 'group', 'entity_id' => $groupId,
            'description' => "{$groupName} · массовое назначение: {$subgroupLabel}",
            'new_value'   => "{$affected} студентов",
        ]);

        echo json_encode(['ok' => true, 'affected' => $affected]);
    } catch (Throwable $e) {
        error_log('bulk_set error: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'Ошибка сервера']);
    }
    exit;
}

// AJAX: автораспределение по половинам
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'auto_split') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_verify(true);

    $groupId = (int)($_POST['group_id'] ?? 0);
    if (!$groupId) { echo json_encode(['ok' => false, 'error' => 'Нет группы']); exit; }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT id FROM students WHERE group_id = ? ORDER BY full_name");
        $stmt->execute([$groupId]);
        $students = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $total = count($students);

        if ($total === 0) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'error' => 'В группе нет студентов']); exit;
        }

        $half = (int)ceil($total / 2);
        $update = $pdo->prepare("UPDATE students SET subgroup = ? WHERE id = ?");
        foreach ($students as $i => $sid) {
            $update->execute([$i < $half ? '1' : '2', $sid]);
        }

        $grpStmt = $pdo->prepare("SELECT name FROM groups_table WHERE id = ?");
        $grpStmt->execute([$groupId]);
        $groupName = $grpStmt->fetchColumn() ?: '';

        auditLog($pdo, 'subgroups.auto', [
            'entity_type' => 'group', 'entity_id' => $groupId,
            'description' => "{$groupName} · автораспределение по половинам",
            'new_value'   => "1-я: {$half} · 2-я: " . ($total - $half),
        ]);

        $pdo->commit();
        echo json_encode(['ok' => true, 'count' => $total, 'half' => $half]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('auto_split error: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'Ошибка сервера']);
    }
    exit;
}

// AJAX: очистить все
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_all') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_verify(true);

    $groupId = (int)($_POST['group_id'] ?? 0);
    if (!$groupId) { echo json_encode(['ok' => false, 'error' => 'Нет группы']); exit; }

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE group_id = ? AND subgroup IS NOT NULL");
        $stmt->execute([$groupId]);
        $wasAssigned = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("UPDATE students SET subgroup = NULL WHERE group_id = ?");
        $stmt->execute([$groupId]);
        $affected = $stmt->rowCount();

        $grpStmt = $pdo->prepare("SELECT name FROM groups_table WHERE id = ?");
        $grpStmt->execute([$groupId]);
        $groupName = $grpStmt->fetchColumn() ?: '';

        auditLog($pdo, 'subgroups.clear', [
            'entity_type' => 'group', 'entity_id' => $groupId,
            'description' => "{$groupName} · очищены все подгруппы",
            'old_value' => "распределено: {$wasAssigned}",
            'new_value' => 'сброшено у всех',
        ]);

        echo json_encode(['ok' => true, 'affected' => $affected]);
    } catch (Throwable $e) {
        error_log('clear_all error: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'Ошибка сервера']);
    }
    exit;
}

// Данные
$groupId = (int)($_GET['group_id'] ?? 0);

$groups = $pdo->query("
    SELECT g.id, g.name, COUNT(s.id) AS cnt
    FROM groups_table g
    LEFT JOIN students s ON s.group_id = g.id
    GROUP BY g.id
    ORDER BY g.name
")->fetchAll();

if (!$groupId && !empty($groups)) $groupId = (int)$groups[0]['id'];

$students = [];
$groupInfo = null;
$subgroupCounts = ['1' => 0, '2' => 0, 'none' => 0];

if ($groupId) {
    $stmt = $pdo->prepare("SELECT * FROM groups_table WHERE id = ?");
    $stmt->execute([$groupId]);
    $groupInfo = $stmt->fetch();

    $stmt = $pdo->prepare("
        SELECT id, full_name, zachetka, subgroup
        FROM students
        WHERE group_id = ?
        ORDER BY
            CASE WHEN subgroup IS NULL OR subgroup = '' THEN 1 ELSE 0 END,
            subgroup, full_name
    ");
    $stmt->execute([$groupId]);
    $students = $stmt->fetchAll();

    foreach ($students as $s) {
        $sg = $s['subgroup'] ?: 'none';
        $subgroupCounts[$sg] = ($subgroupCounts[$sg] ?? 0) + 1;
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
.sg-header { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
.sg-header h2 { font-size: 1.7rem; font-weight: 700; letter-spacing: -0.02em; margin: 0 0 6px; }
.sg-header p { color: #86868b; margin: 0; font-size: 0.95rem; }

.sg-group-selector { background: #fff; border-radius: 20px; border: 1px solid #eaeaea; box-shadow: 0 4px 16px rgba(0,0,0,0.04); padding: 20px 24px; margin-bottom: 20px; }
.sg-group-selector-label { font-size: 0.78rem; font-weight: 700; margin-bottom: 10px; text-transform: uppercase; letter-spacing: 0.3px; display: flex; align-items: center; gap: 6px; }
.sg-group-selector-label i { color: #0071e3; }
.sg-group-chips { display: flex; flex-wrap: wrap; gap: 8px; }
.sg-group-chip { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 12px; background: #f5f5f7; border: 1.5px solid transparent; color: #1d1d1f; font-size: 0.85rem; font-weight: 600; text-decoration: none; transition: all 0.2s; }
.sg-group-chip:hover { background: #fff; border-color: #0071e3; color: #0071e3; transform: translateY(-2px); }
.sg-group-chip--active { background: linear-gradient(135deg, #0071e3 0%, #5e5ce6 100%); color: #fff; box-shadow: 0 6px 16px rgba(0,113,227,0.3); }
.sg-group-chip--active:hover { color: #fff; }
.sg-group-chip .count { display: inline-flex; align-items: center; justify-content: center; min-width: 22px; height: 22px; padding: 0 6px; background: rgba(0,0,0,0.06); border-radius: 11px; font-size: 0.72rem; }
.sg-group-chip--active .count { background: rgba(255,255,255,0.25); }

.sg-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 20px; }
.sg-stat { background: #fff; border-radius: 16px; padding: 16px 20px; border: 1px solid #eaeaea; box-shadow: 0 4px 16px rgba(0,0,0,0.04); position: relative; overflow: hidden; }
.sg-stat::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: var(--sg-color, #0071e3); }
.sg-stat-value { font-size: 1.75rem; font-weight: 700; line-height: 1; margin-bottom: 6px; }
.sg-stat-label { font-size: 0.78rem; color: #86868b; }

.sg-actions-panel { background: #fff; border-radius: 20px; border: 1px solid #eaeaea; box-shadow: 0 4px 16px rgba(0,0,0,0.04); padding: 20px 24px; margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
.sg-action-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 12px; font-size: 0.85rem; font-weight: 600; cursor: pointer; font-family: inherit; transition: all 0.2s; border: 1.5px solid; background: #fff; text-decoration: none; }
.sg-action-btn--primary { color: #fff; background: linear-gradient(135deg, #0071e3 0%, #5e5ce6 100%); border: none; box-shadow: 0 6px 16px rgba(0,113,227,0.3); }
.sg-action-btn--primary:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(0,113,227,0.4); color: #fff; }
.sg-action-btn--danger { color: #dc3545; border-color: #dc3545; }
.sg-action-btn--danger:hover { background: #dc3545; color: #fff; border-color: #dc3545; transform: translateY(-2px); }
.sg-action-btn--secondary { color: #495057; border-color: #d2d2d7; }
.sg-action-btn--secondary:hover { background: #495057; color: #fff; border-color: #495057; transform: translateY(-2px); }

.sg-students-card { background: #fff; border-radius: 20px; border: 1px solid #eaeaea; box-shadow: 0 4px 16px rgba(0,0,0,0.04); overflow: hidden; }
.sg-students-header { padding: 16px 24px; border-bottom: 1px solid #f0f0f2; background: linear-gradient(135deg, #fafafc 0%, #fff 100%); display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
.sg-students-title { font-size: 1rem; font-weight: 700; display: flex; align-items: center; gap: 8px; margin: 0; }
.sg-students-title i { color: #0071e3; }
.sg-select-all { display: inline-flex; align-items: center; gap: 8px; font-size: 0.82rem; font-weight: 600; cursor: pointer; user-select: none; }
.sg-select-all input { width: 18px; height: 18px; cursor: pointer; accent-color: #0071e3; }

.sg-student { display: flex; align-items: center; gap: 14px; padding: 14px 24px; border-bottom: 1px solid #f5f5f7; transition: all 0.2s; cursor: pointer; }
.sg-student:last-child { border-bottom: none; }
.sg-student:hover { background: #fafafc; }
.sg-student--selected { background: linear-gradient(90deg, rgba(0,113,227,0.08) 0%, transparent 100%); }
.sg-student--saving { opacity: 0.6; pointer-events: none; }
.sg-student-checkbox { width: 20px; height: 20px; cursor: pointer; accent-color: #0071e3; flex-shrink: 0; }
.sg-student-num { width: 28px; font-size: 0.78rem; font-weight: 700; color: #86868b; text-align: right; flex-shrink: 0; }
.sg-student-info { flex: 1; min-width: 0; }
.sg-student-name { font-size: 0.92rem; font-weight: 600; margin-bottom: 3px; }
.sg-student-meta { font-size: 0.75rem; color: #86868b; }
.sg-student-meta code { background: rgba(0,113,227,0.08); color: #0071e3; padding: 1px 6px; border-radius: 4px; font-size: 0.7rem; }

.sg-switch { display: inline-flex; gap: 4px; padding: 4px; background: #f0f0f2; border-radius: 12px; flex-shrink: 0; }
.sg-switch-btn { min-width: 40px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; background: transparent; border: none; color: #86868b; font-size: 0.82rem; font-weight: 700; cursor: pointer; font-family: inherit; transition: all 0.2s; padding: 0 10px; }
.sg-switch-btn:hover { background: rgba(255,255,255,0.7); color: #1d1d1f; }
.sg-switch-btn--active.sg-switch-btn--1 { background: #0071e3; color: #fff; box-shadow: 0 2px 8px rgba(0,113,227,0.4); }
.sg-switch-btn--active.sg-switch-btn--2 { background: #bf5af2; color: #fff; box-shadow: 0 2px 8px rgba(191,90,242,0.4); }
.sg-switch-btn--active.sg-switch-btn--none { background: #86868b; color: #fff; box-shadow: 0 2px 8px rgba(134,134,139,0.4); }
.sg-switch-btn--active:hover { color: #fff; }

.sg-empty { padding: 60px 24px; text-align: center; color: #86868b; }
.sg-empty i { font-size: 3rem; opacity: 0.3; display: block; margin-bottom: 12px; }

.sg-bulk-panel { display: none; background: linear-gradient(135deg, #e7f1ff 0%, #fff 100%); border: 2px solid #0071e3; border-radius: 16px; padding: 16px 24px; margin-bottom: 20px; align-items: center; gap: 12px; flex-wrap: wrap; }
.sg-bulk-panel.show { display: flex; }
.sg-bulk-info { display: flex; align-items: center; gap: 8px; font-size: 0.9rem; color: #084298; font-weight: 600; }
.sg-bulk-info i { color: #0071e3; font-size: 1.1rem; }

[data-theme="dark"] .sg-group-selector, [data-theme="dark"] .sg-stat,
[data-theme="dark"] .sg-actions-panel, [data-theme="dark"] .sg-students-card { background: #1c1c1e; border-color: #2c2c2e; }
[data-theme="dark"] .sg-header h2, [data-theme="dark"] .sg-group-selector-label,
[data-theme="dark"] .sg-stat-value, [data-theme="dark"] .sg-students-title,
[data-theme="dark"] .sg-student-name { color: #f5f5f7; }
[data-theme="dark"] .sg-group-chip { background: #2c2c2e; color: #f5f5f7; }
[data-theme="dark"] .sg-students-header { background: linear-gradient(135deg, #2c2c2e 0%, #1c1c1e 100%); border-color: #3c3c3e; }
[data-theme="dark"] .sg-student { border-color: #2c2c2e; }
[data-theme="dark"] .sg-student:hover { background: #2c2c2e; }
[data-theme="dark"] .sg-switch { background: #2c2c2e; }
[data-theme="dark"] .sg-bulk-panel { background: linear-gradient(135deg, rgba(0,113,227,0.15) 0%, #1c1c1e 100%); border-color: #0071e3; }
[data-theme="dark"] .sg-bulk-info { color: #6ea8ff; }
[data-theme="dark"] .sg-action-btn--secondary { background: #2c2c2e; color: #f5f5f7; border-color: #3c3c3e; }
</style>

<div class="sg-header">
    <div>
        <h2><i class="bi bi-diagram-3"></i> Подгруппы студентов</h2>
        <p><i class="bi bi-info-circle"></i> Назначьте студентов в подгруппы — это поможет при отметке посещаемости</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="attendance.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-check2-square"></i> К посещаемости</a>
        <a href="dashboard.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Дашборд</a>
    </div>
</div>

<?php if (empty($groups)): ?>
    <div class="sg-empty" style="background:#fff; border-radius:20px; border:1px solid #eaeaea;">
        <i class="bi bi-people"></i>
        <p>Нет ни одной группы. Сначала <a href="upload_students.php">загрузите студентов</a>.</p>
    </div>
<?php else: ?>

    <div class="sg-group-selector">
        <div class="sg-group-selector-label"><i class="bi bi-people-fill"></i> Выберите группу</div>
        <div class="sg-group-chips">
            <?php foreach ($groups as $g): ?>
                <a href="?group_id=<?= $g['id'] ?>" class="sg-group-chip <?= $g['id'] == $groupId ? 'sg-group-chip--active' : '' ?>">
                    <i class="bi bi-people"></i> <?= htmlspecialchars($g['name']) ?>
                    <span class="count"><?= $g['cnt'] ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="sg-stats">
        <div class="sg-stat" style="--sg-color:#0071e3;"><div class="sg-stat-value"><?= $subgroupCounts['1'] ?? 0 ?></div><div class="sg-stat-label"><i class="bi bi-1-circle-fill" style="color:#0071e3;"></i> Подгруппа 1</div></div>
        <div class="sg-stat" style="--sg-color:#bf5af2;"><div class="sg-stat-value"><?= $subgroupCounts['2'] ?? 0 ?></div><div class="sg-stat-label"><i class="bi bi-2-circle-fill" style="color:#bf5af2;"></i> Подгруппа 2</div></div>
        <div class="sg-stat" style="--sg-color:#86868b;"><div class="sg-stat-value"><?= $subgroupCounts['none'] ?? 0 ?></div><div class="sg-stat-label"><i class="bi bi-dash-circle" style="color:#86868b;"></i> Без подгруппы</div></div>
    </div>

    <div class="sg-actions-panel">
        <button type="button" class="sg-action-btn sg-action-btn--primary" onclick="autoSplit()">
            <i class="bi bi-shuffle"></i> Распределить на 2 (по половинам)
        </button>
        <button type="button" class="sg-action-btn sg-action-btn--danger" onclick="clearAll()">
            <i class="bi bi-x-circle"></i> Очистить все
        </button>
    </div>

    <div class="sg-bulk-panel" id="bulkPanel">
        <div class="sg-bulk-info"><i class="bi bi-check2-circle"></i> Выбрано: <span id="bulkCount">0</span></div>
        <div style="display:flex; gap:6px; flex-wrap:wrap;">
            <button type="button" class="sg-action-btn sg-action-btn--secondary" onclick="bulkSet('1')"><i class="bi bi-1-circle-fill"></i> Подгруппа 1</button>
            <button type="button" class="sg-action-btn sg-action-btn--secondary" onclick="bulkSet('2')"><i class="bi bi-2-circle-fill"></i> Подгруппа 2</button>
            <button type="button" class="sg-action-btn sg-action-btn--secondary" onclick="bulkSet('')"><i class="bi bi-dash-circle"></i> Сбросить</button>
        </div>
        <button type="button" class="sg-action-btn sg-action-btn--secondary" style="margin-left:auto;" onclick="clearSelection()"><i class="bi bi-x-lg"></i> Снять выбор</button>
    </div>

    <div class="sg-students-card">
        <div class="sg-students-header">
            <h3 class="sg-students-title">
                <i class="bi bi-people-fill"></i> <?= htmlspecialchars($groupInfo['name'] ?? '') ?>
                <span style="font-weight:500; color:#86868b; font-size:0.85rem;">· <?= count($students) ?> студентов</span>
            </h3>
            <label class="sg-select-all">
                <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)"> Выбрать всех
            </label>
        </div>

        <?php if (empty($students)): ?>
            <div class="sg-empty"><i class="bi bi-people"></i><p>В группе нет студентов</p></div>
        <?php else: foreach ($students as $i => $s):
            $sg = $s['subgroup'] ?: '';
        ?>
            <div class="sg-student" id="student-<?= $s['id'] ?>">
                <input type="checkbox" class="sg-student-checkbox" data-student-id="<?= $s['id'] ?>" onchange="toggleStudent(this)">
                <div class="sg-student-num"><?= $i + 1 ?></div>
                <div class="sg-student-info">
                    <div class="sg-student-name"><?= htmlspecialchars($s['full_name']) ?></div>
                    <div class="sg-student-meta"><?php if (!empty($s['zachetka'])): ?><code><?= htmlspecialchars($s['zachetka']) ?></code><?php endif; ?></div>
                </div>
                <div class="sg-switch">
                    <button type="button" class="sg-switch-btn sg-switch-btn--1 <?= $sg === '1' ? 'sg-switch-btn--active' : '' ?>" onclick="setSubgroup(<?= $s['id'] ?>, '1')">1</button>
                    <button type="button" class="sg-switch-btn sg-switch-btn--2 <?= $sg === '2' ? 'sg-switch-btn--active' : '' ?>" onclick="setSubgroup(<?= $s['id'] ?>, '2')">2</button>
                    <button type="button" class="sg-switch-btn sg-switch-btn--none <?= $sg === '' ? 'sg-switch-btn--active' : '' ?>" onclick="setSubgroup(<?= $s['id'] ?>, '')" title="Без подгруппы">—</button>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>

<?php endif; ?>

<script>
const GROUP_ID = <?= (int)$groupId ?>;
const CSRF = <?= json_encode(csrf_token()) ?>;

function setSubgroup(studentId, subgroup) {
    const row = document.getElementById('student-' + studentId);
    if (!row) return;
    row.querySelectorAll('.sg-switch-btn').forEach(btn => btn.classList.remove('sg-switch-btn--active'));
    const cls = subgroup === '' ? 'none' : subgroup;
    const activeBtn = row.querySelector('.sg-switch-btn--' + cls);
    if (activeBtn) activeBtn.classList.add('sg-switch-btn--active');
    row.classList.add('sg-student--saving');

    const fd = new FormData();
    fd.append('action', 'set_subgroup');
    fd.append('student_id', studentId);
    fd.append('subgroup', subgroup);
    fd.append('csrf', CSRF);

    fetch('subgroups.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            row.classList.remove('sg-student--saving');
            if (!data.ok) { alert('Ошибка: ' + (data.error || 'не удалось')); location.reload(); return; }
            updateStats();
        })
        .catch(() => { row.classList.remove('sg-student--saving'); alert('Ошибка сети'); });
}

function bulkSet(subgroup) {
    const ids = getSelectedIds();
    if (!ids.length) { alert('Никого не выбрано'); return; }

    const fd = new FormData();
    fd.append('action', 'bulk_set');
    fd.append('group_id', GROUP_ID);
    fd.append('subgroup', subgroup);
    fd.append('csrf', CSRF);
    ids.forEach(id => fd.append('student_ids[]', id));

    fetch('subgroups.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.ok) { alert('Ошибка: ' + (data.error || 'не удалось')); return; }
            ids.forEach(id => {
                const row = document.getElementById('student-' + id);
                if (!row) return;
                row.querySelectorAll('.sg-switch-btn').forEach(btn => btn.classList.remove('sg-switch-btn--active'));
                const cls = subgroup === '' ? 'none' : subgroup;
                const activeBtn = row.querySelector('.sg-switch-btn--' + cls);
                if (activeBtn) activeBtn.classList.add('sg-switch-btn--active');
            });
            updateStats();
            clearSelection();
        })
        .catch(() => alert('Ошибка сети'));
}

function autoSplit() {
    if (!confirm('Распределить студентов по ПОЛОВИНАМ списка?\n\nПервая половина → Подгруппа 1\nВторая → Подгруппа 2')) return;
    const fd = new FormData();
    fd.append('action', 'auto_split');
    fd.append('group_id', GROUP_ID);
    fd.append('csrf', CSRF);
    fetch('subgroups.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.ok) { alert('Ошибка: ' + (data.error || 'не удалось')); return; }
            location.reload();
        })
        .catch(() => alert('Ошибка сети'));
}

function clearAll() {
    if (!confirm('Убрать подгруппы у ВСЕХ студентов этой группы?')) return;
    const fd = new FormData();
    fd.append('action', 'clear_all');
    fd.append('group_id', GROUP_ID);
    fd.append('csrf', CSRF);
    fetch('subgroups.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.ok) { alert('Ошибка: ' + (data.error || 'не удалось')); return; }
            location.reload();
        })
        .catch(() => alert('Ошибка сети'));
}

function toggleStudent(cb) {
    const row = cb.closest('.sg-student');
    if (cb.checked) row.classList.add('sg-student--selected');
    else row.classList.remove('sg-student--selected');
    updateBulkPanel();
}

function toggleSelectAll(master) {
    document.querySelectorAll('.sg-student-checkbox').forEach(cb => {
        cb.checked = master.checked;
        const row = cb.closest('.sg-student');
        if (master.checked) row.classList.add('sg-student--selected');
        else row.classList.remove('sg-student--selected');
    });
    updateBulkPanel();
}

function getSelectedIds() {
    return Array.from(document.querySelectorAll('.sg-student-checkbox:checked')).map(cb => cb.dataset.studentId);
}

function updateBulkPanel() {
    const ids = getSelectedIds();
    const panel = document.getElementById('bulkPanel');
    document.getElementById('bulkCount').textContent = ids.length;
    if (ids.length > 0) panel.classList.add('show');
    else panel.classList.remove('show');
}

function clearSelection() {
    document.querySelectorAll('.sg-student-checkbox').forEach(cb => {
        cb.checked = false;
        cb.closest('.sg-student').classList.remove('sg-student--selected');
    });
    const master = document.getElementById('selectAll');
    if (master) master.checked = false;
    updateBulkPanel();
}

function updateStats() {
    const counts = { '1': 0, '2': 0, 'none': 0 };
    document.querySelectorAll('.sg-student').forEach(row => {
        const active = row.querySelector('.sg-switch-btn--active');
        if (!active) return;
        if (active.classList.contains('sg-switch-btn--1')) counts['1']++;
        else if (active.classList.contains('sg-switch-btn--2')) counts['2']++;
        else counts['none']++;
    });
    const stats = document.querySelectorAll('.sg-stat-value');
    if (stats.length >= 3) {
        stats[0].textContent = counts['1'];
        stats[1].textContent = counts['2'];
        stats[2].textContent = counts['none'];
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>