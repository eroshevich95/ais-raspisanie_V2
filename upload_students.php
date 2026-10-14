<?php
// upload_students.php

$pageTitle = 'Импорт студентов';
require_once __DIR__ . '/config/database.php';
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
$message = '';
$report = [
    'total_rows' => 0, 'added' => 0, 'updated' => 0, 'skipped' => 0,
    'groups_created' => 0, 'groups_used' => 0, 'groups_updated' => 0,
    'errors' => [], 'details' => [],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['file']['tmp_name'])) {
    csrf_verify();

    try {
        $file = $_FILES['file']['tmp_name'];
        $raw  = file_get_contents($file);
        $originalFileName = $_FILES['file']['name'] ?? 'unknown.csv';

        $hasBOM = (substr($raw, 0, 3) === "\xEF\xBB\xBF");
        if ($hasBOM) $raw = substr($raw, 3);

        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1251');
        }

        $firstLine = '';
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            if (trim($line) !== '') { $firstLine = $line; break; }
        }
        $commas = substr_count($firstLine, ',');
        $semicolons = substr_count($firstLine, ';');
        $tabs = substr_count($firstLine, "\t");

        if ($tabs >= $commas && $tabs >= $semicolons) $delimiter = "\t";
        elseif ($semicolons > $commas) $delimiter = ';';
        else $delimiter = ',';

        $tmp = tempnam(sys_get_temp_dir(), 'csv_');
        file_put_contents($tmp, $raw);
        $handle = fopen($tmp, 'r');
        if (!$handle) throw new Exception('Не удалось открыть файл');

        $pdo->beginTransaction();
        $groupCache = [];
        $rowNum = 0;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rowNum++;
            if (empty(array_filter($row, fn($v) => trim((string)$v) !== ''))) continue;

            if ($rowNum === 1) {
                $fc = mb_strtolower(trim((string)($row[0] ?? '')));
                $sc = mb_strtolower(trim((string)($row[1] ?? '')));
                if (str_contains($fc, 'зачёт') || str_contains($fc, '№') || str_contains($sc, 'фамилия')) continue;
            }

            $zachetka   = trim((string)($row[0]  ?? ''));
            $lastName   = trim((string)($row[1]  ?? ''));
            $firstName  = trim((string)($row[2]  ?? ''));
            $middleName = trim((string)($row[3]  ?? ''));
            $groupName  = trim((string)($row[4]  ?? ''));
            $department = trim((string)($row[5]  ?? ''));
            $institute  = trim((string)($row[6]  ?? ''));
            $direction  = trim((string)($row[7]  ?? ''));
            $specialty  = trim((string)($row[8]  ?? ''));
            $status     = trim((string)($row[9]  ?? ''));
            $login      = trim((string)($row[10] ?? ''));

            $report['total_rows']++;

            if (!$lastName || !$firstName || !$groupName) {
                $report['errors'][] = "Строка {$rowNum}: не хватает ФИО или группы";
                $report['skipped']++;
                continue;
            }
            if ($status !== '' && mb_strtolower($status) !== 'обучается') {
                $report['skipped']++;
                continue;
            }

            $fullName = trim("$lastName $firstName $middleName");

            if (!isset($groupCache[$groupName])) {
                $stmt = $pdo->prepare("SELECT id, institute FROM groups_table WHERE name = ?");
                $stmt->execute([$groupName]);
                $existing = $stmt->fetch();

                if ($existing) {
                    $groupId = (int)$existing['id'];
                    $report['groups_used']++;
                    $currentInst = trim($existing['institute'] ?? '');
                    if ($currentInst === '' && $institute !== '') {
                        $pdo->prepare("UPDATE groups_table SET institute = ? WHERE id = ?")->execute([$institute, $groupId]);
                        $report['groups_updated']++;
                    }
                } else {
                    $pdo->prepare("INSERT INTO groups_table (name, institute) VALUES (?, ?)")->execute([$groupName, $institute ?: null]);
                    $groupId = (int)$pdo->lastInsertId();
                    $report['groups_created']++;
                }
                $groupCache[$groupName] = $groupId;
            } else {
                $groupId = $groupCache[$groupName];
            }

            $studentId = null;
            if ($zachetka !== '') {
                $stmt = $pdo->prepare("SELECT id FROM students WHERE zachetka = ?");
                $stmt->execute([$zachetka]);
                $studentId = $stmt->fetchColumn() ?: null;
            }
            if (!$studentId) {
                $stmt = $pdo->prepare("SELECT id FROM students WHERE group_id = ? AND full_name = ?");
                $stmt->execute([$groupId, $fullName]);
                $studentId = $stmt->fetchColumn() ?: null;
            }

            if ($studentId) {
                $stmt = $pdo->prepare("SELECT zachetka, login, department, institute, direction, specialty FROM students WHERE id = ?");
                $stmt->execute([$studentId]);
                $cur = $stmt->fetch();

                $updates = ["group_id = ?", "full_name = ?"];
                $params  = [$groupId, $fullName];
                if (trim($cur['zachetka'] ?? '') === '' && $zachetka !== '') { $updates[] = "zachetka = ?"; $params[] = $zachetka; }
                if (trim($cur['login'] ?? '') === '' && $login !== '') { $updates[] = "login = ?"; $params[] = $login; }
                if (trim($cur['department'] ?? '') === '' && $department !== '') { $updates[] = "department = ?"; $params[] = $department; }
                if (trim($cur['institute'] ?? '') === '' && $institute !== '') { $updates[] = "institute = ?"; $params[] = $institute; }
                if (trim($cur['direction'] ?? '') === '' && $direction !== '') { $updates[] = "direction = ?"; $params[] = $direction; }
                if (trim($cur['specialty'] ?? '') === '' && $specialty !== '') { $updates[] = "specialty = ?"; $params[] = $specialty; }

                $params[] = $studentId;
                $pdo->prepare("UPDATE students SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);
                $report['updated']++;
                $report['details'][] = "↻ {$fullName}";
            } else {
                $pdo->prepare("INSERT INTO students (group_id, zachetka, full_name, login, department, institute, direction, specialty) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$groupId, $zachetka ?: null, $fullName, $login ?: null, $department ?: null, $institute ?: null, $direction ?: null, $specialty ?: null]);
                $report['added']++;
                $report['details'][] = "+ {$fullName}";
            }
        }

        fclose($handle);
        @unlink($tmp);
        $pdo->commit();

        $message = "Импорт завершён: обработано <b>{$report['total_rows']}</b> строк; "
                 . "добавлено <b>{$report['added']}</b>, обновлено <b>{$report['updated']}</b>, "
                 . "пропущено <b>{$report['skipped']}</b>; "
                 . "групп: создано <b>{$report['groups_created']}</b>, "
                 . "использовано <b>{$report['groups_used']}</b>, "
                 . "дополнено <b>{$report['groups_updated']}</b>.";

        auditLog($pdo, 'students.import', [
            'entity_type' => 'file', 'entity_id' => null,
            'description' => "Импорт студентов из CSV: " . $originalFileName . " · всего строк: " . $report['total_rows'],
            'new_value'   => "добавлено: {$report['added']}, обновлено: {$report['updated']}, пропущено: {$report['skipped']}",
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('upload_students error: ' . $e->getMessage());
        $message = 'Ошибка: ' . ($e instanceof Exception ? $e->getMessage() : 'Ошибка сервера');
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<h2 class="mb-4"><i class="bi bi-file-earmark-excel"></i> Импорт студентов из CSV</h2>

<?php if ($message): ?>
    <div class="alert alert-info"><?= $message ?></div>
<?php endif; ?>

<?php if (!empty($report['errors'])): ?>
    <div class="alert alert-warning">
        <strong>Предупреждения:</strong>
        <ul class="mb-0 mt-2 small">
            <?php foreach (array_slice($report['errors'], 0, 10) as $err): ?>
                <li><?= htmlspecialchars($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-7">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h5 class="card-title"><i class="bi bi-upload"></i> Загрузка</h5>
                <p class="text-muted small mb-2">Файл <code>.csv</code> (UTF-8 или Windows-1251).</p>
                <form method="post" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="file" name="file" class="form-control mb-3" accept=".csv,text/csv" required>
                    <button class="btn btn-primary"><i class="bi bi-upload"></i> Загрузить и импортировать</button>
                </form>
                <div class="alert alert-light border small mt-3 mb-0">
                    <strong>Что обновляется при повторном импорте:</strong>
                    <ul class="mb-0 mt-1 ps-3">
                        <li>Институт группы — если был пустой</li>
                        <li>Зачётка, логин, кафедра, институт, направление, специальность студента — если пустые</li>
                        <li>ФИО и группа — всегда актуальные</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-5">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h5 class="card-title"><i class="bi bi-info-circle"></i> Столбцы</h5>
                <table class="table table-sm small mb-0">
                    <tbody>
                    <tr><td><code>A</code></td><td>№ зачётки</td></tr>
                    <tr><td><code>B</code></td><td>Фамилия</td></tr>
                    <tr><td><code>C</code></td><td>Имя</td></tr>
                    <tr><td><code>D</code></td><td>Отчество</td></tr>
                    <tr><td><code>E</code></td><td>Группа</td></tr>
                    <tr><td><code>F</code></td><td>Кафедра</td></tr>
                    <tr><td><code>G</code></td><td>Институт</td></tr>
                    <tr><td><code>H</code></td><td>Направление</td></tr>
                    <tr><td><code>I</code></td><td>Специальность</td></tr>
                    <tr><td><code>J</code></td><td>Статус</td></tr>
                    <tr><td><code>K</code></td><td>Логин</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($report['details'])): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white"><strong>Детали импорта (<?= count($report['details']) ?>)</strong></div>
        <div class="card-body">
            <pre style="max-height: 300px; overflow: auto; background: #f8f9fa; padding: 10px; border-radius: 4px; font-size: 12px;"><?php foreach ($report['details'] as $line) echo htmlspecialchars($line) . "\n"; ?></pre>
        </div>
    </div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <strong><i class="bi bi-people"></i> Группы в базе</strong>
        <span class="badge bg-secondary"><?= $pdo->query("SELECT COUNT(*) FROM groups_table")->fetchColumn() ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light">
            <tr><th>Группа</th><th>Институт</th><th class="text-end">Студентов</th><th></th></tr>
            </thead>
            <tbody>
            <?php
            $groups = $pdo->query("SELECT g.id, g.name, g.institute, COUNT(s.id) AS cnt FROM groups_table g LEFT JOIN students s ON s.group_id = g.id GROUP BY g.id ORDER BY g.name")->fetchAll();
            foreach ($groups as $g):
            ?>
                <tr>
                    <td><strong><?= htmlspecialchars($g['name']) ?></strong></td>
                    <td class="text-muted small">
                        <?php if (empty($g['institute'])): ?><span class="badge bg-warning text-dark">не заполнен</span>
                        <?php else: ?><?= htmlspecialchars($g['institute']) ?><?php endif; ?>
                    </td>
                    <td class="text-end"><?= $g['cnt'] ?></td>
                    <td class="text-end"><a href="group_view.php?id=<?= $g['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>