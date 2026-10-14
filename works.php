<?php
// works.php

$pageTitle = 'Работы';
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
$today  = date('Y-m-d');

// ---------- СОХРАНЕНИЕ РАБОТ ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    csrf_verify();

    $lessonId   = (int)$_POST['lesson_id'];
    $workNumber = trim($_POST['work_number'] ?? '');
    $submitted  = $_POST['submitted'] ?? [];

    if ($workNumber === '') {
        redirectWith('works.php?lesson_id=' . $lessonId, 'error', 'Укажите номер или название работы');
    }

    $stmt = $pdo->prepare("SELECT * FROM lessons WHERE id = ? AND user_id = ?");
    $stmt->execute([$lessonId, $userId]);
    $lesson = $stmt->fetch();

    if (!$lesson) {
        redirectWith('works.php', 'error', 'Занятие не найдено');
    }

    $stmt = $pdo->prepare("
        SELECT MIN(id) FROM lessons
        WHERE user_id = ? AND lesson_date = ? AND lesson_number <=> ?
          AND discipline = ? AND lesson_type = ?
          AND COALESCE(audience, '') = COALESCE(?, '')
          AND COALESCE(subgroup, '0') = COALESCE(?, '0')
    ");
    $stmt->execute([
        $userId, $lesson['lesson_date'], $lesson['lesson_number'],
        $lesson['discipline'], $lesson['lesson_type'],
        $lesson['audience'], $lesson['subgroup'],
    ]);
    $canonicalId = (int)$stmt->fetchColumn();
    if (!$canonicalId) $canonicalId = $lessonId;

    // Все студенты потока
    $stmt = $pdo->prepare("
        SELECT DISTINCT s.id FROM students s
        JOIN lessons l ON l.group_id = s.group_id
        WHERE l.id = ?
    ");
    $stmt->execute([$canonicalId]);
    $allowedStudentIds = array_column($stmt->fetchAll(), 'id');

    // Старые отметки по этой работе
    $oldSubmitted = [];
    $stmt = $pdo->prepare("SELECT student_id FROM submitted_works WHERE lesson_id = ? AND work_number = ?");
    $stmt->execute([$canonicalId, $workNumber]);
    foreach ($stmt->fetchAll() as $r) $oldSubmitted[(int)$r['student_id']] = true;

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM submitted_works WHERE lesson_id = ? AND work_number = ?")
            ->execute([$canonicalId, $workNumber]);

        $addedCount = 0;
        $newSubmitted = [];

        if (!empty($submitted)) {
            $stmt = $pdo->prepare("
                INSERT INTO submitted_works (lesson_id, student_id, work_number, is_submitted)
                VALUES (?, ?, ?, 1)
            ");
            foreach ($submitted as $sid => $val) {
                if ($val != '1') continue;
                if (!in_array((int)$sid, $allowedStudentIds)) continue;
                $stmt->execute([$canonicalId, (int)$sid, $workNumber]);
                $addedCount++;
                $newSubmitted[(int)$sid] = true;
            }
        }

        // Лог
        $grpStmt = $pdo->prepare("SELECT name FROM groups_table WHERE id = ?");
        $grpStmt->execute([$lesson['group_id'] ?? 0]);
        $groupName = $grpStmt->fetchColumn() ?: '—';

        $desc = "«{$workNumber}» — {$lesson['discipline']} (" . date('d.m.Y', strtotime($lesson['lesson_date'])) . ") · группа {$groupName}";

        auditLog($pdo, 'works.update', [
            'entity_type' => 'lesson',
            'entity_id'   => $canonicalId,
            'description' => $desc,
            'old_value'   => count($oldSubmitted) > 0 ? 'сдало ранее: ' . count($oldSubmitted) : 'никто не сдавал',
            'new_value'   => "сдало: {$addedCount}",
        ]);

        $pdo->commit();

        redirectWith(
            'works.php?lesson_id=' . $lessonId . '&work=' . urlencode($workNumber),
            'success',
            "Работа «{$workNumber}» сохранена: сдало {$addedCount} чел."
        );
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('works save error: ' . $e->getMessage());
        redirectWith('works.php?lesson_id=' . $lessonId, 'error', 'Ошибка сохранения');
    }
}

$success = getFlash('success') ?? '';
$error   = getFlash('error') ?? '';

$lessonId   = (int)($_GET['lesson_id'] ?? 0);
$workNumber = trim($_GET['work'] ?? '');

$currentLesson = null;
$students = [];
$submittedIds = [];
$worksList = [];

if ($lessonId) {
    $stmt = $pdo->prepare("
        SELECT l.*, g.name AS group_name
        FROM lessons l
        LEFT JOIN groups_table g ON l.group_id = g.id
        WHERE l.id = ? AND l.user_id = ?
    ");
    $stmt->execute([$lessonId, $userId]);
    $currentLesson = $stmt->fetch();

    if ($currentLesson) {
        // Список работ по предмету
        $stmt = $pdo->prepare("
            SELECT DISTINCT sw.work_number
            FROM submitted_works sw
            JOIN lessons l ON sw.lesson_id = l.id
            WHERE l.user_id = ? AND l.discipline = ?
            ORDER BY sw.work_number
        ");
        $stmt->execute([$userId, $currentLesson['discipline']]);
        $worksList = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Студенты потока
        $stmt = $pdo->prepare("
            SELECT id, group_id FROM lessons
            WHERE user_id = ? AND lesson_date = ? AND lesson_number <=> ?
              AND discipline = ? AND lesson_type = ?
              AND COALESCE(audience, '') = COALESCE(?, '')
              AND COALESCE(subgroup, '0') = COALESCE(?, '0')
        ");
        $stmt->execute([
            $userId, $currentLesson['lesson_date'], $currentLesson['lesson_number'],
            $currentLesson['discipline'], $currentLesson['lesson_type'],
            $currentLesson['audience'], $currentLesson['subgroup'],
        ]);
        $streamLessons  = $stmt->fetchAll();
        $streamGroupIds = array_filter(array_unique(array_column($streamLessons, 'group_id')));

        if (!empty($streamGroupIds)) {
            $in = implode(',', array_fill(0, count($streamGroupIds), '?'));
            $stmt = $pdo->prepare("
                SELECT s.id, s.full_name, s.zachetka, g.name AS group_name
                FROM students s
                LEFT JOIN groups_table g ON s.group_id = g.id
                WHERE s.group_id IN ($in)
                ORDER BY g.name, s.full_name
            ");
            $stmt->execute(array_values($streamGroupIds));
            $students = $stmt->fetchAll();
        }

        // Кто сдал эту работу
        if ($workNumber !== '') {
            $stmt = $pdo->prepare("
                SELECT DISTINCT sw.student_id
                FROM submitted_works sw
                JOIN lessons l ON sw.lesson_id = l.id
                WHERE l.user_id = ? AND l.discipline = ? AND sw.work_number = ?
            ");
            $stmt->execute([$userId, $currentLesson['discipline'], $workNumber]);
            $submittedIds = array_column($stmt->fetchAll(), 'student_id');
        }
    }
}

// Занятия на сегодня
$stmt = $pdo->prepare("
    SELECT MIN(l.id) AS id, l.lesson_number, l.lesson_time, l.discipline,
           GROUP_CONCAT(DISTINCT g.name ORDER BY g.name SEPARATOR ', ') AS group_name
    FROM lessons l
    LEFT JOIN groups_table g ON l.group_id = g.id
    WHERE l.user_id = ? AND l.lesson_date = ?
    GROUP BY l.lesson_date, l.lesson_number, l.discipline, l.lesson_type,
             l.audience, COALESCE(l.subgroup, '0')
    ORDER BY l.lesson_time, l.lesson_number
");
$stmt->execute([$userId, $today]);
$todayLessons = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<style>
.works-header { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
.works-header h2 { font-size: 1.7rem; font-weight: 700; letter-spacing: -0.02em; margin: 0 0 6px; }
.works-header p { color: #86868b; margin: 0; font-size: 0.95rem; }

.works-layout { display: grid; grid-template-columns: 360px 1fr; gap: 20px; }
@media (max-width: 991px) { .works-layout { grid-template-columns: 1fr; } }

.works-sidebar { background: #fff; border-radius: 20px; border: 1px solid #eaeaea; box-shadow: 0 4px 16px rgba(0,0,0,0.04); overflow: hidden; max-height: calc(100vh - 220px); position: sticky; top: 90px; }
@media (max-width: 991px) { .works-sidebar { position: static; max-height: none; } }
.works-sidebar-header { padding: 16px 20px; border-bottom: 1px solid #f0f0f2; display: flex; justify-content: space-between; align-items: center; background: linear-gradient(135deg, #f5f5f7 0%, #fff 100%); }
.works-sidebar-title { font-size: 0.95rem; font-weight: 700; display: flex; align-items: center; gap: 8px; }
.works-sidebar-title i { color: #0071e3; }
.works-lessons { overflow-y: auto; padding: 10px; display: flex; flex-direction: column; gap: 8px; }
.works-lesson { padding: 12px 14px; border-radius: 12px; background: #fafafc; border: 1.5px solid #f0f0f2; text-decoration: none; color: inherit; transition: all 0.25s; position: relative; overflow: hidden; display: block; }
.works-lesson::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; background: #0071e3; }
.works-lesson:hover { background: #fff; border-color: #0071e3; transform: translateX(3px); box-shadow: 0 4px 12px rgba(0,113,227,0.12); color: inherit; }
.works-lesson--active { background: linear-gradient(135deg, #e7f1ff 0%, #fff 100%); border-color: #0071e3; }
.works-lesson-time { font-size: 0.78rem; font-weight: 700; color: #1d1d1f; }
.works-lesson-title { font-size: 0.85rem; font-weight: 600; line-height: 1.3; margin: 6px 0 4px; }
.works-lesson-meta { font-size: 0.72rem; color: #86868b; }
.works-lesson-meta i { color: #0071e3; }
.works-empty { padding: 32px 20px; text-align: center; color: #86868b; font-size: 0.85rem; }
.works-empty i { font-size: 2rem; opacity: 0.3; display: block; margin-bottom: 8px; }

.works-placeholder { background: #fff; border-radius: 20px; border: 1px solid #eaeaea; box-shadow: 0 4px 16px rgba(0,0,0,0.04); padding: 60px 24px; text-align: center; }
.works-placeholder-icon { width: 80px; height: 80px; margin: 0 auto 20px; border-radius: 20px; background: linear-gradient(135deg, #e7f1ff 0%, #d6d0ff 100%); color: #0071e3; display: flex; align-items: center; justify-content: center; font-size: 2.2rem; }
.works-placeholder h4 { font-size: 1.2rem; font-weight: 700; margin: 0 0 8px; }
.works-placeholder p { color: #86868b; margin: 0; font-size: 0.9rem; }

.works-card { background: #fff; border-radius: 20px; border: 1px solid #eaeaea; box-shadow: 0 4px 16px rgba(0,0,0,0.04); overflow: hidden; }
.works-card-header { padding: 20px 24px; background: linear-gradient(135deg, #fafafc 0%, #fff 100%); border-bottom: 1px solid #f0f0f2; }
.works-card-title { font-size: 1.1rem; font-weight: 700; margin: 0 0 6px; display: flex; align-items: center; gap: 8px; }
.works-card-title i { color: #0071e3; }
.works-card-meta { font-size: 0.82rem; color: #86868b; display: flex; gap: 12px; flex-wrap: wrap; }
.works-card-meta i { color: #0071e3; }

.works-work-form { padding: 20px 24px; border-bottom: 1px solid #f0f0f2; background: #fafafc; }
.works-work-form .form-label { font-size: 0.82rem; font-weight: 600; margin-bottom: 6px; display: block; }
.works-work-input { display: flex; gap: 10px; }
.works-work-input input { flex: 1; padding: 12px 16px; border-radius: 12px; border: 1.5px solid #e5e5ea; background: #fff; font-size: 0.92rem; font-family: inherit; outline: none; transition: all 0.2s; }
.works-work-input input:focus { border-color: #0071e3; box-shadow: 0 0 0 4px rgba(0,113,227,0.12); }
.works-work-input button { padding: 12px 24px; border-radius: 12px; background: linear-gradient(135deg, #0071e3 0%, #5e5ce6 100%); color: #fff; border: none; font-size: 0.88rem; font-weight: 600; cursor: pointer; font-family: inherit; transition: all 0.25s; box-shadow: 0 6px 16px rgba(0,113,227,0.25); white-space: nowrap; display: inline-flex; align-items: center; gap: 8px; }
.works-work-input button:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(0,113,227,0.4); }

.works-list { padding: 12px 24px; display: flex; flex-wrap: wrap; gap: 8px; border-bottom: 1px solid #f0f0f2; }
.works-list-label { font-size: 0.75rem; color: #86868b; font-weight: 600; text-transform: uppercase; width: 100%; margin-bottom: 4px; }
.works-chip { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 20px; background: #f5f5f7; color: #1d1d1f; font-size: 0.8rem; font-weight: 600; text-decoration: none; border: 1.5px solid #e5e5ea; transition: all 0.2s; }
.works-chip:hover { background: #0071e3; color: #fff; border-color: #0071e3; transform: translateY(-2px); }
.works-chip--active { background: #0071e3; color: #fff; border-color: #0071e3; }

.works-actions-panel { padding: 14px 24px; border-bottom: 1px solid #f0f0f2; display: flex; gap: 8px; flex-wrap: wrap; }
.works-action-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 10px; font-size: 0.82rem; font-weight: 600; cursor: pointer; font-family: inherit; transition: all 0.2s; border: 1.5px solid; background: #fff; }
.works-action-btn--success { color: #198754; border-color: #198754; }
.works-action-btn--success:hover { background: #198754; color: #fff; transform: translateY(-2px); }
.works-action-btn--danger { color: #dc3545; border-color: #dc3545; }
.works-action-btn--danger:hover { background: #dc3545; color: #fff; transform: translateY(-2px); }

.works-students { padding: 8px; }
.works-student { display: flex; align-items: center; gap: 14px; padding: 12px 16px; border-radius: 12px; transition: all 0.2s; border-bottom: 1px solid #f5f5f7; }
.works-student:last-child { border-bottom: none; }
.works-student:hover { background: #fafafc; }
.works-student--submitted { background: linear-gradient(90deg, rgba(48,209,88,0.08) 0%, transparent 100%); }
.works-student-num { width: 28px; font-size: 0.78rem; font-weight: 700; color: #86868b; text-align: right; flex-shrink: 0; }
.works-student-info { flex: 1; min-width: 0; }
.works-student-name { font-size: 0.92rem; font-weight: 600; color: #1d1d1f; margin-bottom: 3px; }
.works-student-meta { font-size: 0.75rem; color: #86868b; display: flex; gap: 10px; flex-wrap: wrap; }
.works-student-meta code { background: rgba(0,113,227,0.08); color: #0071e3; padding: 1px 6px; border-radius: 4px; font-size: 0.7rem; }

.works-toggle { display: inline-flex; align-items: center; gap: 10px; cursor: pointer; user-select: none; }
.works-toggle input { position: absolute; opacity: 0; pointer-events: none; }
.works-toggle-switch { position: relative; width: 48px; height: 28px; background: #d2d2d7; border-radius: 28px; transition: all 0.3s cubic-bezier(0.16,1,0.3,1); }
.works-toggle-switch::after { content: ''; position: absolute; top: 3px; left: 3px; width: 22px; height: 22px; background: #fff; border-radius: 50%; box-shadow: 0 2px 6px rgba(0,0,0,0.2); transition: all 0.3s cubic-bezier(0.16,1,0.3,1); }
.works-toggle input:checked ~ .works-toggle-switch { background: linear-gradient(135deg, #30d158, #198754); box-shadow: 0 4px 12px rgba(48,209,88,0.4); }
.works-toggle input:checked ~ .works-toggle-switch::after { left: 23px; }
.works-toggle-label { font-size: 0.82rem; font-weight: 600; color: #86868b; min-width: 70px; }
.works-toggle input:checked ~ .works-toggle-label { color: #198754; }

.works-card-footer { padding: 16px 24px; border-top: 1px solid #f0f0f2; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; background: #fafafc; }
.works-footer-hint { font-size: 0.78rem; color: #86868b; display: flex; align-items: center; gap: 6px; }
.works-save-btn { display: inline-flex; align-items: center; gap: 8px; padding: 12px 28px; border-radius: 12px; background: linear-gradient(135deg, #0071e3 0%, #5e5ce6 100%); color: #fff; border: none; font-size: 0.92rem; font-weight: 600; cursor: pointer; font-family: inherit; transition: all 0.25s; box-shadow: 0 6px 16px rgba(0,113,227,0.3); }
.works-save-btn:hover { transform: translateY(-2px); box-shadow: 0 12px 28px rgba(0,113,227,0.45); }

[data-theme="dark"] .works-sidebar,
[data-theme="dark"] .works-card,
[data-theme="dark"] .works-placeholder { background: #1c1c1e; border-color: #2c2c2e; }
[data-theme="dark"] .works-lesson { background: #2c2c2e; border-color: #3c3c3e; }
[data-theme="dark"] .works-lesson:hover { background: #3c3c3e; color: inherit; }
[data-theme="dark"] .works-lesson--active { background: linear-gradient(135deg, rgba(0,113,227,0.2) 0%, #2c2c2e 100%); }
[data-theme="dark"] .works-lesson-title, [data-theme="dark"] .works-student-name,
[data-theme="dark"] .works-card-title, [data-theme="dark"] .works-placeholder h4 { color: #f5f5f7; }
[data-theme="dark"] .works-student { border-color: #2c2c2e; }
[data-theme="dark"] .works-student:hover { background: #2c2c2e; }
[data-theme="dark"] .works-work-input input { background: #2c2c2e; border-color: #3c3c3e; color: #f5f5f7; }
[data-theme="dark"] .works-card-footer, [data-theme="dark"] .works-work-form { background: #141416; border-color: #2c2c2e; }
[data-theme="dark"] .works-toggle-switch { background: #3c3c3e; }
[data-theme="dark"] .works-chip { background: #2c2c2e; color: #f5f5f7; border-color: #3c3c3e; }
</style>

<div class="works-header">
    <div>
        <h2><i class="bi bi-journal-check"></i> Сданные работы</h2>
        <p><i class="bi bi-calendar3"></i> <?= ruDayFull($today) ?>, <?= date('d.m.Y') ?></p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Дашборд
        </a>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger">
        <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<div class="works-layout">

    <aside class="works-sidebar">
        <div class="works-sidebar-header">
            <div class="works-sidebar-title"><i class="bi bi-calendar-day"></i> Занятия на сегодня</div>
            <span class="badge bg-primary"><?= count($todayLessons) ?></span>
        </div>
        <div class="works-lessons">
            <?php if (empty($todayLessons)): ?>
                <div class="works-empty"><i class="bi bi-calendar-x"></i><div>На сегодня занятий нет</div></div>
            <?php else: foreach ($todayLessons as $l): ?>
                <a href="?lesson_id=<?= $l['id'] ?>" class="works-lesson <?= $l['id'] == $lessonId ? 'works-lesson--active' : '' ?>">
                    <div class="works-lesson-time"><i class="bi bi-clock"></i> <?= $l['lesson_time'] ? substr($l['lesson_time'], 0, 5) : '—' ?></div>
                    <div class="works-lesson-title"><?= htmlspecialchars($l['discipline']) ?></div>
                    <div class="works-lesson-meta"><i class="bi bi-people-fill"></i> <?= htmlspecialchars($l['group_name'] ?? '—') ?></div>
                </a>
            <?php endforeach; endif; ?>
        </div>
    </aside>

    <div class="works-main">
        <?php if (!$currentLesson): ?>
            <div class="works-placeholder">
                <div class="works-placeholder-icon"><i class="bi bi-journal-text"></i></div>
                <h4>Выберите занятие</h4>
                <p>Нажмите на занятие слева, чтобы отметить сдавших</p>
            </div>
        <?php else: ?>

            <div class="works-card">
                <div class="works-card-header">
                    <h3 class="works-card-title"><i class="bi bi-journal-check"></i> <?= htmlspecialchars($currentLesson['discipline']) ?></h3>
                    <div class="works-card-meta">
                        <span><i class="bi bi-people-fill"></i> <?= htmlspecialchars($currentLesson['group_name'] ?? '—') ?></span>
                        <span><i class="bi bi-calendar-event"></i> <?= date('d.m.Y', strtotime($currentLesson['lesson_date'])) ?></span>
                    </div>
                </div>

                <form method="get" class="works-work-form">
                    <input type="hidden" name="lesson_id" value="<?= $lessonId ?>">
                    <label class="form-label"><i class="bi bi-pencil"></i> Номер или название работы</label>
                    <div class="works-work-input">
                        <input type="text" name="work" value="<?= htmlspecialchars($workNumber) ?>" placeholder="Например: Лабораторная №3" autocomplete="off" autofocus>
                        <button type="submit"><i class="bi bi-arrow-right-circle"></i> Открыть</button>
                    </div>
                </form>

                <?php if (!empty($worksList)): ?>
                    <div class="works-list">
                        <div class="works-list-label"><i class="bi bi-clock-history"></i> Работы по предмету:</div>
                        <?php foreach ($worksList as $w): ?>
                            <a href="?lesson_id=<?= $lessonId ?>&work=<?= urlencode($w) ?>" class="works-chip <?= $workNumber === $w ? 'works-chip--active' : '' ?>">
                                <i class="bi bi-journal-check"></i> <?= htmlspecialchars($w) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($workNumber === ''): ?>
                    <div class="works-empty"><i class="bi bi-journal-text"></i><div>Введите название работы</div></div>
                <?php elseif (empty($students)): ?>
                    <div class="works-empty"><i class="bi bi-people"></i><div>В группе нет студентов</div></div>
                <?php else: ?>

                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="lesson_id" value="<?= $lessonId ?>">
                        <input type="hidden" name="work_number" value="<?= htmlspecialchars($workNumber) ?>">

                        <div class="works-actions-panel">
                            <button type="button" class="works-action-btn works-action-btn--success" onclick="markAll(1)"><i class="bi bi-check-all"></i> Все сдали</button>
                            <button type="button" class="works-action-btn works-action-btn--danger" onclick="markAll(0)"><i class="bi bi-x-circle"></i> Сбросить</button>
                        </div>

                        <div class="works-students">
                            <?php foreach ($students as $i => $s):
                                $isSubmitted = in_array($s['id'], $submittedIds);
                                $rowClass = 'works-student';
                                if ($isSubmitted) $rowClass .= ' works-student--submitted';
                            ?>
                                <div class="<?= $rowClass ?>" id="row-<?= $s['id'] ?>">
                                    <div class="works-student-num"><?= $i + 1 ?></div>
                                    <div class="works-student-info">
                                        <div class="works-student-name"><?= htmlspecialchars($s['full_name']) ?></div>
                                        <div class="works-student-meta">
                                            <?php if (!empty($s['zachetka'])): ?><code><?= htmlspecialchars($s['zachetka']) ?></code><?php endif; ?>
                                            <?php if (!empty($s['group_name'])): ?><span><i class="bi bi-people"></i> <?= htmlspecialchars($s['group_name']) ?></span><?php endif; ?>
                                        </div>
                                    </div>
                                    <label class="works-toggle">
                                        <input type="checkbox" name="submitted[<?= $s['id'] ?>]" value="1" class="submitted-checkbox" data-student="<?= $s['id'] ?>" <?= $isSubmitted ? 'checked' : '' ?>>
                                        <span class="works-toggle-switch"></span>
                                        <span class="works-toggle-label"><?= $isSubmitted ? 'Сдал' : 'Не сдал' ?></span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="works-card-footer">
                            <div class="works-footer-hint"><i class="bi bi-info-circle"></i> Сохраняются только сдавшие.</div>
                            <button type="submit" name="save" class="works-save-btn"><i class="bi bi-save"></i> Сохранить</button>
                        </div>
                    </form>

                <?php endif; ?>
            </div>

        <?php endif; ?>
    </div>
</div>

<script>
function markAll(value) {
    document.querySelectorAll('.submitted-checkbox').forEach(cb => {
        cb.checked = (value === 1);
    });
    updateLabels();
}
function updateLabels() {
    document.querySelectorAll('.submitted-checkbox').forEach(cb => {
        const row = document.getElementById('row-' + cb.dataset.student);
        const label = cb.closest('.works-toggle').querySelector('.works-toggle-label');
        if (cb.checked) {
            if (row) row.classList.add('works-student--submitted');
            if (label) label.textContent = 'Сдал';
        } else {
            if (row) row.classList.remove('works-student--submitted');
            if (label) label.textContent = 'Не сдал';
        }
    });
}
document.querySelectorAll('.submitted-checkbox').forEach(cb => cb.addEventListener('change', updateLabels));
document.addEventListener('DOMContentLoaded', updateLabels);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>