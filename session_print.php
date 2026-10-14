<?php
// session_print.php

$pageTitle = 'Печать ведомости';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/session_helpers.php';
require_once __DIR__ . '/includes/session_boot.php';
require_once __DIR__ . '/includes/security.php';

sendSecurityHeaders();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId   = (int)$_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'teacher';
$isAdmin  = in_array($userRole, ['head_of_department', 'administration', 'admin']);

$sheetId = (int)($_GET['id'] ?? 0);
if (!$sheetId) { header('Location: session.php'); exit; }

$stmt = $pdo->prepare("
    SELECT s.*, g.name AS group_name, g.institute AS group_institute,
           u.full_name AS teacher_name, u.department AS teacher_department
    FROM session_sheets s
    LEFT JOIN groups_table g ON s.group_id = g.id
    LEFT JOIN users u ON s.user_id = u.id
    WHERE s.id = ?
");
$stmt->execute([$sheetId]);
$sheet = $stmt->fetch();

if (!$sheet) exit('Ведомость не найдена');
if (!$isAdmin && (int)$sheet['user_id'] !== $userId) exit('Нет доступа');

$stmt = $pdo->prepare("SELECT id, full_name, zachetka, subgroup FROM students WHERE group_id = ? ORDER BY full_name");
$stmt->execute([$sheet['group_id']]);
$students = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM session_grades WHERE sheet_id = ?");
$stmt->execute([$sheetId]);
$gradesMap = [];
foreach ($stmt->fetchAll() as $g) $gradesMap[$g['student_id']] = $g;

$types = sessionTypes();
$type  = $types[$sheet['session_type']] ?? $types['zachet'];

$typeFull = [
    'zachet'      => 'ЗАЧЁТНАЯ ВЕДОМОСТЬ',
    'exam'        => 'ЭКЗАМЕНАЦИОННАЯ ВЕДОМОСТЬ',
    'diff_zachet' => 'ВЕДОМОСТЬ ДИФФЕРЕНЦИРОВАННОГО ЗАЧЁТА',
][$sheet['session_type']] ?? 'ВЕДОМОСТЬ';

$semParts = explode('-', $sheet['semester']);
$studyYear = (count($semParts) >= 2) ? ($semParts[0] . '/' . $semParts[1]) : $sheet['semester'];
$semesterNum = $semParts[2] ?? '1';
$semesterText = $semesterNum === '1' ? 'Осенний семестр' : 'Весенний семестр';

$hoursParts = [];
if ($sheet['hours_total'] !== null)    $hoursParts[] = 'всего ' . (int)$sheet['hours_total'];
if ($sheet['hours_lecture'] !== null)  $hoursParts[] = 'лек. ' . (int)$sheet['hours_lecture'];
if ($sheet['hours_practice'] !== null) $hoursParts[] = 'практ. ' . (int)$sheet['hours_practice'];
if ($sheet['hours_lab'] !== null)      $hoursParts[] = 'лаб. ' . (int)$sheet['hours_lab'];
$hoursText = implode(', ', $hoursParts);

function formatGradeForPrint(?string $grade): string {
    if ($grade === null || $grade === '') return '';
    return mb_strtoupper(mb_substr($grade, 0, 1)) . mb_substr($grade, 1);
}

$totalStudents = count($students);
$graded = $excellent = $good = $satisfactory = $unsatisfactory = 0;
$passedZ = $failedZ = $notPresent = 0;

foreach ($gradesMap as $g) {
    if (empty($g['grade'])) continue;
    $graded++;
    switch ($g['grade']) {
        case 'отлично':              $excellent++; break;
        case 'хорошо':               $good++; break;
        case 'удовлетворительно':    $satisfactory++; break;
        case 'не удовлетворительно': $unsatisfactory++; break;
        case 'зачтено':              $passedZ++; break;
        case 'не зачтено':           $failedZ++; break;
        case 'не явился':            $notPresent++; break;
    }
}

$printDate = date('d.m.Y');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($typeFull) ?> — <?= htmlspecialchars($sheet['discipline']) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { background: #e8e8e8; font-family: "Times New Roman", Times, serif; color: #000; font-size: 11pt; }
        .page { background: #fff; width: 210mm; min-height: 297mm; margin: 20px auto; padding: 15mm 15mm 15mm 20mm; box-shadow: 0 4px 24px rgba(0,0,0,0.15); }
        .header { text-align: center; margin-bottom: 10mm; line-height: 1.3; }
        .header-min { font-size: 10pt; margin-bottom: 3mm; text-transform: uppercase; }
        .header-uni { font-size: 11pt; font-weight: bold; margin-bottom: 2mm; text-transform: uppercase; }
        .header-uni-name { font-size: 11pt; font-weight: bold; margin-bottom: 6mm; text-transform: uppercase; }
        .header-dept { font-size: 10pt; margin-bottom: 8mm; }
        .header-title { font-size: 14pt; font-weight: bold; letter-spacing: 1.5px; margin-bottom: 8mm; text-transform: uppercase; }
        .info { margin-bottom: 8mm; font-size: 11pt; line-height: 1.6; }
        .info-row { display: flex; margin-bottom: 1mm; align-items: baseline; }
        .info-row .label { width: 55mm; flex-shrink: 0; }
        .info-row .colon { width: 3mm; flex-shrink: 0; }
        .info-row .value { flex: 1; font-weight: bold; padding-bottom: 0.5mm; border-bottom: 1px dotted #666; min-height: 5mm; }
        .grades-table { width: 100%; border-collapse: collapse; margin-bottom: 8mm; font-size: 10.5pt; }
        .grades-table th, .grades-table td { border: 1px solid #000; padding: 1.8mm 2mm; vertical-align: middle; }
        .grades-table thead th { background: #f0f0f0; font-weight: bold; text-align: center; font-size: 9.5pt; text-transform: uppercase; }
        .col-num { width: 8mm; text-align: center; }
        .col-zachetka { width: 24mm; text-align: center; font-size: 10pt; }
        .col-grade { width: 42mm; text-align: center; font-weight: bold; }
        .col-sign { width: 32mm; }
        .totals { margin-bottom: 10mm; font-size: 11pt; line-height: 1.8; }
        .totals-title { font-weight: bold; margin-bottom: 2mm; text-decoration: underline; }
        .totals-row { display: flex; align-items: baseline; margin-bottom: 1mm; max-width: 120mm; }
        .totals-row .label { width: 70mm; flex-shrink: 0; }
        .totals-row .dots { flex: 1; border-bottom: 1px dotted #000; margin: 0 2mm; min-height: 4mm; }
        .totals-row .value { min-width: 20mm; text-align: center; font-weight: bold; }
        .signatures { margin-top: 15mm; font-size: 11pt; }
        .signatures-row { display: flex; align-items: flex-end; margin-bottom: 12mm; gap: 3mm; }
        .signatures-label { white-space: nowrap; padding-bottom: 1mm; }
        .signatures-line { flex: 1; border-bottom: 1px solid #000; min-height: 8mm; }
        .signatures-caption { font-size: 8pt; color: #333; text-align: center; margin-top: 1mm; font-style: italic; }
        .signatures-date-row { display: flex; align-items: baseline; gap: 2mm; margin-top: 10mm; }
        .signatures-date-row .line { border-bottom: 1px solid #000; min-width: 40mm; padding: 0 3mm; text-align: center; font-weight: bold; }
        .actions { position: fixed; top: 20px; right: 20px; display: flex; gap: 10px; z-index: 1000; }
        .action-btn { display: inline-flex; align-items: center; gap: 8px; padding: 11px 20px; border-radius: 12px; background: linear-gradient(135deg, #0071e3 0%, #5e5ce6 100%); color: #fff; border: none; font-size: 0.88rem; font-weight: 600; cursor: pointer; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; box-shadow: 0 6px 20px rgba(0,113,227,0.35); text-decoration: none; white-space: nowrap; }
        .action-btn--secondary { background: #fff; color: #495057; border: 1.5px solid #d2d2d7; box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        @media print {
            html, body { background: #fff; }
            .page { width: auto; min-height: auto; margin: 0; padding: 10mm 15mm 10mm 20mm; box-shadow: none; }
            .actions { display: none !important; }
            @page { size: A4 portrait; margin: 0; }
            .grades-table tr { page-break-inside: avoid; }
            .signatures, .totals { page-break-inside: avoid; }
        }
        @media screen and (max-width: 900px) {
            .page { width: 100%; padding: 12mm; margin: 0; }
            .actions { position: static; margin: 12px; justify-content: center; }
        }
    </style>
</head>
<body>

<div class="actions">
    <button class="action-btn" onclick="window.print()">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M19 8H5c-1.66 0-3 1.34-3 3v6h4v4h12v-4h4v-6c0-1.66-1.34-3-3-3zm-3 11H8v-5h8v5zm3-7c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm-1-9H6v4h12V3z"/></svg>
        Печать
    </button>
    <a href="session_sheet.php?id=<?= $sheetId ?>" class="action-btn action-btn--secondary">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
        Закрыть
    </a>
</div>

<div class="page">
    <div class="header">
        <div class="header-min">Министерство науки и высшего образования Российской Федерации</div>
        <div class="header-uni">Федеральное государственное бюджетное<br>образовательное учреждение высшего образования</div>
        <div class="header-uni-name">«Кузбасский государственный технический университет<br>имени Т.Ф. Горбачёва»</div>
        <?php if (!empty($sheet['teacher_department'])): ?>
            <div class="header-dept"><?= htmlspecialchars($sheet['teacher_department']) ?></div>
        <?php elseif (!empty($sheet['group_institute'])): ?>
            <div class="header-dept"><?= htmlspecialchars($sheet['group_institute']) ?></div>
        <?php endif; ?>
        <div class="header-title"><?= htmlspecialchars($typeFull) ?></div>
    </div>

    <div class="info">
        <div class="info-row"><span class="label">Дисциплина</span><span class="colon">:</span><span class="value"><?= htmlspecialchars($sheet['discipline']) ?></span></div>
        <div class="info-row"><span class="label">Группа</span><span class="colon">:</span><span class="value"><?= htmlspecialchars($sheet['group_name']) ?></span></div>
        <div class="info-row"><span class="label">Преподаватель</span><span class="colon">:</span><span class="value"><?= htmlspecialchars($sheet['teacher_name']) ?></span></div>
        <div class="info-row"><span class="label">Учебный год / семестр</span><span class="colon">:</span><span class="value"><?= htmlspecialchars($studyYear) ?> · <?= htmlspecialchars($semesterText) ?></span></div>
        <?php if ($hoursText !== ''): ?>
            <div class="info-row"><span class="label">Часы по плану</span><span class="colon">:</span><span class="value"><?= htmlspecialchars($hoursText) ?></span></div>
        <?php endif; ?>
    </div>

    <table class="grades-table">
        <thead>
        <tr>
            <th class="col-num">№<br>п/п</th>
            <th class="col-zachetka">№ зачётной<br>книжки</th>
            <th>Фамилия, имя, отчество</th>
            <th class="col-grade">Оценка</th>
            <th class="col-sign">Подпись<br>преподавателя</th>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($students)): ?>
            <tr><td colspan="5" style="text-align:center; padding:10mm; font-style:italic; color:#666;">В группе нет студентов</td></tr>
        <?php else: foreach ($students as $i => $s):
            $g = $gradesMap[$s['id']] ?? null;
            $gradeText = formatGradeForPrint($g['grade'] ?? '');
        ?>
            <tr>
                <td class="col-num"><?= $i + 1 ?></td>
                <td class="col-zachetka"><?= htmlspecialchars($s['zachetka'] ?? '') ?></td>
                <td><?= htmlspecialchars($s['full_name']) ?></td>
                <td class="col-grade" style="<?= $gradeText === '' ? 'color:#999;' : '' ?>"><?= htmlspecialchars($gradeText) ?: '—' ?></td>
                <td class="col-sign"></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>

    <?php if ($graded > 0): ?>
        <div class="totals">
            <div class="totals-title">Итоги:</div>
            <div class="totals-row"><span class="label">Всего студентов:</span><span class="dots"></span><span class="value"><?= $totalStudents ?></span></div>
            <div class="totals-row"><span class="label">Выставлено оценок:</span><span class="dots"></span><span class="value"><?= $graded ?></span></div>
            <?php if ($sheet['session_type'] === 'zachet'): ?>
                <div class="totals-row"><span class="label">Зачтено:</span><span class="dots"></span><span class="value"><?= $passedZ ?></span></div>
                <div class="totals-row"><span class="label">Не зачтено:</span><span class="dots"></span><span class="value"><?= $failedZ ?></span></div>
            <?php else: ?>
                <div class="totals-row"><span class="label">«Отлично»:</span><span class="dots"></span><span class="value"><?= $excellent ?></span></div>
                <div class="totals-row"><span class="label">«Хорошо»:</span><span class="dots"></span><span class="value"><?= $good ?></span></div>
                <div class="totals-row"><span class="label">«Удовлетворительно»:</span><span class="dots"></span><span class="value"><?= $satisfactory ?></span></div>
                <div class="totals-row"><span class="label">«Не удовлетворительно»:</span><span class="dots"></span><span class="value"><?= $unsatisfactory ?></span></div>
            <?php endif; ?>
            <?php if ($notPresent > 0): ?>
                <div class="totals-row"><span class="label">Не явилось:</span><span class="dots"></span><span class="value"><?= $notPresent ?></span></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="signatures">
        <div class="signatures-row">
            <span class="signatures-label">Преподаватель:</span>
            <span class="signatures-line"></span>
            <span style="font-size:10pt; white-space:nowrap; padding-bottom:1mm;"><?= htmlspecialchars($sheet['teacher_name']) ?></span>
        </div>
        <div style="text-align:right; margin-top:-8mm; margin-bottom:10mm;"><span class="signatures-caption">(подпись)</span></div>
        <div class="signatures-row">
            <span class="signatures-label">Заведующий кафедрой:</span>
            <span class="signatures-line"></span>
            <span style="font-size:10pt; white-space:nowrap; padding-bottom:1mm;">&nbsp;</span>
        </div>
        <div style="text-align:right; margin-top:-8mm; margin-bottom:10mm;"><span class="signatures-caption">(подпись)</span></div>
        <div class="signatures-date-row">
            <span>Дата составления:</span>
            <span class="line"><?= $printDate ?></span>
        </div>
    </div>
</div>

<script>
document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'p') { e.preventDefault(); window.print(); return; }
    if (e.key === 'Escape') window.close();
});
</script>

</body>
</html>