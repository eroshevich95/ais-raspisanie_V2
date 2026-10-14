<?php
// schedule.php

$pageTitle = 'Расписание';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/kuzstu_api.php';
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

// --- Синхронизация с API ---
$syncResult = null;
if (isset($_GET['sync'])) {
    try {
        $syncResult = syncTeacherSchedule($pdo, $userId);
        $_SESSION['last_schedule_sync'] = time();
        if (!isset($syncResult['error'])) {
            auditLog($pdo, 'schedule.sync', [
                'description' => "Синхронизация: +{$syncResult['added']} новых, ~{$syncResult['updated']} обновлено",
            ]);
        }
    } catch (Throwable $e) {
        $syncResult = ['error' => $e->getMessage()];
    }
}

// --- POST: добавление/удаление ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (($_POST['action'] ?? '') === 'add') {
        $discipline = trim($_POST['discipline'] ?? '');
        $date       = $_POST['lesson_date'] ?? '';
        $number     = (int)($_POST['lesson_number'] ?? 0);
        $time       = getLessonStart($number);
        $type       = $_POST['lesson_type'] ?? 'practice';
        $groupId    = !empty($_POST['group_id']) ? (int)$_POST['group_id'] : null;
        $audience   = trim($_POST['audience'] ?? '');
        $newGroup   = trim($_POST['new_group'] ?? '');

        if (empty($discipline) || empty($date)) {
            redirectWith('schedule.php', 'error', 'Заполните дисциплину и дату');
        }

        if ($newGroup !== '') {
            $pdo->prepare("INSERT IGNORE INTO groups_table (name) VALUES (?)")->execute([$newGroup]);
            $stmt = $pdo->prepare("SELECT id FROM groups_table WHERE name = ?");
            $stmt->execute([$newGroup]);
            $groupId = (int)$stmt->fetchColumn();
        }

        $pdo->prepare("
            INSERT INTO lessons (user_id, group_id, discipline, lesson_date, lesson_time,
                                 lesson_type, lesson_number, audience, is_manual)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
        ")->execute([$userId, $groupId, $discipline, $date, $time, $type, $number ?: null, $audience]);

        auditLog($pdo, 'schedule.add', [
            'description' => "Добавлено занятие: {$discipline} ({$date})",
        ]);

        redirectWith('schedule.php?week=' . ($_POST['week_offset'] ?? 0), 'success', 'Занятие добавлено');
    }

    if (($_POST['action'] ?? '') === 'delete') {
        $lessonId = (int)$_POST['lesson_id'];
        $stmt = $pdo->prepare("SELECT discipline, lesson_date FROM lessons WHERE id = ? AND user_id = ?");
        $stmt->execute([$lessonId, $userId]);
        $l = $stmt->fetch();
        if ($l) {
            $pdo->prepare("DELETE FROM attendance WHERE lesson_id = ?")->execute([$lessonId]);
            $pdo->prepare("DELETE FROM submitted_works WHERE lesson_id = ?")->execute([$lessonId]);
            $pdo->prepare("DELETE FROM lessons WHERE id = ?")->execute([$lessonId]);
            auditLog($pdo, 'schedule.delete', [
                'description' => "Удалено занятие: {$l['discipline']} ({$l['lesson_date']})",
            ]);
        }
        redirectWith('schedule.php?week=' . ($_POST['week_offset'] ?? 0), 'success', 'Занятие удалено');
    }
}

$success = getFlash('success') ?? '';
$error   = getFlash('error') ?? '';

// --- Неделя ---
$weekOffset = (int)($_GET['week'] ?? 0);
$monday = new DateTime('monday this week');
if ($weekOffset !== 0) $monday->modify(($weekOffset > 0 ? '+' : '') . $weekOffset . ' week');
$sunday = (clone $monday)->modify('+6 days');

