<?php
// includes/messages.php

if (!function_exists('getSectionsList')) {
    function getSectionsList(): array {
        return [
            'all'            => '🌐 Все страницы',
            'dashboard'      => '🏠 Дашборд',
            'schedule'       => '📅 Расписание',
            'attendance'     => '✅ Посещаемость',
            'works'          => '📓 Работы',
            'reports'        => '📊 Отчёты',
            'check_reports'  => '🔍 Проверка отчётов',
            'debtors'        => '⚠️ Должники',
            'session'        => '🎓 Сессия',
            'session_sheet'  => '📝 Ведомость',
            'groups'         => '👥 Группы',
            'subgroups'      => '🔀 Подгруппы',
            'upload_students'=> '📥 Импорт студентов',
            'audit'          => '🕓 Журнал',
            'notifications'  => '🔔 Уведомления',
            'profile'        => '👤 Профиль',
            'docs'           => '📖 Документация',
        ];
    }
}

if (!function_exists('getMessagesTypeMeta')) {
    function getMessagesTypeMeta(): array {
        return [
            'info'    => ['Информация', 'bi-info-circle-fill', '#0071e3', 'rgba(0,113,227,0.08)',  'rgba(0,113,227,0.25)'],
            'success' => ['Успех',      'bi-check-circle-fill', '#198754', 'rgba(48,209,88,0.08)',  'rgba(48,209,88,0.25)'],
            'warning' => ['Внимание',   'bi-exclamation-triangle-fill', '#b25c00', 'rgba(255,159,10,0.08)', 'rgba(255,159,10,0.3)'],
            'danger'  => ['Ошибка',     'bi-x-octagon-fill', '#842029', 'rgba(255,69,58,0.08)',  'rgba(255,69,58,0.25)'],
        ];
    }
}

if (!function_exists('getActiveMessages')) {
    function getActiveMessages(PDO $pdo, string $section): array {
        try {
            $stmt = $pdo->prepare("
                SELECT id, section, type, title, text, dismissible, sort_order
                FROM site_messages
                WHERE is_active = 1
                  AND (section = ? OR section = 'all')
                ORDER BY sort_order ASC, id ASC
            ");
            $stmt->execute([$section]);
            return $stmt->fetchAll() ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('renderMessages')) {
    function renderMessages(PDO $pdo, string $section): string {
        $messages = getActiveMessages($pdo, $section);
        if (empty($messages)) return '';

        $types = getMessagesTypeMeta();
        $html = '<div class="site-messages" data-section="' . htmlspecialchars($section) . '">';

        foreach ($messages as $m) {
            $t = $types[$m['type']] ?? $types['info'];
            $id = (int)$m['id'];
            $dismissible = !empty($m['dismissible']);
            $storageKey = 'msg_' . $id;

            $html .= '<div class="site-message site-message--' . htmlspecialchars($m['type']) . '" '
                   . 'data-msg-id="' . $id . '" '
                   . 'data-dismiss-key="' . $storageKey . '" '
                   . 'style="--msg-color:' . $t[2] . '; --msg-bg:' . $t[3] . '; --msg-border:' . $t[4] . ';">';

            $html .= '<div class="site-message-icon"><i class="bi ' . $t[1] . '"></i></div>';
            $html .= '<div class="site-message-body">';
            if (!empty($m['title'])) {
                $html .= '<div class="site-message-title">' . htmlspecialchars($m['title']) . '</div>';
            }
            $html .= '<div class="site-message-text">' . nl2br(htmlspecialchars($m['text'])) . '</div>';
            $html .= '</div>';

            if ($dismissible) {
                $html .= '<button type="button" class="site-message-close" '
                       . 'onclick="dismissSiteMessage(' . $id . ')" title="Скрыть">'
                       . '<i class="bi bi-x-lg"></i></button>';
            }

            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }
}

if (!function_exists('renderMessagesStyles')) {
    function renderMessagesStyles(): string {
        return <<<CSS
<style>
.site-messages { display: flex; flex-direction: column; gap: 10px; margin: 0 0 18px; }
.site-message {
    display: flex; align-items: flex-start; gap: 14px;
    padding: 14px 18px; border-radius: 14px;
    background: var(--msg-bg, rgba(0,113,227,0.08));
    border: 1px solid var(--msg-border, rgba(0,113,227,0.2));
    color: var(--msg-color, #084298);
    font-size: 0.9rem; line-height: 1.5; position: relative;
    animation: siteMsgIn 0.4s cubic-bezier(0.16,1,0.3,1);
}
@keyframes siteMsgIn { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }
.site-message-icon { flex-shrink: 0; font-size: 1.2rem; margin-top: 1px; }
.site-message-body { flex: 1; min-width: 0; }
.site-message-title { font-weight: 700; margin-bottom: 4px; font-size: 0.95rem; }
.site-message-text { opacity: 0.95; }
.site-message-text a { color: inherit; text-decoration: underline; }
.site-message-close {
    flex-shrink: 0; width: 28px; height: 28px; border-radius: 50%;
    background: rgba(255,255,255,0.5); border: none; color: currentColor;
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    font-size: 0.78rem; transition: all 0.2s;
}
.site-message-close:hover { background: currentColor; color: #fff; transform: rotate(90deg); }
[data-theme="dark"] .site-message { background: var(--msg-bg, rgba(10,132,255,0.12)); color: #e7ecf2; }
[data-theme="dark"] .site-message--warning { color: #ffb74d; }
[data-theme="dark"] .site-message--success { color: #6ee7a3; }
[data-theme="dark"] .site-message--danger  { color: #ff7b6e; }
[data-theme="dark"] .site-message--info    { color: #6ea8ff; }
[data-theme="dark"] .site-message-close { background: rgba(255,255,255,0.1); }
@media (max-width: 576px) {
    .site-message { padding: 12px 14px; font-size: 0.85rem; gap: 10px; }
    .site-message-icon { font-size: 1.05rem; }
}
</style>
CSS;
    }
}

if (!function_exists('renderMessagesScript')) {
    function renderMessagesScript(): string {
        return <<<JS
<script>
function dismissSiteMessage(id) {
    try { localStorage.setItem('ap_msg_dismissed_' + id, '1'); } catch (e) {}
    const el = document.querySelector('.site-message[data-msg-id="' + id + '"]');
    if (el) {
        el.style.transition = 'opacity 0.25s, transform 0.25s';
        el.style.opacity = '0';
        el.style.transform = 'translateX(-20px)';
        setTimeout(() => el.remove(), 260);
    }
}
(function() {
    document.querySelectorAll('.site-message[data-dismiss-key]').forEach(el => {
        const id = el.dataset.msgId;
        try {
            if (localStorage.getItem('ap_msg_dismissed_' + id) === '1') el.remove();
        } catch (e) {}
    });
})();
</script>
JS;
    }
}