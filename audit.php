<?php
// audit.php

$pageTitle = 'Журнал действий';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access_control.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/session_boot.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/csrf.php';

sendSecurityHeaders();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId   = (int)$_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'teacher';

// AJAX: очистка (только админ)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_audit') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_verify(true);

    if ($userRole !== 'admin') {
        echo json_encode(['ok' => false, 'error' => 'Только администратор']);
        exit;
    }

    $mode = $_POST['mode'] ?? 'all';
    $from = $_POST['from'] ?? '';
    $to   = $_POST['to']   ?? '';

    try {
        if ($mode === 'all') {
            $stmt = $pdo->prepare("DELETE FROM audit_log");
            $stmt->execute();
            $deleted = $stmt->rowCount();

            auditLog($pdo, 'audit.cleared', [
                'description' => "Журнал полностью очищен (удалено: {$deleted})",
            ]);
            echo json_encode(['ok' => true, 'deleted' => $deleted]);
            exit;
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            echo json_encode(['ok' => false, 'error' => 'Некорректные даты']); exit;
        }
        if (strtotime($from) > strtotime($to)) {
            echo json_encode(['ok' => false, 'error' => 'Дата «С» больше «По»']); exit;
        }

        $stmt = $pdo->prepare("DELETE FROM audit_log WHERE DATE(created_at) BETWEEN ? AND ?");
        $stmt->execute([$from, $to]);
        $deleted = $stmt->rowCount();

        auditLog($pdo, 'audit.cleared', [
            'description' => "Журнал очищен за {$from} — {$to} (удалено: {$deleted})",
        ]);
        echo json_encode(['ok' => true, 'deleted' => $deleted]);
    } catch (Throwable $e) {
        error_log('clear_audit error: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'Ошибка сервера']);
    }
    exit;
}

$canViewAll = in_array($userRole, ['head_of_department', 'administration', 'admin']);
$isAdmin    = ($userRole === 'admin');

$scope = getAccessScope($pdo, $userId);

$action   = trim($_GET['action'] ?? '');
$entityId = (int)($_GET['entity_id'] ?? 0);
$search   = trim($_GET['search'] ?? '');
$from     = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$to       = $_GET['to']   ?? date('Y-m-d');
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 50;

$where  = ["DATE(a.created_at) BETWEEN ? AND ?"];
$params = [$from, $to];

if (!$canViewAll) {
    $where[] = "a.user_id = ?"; $params[] = $userId;
} else {
    $allowedTeacherIds = getAllowedTeacherIds($pdo, $scope);
    if ($allowedTeacherIds !== null) {
        if (empty($allowedTeacherIds)) $where[] = "1 = 0";
        else {
            $in = implode(',', array_fill(0, count($allowedTeacherIds), '?'));
            $where[] = "a.user_id IN ($in)";
            foreach ($allowedTeacherIds as $t) $params[] = $t;
        }
    }
}