$stmt = $pdo->prepare("
    SELECT
        MIN(l.id) AS id,
        l.api_lesson_id,
        l.user_id,
        l.lesson_date,
        l.lesson_time,
        l.lesson_number,
        l.discipline,
        l.lesson_type,
        l.audience,
        l.subgroup,
        l.is_manual,
        GROUP_CONCAT(DISTINCT g.name ORDER BY g.name SEPARATOR ', ') AS group_name,
        COUNT(DISTINCT l.group_id) AS groups_count
    FROM lessons l
    LEFT JOIN groups_table g ON l.group_id = g.id
    WHERE l.user_id = ? AND l.lesson_date BETWEEN ? AND ?
    GROUP BY
        l.lesson_date, l.lesson_number, l.discipline,
        l.lesson_type, l.audience, COALESCE(l.subgroup, '0')
    ORDER BY l.lesson_date, l.lesson_time, l.lesson_number
");
$stmt->execute([$userId, $monday->format('Y-m-d'), $sunday->format('Y-m-d')]);
$lessons = $stmt->fetchAll();

$byDate = [];
foreach ($lessons as $l) $byDate[$l['lesson_date']][] = $l;

$groups = $pdo->query("SELECT id, name FROM groups_table ORDER BY name")->fetchAll();

$typeLabelsFull = [
    'lecture'  => ['label' => 'Лекция',       'color' => 'primary', 'icon' => 'bi-book'],
    'practice' => ['label' => 'Практика',     'color' => 'success', 'icon' => 'bi-pencil'],
    'lab'      => ['label' => 'Лабораторная', 'color' => 'info',    'icon' => 'bi-flask'],
    'seminar'  => ['label' => 'Семинар',      'color' => 'warning', 'icon' => 'bi-people'],
    'other'    => ['label' => 'Другое',       'color' => 'secondary','icon' => 'bi-journal'],
];

$currentLessonNum = getCurrentLessonNumber();
$nextLessonNum    = getNextLessonNumber();

require_once __DIR__ . '/includes/header.php';
?>

<style>
.sched-header { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
.sched-header h2 { font-size: 1.7rem; font-weight: 700; letter-spacing: -0.02em; margin: 0 0 6px; }
.sched-header p { color: #86868b; margin: 0; font-size: 0.95rem; }

.week-nav {
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    background: #fff; border-radius: 16px; padding: 12px 20px;
    border: 1px solid #eaeaea; box-shadow: 0 4px 16px rgba(0,0,0,0.04);
    margin-bottom: 24px; flex-wrap: wrap;
}
.week-nav-center { text-align: center; flex: 1; min-width: 200px; }
.week-nav-label { font-size: 1.05rem; font-weight: 700; color: #1d1d1f; letter-spacing: -0.01em; }
.week-nav-sub { font-size: 0.8rem; color: #86868b; margin-top: 2px; }
.week-nav-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px; border-radius: 10px;
    background: #f5f5f7; border: 1px solid #eaeaea; color: #1d1d1f;
    text-decoration: none; font-size: 0.85rem; font-weight: 600; transition: all 0.2s;
}
.week-nav-btn:hover { background: #0071e3; color: #fff; border-color: #0071e3; transform: translateY(-2px); }
.week-nav-btn--current {
    background: linear-gradient(135deg, #0071e3 0%, #5e5ce6 100%);
    color: #fff; border-color: transparent;
    box-shadow: 0 4px 12px rgba(0,113,227,0.3);
}
.week-nav-btn--current:hover { box-shadow: 0 8px 20px rgba(0,113,227,0.45); transform: translateY(-2px); }

.week-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 12px; margin-bottom: 32px; }
@media (max-width: 1199px) { .week-grid { grid-template-columns: repeat(4, 1fr); } }
@media (max-width: 767px)  { .week-grid { grid-template-columns: 1fr; } }

.day-col {
    background: #fff; border-radius: 16px; border: 1px solid #eaeaea;
    overflow: hidden; transition: all 0.3s; display: flex; flex-direction: column; min-height: 200px;
}
.day-col:hover { box-shadow: 0 12px 30px rgba(0,0,0,0.06); }
.day-col--today { border-color: #0071e3; border-width: 2px; box-shadow: 0 8px 24px rgba(0,113,227,0.15); }
.day-col--weekend { background: #fafafc; }

.day-header {
    padding: 12px 14px; border-bottom: 1px solid #f0f0f2;
    display: flex; justify-content: space-between; align-items: center;
}
.day-col--today .day-header {
    background: linear-gradient(135deg, #0071e3 0%, #5e5ce6 100%);
    color: #fff; border-bottom: none;
}
.day-name { font-size: 0.78rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; opacity: 0.75; }
.day-date { font-size: 1.15rem; font-weight: 700; letter-spacing: -0.02em; margin-top: 2px; }
.day-count { font-size: 0.7rem; font-weight: 600; padding: 3px 8px; border-radius: 20px; background: rgba(0,0,0,0.06); }
.day-col--today .day-count { background: rgba(255,255,255,0.25); color: #fff; }

.day-body { padding: 10px; flex: 1; display: flex; flex-direction: column; gap: 8px; }
.day-empty { padding: 20px 8px; text-align: center; color: #86868b; font-size: 0.8rem; opacity: 0.6; }

.mini-lesson {
    padding: 10px 12px; border-radius: 12px;
    background: #fafafc; border: 1px solid #f0f0f2;
    position: relative; transition: all 0.25s cubic-bezier(0.16,1,0.3,1);
    overflow: hidden; cursor: pointer;
}
.mini-lesson::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; background: #0071e3; }
.mini-lesson--lecture::before  { background: #0071e3; }
.mini-lesson--practice::before { background: #30d158; }
.mini-lesson--lab::before      { background: #5ac8fa; }
.mini-lesson--seminar::before  { background: #ff9f0a; }
.mini-lesson--other::before    { background: #86868b; }
.mini-lesson:hover { background: #fff; border-color: #0071e3; transform: translateX(2px); box-shadow: 0 4px 16px rgba(0,113,227,0.15); }
.mini-lesson--current {
    background: linear-gradient(135deg, #f0faf3 0%, #fff 100%);
    border-color: #30d158; border-width: 2px;
    animation: miniCurrentPulse 2.4s ease-in-out infinite;
}
.mini-lesson--current::before { background: #30d158; width: 4px; }
@keyframes miniCurrentPulse {
    0%, 100% { box-shadow: 0 4px 16px rgba(48,209,88,0.2), 0 0 0 0 rgba(48,209,88,0.5); }
    50%      { box-shadow: 0 8px 24px rgba(48,209,88,0.4), 0 0 0 10px rgba(48,209,88,0); }
}
.mini-lesson--past { opacity: 0.55; }
.mini-lesson--next {
    background: linear-gradient(135deg, #e7f6fb 0%, #fff 100%);
    border-color: #0dcaf0;
}
.mini-lesson--next::before { background: #0dcaf0; width: 4px; }

.mini-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; gap: 6px; }
.mini-time { font-size: 0.7rem; font-weight: 700; color: #1d1d1f; font-variant-numeric: tabular-nums; letter-spacing: -0.01em; }
.mini-badge { font-size: 0.6rem; font-weight: 700; padding: 2px 6px; border-radius: 8px; text-transform: uppercase; letter-spacing: 0.3px; white-space: nowrap; }
.mini-badge--current { background: #30d158; color: #fff; padding-left: 16px; position: relative; }
.mini-badge--current::before { content: ''; position: absolute; left: 5px; top: 50%; transform: translateY(-50%); width: 6px; height: 6px; background: #fff; border-radius: 50%; }
.mini-badge--next { background: #0dcaf0; color: #fff; }
.mini-badge--stream {
    display: inline-flex; align-items: center; gap: 3px;
    background: linear-gradient(135deg, #cfe2ff 0%, #d6d0ff 100%); color: #084298;
    font-size: 0.6rem; padding: 2px 7px; border-radius: 8px;
    font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; white-space: nowrap;
}
.mini-title {
    font-size: 0.82rem; font-weight: 600; color: #1d1d1f; line-height: 1.3; margin-bottom: 4px;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}
.mini-meta { font-size: 0.7rem; color: #86868b; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.mini-meta span { display: inline-flex; align-items: center; gap: 3px; }
.mini-meta i { color: #0071e3; }

[data-theme="dark"] .day-col,
[data-theme="dark"] .week-nav,
[data-theme="dark"] .mini-lesson { background: #1c1c1e; border-color: #2c2c2e; }
[data-theme="dark"] .day-col--weekend { background: #141416; }
[data-theme="dark"] .mini-title,
[data-theme="dark"] .day-date,
[data-theme="dark"] .week-nav-label { color: #f5f5f7; }
[data-theme="dark"] .mini-time { color: #f5f5f7; }
[data-theme="dark"] .mini-lesson--current { background: linear-gradient(135deg, rgba(48,209,88,0.15) 0%, #1c1c1e 100%); }
[data-theme="dark"] .mini-lesson--next { background: linear-gradient(135deg, rgba(13,202,240,0.15) 0%, #1c1c1e 100%); }
[data-theme="dark"] .week-nav-btn { background: #2c2c2e; color: #f5f5f7; border-color: #3c3c3e; }
</style>

<div class="sched-header">
    <div>
        <h2><i class="bi bi-calendar-week"></i> Расписание</h2>
        <p><i class="bi bi-info-circle"></i> Занятия синхронизируются с порталом КузГТУ</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="?sync=1&week=<?= $weekOffset ?>" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-arrow-repeat"></i> Синхронизировать
        </a>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addModal">
            <i class="bi bi-plus-circle"></i> Добавить
        </button>
    </div>
</div>

<?php if ($syncResult): ?>
    <?php if (isset($syncResult['error'])): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle"></i>
            Ошибка синхронизации: <?= htmlspecialchars($syncResult['error']) ?>
        </div>
    <?php else: ?>
        <div class="alert alert-success">
            <i class="bi bi-check-circle"></i>
            Синхронизация: добавлено <?= $syncResult['added'] ?>, обновлено <?= $syncResult['updated'] ?> занятий
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle"></i> <?= htmlspecialchars($success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger">
        <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<div class="week-nav">
    <a href="?week=<?= $weekOffset - 1 ?>" class="week-nav-btn">
        <i class="bi bi-chevron-left"></i> Предыдущая
    </a>
    <div class="week-nav-center">
        <div class="week-nav-label"><?= $monday->format('d.m') ?> — <?= $sunday->format('d.m.Y') ?></div>
        <div class="week-nav-sub">
            <?php
            if ($weekOffset === 0) echo 'Текущая неделя';
            elseif ($weekOffset === -1) echo 'Прошлая неделя';
            elseif ($weekOffset === 1) echo 'Следующая неделя';
            else echo $weekOffset > 0 ? "Через $weekOffset нед." : "Назад " . abs($weekOffset) . " нед.";
            ?>
        </div>
    </div>
    <div class="d-flex gap-2">
        <?php if ($weekOffset !== 0): ?>
            <a href="?week=0" class="week-nav-btn week-nav-btn--current">
                <i class="bi bi-calendar-check"></i> Текущая
            </a>
        <?php endif; ?>
        <a href="?week=<?= $weekOffset + 1 ?>" class="week-nav-btn">
            Следующая <i class="bi bi-chevron-right"></i>
        </a>
    </div>
</div>

<div class="week-grid">
    <?php for ($i = 0; $i < 7; $i++):
        $day = (clone $monday)->modify("+$i days");
        $dateStr = $day->format('Y-m-d');
        $isToday = $dateStr === $today;
        $isWeekend = $i >= 5;
        $dayLessons = $byDate[$dateStr] ?? [];

        $colClass = 'day-col';
        if ($isToday) $colClass .= ' day-col--today';
        elseif ($isWeekend) $colClass .= ' day-col--weekend';

        $dayNamesFull = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
    ?>
        <div class="<?= $colClass ?>">
            <div class="day-header">
                <div>
                    <div class="day-name">
                        <?= $dayNamesFull[$i] ?>
                        <?php if ($isToday): ?> · Сегодня<?php endif; ?>
                    </div>
                    <div class="day-date"><?= $day->format('d.m') ?></div>
                </div>
                <?php if (!empty($dayLessons)): ?>
                    <span class="day-count"><?= count($dayLessons) ?></span>
                <?php endif; ?>
            </div>

            <div class="day-body">
                <?php if (empty($dayLessons)): ?>
                    <div class="day-empty">
                        <i class="bi bi-cup-hot"></i>
                        <div>Пар нет</div>
                    </div>
                <?php else: foreach ($dayLessons as $l):
                    $t = $typeLabelsFull[$l['lesson_type']] ?? $typeLabelsFull['other'];
                    $isCurrent = $isToday && $currentLessonNum && (int)$l['lesson_number'] === $currentLessonNum;
                    $isNext = $isToday && !$isCurrent && $nextLessonNum && (int)$l['lesson_number'] === $nextLessonNum;
                    $isPast = $l['lesson_time'] && $l['lesson_time'] < date('H:i:s') && !$isCurrent;
                    $isStream = ($l['groups_count'] ?? 1) > 1;

                    $miniClass = 'mini-lesson mini-lesson--' . $l['lesson_type'];
                    if ($isCurrent) $miniClass .= ' mini-lesson--current';
                    elseif ($isNext) $miniClass .= ' mini-lesson--next';
                    elseif ($isPast) $miniClass .= ' mini-lesson--past';

                    $lessonData = [
                        'id'          => (int)$l['id'],
                        'discipline'  => $l['discipline'],
                        'type_label'  => $t['label'],
                        'type_icon'   => $t['icon'],
                        'group'       => $l['group_name'] ?? '',
                        'audience'    => $l['audience'] ?? '',
                        'subgroup'    => $l['subgroup'] ?? '',
                        'time'        => formatLessonTime($l['lesson_time'], $l['lesson_number']),
                        'date'        => date('d.m.Y', strtotime($l['lesson_date'])),
                        'day_name'    => ruDayFull($l['lesson_date']),
                        'is_manual'   => (bool)$l['is_manual'],
                        'is_current'  => $isCurrent,
                        'is_stream'   => $isStream,
                        'groups_count'=> (int)($l['groups_count'] ?? 1),
                    ];
                ?>
                    <div class="<?= $miniClass ?>"
                         onclick='openLessonModal(<?= json_encode($lessonData, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>)'>
                        <div class="mini-top">
                            <span class="mini-time">
                                <?= $l['lesson_time'] ? substr($l['lesson_time'], 0, 5) : '—' ?>
                            </span>
                            <div class="d-flex gap-1 flex-wrap">
                                <?php if ($isCurrent): ?>
                                    <span class="mini-badge mini-badge--current">LIVE</span>
                                <?php elseif ($isNext): ?>
                                    <span class="mini-badge mini-badge--next">Далее</span>
                                <?php endif; ?>
                                <?php if ($isStream): ?>
                                    <span class="mini-badge--stream"><i class="bi bi-diagram-3"></i> поток</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="mini-title"><?= htmlspecialchars($l['discipline']) ?></div>

                        <div class="mini-meta">
                            <?php if (!empty($l['group_name'])): ?>
                                <span><i class="bi bi-people-fill"></i> <?= htmlspecialchars($l['group_name']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($l['audience'])): ?>
                                <span><i class="bi bi-geo-alt-fill"></i> <?= htmlspecialchars($l['audience']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    <?php endfor; ?>
</div>

<!-- Модалка деталей -->
<div class="modal fade" id="lessonModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:24px; border:none;">
            <div class="modal-header" style="border-bottom:1px solid #f0f0f2; padding:20px 24px;">
                <h5 style="font-weight:700; display:flex; align-items:center; gap:10px; margin:0;">
                    <i class="bi bi-book" id="modalIcon"></i>
                    <span id="modalTitle">—</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding:24px;">
                <div id="modalStreamBadge" style="display:none; margin-bottom:16px;">
                    <div style="padding:10px 14px; border-radius:12px; background:linear-gradient(135deg, #cfe2ff 0%, #d6d0ff 100%); color:#084298; font-size:0.85rem; font-weight:600;">
                        <i class="bi bi-diagram-3"></i>
                        <span id="modalStreamText"></span>
                    </div>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:24px;">
                    <div><small style="color:#86868b; text-transform:uppercase; font-size:0.7rem; font-weight:600;">Дата</small><div id="modalDate" style="font-weight:600;">—</div></div>
                    <div><small style="color:#86868b; text-transform:uppercase; font-size:0.7rem; font-weight:600;">Время</small><div id="modalTime" style="font-weight:600;">—</div></div>
                    <div><small style="color:#86868b; text-transform:uppercase; font-size:0.7rem; font-weight:600;">Группа</small><div id="modalGroup" style="font-weight:600;">—</div></div>
                    <div><small style="color:#86868b; text-transform:uppercase; font-size:0.7rem; font-weight:600;">Аудитория</small><div id="modalAudience" style="font-weight:600;">—</div></div>
                </div>
                <div style="display:flex; gap:10px;">
                    <a href="#" id="modalAttendBtn" class="btn btn-primary flex-fill">
                        <i class="bi bi-check2-square"></i> Посещаемость
                    </a>
                    <a href="#" id="modalWorksBtn" class="btn btn-outline-primary flex-fill">
                        <i class="bi bi-journal-check"></i> Работы
                    </a>
                </div>
                <div id="modalDeleteWrap" style="margin-top:12px;">
                    <form method="post" onsubmit="return confirm('Удалить занятие?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="lesson_id" id="modalDeleteId" value="">
                        <input type="hidden" name="week_offset" value="<?= $weekOffset ?>">
                        <button type="submit" class="btn btn-outline-danger w-100">
                            <i class="bi bi-trash"></i> Удалить занятие
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Модалка добавления -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" class="modal-content" style="border-radius:24px; border:none;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="week_offset" value="<?= $weekOffset ?>">

            <div class="modal-header" style="border-bottom:1px solid #f0f0f2; padding:20px 24px;">
                <h5 style="font-weight:700; margin:0; display:flex; align-items:center; gap:10px;">
                    <i class="bi bi-plus-circle"></i> Добавить занятие
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding:24px;">
                <div class="mb-3">
                    <label class="form-label">Дисциплина *</label>
                    <input type="text" name="discipline" class="form-control" required>
                </div>
                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label">Дата *</label>
                        <input type="date" name="lesson_date" class="form-control" value="<?= $today ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Пара №</label>
                        <select name="lesson_number" class="form-select">
                            <option value="">—</option>
                            <?php foreach (LESSON_TIMES as $num => $t): ?>
                                <option value="<?= $num ?>"><?= $num ?> (<?= $t['start'] ?>–<?= $t['end'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row g-2 mt-2">
                    <div class="col-md-6">
                        <label class="form-label">Тип</label>
                        <select name="lesson_type" class="form-select">
                            <option value="practice">Практика</option>
                            <option value="lecture">Лекция</option>
                            <option value="lab">Лабораторная</option>
                            <option value="seminar">Семинар</option>
                            <option value="other">Другое</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Аудитория</label>
                        <input type="text" name="audience" class="form-control" placeholder="1311">
                    </div>
                </div>
                <div class="mt-2">
                    <label class="form-label">Группа</label>
                    <select name="group_id" class="form-select">
                        <option value="">— Выберите —</option>
                        <?php foreach ($groups as $g): ?>
                            <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mt-2">
                    <label class="form-label">…или новая группа</label>
                    <input type="text" name="new_group" class="form-control" placeholder="ИСт-241">
                </div>
            </div>
            <div class="modal-footer" style="border-top:1px solid #f0f0f2; padding:16px 24px;">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Добавить</button>
            </div>
        </form>
    </div>
</div>

<script>
function openLessonModal(data) {
    document.getElementById('modalTitle').textContent = data.discipline;
    document.getElementById('modalDate').textContent = data.day_name + ', ' + data.date;
    document.getElementById('modalTime').textContent = data.time;
    document.getElementById('modalGroup').textContent = data.group || '—';
    document.getElementById('modalAudience').textContent = data.audience || '—';
    document.getElementById('modalDeleteId').value = data.id;
    document.getElementById('modalIcon').className = 'bi ' + data.type_icon;

    const streamBadge = document.getElementById('modalStreamBadge');
    if (data.is_stream) {
        streamBadge.style.display = 'block';
        document.getElementById('modalStreamText').textContent = 'Потоковая лекция для ' + data.groups_count + ' групп';
    } else {
        streamBadge.style.display = 'none';
    }

    document.getElementById('modalAttendBtn').href = 'attendance.php?lesson_id=' + data.id;
    document.getElementById('modalWorksBtn').href  = 'works.php?lesson_id=' + data.id;
    document.getElementById('modalDeleteWrap').style.display = data.is_manual ? 'block' : 'none';

    new bootstrap.Modal(document.getElementById('lessonModal')).show();
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>