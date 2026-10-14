<?php
// includes/session_helpers.php

function getCurrentSemester(?string $date = null): string {
    $ts = $date ? strtotime($date) : time();
    $year = (int)date('Y', $ts);
    $month = (int)date('n', $ts);

    if ($month >= 9) {
        return $year . '-' . ($year + 1) . '-1';
    }
    if ($month === 1) {
        return ($year - 1) . '-' . $year . '-1';
    }
    return ($year - 1) . '-' . $year . '-2';
}

function semesterLabel(string $semester): string {
    $parts = explode('-', $semester);
    if (count($parts) !== 3) return $semester;
    return "{$parts[0]}/{$parts[1]} · {$parts[2]} семестр";
}

function sessionTypes(): array {
    return [
        'zachet'      => ['label' => 'Зачёт',       'icon' => 'bi-clipboard-check', 'color' => 'success'],
        'exam'        => ['label' => 'Экзамен',     'icon' => 'bi-mortarboard',     'color' => 'danger'],
        'diff_zachet' => ['label' => 'Дифф. зачёт', 'icon' => 'bi-award',           'color' => 'warning'],
    ];
}

function allowedGrades(string $type): array {
    $common = ['не явился' => 'Не явился'];

    switch ($type) {
        case 'zachet':
            return array_merge([
                'зачтено'    => 'Зачтено',
                'не зачтено' => 'Не зачтено',
            ], $common);

        case 'exam':
        case 'diff_zachet':
            return array_merge([
                'отлично'              => 'Отлично',
                'хорошо'               => 'Хорошо',
                'удовлетворительно'    => 'Удовлетворительно',
                'не удовлетворительно' => 'Не удовлетворительно',
            ], $common);
    }
    return $common;
}

function calcStudentSessionStats(PDO $pdo, int $studentId, int $groupId, string $discipline, int $userId, string $from, string $to): array {
    $stmt = $pdo->prepare("
        SELECT
            COUNT(a.id) AS total_marked,
            COALESCE(SUM(a.is_present), 0) AS present
        FROM lessons l
        LEFT JOIN attendance a ON a.lesson_id = l.id AND a.student_id = ?
        WHERE l.user_id = ?
          AND l.group_id = ?
          AND l.discipline = ?
          AND l.lesson_date BETWEEN ? AND ?
    ");
    $stmt->execute([$studentId, $userId, $groupId, $discipline, $from, $to]);
    $row = $stmt->fetch();

    $totalMarked = (int)($row['total_marked'] ?? 0);
    $present     = (int)($row['present'] ?? 0);

    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT sw.work_number) AS cnt
        FROM submitted_works sw
        JOIN lessons l ON sw.lesson_id = l.id
        WHERE l.user_id = ?
          AND l.group_id = ?
          AND l.discipline = ?
          AND l.lesson_date BETWEEN ? AND ?
    ");
    $stmt->execute([$userId, $groupId, $discipline, $from, $to]);
    $worksTotal = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT sw.work_number) AS cnt
        FROM submitted_works sw
        JOIN lessons l ON sw.lesson_id = l.id
        WHERE sw.student_id = ?
          AND l.user_id = ?
          AND l.group_id = ?
          AND l.discipline = ?
          AND l.lesson_date BETWEEN ? AND ?
    ");
    $stmt->execute([$studentId, $userId, $groupId, $discipline, $from, $to]);
    $worksSubmitted = (int)$stmt->fetchColumn();

    return [
        'total_marked'    => $totalMarked,
        'present'         => $present,
        'absent'          => $totalMarked - $present,
        'attendance_pct'  => $totalMarked > 0 ? (int)round($present / $totalMarked * 100) : null,
        'works_total'     => $worksTotal,
        'works_submitted' => $worksSubmitted,
        'works_pct'       => $worksTotal > 0 ? (int)round($worksSubmitted / $worksTotal * 100) : null,
    ];
}

function autoVerdict(?int $attendancePct, ?int $worksPct, int $minAttendance, int $minWorks): ?int {
    if ($attendancePct === null && $worksPct === null) return null;

    $attOk = $attendancePct === null ? true : ($attendancePct >= $minAttendance);
    $wrkOk = $worksPct      === null ? true : ($worksPct      >= $minWorks);

    return ($attOk && $wrkOk) ? 1 : 0;
}

function semesterDateRange(string $semester): array {
    $parts = explode('-', $semester);
    if (count($parts) !== 3) {
        return [date('Y-09-01'), date('Y-m-d')];
    }
    $year1 = (int)$parts[0];
    $year2 = (int)$parts[1];
    $num   = (int)$parts[2];

    if ($num === 1) return ["$year1-09-01", "$year2-01-31"];
    return ["$year2-02-01", "$year2-08-31"];
}