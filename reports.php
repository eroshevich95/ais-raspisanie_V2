<?php
// reports.php

$pageTitle = 'Отчёты';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/session_boot.php';
require_once __DIR__ . '/includes/security.php';

sendSecurityHeaders();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$userId = (int)$_SESSION['user_id'];

$subject  = trim($_GET['subject'] ?? '');
$groupId  = (int)($_GET['group_id'] ?? 0);
$from     = $_GET['from'] ?? date('Y-m-01');
$to       = $_GET['to']   ?? date('Y-m-d');

$stmt = $pdo->prepare("SELECT DISTINCT discipline FROM lessons WHERE user_id = ? ORDER BY discipline");
$stmt->execute([$userId]);
$subjects = $stmt->fetchAll(PDO::FETCH_COLUMN);

$stmt = $pdo->prepare("
    SELECT DISTINCT g.id, g.name
    FROM groups_table g
    JOIN lessons l ON l.group_id = g.id
    WHERE l.user_id = ?
    ORDER BY g.name
");
$stmt->execute([$userId]);
$groups = $stmt->fetchAll();

$report = null;

if ($subject !== '' && $groupId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM groups_table WHERE id = ?");
    $stmt->execute([$groupId]);
    $groupInfo = $stmt->fetch();

    // Занятия (объединяем потоковые)
    $stmt = $pdo->prepare("
        SELECT
            MIN(l.id) AS id,
            l.lesson_date, l.lesson_time, l.lesson_number, l.lesson_type,
            l.audience, l.subgroup, l.discipline,
            GROUP_CONCAT(DISTINCT g.name ORDER BY g.name SEPARATOR ', ') AS groups_list,
            COUNT(DISTINCT l.group_id) AS groups_count
        FROM lessons l
        LEFT JOIN groups_table g ON l.group_id = g.id
        WHERE l.user_id = ? AND l.discipline = ? AND l.group_id = ?
          AND l.lesson_date BETWEEN ? AND ?
        GROUP BY l.lesson_date, l.lesson_number, l.discipline, l.lesson_type,
                 l.audience, COALESCE(l.subgroup, '0')
        ORDER BY l.lesson_date, l.lesson_time, l.lesson_number
    ");
    $stmt->execute([$userId, $subject, $groupId, $from, $to]);
    $lessons = $stmt->fetchAll();

    // Все lesson_id потока для каждого primary
    $allStreamLessonIds = [];
    $flatLessonIds = [];
    foreach ($lessons as $l) {
        $stmt = $pdo->prepare("
            SELECT id FROM lessons
            WHERE user_id = ? AND lesson_date = ? AND lesson_number <=> ?
              AND discipline = ? AND lesson_type = ?
              AND COALESCE(audience, '') = COALESCE(?, '')
              AND COALESCE(subgroup, '0') = COALESCE(?, '0')
        ");
        $stmt->execute([
            $userId, $l['lesson_date'], $l['lesson_number'],
            $l['discipline'], $l['lesson_type'], $l['audience'], $l['subgroup'],
        ]);
        $ids = array_column($stmt->fetchAll(), 'id');
        $allStreamLessonIds[$l['id']] = $ids;
        $flatLessonIds = array_merge($flatLessonIds, $ids);
    }
    $flatLessonIds = array_values(array_unique($flatLessonIds));

    // Студенты
    $stmt = $pdo->prepare("SELECT id, full_name, zachetka FROM students WHERE group_id = ? ORDER BY full_name");
    $stmt->execute([$groupId]);
    $students = $stmt->fetchAll();

    // Посещаемость
    $attendance = [];
    if (!empty($flatLessonIds)) {
        $in = implode(',', array_fill(0, count($flatLessonIds), '?'));
        $stmt = $pdo->prepare("SELECT student_id, lesson_id, is_present FROM attendance WHERE lesson_id IN ($in)");
        $stmt->execute($flatLessonIds);
        foreach ($stmt->fetchAll() as $row) {
            $attendance[$row['student_id']][$row['lesson_id']] = (int)$row['is_present'];
        }
    }

    // Работы
    $worksByStudent = [];
    $allWorks = [];
    if (!empty($flatLessonIds)) {
        $in = implode(',', array_fill(0, count($flatLessonIds), '?'));
        $stmt = $pdo->prepare("SELECT DISTINCT student_id, work_number FROM submitted_works WHERE lesson_id IN ($in) ORDER BY work_number");
        $stmt->execute($flatLessonIds);
        foreach ($stmt->fetchAll() as $row) {
            $worksByStudent[$row['student_id']][] = $row['work_number'];
            if (!in_array($row['work_number'], $allWorks)) $allWorks[] = $row['work_number'];
        }
    }
    sort($allWorks);

    // Статистика по студентам
    $stats = [];
    $totalLessons = count($lessons);
    foreach ($students as $s) {
        $present = $absent = $unmarked = 0;
        $byLesson = [];
        foreach ($lessons as $l) {
            $mark = null;
            $streamIds = $allStreamLessonIds[$l['id']] ?? [$l['id']];
            foreach ($streamIds as $sid) {
                if (isset($attendance[$s['id']][$sid])) { $mark = $attendance[$s['id']][$sid]; break; }
            }
            $byLesson[$l['id']] = $mark;
            if ($mark === 1) $present++;
            elseif ($mark === 0) $absent++;
            else $unmarked++;
        }
        $marked = $present + $absent;
        $pct = $marked > 0 ? round($present / $marked * 100) : null;
        $works = array_unique($worksByStudent[$s['id']] ?? []);

        $stats[] = [
            'student'   => $s,
            'present'   => $present,
            'absent'    => $absent,
            'unmarked'  => $unmarked,
            'marked'    => $marked,
            'pct'       => $pct,
            'works'     => $works,
            'works_cnt' => count($works),
            'by_lesson' => $byLesson,
        ];
    }

    usort($stats, fn($a, $b) => strcoll(mb_strtolower($a['student']['full_name']), mb_strtolower($b['student']['full_name'])));

    $totalPresent = array_sum(array_column($stats, 'present'));
    $totalAbsent  = array_sum(array_column($stats, 'absent'));
    $avgPct = ($totalPresent + $totalAbsent) > 0 ? round($totalPresent / ($totalPresent + $totalAbsent) * 100) : null;
    $totalWorksAll = array_sum(array_column($stats, 'works_cnt'));

    $report = [
        'group'         => $groupInfo,
        'lessons'       => $lessons,
        'students'      => $students,
        'stats'         => $stats,
        'allWorks'      => $allWorks,
        'totalLessons'  => $totalLessons,
        'totalPresent'  => $totalPresent,
        'totalAbsent'   => $totalAbsent,
        'avgPct'        => $avgPct,
        'totalWorksAll' => $totalWorksAll,
    ];
}

$typeLabels = [
    'lecture'  => ['label' => 'Лекция',       'color' => 'primary',   'icon' => 'bi-book'],
    'practice' => ['label' => 'Практика',     'color' => 'success',   'icon' => 'bi-pencil'],
    'lab'      => ['label' => 'Лабораторная', 'color' => 'info',      'icon' => 'bi-flask'],
    'seminar'  => ['label' => 'Семинар',      'color' => 'warning',   'icon' => 'bi-people'],
    'other'    => ['label' => 'Другое',       'color' => 'secondary', 'icon' => 'bi-journal'],
];

require_once __DIR__ . '/includes/header.php';
?>

<style>
.rep-header { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
.rep-header h2 { font-size: 1.7rem; font-weight: 700; letter-spacing: -0.02em; margin: 0 0 6px; }
.rep-header p { color: #86868b; margin: 0; font-size: 0.95rem; }

.rep-filter { background: #fff; border-radius: 20px; border: 1px solid #eaeaea; box-shadow: 0 4px 16px rgba(0,0,0,0.04); padding: 24px; margin-bottom: 20px; }
.rep-filter-row { display: grid; grid-template-columns: 2fr 2fr 1fr 1fr auto; gap: 12px; align-items: end; }
@media (max-width: 991px) { .rep-filter-row { grid-template-columns: 1fr 1fr; } }
@media (max-width: 575px) { .rep-filter-row { grid-template-columns: 1fr; } }
.rep-filter-label { font-size: 0.78rem; font-weight: 700; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.3px; }
.rep-filter-label i { color: #0071e3; margin-right: 4px; }
.rep-filter-label .req { color: #ff453a; }
.rep-select, .rep-input { width: 100%; padding: 11px 14px; border-radius: 10px; border: 1.5px solid #e5e5ea; background: #fff; font-size: 0.9rem; font-family: inherit; outline: none; transition: all 0.2s; }
.rep-select:focus, .rep-input:focus { border-color: #0071e3; box-shadow: 0 0 0 4px rgba(0,113,227,0.12); }
.rep-submit-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 11px 24px; border-radius: 10px; background: linear-gradient(135deg, #0071e3 0%, #5e5ce6 100%); color: #fff; border: none; font-size: 0.9rem; font-weight: 600; cursor: pointer; font-family: inherit; transition: all 0.25s; box-shadow: 0 6px 16px rgba(0,113,227,0.25); white-space: nowrap; }
.rep-submit-btn:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(0,113,227,0.4); }
.rep-filter-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; padding-top: 12px; border-top: 1px solid #f0f0f2; }
.rep-action-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 10px; font-size: 0.82rem; font-weight: 600; cursor: pointer; font-family: inherit; transition: all 0.2s; border: 1.5px solid; background: #fff; text-decoration: none; }
.rep-action-btn--reset { color: #495057; border-color: #d2d2d7; }
.rep-action-btn--reset:hover { background: #495057; color: #fff; border-color: #495057; transform: translateY(-2px); }
.rep-action-btn--print { color: #0071e3; border-color: #0071e3; }
.rep-action-btn--print:hover { background: #0071e3; color: #fff; transform: translateY(-2px); }
.rep-action-btn--csv { color: #198754; border-color: #198754; }
.rep-action-btn--csv:hover { background: #198754; color: #fff; transform: translateY(-2px); }

.rep-placeholder { background: #fff; border-radius: 20px; border: 1px solid #eaeaea; box-shadow: 0 4px 16px rgba(0,0,0,0.04); padding: 60px 24px; text-align: center; }
.rep-placeholder-icon { width: 80px; height: 80px; margin: 0 auto 20px; border-radius: 20px; background: linear-gradient(135deg, #e7f1ff 0%, #d6d0ff 100%); color: #0071e3; display: flex; align-items: center; justify-content: center; font-size: 2.2rem; }
.rep-placeholder h4 { font-size: 1.2rem; font-weight: 700; margin: 0 0 8px; }
.rep-placeholder p { color: #86868b; margin: 0; font-size: 0.9rem; }

.rep-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 20px; }
.rep-stat { background: #fff; border-radius: 20px; padding: 22px; border: 1px solid #eaeaea; box-shadow: 0 4px 16px rgba(0,0,0,0.04); transition: all 0.3s; position: relative; overflow: hidden; }
.rep-stat::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: var(--rep-color, #0071e3); }
.rep-stat:hover { transform: translateY(-4px); box-shadow: 0 20px 40px rgba(0,0,0,0.08); }
.rep-stat-icon { width: 42px; height: 42px; border-radius: 12px; background: var(--rep-bg, rgba(0,113,227,0.1)); color: var(--rep-color, #0071e3); display: flex; align-items: center; justify-content: center; font-size: 1.25rem; margin-bottom: 12px; }
.rep-stat-value { font-size: 2rem; font-weight: 700; line-height: 1; letter-spacing: -0.02em; color: #1d1d1f; margin-bottom: 6px; }
.rep-stat-label { font-size: 0.82rem; color: #86868b; font-weight: 500; }
.rep-stat-sub { font-size: 0.75rem; color: #86868b; margin-top: 4px; }

.rep-card { background: #fff; border-radius: 20px; border: 1px solid #eaeaea; box-shadow: 0 4px 16px rgba(0,0,0,0.04); overflow: hidden; margin-bottom: 20px; }
.rep-card-header { padding: 18px 24px; border-bottom: 1px solid #f0f0f2; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; background: linear-gradient(135deg, #fafafc 0%, #fff 100%); }
.rep-card-title { font-size: 1rem; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 8px; }
.rep-card-title i { color: #0071e3; }
.rep-card-badge { font-size: 0.7rem; font-weight: 600; color: #86868b; background: #f5f5f7; padding: 4px 12px; border-radius: 20px; }

.rep-live-search { padding: 14px 20px; border-bottom: 1px solid #f0f0f2; background: #fafafc; }
.rep-live-search-row { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
.rep-live-search-input { flex: 1; min-width: 200px; position: relative; }
.rep-live-search-input input { width: 100%; padding: 10px 14px 10px 40px; border-radius: 10px; border: 1.5px solid #e5e5ea; background: #fff; font-size: 0.9rem; font-family: inherit; outline: none; transition: all 0.2s; }
.rep-live-search-input input:focus { border-color: #0071e3; box-shadow: 0 0 0 4px rgba(0,113,227,0.12); }
.rep-live-search-input .icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #0071e3; font-size: 0.95rem; pointer-events: none; }
.rep-live-search-input .clear-btn { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: transparent; border: none; color: #86868b; cursor: pointer; padding: 4px 8px; border-radius: 6px; font-size: 0.85rem; }
.rep-live-search-input .clear-btn:hover { background: #f0f0f2; color: #1d1d1f; }
.rep-live-search-count { font-size: 0.78rem; color: #86868b; font-weight: 600; }
.rep-live-search-count b { color: #0071e3; }

.rep-table-wrap { overflow-x: auto; }
.rep-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.rep-table thead th { background: #fafafc; padding: 12px 14px; text-align: left; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; color: #86868b; border-bottom: 1px solid #f0f0f2; white-space: nowrap; position: sticky; top: 0; z-index: 1; }
.rep-table thead th.center { text-align: center; }
.rep-table tbody td { padding: 12px 14px; border-bottom: 1px solid #f5f5f7; vertical-align: middle; }
.rep-table tbody tr:last-child td { border-bottom: none; }
.rep-table tbody tr:hover { background: #fafafc; }
.rep-table tbody tr.rep-row-danger { background: rgba(255,69,58,0.05); }
.rep-table tbody tr.rep-row-warning { background: rgba(255,159,10,0.05); }
.rep-table .num { text-align: center; }
.rep-table .student-name { font-weight: 600; color: #1d1d1f; }
.rep-table code { background: rgba(0,113,227,0.08); color: #0071e3; padding: 2px 8px; border-radius: 6px; font-size: 0.72rem; font-family: "SF Mono", Monaco, monospace; font-weight: 600; }

.rep-progress { display: inline-flex; align-items: center; gap: 8px; min-width: 90px; }
.rep-progress-bar { flex: 1; height: 6px; background: #f0f0f2; border-radius: 3px; overflow: hidden; min-width: 50px; }
.rep-progress-fill { height: 100%; border-radius: 3px; transition: width 0.4s; }
.rep-progress-fill--success { background: #30d158; }
.rep-progress-fill--warning { background: #ff9f0a; }
.rep-progress-fill--danger  { background: #ff453a; }
.rep-progress-text { font-size: 0.75rem; font-weight: 700; min-width: 36px; text-align: right; }

.rep-matrix { width: 100%; border-collapse: collapse; font-size: 0.78rem; }
.rep-matrix th { background: #fafafc; padding: 8px 6px; text-align: center; font-size: 0.68rem; font-weight: 700; color: #86868b; border-bottom: 1px solid #f0f0f2; border-right: 1px solid #f5f5f7; white-space: nowrap; }
.rep-matrix th:first-child { text-align: left; padding-left: 14px; position: sticky; left: 0; background: #fafafc; z-index: 2; min-width: 160px; }
.rep-matrix th.th-total { background: #f0f0f2; color: #1d1d1f; }
.rep-matrix td { padding: 8px 6px; text-align: center; border-bottom: 1px solid #f5f5f7; border-right: 1px solid #f5f5f7; font-weight: 600; }
.rep-matrix td:first-child { text-align: left; padding-left: 14px; font-weight: 500; font-size: 0.78rem; position: sticky; left: 0; background: #fff; z-index: 1; }
.rep-matrix td.td-total { background: #fafafc; font-weight: 700; }
.rep-matrix .mark-yes { color: #30d158; font-weight: 700; }
.rep-matrix .mark-no { color: #ff453a; font-weight: 700; }
.rep-matrix .mark-na { color: #d2d2d7; }

.rep-lesson-row { display: grid; grid-template-columns: auto 1fr auto auto; gap: 12px; padding: 12px 20px; border-bottom: 1px solid #f5f5f7; align-items: center; transition: all 0.15s; }
.rep-lesson-row:last-child { border-bottom: none; }
.rep-lesson-row:hover { background: #fafafc; }
.rep-lesson-date { display: inline-flex; flex-direction: column; align-items: center; justify-content: center; min-width: 52px; padding: 6px 10px; border-radius: 10px; background: #f5f5f7; font-weight: 700; font-size: 0.75rem; line-height: 1.2; flex-shrink: 0; }
.rep-lesson-date .d { font-size: 0.88rem; }
.rep-lesson-date .m { font-size: 0.62rem; color: #86868b; text-transform: uppercase; }
.rep-lesson-title { font-size: 0.88rem; font-weight: 600; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.rep-lesson-meta { font-size: 0.75rem; color: #86868b; display: flex; gap: 10px; flex-wrap: wrap; margin-top: 2px; }
.rep-lesson-meta i { color: #0071e3; }
.rep-icon-btn { width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; border: 1.5px solid #e5e5ea; background: #fff; color: #86868b; text-decoration: none; font-size: 0.85rem; transition: all 0.2s; }
.rep-icon-btn:hover { background: #0071e3; color: #fff; border-color: #0071e3; transform: translateY(-2px); }

.rep-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 20px; font-size: 0.72rem; font-weight: 700; }
.rep-badge--primary { background: rgba(0,113,227,0.12); color: #0071e3; }
.rep-badge--success { background: rgba(48,209,88,0.12); color: #198754; }
.rep-badge--info    { background: rgba(13,202,240,0.15); color: #055160; }
.rep-badge--warning { background: rgba(255,159,10,0.15); color: #b25c00; }
.rep-badge--secondary { background: rgba(134,134,139,0.12); color: #495057; }
.rep-badge--stream { background: linear-gradient(135deg, #cfe2ff 0%, #d6d0ff 100%); color: #084298; }

.rep-empty { padding: 60px 24px; text-align: center; color: #86868b; }
.rep-empty i { font-size: 3rem; opacity: 0.3; display: block; margin-bottom: 12px; }

[data-theme="dark"] .rep-filter, [data-theme="dark"] .rep-stat,
[data-theme="dark"] .rep-card, [data-theme="dark"] .rep-placeholder { background: #1c1c1e; border-color: #2c2c2e; }
[data-theme="dark"] .rep-filter-label, [data-theme="dark"] .rep-card-title,
[data-theme="dark"] .rep-stat-value, [data-theme="dark"] .rep-placeholder h4,
[data-theme="dark"] .rep-table .student-name, [data-theme="dark"] .rep-lesson-title { color: #f5f5f7; }
[data-theme="dark"] .rep-select, [data-theme="dark"] .rep-input,
[data-theme="dark"] .rep-live-search-input input { background: #2c2c2e; border-color: #3c3c3e; color: #f5f5f7; }
[data-theme="dark"] .rep-card-header, [data-theme="dark"] .rep-live-search,
[data-theme="dark"] .rep-table thead th, [data-theme="dark"] .rep-matrix th,
[data-theme="dark"] .rep-matrix th:first-child { background: #2c2c2e; border-color: #3c3c3e; color: #a1a1a6; }
[data-theme="dark"] .rep-table tbody td, [data-theme="dark"] .rep-matrix td { border-color: #2c2c2e; }
[data-theme="dark"] .rep-table tbody tr:hover { background: #2c2c2e; }
[data-theme="dark"] .rep-matrix td:first-child { background: #1c1c1e; }
[data-theme="dark"] .rep-matrix td.td-total { background: #2c2c2e; }
[data-theme="dark"] .rep-matrix th.th-total { background: #3c3c3e; color: #f5f5f7; }
[data-theme="dark"] .rep-card-badge { background: #2c2c2e; color: #a1a1a6; }
[data-theme="dark"] .rep-lesson-date { background: #2c2c2e; color: #f5f5f7; }
[data-theme="dark"] .rep-icon-btn { background: #2c2c2e; border-color: #3c3c3e; color: #a1a1a6; }
[data-theme="dark"] .rep-progress-bar { background: #3c3c3e; }

@media print {
    .navbar, footer, .rep-filter, .rep-action-btn, .rep-live-search,
    .rep-lesson-actions, .rep-icon-btn, .btn { display: none !important; }
    .rep-card, .rep-stat { box-shadow: none !important; border: 1px solid #ddd !important; }
    body { background: #fff !important; }
}
</style>

<div class="rep-header">
    <div>
        <h2><i class="bi bi-bar-chart"></i> Отчёты</h2>
        <p><i class="bi bi-info-circle"></i> Посещаемость, сданные работы и аналитика по группе</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Дашборд
        </a>
    </div>
</div>

<form method="get" class="rep-filter">
    <div class="rep-filter-row">
        <div>
            <div class="rep-filter-label"><i class="bi bi-book"></i> Предмет <span class="req">*</span></div>
            <select name="subject" class="rep-select" required>
                <option value="">— Выберите —</option>
                <?php foreach ($subjects as $s): ?>
                    <option value="<?= htmlspecialchars($s) ?>" <?= $subject === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <div class="rep-filter-label"><i class="bi bi-people"></i> Группа <span class="req">*</span></div>
            <select name="group_id" class="rep-select" required>
                <option value="">— Выберите —</option>
                <?php foreach ($groups as $g): ?>
                    <option value="<?= $g['id'] ?>" <?= $groupId == $g['id'] ? 'selected' : '' ?>><?= htmlspecialchars($g['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <div class="rep-filter-label"><i class="bi bi-calendar-event"></i> С</div>
            <input type="date" name="from" class="rep-input" value="<?= htmlspecialchars($from) ?>">
        </div>
        <div>
            <div class="rep-filter-label"><i class="bi bi-calendar-event"></i> По</div>
            <input type="date" name="to" class="rep-input" value="<?= htmlspecialchars($to) ?>">
        </div>
        <div>
            <button type="submit" class="rep-submit-btn"><i class="bi bi-funnel"></i> Показать</button>
        </div>
    </div>

    <div class="rep-filter-actions">
        <a href="reports.php" class="rep-action-btn rep-action-btn--reset"><i class="bi bi-x-circle"></i> Сбросить</a>
        <?php if ($report): ?>
            <button type="button" onclick="window.print()" class="rep-action-btn rep-action-btn--print"><i class="bi bi-printer"></i> Печать</button>
            <button type="button" onclick="exportCSV()" class="rep-action-btn rep-action-btn--csv"><i class="bi bi-file-earmark-excel"></i> Скачать CSV</button>
        <?php endif; ?>
    </div>
</form>

<?php if (!$report): ?>
    <div class="rep-placeholder">
        <div class="rep-placeholder-icon"><i class="bi bi-funnel"></i></div>
        <h4>Выберите параметры</h4>
        <p>Укажите предмет и группу, чтобы увидеть отчёт</p>
    </div>
<?php else: ?>

    <div class="rep-card" style="margin-bottom: 20px;">
        <div class="rep-card-header">
            <div>
                <h3 class="rep-card-title" style="margin-bottom: 6px;"><i class="bi bi-file-earmark-bar-graph"></i> <?= htmlspecialchars($subject) ?></h3>
                <div style="font-size: 0.85rem; color: #86868b; display: flex; gap: 12px; flex-wrap: wrap;">
                    <span><i class="bi bi-people-fill" style="color:#0071e3;"></i> <strong style="color:#1d1d1f;"><?= htmlspecialchars($report['group']['name']) ?></strong></span>
                    <span><i class="bi bi-calendar-range" style="color:#0071e3;"></i> <?= date('d.m.Y', strtotime($from)) ?> — <?= date('d.m.Y', strtotime($to)) ?></span>
                </div>
            </div>
            <span class="rep-card-badge"><i class="bi bi-clock-history"></i> <?= count($report['lessons']) ?> занятий</span>
        </div>
    </div>

    <div class="rep-stats-grid">
        <div class="rep-stat" style="--rep-color:#0071e3; --rep-bg:rgba(0,113,227,0.1);">
            <div class="rep-stat-icon"><i class="bi bi-calendar-week"></i></div>
            <div class="rep-stat-value"><?= $report['totalLessons'] ?></div>
            <div class="rep-stat-label">Всего занятий</div>
            <div class="rep-stat-sub"><?= $report['avgPct'] !== null ? 'средняя посещаемость ' . $report['avgPct'] . '%' : 'нет отметок' ?></div>
        </div>
        <div class="rep-stat" style="--rep-color:#30d158; --rep-bg:rgba(48,209,88,0.1);">
            <div class="rep-stat-icon"><i class="bi bi-check-circle-fill"></i></div>
            <div class="rep-stat-value"><?= $report['totalPresent'] ?></div>
            <div class="rep-stat-label">Присутствий</div>
        </div>
        <div class="rep-stat" style="--rep-color:#ff453a; --rep-bg:rgba(255,69,58,0.1);">
            <div class="rep-stat-icon"><i class="bi bi-x-circle-fill"></i></div>
            <div class="rep-stat-value"><?= $report['totalAbsent'] ?></div>
            <div class="rep-stat-label">Пропусков</div>
        </div>
        <div class="rep-stat" style="--rep-color:#bf5af2; --rep-bg:rgba(191,90,242,0.1);">
            <div class="rep-stat-icon"><i class="bi bi-journal-check"></i></div>
            <div class="rep-stat-value"><?= $report['totalWorksAll'] ?></div>
            <div class="rep-stat-label">Работ сдано</div>
            <div class="rep-stat-sub">видов: <?= count($report['allWorks']) ?></div>
        </div>
    </div>

    <div class="rep-card">
        <div class="rep-live-search">
            <div class="rep-live-search-row">
                <div class="rep-live-search-input">
                    <i class="bi bi-search icon"></i>
                    <input type="text" id="repLiveSearch" placeholder="Живой поиск: ФИО, зачётка..." autocomplete="off">
                    <button class="clear-btn" type="button" id="repClearSearch" style="display:none;"><i class="bi bi-x-lg"></i></button>
                </div>
                <div class="rep-live-search-count">Найдено: <b id="repFoundCount"><?= count($report['stats']) ?></b> из <b><?= count($report['stats']) ?></b></div>
            </div>
        </div>
    </div>

    <div class="rep-card">
        <div class="rep-card-header">
            <h3 class="rep-card-title"><i class="bi bi-people-fill"></i> Сводная по студентам</h3>
            <span class="rep-card-badge"><i class="bi bi-sort-down"></i> по алфавиту</span>
        </div>
        <div class="rep-table-wrap">
            <table class="rep-table" id="repStudentsTable">
                <thead>
                <tr>
                    <th style="width: 40px;">№</th>
                    <th style="width: 90px;">Зачётка</th>
                    <th>Студент</th>
                    <th class="center" style="width: 80px;">Занятий</th>
                    <th class="center" style="width: 80px;">Был</th>
                    <th class="center" style="width: 90px;">Пропустил</th>
                    <th class="center" style="width: 90px;">Не отмеч.</th>
                    <th class="center" style="width: 130px;">Посещаемость</th>
                    <th class="center" style="width: 90px;">Работ</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($report['stats'] as $i => $r):
                    $pct = $r['pct'];
                    $pctClass = $pct === null ? 'secondary' : ($pct >= 75 ? 'success' : ($pct >= 50 ? 'warning' : 'danger'));
                    $rowClass = '';
                    if ($r['absent'] > 5) $rowClass = 'rep-row-danger';
                    elseif ($r['absent'] > 3) $rowClass = 'rep-row-warning';
                    $searchData = mb_strtolower($r['student']['full_name'] . ' ' . ($r['student']['zachetka'] ?? ''));
                ?>
                    <tr class="<?= $rowClass ?>" data-search="<?= htmlspecialchars($searchData) ?>">
                        <td class="num"><?= $i + 1 ?></td>
                        <td>
                            <?php if (!empty($r['student']['zachetka'])): ?>
                                <code><?= htmlspecialchars($r['student']['zachetka']) ?></code>
                            <?php else: ?>
                                <span style="color:#86868b;">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="student-name"><?= htmlspecialchars($r['student']['full_name']) ?></td>
                        <td class="num"><?= $report['totalLessons'] ?></td>
                        <td class="num" style="color:#198754; font-weight:700;"><?= $r['present'] ?></td>
                        <td class="num" style="color:#842029; font-weight:700;"><?= $r['absent'] ?></td>
                        <td class="num" style="color:#86868b;"><?= $r['unmarked'] ?></td>
                        <td class="num">
                            <?php if ($pct === null): ?>
                                <span style="color:#86868b;">—</span>
                            <?php else: ?>
                                <div class="rep-progress">
                                    <div class="rep-progress-bar"><div class="rep-progress-fill rep-progress-fill--<?= $pctClass ?>" style="width: <?= $pct ?>%;"></div></div>
                                    <span class="rep-progress-text" style="color: <?= $pct >= 75 ? '#198754' : ($pct >= 50 ? '#b25c00' : '#842029') ?>;"><?= $pct ?>%</span>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="num"><span class="rep-badge rep-badge--primary"><i class="bi bi-journal-check"></i> <?= $r['works_cnt'] ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if (!empty($report['lessons'])): ?>
        <div class="rep-card">
            <div class="rep-card-header">
                <h3 class="rep-card-title"><i class="bi bi-grid-3x3-gap"></i> Матрица посещаемости</h3>
                <span class="rep-card-badge"><span style="color:#198754;">✓</span> был · <span style="color:#842029;">✗</span> пропустил · <span style="color:#86868b;">·</span> не отмечен</span>
            </div>
            <div class="rep-table-wrap">
                <table class="rep-matrix">
                    <thead>
                    <tr>
                        <th>Студент</th>
                        <?php foreach ($report['lessons'] as $l):
                            $dateObj = new DateTime($l['lesson_date']);
                            $isStream = ($l['groups_count'] ?? 1) > 1;
                        ?>
                            <th title="<?= $dateObj->format('d.m.Y') ?> <?= formatLessonTime($l['lesson_time'], $l['lesson_number']) ?>">
                                <div style="display:flex; flex-direction:column; align-items:center; gap:1px;">
                                    <span style="font-size:0.72rem; color:#1d1d1f; font-weight:700;"><?= $dateObj->format('d.m') ?></span>
                                    <span style="font-size:0.62rem; color:#86868b;"><?= ruDayShort($l['lesson_date']) ?></span>
                                    <?php if ($isStream): ?><span style="font-size:0.55rem; color:#084298;">ПОТОК</span><?php endif; ?>
                                </div>
                            </th>
                        <?php endforeach; ?>
                        <th class="th-total">Σ</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($report['stats'] as $r):
                        $searchData = mb_strtolower($r['student']['full_name'] . ' ' . ($r['student']['zachetka'] ?? ''));
                    ?>
                        <tr data-search="<?= htmlspecialchars($searchData) ?>">
                            <td><?= htmlspecialchars($r['student']['full_name']) ?></td>
                            <?php foreach ($report['lessons'] as $l):
                                $mark = $r['by_lesson'][$l['id']] ?? null;
                            ?>
                                <td>
                                    <?php if ($mark === 1): ?><span class="mark-yes">✓</span>
                                    <?php elseif ($mark === 0): ?><span class="mark-no">✗</span>
                                    <?php else: ?><span class="mark-na">·</span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <td class="td-total"><span style="color:#842029;"><?= $r['absent'] ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($report['allWorks'])): ?>
        <div class="rep-card">
            <div class="rep-card-header">
                <h3 class="rep-card-title"><i class="bi bi-journal-check"></i> Сданные работы</h3>
                <span class="rep-card-badge"><span style="color:#198754;">✓</span> сдал · <span style="color:#86868b;">·</span> не сдал</span>
            </div>
            <div class="rep-table-wrap">
                <table class="rep-matrix">
                    <thead>
                    <tr>
                        <th>Студент</th>
                        <?php foreach ($report['allWorks'] as $w): ?>
                            <th title="<?= htmlspecialchars($w) ?>"><span style="font-size:0.68rem; max-width:80px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; display:inline-block;"><?= htmlspecialchars($w) ?></span></th>
                        <?php endforeach; ?>
                        <th class="th-total">Σ</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($report['stats'] as $r):
                        $searchData = mb_strtolower($r['student']['full_name'] . ' ' . ($r['student']['zachetka'] ?? ''));
                    ?>
                        <tr data-search="<?= htmlspecialchars($searchData) ?>">
                            <td><?= htmlspecialchars($r['student']['full_name']) ?></td>
                            <?php foreach ($report['allWorks'] as $w): ?>
                                <td>
                                    <?php if (in_array($w, $r['works'])): ?><span class="mark-yes">✓</span>
                                    <?php else: ?><span class="mark-na">·</span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <td class="td-total"><span style="color:#0071e3;"><?= $r['works_cnt'] ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <div class="rep-card">
        <div class="rep-card-header">
            <h3 class="rep-card-title"><i class="bi bi-list-ul"></i> Все занятия</h3>
            <span class="rep-card-badge"><?= count($report['lessons']) ?></span>
        </div>
        <?php if (empty($report['lessons'])): ?>
            <div class="rep-empty"><i class="bi bi-calendar-x"></i><p>За выбранный период занятий нет</p></div>
        <?php else: foreach ($report['lessons'] as $l):
            $t = $typeLabels[$l['lesson_type']] ?? $typeLabels['other'];
            $dateObj = new DateTime($l['lesson_date']);
            $isStream = ($l['groups_count'] ?? 1) > 1;
            $presentHere = 0;
            foreach ($report['stats'] as $r) if (($r['by_lesson'][$l['id']] ?? null) === 1) $presentHere++;
        ?>
            <div class="rep-lesson-row">
                <div class="rep-lesson-date">
                    <span class="d"><?= $dateObj->format('d') ?></span>
                    <span class="m"><?= $dateObj->format('M') ?></span>
                </div>
                <div>
                    <div class="rep-lesson-title">
                        <span class="rep-badge rep-badge--<?= $t['color'] ?>"><i class="<?= $t['icon'] ?>"></i> <?= $t['label'] ?></span>
                        <span><?= formatLessonTime($l['lesson_time'], $l['lesson_number']) ?></span>
                        <?php if ($isStream): ?><span class="rep-badge rep-badge--stream"><i class="bi bi-diagram-3"></i> поток · <?= $l['groups_count'] ?></span><?php endif; ?>
                    </div>
                    <div class="rep-lesson-meta">
                        <?php if (!empty($l['audience'])): ?><span><i class="bi bi-geo-alt-fill"></i> <?= htmlspecialchars($l['audience']) ?></span><?php endif; ?>
                        <span><i class="bi bi-check-circle-fill"></i> <?= $presentHere ?> из <?= count($report['students']) ?></span>
                    </div>
                </div>
                <div><span class="rep-badge rep-badge--success"><i class="bi bi-check-circle-fill"></i> <?= $presentHere ?></span></div>
                <div style="display:flex; gap:6px;">
                    <a href="attendance.php?lesson_id=<?= $l['id'] ?>" class="rep-icon-btn" title="Посещаемость"><i class="bi bi-check2-square"></i></a>
                    <a href="works.php?lesson_id=<?= $l['id'] ?>" class="rep-icon-btn" title="Работы"><i class="bi bi-journal-check"></i></a>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>

<?php endif; ?>

<script>
(function() {
    const search = document.getElementById('repLiveSearch');
    const clearBtn = document.getElementById('repClearSearch');
    const foundEl = document.getElementById('repFoundCount');
    if (!search) return;

    const allRows = () => Array.from(document.querySelectorAll('tr[data-search]'));
    const total = allRows().length;

    function apply() {
        const q = search.value.trim().toLowerCase();
        let found = 0;
        allRows().forEach(row => {
            const ok = !q || (row.dataset.search || '').includes(q);
            row.style.display = ok ? '' : 'none';
            if (ok) found++;
        });
        if (foundEl) foundEl.textContent = found;
        clearBtn.style.display = q ? 'inline-flex' : 'none';
    }

    let timer;
    search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(apply, 120); });
    clearBtn.addEventListener('click', () => { search.value = ''; apply(); search.focus(); });
})();

function exportCSV() {
    const table = document.getElementById('repStudentsTable');
    if (!table) return;
    const rows = [];
    const headers = [];
    table.querySelectorAll('thead th').forEach(th => headers.push('"' + th.textContent.trim().replace(/"/g, '""') + '"'));
    rows.push(headers.join(';'));
    table.querySelectorAll('tbody tr').forEach(tr => {
        if (tr.style.display === 'none') return;
        const cells = [];
        tr.querySelectorAll('td').forEach(td => {
            let txt = td.textContent.trim().replace(/\s+/g, ' ');
            cells.push('"' + txt.replace(/"/g, '""') + '"');
        });
        rows.push(cells.join(';'));
    });
    const csv = '\uFEFF' + rows.join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    const subject = <?= json_encode($subject) ?>;
    const group = <?= json_encode($report['group']['name'] ?? '') ?>;
    const from  = <?= json_encode($from) ?>;
    const to    = <?= json_encode($to) ?>;
    a.download = `report_${group}_${subject}_${from}_${to}.csv`.replace(/\s+/g, '_');
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>