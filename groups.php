<?php
// groups.php

$pageTitle = 'Группы';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/session_boot.php';
require_once __DIR__ . '/includes/security.php';

sendSecurityHeaders();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$groups = $pdo->query("
    SELECT g.id, g.name, g.institute, COUNT(s.id) AS cnt
    FROM groups_table g
    LEFT JOIN students s ON s.group_id = g.id
    GROUP BY g.id
    ORDER BY g.name
")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<h2 class="mb-4"><i class="bi bi-people"></i> Группы</h2>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
            <tr>
                <th>Группа</th>
                <th>Институт</th>
                <th class="text-end">Студентов</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($groups as $g): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($g['name']) ?></strong></td>
                    <td class="text-muted small"><?= htmlspecialchars($g['institute'] ?? '—') ?></td>
                    <td class="text-end"><?= $g['cnt'] ?></td>
                    <td class="text-end">
                        <a href="group_view.php?id=<?= $g['id'] ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-eye"></i> Открыть
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>