<?php
// includes/audit.php

if (!function_exists('auditLog')) {
    function auditLog(PDO $pdo, string $action, array $data = []): void {
        $userId   = (int)($data['user_id']   ?? ($_SESSION['user_id'] ?? 0));
        $userName = $data['user_name'] ?? ($_SESSION['user_name'] ?? null);

        if (!$userId) return;

        try {
            $stmt = $pdo->prepare("
                INSERT INTO audit_log
                    (user_id, user_name, action, entity_type, entity_id,
                     description, old_value, new_value, ip, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            if (function_exists('clientIp')) $ip = clientIp();

            $ua = isset($_SERVER['HTTP_USER_AGENT'])
                ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 250)
                : null;

            $oldValue = $data['old_value'] ?? null;
            $newValue = $data['new_value'] ?? null;

            if (is_array($oldValue)) $oldValue = json_encode($oldValue, JSON_UNESCAPED_UNICODE);
            if (is_array($newValue)) $newValue = json_encode($newValue, JSON_UNESCAPED_UNICODE);

            $stmt->execute([
                $userId,
                $userName,
                $action,
                $data['entity_type'] ?? null,
                $data['entity_id']   ?? null,
                $data['description'] ?? null,
                $oldValue,
                $newValue,
                $ip,
                $ua,
            ]);
        } catch (Throwable $e) {
            error_log('auditLog error: ' . $e->getMessage());
        }
    }
}

if (!function_exists('auditActionLabels')) {
    function auditActionLabels(): array {
        return [
            'login.success'         => ['Вход в систему', 'success', 'bi-box-arrow-in-right'],
            'login.failed'          => ['Неудачный вход', 'danger', 'bi-shield-exclamation'],
            'login.rate_limited'    => ['Блокировка входа', 'danger', 'bi-shield-x'],
            'logout'                => ['Выход', 'secondary', 'bi-box-arrow-right'],

            'attendance.update'     => ['Изменение посещаемости', 'primary', 'bi-check2-square'],
            'attendance.bulk'       => ['Массовая отметка', 'info', 'bi-people-fill'],

            'works.update'          => ['Изменение работ', 'primary', 'bi-journal-check'],

            'session.create'        => ['Создание ведомости', 'success', 'bi-file-earmark-plus'],
            'session.delete'        => ['Удаление ведомости', 'danger', 'bi-trash'],
            'session.close'         => ['Закрытие ведомости', 'success', 'bi-lock-fill'],
            'session.reopen'        => ['Открытие ведомости', 'warning', 'bi-unlock-fill'],
            'session.grade'         => ['Выставление оценки', 'primary', 'bi-award'],
            'session.allow'         => ['Изменение допуска', 'primary', 'bi-check-circle'],
            'session.comment'       => ['Комментарий', 'secondary', 'bi-chat-left-text'],
            'session.hours'         => ['Часы по плану', 'secondary', 'bi-clock-history'],
            'session.bulk'          => ['Массовое действие', 'info', 'bi-people-fill'],

            'profile.update'        => ['Изменение профиля', 'warning', 'bi-person-gear'],
            'password.change'       => ['Смена пароля', 'danger', 'bi-key'],

            'subgroup.update'       => ['Изменение подгруппы', 'info', 'bi-diagram-3'],
            'subgroups.bulk'        => ['Массовое распределение', 'info', 'bi-diagram-3'],
            'subgroups.auto'        => ['Автораспределение', 'info', 'bi-shuffle'],
            'subgroups.clear'       => ['Очистка подгрупп', 'warning', 'bi-eraser'],

            'students.import'       => ['Импорт студентов', 'success', 'bi-file-earmark-excel'],
            'students.import_error' => ['Ошибка импорта', 'danger', 'bi-exclamation-triangle'],

            'schedule.add'          => ['Добавление занятия', 'success', 'bi-plus-circle'],
            'schedule.delete'       => ['Удаление занятия', 'danger', 'bi-trash'],
            'schedule.sync'         => ['Синхронизация', 'info', 'bi-arrow-repeat'],

            'admin.settings_save'   => ['Настройки сайта', 'warning', 'bi-gear'],
            'admin.user_create'     => ['Создание пользователя', 'success', 'bi-person-plus'],
            'admin.user_update'     => ['Изменение пользователя', 'warning', 'bi-person-gear'],
            'admin.user_delete'     => ['Удаление пользователя', 'danger', 'bi-person-x'],
            'admin.user_toggle'     => ['Блокировка пользователя', 'warning', 'bi-lock'],
            'admin.user_password'   => ['Сброс пароля', 'warning', 'bi-key'],
            'admin.group_create'    => ['Создание группы', 'success', 'bi-plus-circle'],
            'admin.group_update'    => ['Изменение группы', 'warning', 'bi-pencil'],
            'admin.group_delete'    => ['Удаление группы', 'danger', 'bi-trash'],
            'admin.student_update'  => ['Изменение студента', 'warning', 'bi-pencil'],
            'admin.student_delete'  => ['Удаление студента', 'danger', 'bi-trash'],
            'admin.lesson_delete'   => ['Удаление занятия', 'danger', 'bi-trash'],
            'admin.message_create'  => ['Создание сообщения', 'success', 'bi-chat-plus'],
            'admin.message_update'  => ['Изменение сообщения', 'warning', 'bi-chat-text'],
            'admin.message_delete'  => ['Удаление сообщения', 'danger', 'bi-chat-x'],
            'admin.message_toggle'  => ['Переключение сообщения', 'info', 'bi-toggles'],
            'admin.reset_approve'   => ['Одобрение сброса', 'success', 'bi-check-circle'],
            'admin.reset_reject'    => ['Отклонение сброса', 'danger', 'bi-x-circle'],
            'admin.backup'          => ['Скачивание бэкапа', 'info', 'bi-download'],
            'admin.cache_clear'     => ['Очистка кэша', 'info', 'bi-eraser'],
            'audit.cleared'         => ['Очистка журнала', 'danger', 'bi-trash'],
        ];
    }
}