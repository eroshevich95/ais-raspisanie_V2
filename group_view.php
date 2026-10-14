<?php
// group_view.php

$pageTitle = 'Группа';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/session_boot.php';
require_once __DIR__ . '/includes/security.php';

sendSecurityHeaders();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM groups_table WHERE id = ?");
$stmt->execute([$id]);
$group = $stmt->fetch();

if (!$group) {
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="alert alert-danger">Группа не найдена</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM students WHERE group_id = ? ORDER BY full_name");
$stmt->execute([$id]);
$students = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="groups.php">Группы</a></li>
        <li class="breadcrumb-item active"><?= htmlspecialchars($group['name']) ?></li>
    </ol>
</nav>

<h2 class="mb-4">
    <i class="bi bi-people"></i> <?= htmlspecialchars($group['name']) ?>
    <span class="text-muted fs-6">(<?= count($students) ?> студентов)</span>
</h2>

<?php if (!empty($group['institute'])): ?>
    <p class="text-muted"><i class="bi bi-building"></i> <?= htmlspecialchars($group['institute']) ?></p>
<?php endif; ?>

<div class="d-flex gap-2 mb-3">
    <a href="subgroups.php?group_id=<?= (int)$group['id'] ?>" class="btn btn-outline-primary btn-sm">
        <i class="bi bi-diagram-3"></i> Подгруппы
    </a>
    <a href="upload_students.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-file-earmark-excel"></i> Импорт студентов
    </a>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light">
            <tr>
                <th style="width: 50px;">№</th>
                <th style="width: 100px;">Зачётка</th>
                <th>ФИО</th>
                <th style="width: 100px;">Подгруппа</th>
                <th>Логин</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($students as $i => $s): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><code><?= htmlspecialchars($s['zachetka'] ?? '—') ?></code></td>
                    <td><?= htmlspecialchars($s['full_name']) ?></td>
                    <td>
                        <?php if (!empty($s['subgroup'])): ?>
                            <span class="badge bg-primary"><?= htmlspecialchars($s['subgroup']) ?></span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted small"><?= htmlspecialchars($s['login'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>