if ($action !== '') { $where[] = "a.action = ?"; $params[] = $action; }
if ($entityId > 0)  { $where[] = "a.entity_id = ?"; $params[] = $entityId; }
if ($search !== '') {
    $where[] = "(a.user_name LIKE ? OR a.description LIKE ? OR a.old_value LIKE ? OR a.new_value LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}

$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_log a WHERE $whereSql");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("SELECT a.* FROM audit_log a WHERE $whereSql ORDER BY a.id DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$actions = $pdo->query("SELECT DISTINCT action FROM audit_log ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
$actionLabels = auditActionLabels();
$scopeInfo    = scopeLabel($scope);

require_once __DIR__ . '/includes/header.php';
?>

<style>
.aud-header { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
.aud-header h2 { font-size: 1.7rem; font-weight: 700; letter-spacing: -0.02em; margin: 0 0 6px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.aud-header p { color: #86868b; margin: 0; font-size: 0.95rem; }
.aud-scope-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; background: rgba(0,113,227,0.1); color: #0071e3; }

.aud-filter { background: #fff; border-radius: 20px; border: 1px solid #eaeaea; box-shadow: 0 4px 16px rgba(0,0,0,0.04); padding: 20px 24px; margin-bottom: 20px; }
.aud-filter-row { display: grid; grid-template-columns: 2fr 2fr 1fr 1fr auto; gap: 12px; align-items: end; }
@media (max-width: 991px) { .aud-filter-row { grid-template-columns: 1fr 1fr; } }
@media (max-width: 575px) { .aud-filter-row { grid-template-columns: 1fr; } }
.aud-filter-label { font-size: 0.75rem; font-weight: 700; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.3px; }
.aud-filter-label i { color: #0071e3; margin-right: 4px; }
.aud-input, .aud-select { width: 100%; padding: 11px 14px; border-radius: 10px; border: 1.5px solid #e5e5ea; background: #fff; font-size: 0.9rem; font-family: inherit; outline: none; transition: all 0.2s; }
.aud-input:focus, .aud-select:focus { border-color: #0071e3; box-shadow: 0 0 0 4px rgba(0,113,227,0.12); }
.aud-submit-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 11px 24px; border-radius: 10px; background: linear-gradient(135deg, #0071e3 0%, #5e5ce6 100%); color: #fff; border: none; font-size: 0.9rem; font-weight: 600; cursor: pointer; font-family: inherit; transition: all 0.25s; box-shadow: 0 6px 16px rgba(0,113,227,0.25); white-space: nowrap; }
.aud-submit-btn:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(0,113,227,0.4); }

.aud-card { background: #fff; border-radius: 20px; border: 1px solid #eaeaea; box-shadow: 0 4px 16px rgba(0,0,0,0.04); overflow: hidden; margin-bottom: 20px; }
.aud-table-wrap { overflow-x: auto; }
.aud-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.aud-table thead th { background: #fafafc; padding: 12px 14px; text-align: left; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; color: #86868b; border-bottom: 1px solid #f0f0f2; white-space: nowrap; position: sticky; top: 0; z-index: 1; }
.aud-table tbody td { padding: 12px 14px; border-bottom: 1px solid #f5f5f7; vertical-align: top; }
.aud-table tbody tr:last-child td { border-bottom: none; }
.aud-table tbody tr:hover { background: #fafafc; }
.aud-time { font-size: 0.75rem; color: #86868b; white-space: nowrap; line-height: 1.3; }
.aud-time .date { font-weight: 700; color: #1d1d1f; }
.aud-user { display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: 0.85rem; }
.aud-user-avatar { width: 26px; height: 26px; border-radius: 50%; background: linear-gradient(135deg, #0071e3 0%, #5e5ce6 100%); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; font-weight: 700; flex-shrink: 0; }
.aud-user-ip { font-size: 0.7rem; color: #86868b; font-family: "SF Mono", Monaco, monospace; }
.aud-action { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 20px; font-size: 0.72rem; font-weight: 700; white-space: nowrap; }
.aud-action--success   { background: rgba(48,209,88,0.15); color: #198754; }
.aud-action--danger    { background: rgba(255,69,58,0.15); color: #842029; }
.aud-action--warning   { background: rgba(255,159,10,0.15); color: #b25c00; }
.aud-action--primary   { background: rgba(0,113,227,0.12); color: #0071e3; }
.aud-action--info      { background: rgba(13,202,240,0.15); color: #055160; }
.aud-action--secondary { background: rgba(134,134,139,0.15); color: #495057; }
.aud-desc { color: #1d1d1f; font-weight: 500; line-height: 1.4; font-size: 0.85rem; }
.aud-entity { font-size: 0.72rem; color: #86868b; margin-top: 3px; }
.aud-entity code { background: rgba(0,113,227,0.08); color: #0071e3; padding: 1px 6px; border-radius: 4px; font-family: "SF Mono", Monaco, monospace; }

.aud-pagination { display: flex; justify-content: center; gap: 6px; margin-top: 20px; flex-wrap: wrap; }
.aud-page-btn { min-width: 38px; height: 38px; padding: 0 12px; border-radius: 10px; border: 1.5px solid #e5e5ea; background: #fff; color: #1d1d1f; font-size: 0.85rem; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s; }
.aud-page-btn:hover { border-color: #0071e3; color: #0071e3; }
.aud-page-btn--active { background: linear-gradient(135deg, #0071e3 0%, #5e5ce6 100%); color: #fff; border-color: transparent; }
.aud-empty { padding: 60px 24px; text-align: center; color: #86868b; }
.aud-empty i { font-size: 3rem; opacity: 0.3; display: block; margin-bottom: 12px; }

[data-theme="dark"] .aud-filter, [data-theme="dark"] .aud-card { background: #1c1c1e; border-color: #2c2c2e; }
[data-theme="dark"] .aud-header h2, [data-theme="dark"] .aud-filter-label, [data-theme="dark"] .aud-desc,
[data-theme="dark"] .aud-user, [data-theme="dark"] .aud-time .date { color: #f5f5f7; }
[data-theme="dark"] .aud-input, [data-theme="dark"] .aud-select { background: #2c2c2e; border-color: #3c3c3e; color: #f5f5f7; }
[data-theme="dark"] .aud-table thead th { background: #2c2c2e; border-color: #3c3c3e; color: #a1a1a6; }
[data-theme="dark"] .aud-table tbody td { border-color: #2c2c2e; }
[data-theme="dark"] .aud-table tbody tr:hover { background: #2c2c2e; }
[data-theme="dark"] .aud-page-btn { background: #2c2c2e; border-color: #3c3c3e; color: #f5f5f7; }
</style>

<div class="aud-header">
    <div>
        <h2>
            <i class="bi bi-clock-history"></i> Журнал действий
            <?php if ($canViewAll): ?>
                <span class="aud-scope-badge"><i class="bi <?= $scopeInfo[2] ?>"></i> <?= htmlspecialchars($scopeInfo[0]) ?></span>
            <?php endif; ?>
        </h2>
        <p><i class="bi bi-info-circle"></i> История изменений: посещаемость, работы, оценки, профиль</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($isAdmin): ?>
            <button type="button" class="btn btn-outline-danger btn-sm" onclick="openClearModal()">
                <i class="bi bi-trash"></i> Очистить журнал
            </button>
        <?php endif; ?>
        <a href="dashboard.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Дашборд</a>
    </div>
</div>

<div class="aud-filter">
    <form method="get">
        <div class="aud-filter-row">
            <div>
                <div class="aud-filter-label"><i class="bi bi-funnel"></i> Действие</div>
                <select name="action" class="aud-select">
                    <option value="">— Все —</option>
                    <?php foreach ($actions as $a): ?>
                        <option value="<?= htmlspecialchars($a) ?>" <?= $action === $a ? 'selected' : '' ?>>
                            <?= htmlspecialchars($actionLabels[$a][0] ?? $a) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <div class="aud-filter-label"><i class="bi bi-search"></i> Поиск</div>
                <input type="text" name="search" class="aud-input" value="<?= htmlspecialchars($search) ?>" placeholder="ФИО, описание...">
            </div>
            <div>
                <div class="aud-filter-label"><i class="bi bi-calendar-event"></i> С</div>
                <input type="date" name="from" class="aud-input" value="<?= htmlspecialchars($from) ?>">
            </div>
            <div>
                <div class="aud-filter-label"><i class="bi bi-calendar-event"></i> По</div>
                <input type="date" name="to" class="aud-input" value="<?= htmlspecialchars($to) ?>">
            </div>
            <div><button type="submit" class="aud-submit-btn"><i class="bi bi-search"></i> Показать</button></div>
        </div>
        <div style="margin-top:12px; padding-top:12px; border-top:1px solid #f0f0f2; display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
            <a href="audit.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle"></i> Сбросить</a>
            <div style="font-size:0.85rem; color:#86868b;">Найдено: <strong><?= $total ?></strong> · страница <?= $page ?> из <?= $totalPages ?></div>
        </div>
    </form>
</div>

<?php if (empty($rows)): ?>
    <div class="aud-card"><div class="aud-empty"><i class="bi bi-clock-history"></i><p>За выбранный период действий не найдено</p></div></div>
<?php else: ?>
    <div class="aud-card">
        <div class="aud-table-wrap">
            <table class="aud-table">
                <thead><tr><th style="width:140px;">Когда</th><th style="width:220px;">Кто</th><th style="width:240px;">Действие</th><th>Что именно</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $lbl = $actionLabels[$r['action']] ?? [$r['action'], 'secondary', 'bi-info-circle'];
                    $dateObj = new DateTime($r['created_at']);
                    $np = explode(' ', $r['user_name'] ?? '');
                    $initials = '';
                    if (!empty($np[0])) $initials .= mb_strtoupper(mb_substr($np[0], 0, 1));
                    if (!empty($np[1])) $initials .= mb_strtoupper(mb_substr($np[1], 0, 1));
                ?>
                    <tr>
                        <td><div class="aud-time"><div class="date"><?= $dateObj->format('d.m.Y') ?></div><div><?= $dateObj->format('H:i:s') ?></div></div></td>
                        <td>
                            <div class="aud-user">
                                <div class="aud-user-avatar"><?= htmlspecialchars($initials ?: '?') ?></div>
                                <div>
                                    <div><?= htmlspecialchars($r['user_name'] ?? '—') ?></div>
                                    <?php if (!empty($r['ip'])): ?><div class="aud-user-ip"><?= htmlspecialchars($r['ip']) ?></div><?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td><span class="aud-action aud-action--<?= $lbl[1] ?>"><i class="bi <?= $lbl[2] ?>"></i> <?= htmlspecialchars($lbl[0]) ?></span></td>
                        <td>
                            <?php if (!empty($r['description'])): ?><div class="aud-desc"><?= htmlspecialchars($r['description']) ?></div><?php endif; ?>
                            <?php if (!empty($r['entity_type']) && !empty($r['entity_id'])): ?>
                                <div class="aud-entity"><code><?= htmlspecialchars($r['entity_type']) ?>#<?= (int)$r['entity_id'] ?></code></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="aud-pagination">
            <?php if ($page > 1): ?><a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="aud-page-btn"><i class="bi bi-chevron-left"></i></a><?php endif; ?>
            <?php
            $start = max(1, $page - 3); $end = min($totalPages, $page + 3);
            if ($start > 1) echo '<a href="?' . http_build_query(array_merge($_GET, ['page' => 1])) . '" class="aud-page-btn">1</a>';
            if ($start > 2) echo '<span style="padding:0 6px;color:#86868b;">…</span>';
            for ($i = $start; $i <= $end; $i++): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" class="aud-page-btn <?= $i === $page ? 'aud-page-btn--active' : '' ?>"><?= $i ?></a>
            <?php endfor;
            if ($end < $totalPages - 1) echo '<span style="padding:0 6px;color:#86868b;">…</span>';
            if ($end < $totalPages) echo '<a href="?' . http_build_query(array_merge($_GET, ['page' => $totalPages])) . '" class="aud-page-btn">' . $totalPages . '</a>';
            ?>
            <?php if ($page < $totalPages): ?><a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="aud-page-btn"><i class="bi bi-chevron-right"></i></a><?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($isAdmin): ?>
<script>
function openClearModal() {
    const mode = prompt('Очистить журнал:\n1 — всё\n2 — за период\n\nВведите 1 или 2:', '1');
    if (mode === '1') {
        if (!confirm('Точно удалить ВСЕ записи журнала?')) return;
        const fd = new FormData();
        fd.append('action', 'clear_audit');
        fd.append('mode', 'all');
        fd.append('csrf', CSRF);
        sendClear(fd);
    } else if (mode === '2') {
        const from = prompt('С какой даты? (YYYY-MM-DD)', '<?= date('Y-m-d', strtotime('-30 days')) ?>');
        const to   = prompt('По какую дату? (YYYY-MM-DD)', '<?= date('Y-m-d') ?>');
        if (!from || !to) return;
        if (!confirm(`Удалить записи с ${from} по ${to}?`)) return;
        const fd = new FormData();
        fd.append('action', 'clear_audit');
        fd.append('mode', 'period');
        fd.append('from', from);
        fd.append('to', to);
        fd.append('csrf', CSRF);
        sendClear(fd);
    }
}
function sendClear(fd) {
    fetch('audit.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.ok) { alert('Ошибка: ' + (data.error || 'не удалось')); return; }
            alert('Удалено записей: ' + data.deleted);
            location.reload();
        })
        .catch(() => alert('Ошибка сети'));
}
const CSRF = <?= json_encode(csrf_token()) ?>;
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>