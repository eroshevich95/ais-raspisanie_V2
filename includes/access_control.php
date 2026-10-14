<?php
// includes/access_control.php

if (!function_exists('getAccessScope')) {
    function getAccessScope(PDO $pdo, int $userId): array {
        $stmt = $pdo->prepare("
            SELECT id, role, institute_id, institute_name,
                   department_id, department_name, department
            FROM users WHERE id = ?
        ");
        $stmt->execute([$userId]);
        $u = $stmt->fetch();

        if (!$u) {
            return [
                'role'       => 'guest',
                'user_id'    => 0,
                'restricted' => true,
            ];
        }

        $role = $u['role'] ?? 'teacher';

        return [
            'role'            => $role,
            'user_id'         => (int)$u['id'],
            'institute_id'    => $u['institute_id'] ?: null,
            'institute_name'  => $u['institute_name'] ?: null,
            'department_id'   => $u['department_id'] ?: null,
            'department_name' => $u['department_name'] ?: ($u['department'] ?: null),
            'restricted'      => $role !== 'admin',
        ];
    }
}

if (!function_exists('getAllowedTeacherIds')) {
    function getAllowedTeacherIds(PDO $pdo, array $scope): ?array {
        if ($scope['role'] === 'admin') return null;
        if ($scope['role'] === 'teacher') return [$scope['user_id']];

        if ($scope['role'] === 'head_of_department') {
            if (empty($scope['department_name'])) return [$scope['user_id']];
            $stmt = $pdo->prepare("
                SELECT id FROM users
                WHERE is_active = 1
                  AND (department_name = ? OR department = ?)
            ");
            $stmt->execute([$scope['department_name'], $scope['department_name']]);
            $ids = array_column($stmt->fetchAll(), 'id');
            if (!in_array($scope['user_id'], $ids)) $ids[] = $scope['user_id'];
            return $ids;
        }

        if ($scope['role'] === 'administration') {
            if (empty($scope['institute_name'])) return [$scope['user_id']];
            $stmt = $pdo->prepare("
                SELECT id FROM users
                WHERE is_active = 1 AND institute_name = ?
            ");
            $stmt->execute([$scope['institute_name']]);
            $ids = array_column($stmt->fetchAll(), 'id');
            if (!in_array($scope['user_id'], $ids)) $ids[] = $scope['user_id'];
            return $ids;
        }

        return [$scope['user_id']];
    }
}

if (!function_exists('getAllowedGroupIds')) {
    function getAllowedGroupIds(PDO $pdo, array $scope, ?array $teacherIds = null): ?array {
        if ($scope['role'] === 'admin') return null;

        if ($scope['role'] === 'teacher') {
            $stmt = $pdo->prepare("
                SELECT DISTINCT group_id FROM lessons
                WHERE user_id = ? AND group_id IS NOT NULL
            ");
            $stmt->execute([$scope['user_id']]);
            return array_column($stmt->fetchAll(), 'group_id');
        }

        if ($teacherIds === null) {
            $teacherIds = getAllowedTeacherIds($pdo, $scope) ?? [];
        }

        $idsFromLessons = [];
        if (!empty($teacherIds)) {
            $in = implode(',', array_fill(0, count($teacherIds), '?'));
            $stmt = $pdo->prepare("
                SELECT DISTINCT group_id FROM lessons
                WHERE user_id IN ($in) AND group_id IS NOT NULL
            ");
            $stmt->execute($teacherIds);
            $idsFromLessons = array_column($stmt->fetchAll(), 'group_id');
        }

        $idsFromInstitute = [];
        if ($scope['role'] === 'administration' && !empty($scope['institute_name'])) {
            $stmt = $pdo->prepare("SELECT id FROM groups_table WHERE institute = ?");
            $stmt->execute([$scope['institute_name']]);
            $idsFromInstitute = array_column($stmt->fetchAll(), 'id');
        }

        return array_values(array_unique(array_merge($idsFromLessons, $idsFromInstitute)));
    }
}

if (!function_exists('buildTeacherFilterSql')) {
    function buildTeacherFilterSql(?array $teacherIds, string $column = 'l.user_id'): array {
        if ($teacherIds === null) return ['sql' => '', 'params' => []];
        if (empty($teacherIds))   return ['sql' => " AND 1 = 0", 'params' => []];
        $in = implode(',', array_fill(0, count($teacherIds), '?'));
        return [
            'sql'    => " AND $column IN ($in)",
            'params' => array_values($teacherIds),
        ];
    }
}

if (!function_exists('buildGroupFilterSql')) {
    function buildGroupFilterSql(?array $groupIds, string $column = 'l.group_id'): array {
        if ($groupIds === null) return ['sql' => '', 'params' => []];
        if (empty($groupIds))   return ['sql' => " AND 1 = 0", 'params' => []];
        $in = implode(',', array_fill(0, count($groupIds), '?'));
        return [
            'sql'    => " AND $column IN ($in)",
            'params' => array_values($groupIds),
        ];
    }
}

if (!function_exists('scopeLabel')) {
    function scopeLabel(array $scope): array {
        switch ($scope['role']) {
            case 'admin':
                return ['Все данные', 'danger', 'bi-shield-fill-check'];
            case 'administration':
                return ['Институт: ' . ($scope['institute_name'] ?: '—'), 'success', 'bi-building'];
            case 'head_of_department':
                return ['Кафедра: ' . ($scope['department_name'] ?: '—'), 'warning', 'bi-diagram-3'];
            case 'teacher':
                return ['Только мои занятия', 'info', 'bi-person'];
            default:
                return ['Ограниченный доступ', 'secondary', 'bi-shield'];
        }
    }
}