<?php
// includes/kuzstu_api.php

if (!function_exists('kuzstuApiRequest')) {
    function kuzstuApiRequest(string $path, array $params = [], int $timeout = 15): ?array {
        $url = KUZSTU_API_URL . $path;
        if (!empty($params)) $url .= '?' . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_USERAGENT      => 'AIS-Raspisanie/1.0',
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) return null;

        $json = json_decode($response, true);
        return is_array($json) ? $json : null;
    }
}

if (!function_exists('mapKuzstuTypeToInternal')) {
    function mapKuzstuTypeToInternal(string $type): string {
        $t = mb_strtolower(trim($type));
        if ($t === '') return 'other';
        if (mb_strpos($t, 'лек') !== false) return 'lecture';
        if (mb_strpos($t, 'лаб') !== false) return 'lab';
        if (mb_strpos($t, 'пр')  !== false) return 'practice';
        if (mb_strpos($t, 'сем') !== false) return 'seminar';
        return 'other';
    }
}

if (!function_exists('syncTeacherSchedule')) {
    /**
     * Синхронизация расписания преподавателя из API КузГТУ.
     * Заменяет дублирующиеся syncFromApi() в schedule.php и dashboard.php.
     *
     * @return array ['added'=>int, 'updated'=>int] | ['error'=>string]
     */
    function syncTeacherSchedule(PDO $pdo, int $userId): array {
        $stmt = $pdo->prepare("SELECT api_teacher_id FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $teacherId = $stmt->fetchColumn();
        if (!$teacherId) return ['error' => 'Не найден ID преподавателя в API'];

        $apiLessons = kuzstuApiRequest('/teacher_schedule', ['teacher_id' => $teacherId]);
        if ($apiLessons === null) return ['error' => 'API недоступен'];

        $timeMap = [];
        foreach (LESSON_TIMES as $n => $t) $timeMap[$n] = $t['start'];

        $added = 0;
        $updated = 0;

        foreach ($apiLessons as $item) {
            $apiLessonId = (string)($item['id'] ?? '');
            $date = $item['date_lesson'] ?? null;
            if (!$apiLessonId || !$date) continue;

            $apiGroupId = (string)($item['education_group_id'] ?? '');
            $groupName  = trim($item['education_group_name'] ?? '');
            $groupId = null;

            if ($apiGroupId !== '' && $groupName !== '') {
                $stmt = $pdo->prepare("SELECT id FROM groups_table WHERE api_group_id = ?");
                $stmt->execute([$apiGroupId]);
                $groupId = $stmt->fetchColumn();

                if (!$groupId) {
                    $stmt = $pdo->prepare("SELECT id FROM groups_table WHERE name = ?");
                    $stmt->execute([$groupName]);
                    $groupId = $stmt->fetchColumn();

                    if ($groupId) {
                        $pdo->prepare("UPDATE groups_table SET api_group_id = ? WHERE id = ?")
                            ->execute([$apiGroupId, $groupId]);
                    } else {
                        $pdo->prepare("INSERT INTO groups_table (name, api_group_id) VALUES (?, ?)")
                            ->execute([$groupName, $apiGroupId]);
                        $groupId = (int)$pdo->lastInsertId();
                    }
                }
            }

            $lessonNumber = (int)($item['lesson_number'] ?? 0);
            $lessonTime   = $timeMap[$lessonNumber] ?? null;
            $type         = mapKuzstuTypeToInternal((string)($item['type'] ?? ''));
            $discipline   = trim($item['subject'] ?? 'Без названия');
            $audience     = trim($item['place'] ?? '');
            $subgroup     = (string)($item['subgroup'] ?? '0');

            $stmt = $pdo->prepare("SELECT id FROM lessons WHERE user_id = ? AND api_lesson_id = ?");
            $stmt->execute([$userId, $apiLessonId]);
            $exists = $stmt->fetchColumn();

            if ($exists) {
                $pdo->prepare("
                    UPDATE lessons SET
                        group_id = ?, discipline = ?, lesson_date = ?, lesson_time = ?,
                        lesson_type = ?, subgroup = ?, lesson_number = ?, audience = ?
                    WHERE id = ?
                ")->execute([
                    $groupId ?: null, $discipline, $date, $lessonTime,
                    $type, $subgroup, $lessonNumber ?: null, $audience, $exists
                ]);
                $updated++;
            } else {
                $pdo->prepare("
                    INSERT INTO lessons
                        (api_lesson_id, user_id, group_id, discipline, lesson_date, lesson_time,
                         lesson_type, subgroup, lesson_number, audience, is_manual)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
                ")->execute([
                    $apiLessonId, $userId, $groupId ?: null, $discipline, $date, $lessonTime,
                    $type, $subgroup, $lessonNumber ?: null, $audience
                ]);
                $added++;
            }
        }

        return ['added' => $added, 'updated' => $updated];
    }
}