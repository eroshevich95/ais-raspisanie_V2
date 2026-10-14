<?php
// includes/notifications.php

if (!function_exists('getUnmarkedLessons')) {
    function getUnmarkedLessons(PDO $pdo, int $userId, int $daysBack = 14, int $limit = 50): array {
        $stmt = $pdo->prepare("
            SELECT
                l.lesson_date,
                COALESCE(l.lesson_number, 0) AS lesson_number,
                l.discipline,
                l.lesson_type,
                COALESCE(l.audience, '') AS audience,
                COALESCE(NULLIF(l.subgroup, ''), '0') AS subgroup,
                MIN(l.id) AS id,
                MIN(l.lesson_time) AS lesson_time,
                MAX(g.name) AS group_name
            FROM lessons l
            LEFT JOIN groups_table g ON l.group_id = g.id
            WHERE l.user_id = :uid
              AND DATE(l.lesson_date) < CURDATE()
              AND DATE(l.lesson_date) >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
            GROUP BY
                l.lesson_date,
                COALESCE(l.lesson_number, 0),
                l.discipline,
                l.lesson_type,
                COALESCE(l.audience, ''),
                COALESCE(NULLIF(l.subgroup, ''), '0')
            ORDER BY l.lesson_date DESC, l.lesson_time DESC, l.lesson_number DESC
        ");
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':days', $daysBack, PDO::PARAM_INT);
        $stmt->execute();
        $pairs = $stmt->fetchAll();

        if (empty($pairs)) return [];

        $result = [];
        foreach ($pairs as $p) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM attendance a
                WHERE a.lesson_id IN (
                    SELECT l2.id FROM lessons l2
                    WHERE l2.user_id = :uid
                      AND l2.lesson_date = :date
                      AND COALESCE(l2.lesson_number, 0) = :num
                      AND l2.discipline = :disc
                      AND l2.lesson_type = :type
                      AND COALESCE(l2.audience, '') = :aud
                      AND COALESCE(NULLIF(l2.subgroup, ''), '0') = :sg
                )
            ");
            $stmt->execute([
                ':uid'  => $userId,
                ':date' => $p['lesson_date'],
                ':num'  => $p['lesson_number'],
                ':disc' => $p['discipline'],
                ':type' => $p['lesson_type'],
                ':aud'  => $p['audience'],
                ':sg'   => $p['subgroup'],
            ]);
            $marks = (int)$stmt->fetchColumn();

            if ($marks === 0) {
                $result[] = $p;
                if (count($result) >= $limit) break;
            }
        }
        return $result;
    }
}

if (!function_exists('countUnmarkedLessons')) {
    function countUnmarkedLessons(PDO $pdo, int $userId, int $daysBack = 14): int {
        $stmt = $pdo->prepare("
            SELECT
                l.lesson_date,
                COALESCE(l.lesson_number, 0) AS lesson_number,
                l.discipline,
                l.lesson_type,
                COALESCE(l.audience, '') AS audience,
                COALESCE(NULLIF(l.subgroup, ''), '0') AS subgroup
            FROM lessons l
            WHERE l.user_id = :uid
              AND DATE(l.lesson_date) < CURDATE()
              AND DATE(l.lesson_date) >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
            GROUP BY
                l.lesson_date,
                COALESCE(l.lesson_number, 0),
                l.discipline,
                l.lesson_type,
                COALESCE(l.audience, ''),
                COALESCE(NULLIF(l.subgroup, ''), '0')
        ");
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':days', $daysBack, PDO::PARAM_INT);
        $stmt->execute();
        $pairs = $stmt->fetchAll();

        if (empty($pairs)) return 0;

        $count = 0;
        $checkStmt = $pdo->prepare("
            SELECT COUNT(*) FROM attendance a
            WHERE a.lesson_id IN (
                SELECT l2.id FROM lessons l2
                WHERE l2.user_id = :uid
                  AND l2.lesson_date = :date
                  AND COALESCE(l2.lesson_number, 0) = :num
                  AND l2.discipline = :disc
                  AND l2.lesson_type = :type
                  AND COALESCE(l2.audience, '') = :aud
                  AND COALESCE(NULLIF(l2.subgroup, ''), '0') = :sg
            )
        ");

        foreach ($pairs as $p) {
            $checkStmt->execute([
                ':uid'  => $userId,
                ':date' => $p['lesson_date'],
                ':num'  => $p['lesson_number'],
                ':disc' => $p['discipline'],
                ':type' => $p['lesson_type'],
                ':aud'  => $p['audience'],
                ':sg'   => $p['subgroup'],
            ]);
            if ((int)$checkStmt->fetchColumn() === 0) $count++;
        }
        return $count;
    }
}