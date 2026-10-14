<?php
// notifications.php

$pageTitle = 'Уведомления';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/notifications.php';
require_once __DIR__ . '/includes/session_boot.php';
require_once __DIR__ . '/includes/security.php';

sendSecurityHeaders();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$daysBack = max(1, min(90, (int)($_GET['days'] ?? 14)));
$unmarked = getUnmarkedLessons($pdo, $userId, $daysBack, 200);

$byDate = [];
foreach ($unmarked as $l) $byDate[$l['lesson_date']][] = $l;

$typeLabelsFull = [
    'lecture'  => ['label' => 'Лекция',       'color' => 'primary'],
    'practice' => ['label' => 'Практика',     'color' => 'success'],
    'lab'      => ['label' => 'Лабораторная', 'color' => 'info'],
    'seminar'  => ['label' => 'Семинар',      'color' => 'warning'],
    'other'    => ['label' => 'Другое',       'color' => 'secondary'],
];

require_once __DIR__ . '/includes/header.php';
?>

<style>
.nf-header { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
.nf-header h2 { font-size: 1.7rem; font-weight: 700; letter-spacing: -0.02em; margin: 0 0 6px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.nf-header p { color: #86868b; margin: 0; font-size: 0.95rem; }
.nf-days-picker { background: #fff; border-radius: 14px; padding: 10px 16px; border: 1px solid #eaeaea; box-shadow: 0 4px 16px rgba(0,0,0,0.04); display: inline-flex; align-items: center; gap: 12px; font-size: 0.85rem; }
.nf-days-picker span { color: #86868b; }
.nf-days-picker a { padding: 6px 12px; border-radius: 8px; text-decoration: none; color: #495057; font-weight: 600; transition: all 0.2s; }
.nf-days-picker a:hover { background: #f5f5f7; color: #0071e3; }
.nf-days-picker a.active { background: linear-gradient(135deg, #0071e3 0%, #5e5ce6 100%); color: #fff; }

.nf-empty { background: #fff; border-radius: 20px; border: 1px solid #eaeaea; padding: 60px 24px; text-align: center; color: #86868b; }
.nf-empty i { font-size: 4rem; color: #30d158; opacity: 0.5; display: block; margin-bottom: 16px; }
.nf-empty h4 { font-size: 1.2rem; font-weight: 700; color: #1d1d1f; margin: 0 0 8px; }

.nf-day-group { background: #fff; border-radius: 20px; border: 1px solid #eaeaea; box-shadow: 0 4px 16px rgba(0,0,0,0.04); margin-bottom: 20px; overflow: hidden; }
.nf-day-header { padding: 16px 24px; background: linear-gradient(135deg, #fef2f2 0%, #fff 100%); border-bottom: 1px solid #f0f0f2; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
.nf-day-title { font-size: 1rem; font-weight: 700; display: flex; align-items: center; gap: 10px; }
.nf-day-title i { color: #ff453a; }
.nf-day-badge { font-size: 0.72rem; font-weight: 700; background: rgba(255,69,58,0.15); color: #842029; padding: 4px 12px; border-radius: 20px; }

.nf-lesson { display: flex; align-items: center; gap: 14px; padding: 16px 24px; border-bottom: 1px solid #f5f5f7; text-decoration: none; color: inherit; transition: all 0.15s; }
.nf-lesson:last-child { border-bottom: none; }
.nf-lesson:hover { background: #fafafc; color: inherit; }
.nf-lesson-time { display: inline-flex; align-items: center; justify-content: center; min-width: 54px; padding: 6px 10px; border-radius: 10px; background: #1d1d1f; color: #fff; font-size: 0.75rem; font-weight: 700; flex-shrink: 0; }
.nf-lesson-info { flex: 1; min-width: 0; }
.nf-lesson-title { font-size: 0.95rem; font-weight: 700; margin-bottom: 4px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.nf-lesson-meta { font-size: 0.78rem; color: #86868b; display: flex; gap: 12px; flex-wrap: wrap; }
.nf-lesson-meta i { color: #0071e3; }
.nf-action-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 10px; background: linear-gradient(135deg, #0071e3 0%, #5e5ce6 100%); color: #fff; font-size: 0.82rem; font-weight: 600; text-decoration: none; transition: all 0.2s; box-shadow: 0 4px 12px rgba(0,113,227,0.25); white-space: nowrap; flex-shrink: 0; }
.nf-action-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,113,227,0.4); color: #fff; }

[data-theme="dark"] .nf-days-picker, [data-theme="dark"] .nf-day-group, [data-theme="dark"] .nf-empty { background: #1c1c1e; border-color: #2c2c2e; }
[data-theme="dark"] .nf-header h2, [data-theme="dark"] .nf-day-title, [data-theme="dark"] .nf-lesson-title, [data-theme="dark"] .nf-empty h4 { color: #f5f5f7; }
[data-theme="dark"] .nf-days-picker a { color: #a1a1a6; }
[data-theme="dark"] .nf-days-picker a:hover { background: #2c2c2e; color: #6ea8ff; }
[data-theme="dark"] .nf-day-header { background: linear-gradient(135deg, rgba(255,69,58,0.15) 0%, #1c1c1e 100%); border-color: #2c2c2e; }
[data-theme="dark"] .nf-lesson { border-color: #2c2c2e; }
[data-theme="dark"] .nf-lesson:hover { background: #2c2c2e; color: inherit; }
</style>

<div class="nf-header">
    <div>
        <h2>
            <i class="bi bi-bell-fill" style="color:#ff453a;"></i>
            Не отмечена посещаемость
            <?php if (count($unmarked) > 0): ?>
                <span style="font-size:0.55em; font-weight:600; background:rgba(255,69,58,0.15); color:#842029; padding:4px 12px; border-radius:20px;"><?= count($unmarked) ?></span>
            <?php endif; ?>
        </h2>
        <p><i class="bi bi-info-circle"></i> Занятия, которые уже прошли, но посещаемость не отмечена</p>
    </div>
    <div class="nf-days-picker">
        <span>Период:</span>
        <?php foreach ([7, 14, 30, 90] as $d): ?>
            <a href="?days=<?= $d ?>" class="<?= $daysBack === $d ? 'active' : '' ?>"><?= $d ?> дн.</a>
        <?php endforeach; ?>
    </div>
</div>

<?php if (empty($unmarked)): ?>
    <div class="nf-empty">
        <i class="bi bi-check-circle-fill"></i>
        <h4>Все занятия отмечены! 🎉</h4>
        <p>За последние <?= $daysBack ?> дней нет забытых пар</p>
    </div>
<?php else: foreach ($byDate as $date => $lessons):
    $dateObj = new DateTime($date);
?>
    <div class="nf-day-group">
        <div class="nf-day-header">
            <div class="nf-day-title"><i class="bi bi-calendar-event"></i> <?= $dateObj->format('d.m.Y') ?>, <?= ruDayFull($date) ?></div>
            <span class="nf-day-badge"><?= count($lessons) ?> <?= count($lessons) === 1 ? 'занятие' : 'занятий' ?></span>
        </div>
        <?php foreach ($lessons as $l):
            $t = $typeLabelsFull[$l['lesson_type']] ?? $typeLabelsFull['other'];
        ?>
            <a href="attendance.php?lesson_id=<?= $l['id'] ?>" class="nf-lesson">
                <span class="nf-lesson-time"><?= $l['lesson_time'] ? substr($l['lesson_time'], 0, 5) : '—' ?></span>
                <div class="nf-lesson-info">
                    <div class="nf-lesson-title">
                        <span class="badge bg-<?= $t['color'] ?>"><?= $t['label'] ?></span>
                        <?= htmlspecialchars($l['discipline']) ?>
                    </div>
                    <div class="nf-lesson-meta">
                        <?php if (!empty($l['group_name'])): ?><span><i class="bi bi-people-fill"></i> <?= htmlspecialchars($l['group_name']) ?></span><?php endif; ?>
                        <?php if (!empty($l['audience'])): ?><span><i class="bi bi-geo-alt-fill"></i> <?= htmlspecialchars($l['audience']) ?></span><?php endif; ?>
                    </div>
                </div>
                <span class="nf-action-btn"><i class="bi bi-check2-square"></i> Отметить</span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endforeach; endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>