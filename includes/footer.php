<?php
// includes/footer.php
?>
</main>

<footer class="site-footer mt-5">
    <div class="container py-5">
        <div class="row g-4">

            <div class="col-lg-4 col-md-6">
                <div class="d-flex align-items-center mb-3">
                    <i class="bi bi-mortarboard-fill fs-3 text-primary me-2"></i>
                    <div>
                        <div class="fw-bold text-white">АИС-расписание.РФ</div>
                        <div class="small footer-subtext">Автоматизированная информационная система</div>
                    </div>
                </div>
                <p class="small text-muted mb-3">
                    Работает с официальными данными портала <strong>portal.kuzstu.ru</strong>.
                </p>
                <div class="d-flex gap-2">
                    <a href="https://max.ru/se14203391_bot" target="_blank" rel="noopener"
                       class="footer-social" title="КузГТУшник — бот в MAX">
                        <i class="bi bi-chat-dots-fill"></i>
                    </a>
                    <a href="https://vk.com/news_kuzstu" target="_blank" rel="noopener"
                       class="footer-social" title="КузГТУ во ВКонтакте">
                        <i class="bi bi-vk"></i>
                    </a>
                    <a href="https://t.me/vkuzstu" target="_blank" rel="noopener"
                       class="footer-social" title="КузГТУ в Telegram">
                        <i class="bi bi-telegram"></i>
                    </a>
                </div>
            </div>

            <div class="col-lg-3 col-md-6">
                <h6 class="footer-heading">Сервисы КузГТУ</h6>
                <ul class="footer-links">
                    <li><a href="https://kuzstu.ru" target="_blank" rel="noopener"><i class="bi bi-globe"></i> Главный сайт</a></li>
                    <li><a href="https://portal.kuzstu.ru" target="_blank" rel="noopener"><i class="bi bi-person-badge"></i> Портал КузГТУ</a></li>
                    <li><a href="https://el.kuzstu.ru" target="_blank" rel="noopener"><i class="bi bi-mortarboard"></i> Moodle</a></li>
                    <li><a href="https://ems.kuzstu.ru" target="_blank" rel="noopener"><i class="bi bi-envelope"></i> Почта</a></li>
                    <li><a href="https://library.kuzstu.ru" target="_blank" rel="noopener"><i class="bi bi-book"></i> Библиотека</a></li>
                </ul>
            </div>

            <div class="col-lg-2 col-md-6">
                <h6 class="footer-heading">Помощь</h6>
                <ul class="footer-links">
                    <li><a href="docs.php"><i class="bi bi-book"></i> Документация</a></li>
                    <li><a href="student.php"><i class="bi bi-mortarboard"></i> Проверить посещаемость</a></li>
                    <li><a href="https://max.ru/kuzstu_infobot" target="_blank" rel="noopener"><i class="bi bi-robot"></i> Помощник абитуриента</a></li>
                </ul>
            </div>

            <div class="col-lg-3 col-md-6">
                <h6 class="footer-heading">Приложение</h6>
                <p class="small text-muted mb-3">
                    Установите портал как приложение на телефон или компьютер.
                </p>

                <button id="pwa-install-footer-btn" class="pwa-install-btn" type="button" hidden>
                    <i class="bi bi-download"></i>
                    <span>Установить приложение</span>
                </button>

                <div id="pwa-installed-badge" class="pwa-installed-badge" hidden>
                    <i class="bi bi-check-circle-fill"></i>
                    <span>Приложение установлено</span>
                </div>

                <div id="pwa-ios-footer-hint" class="pwa-ios-hint" hidden>
                    <i class="bi bi-share-fill"></i>
                    <div>
                        Нажмите <strong>Поделиться</strong> в браузере,
                        затем <strong>«На главный экран»</strong>.
                    </div>
                </div>

                <div id="pwa-manual-footer-hint" class="pwa-manual-hint" hidden>
                    <i class="bi bi-info-circle"></i>
                    <div>
                        Откройте меню браузера (⋮) и выберите
                        <strong>«Установить приложение»</strong>.
                    </div>
                </div>
            </div>

        </div>

        <div class="tgk-banner mt-5">
            <div class="tgk-banner-inner">
                <div class="tgk-banner-left">
                    <div class="tgk-icon">
                        <i class="bi bi-calendar-check-fill"></i>
                    </div>
                    <div>
                        <div class="tgk-title">
                            <span class="tgk-badge">MAX</span>
                            КузГТУшник
                        </div>
                        <div class="tgk-desc">
                            Актуальное расписание для студентов и сотрудников КузГТУ.
                            Пары, замены, аудитории и график преподавателей — всегда под рукой.
                        </div>
                    </div>
                </div>
                <a href="https://max.ru/se14203391_bot" target="_blank" rel="noopener"
                   class="tgk-button">
                    <i class="bi bi-send-fill"></i>
                    Открыть бот в MAX
                    <i class="bi bi-arrow-right tgk-arrow"></i>
                </a>
            </div>
        </div>

        <hr class="my-4">

        <div class="row align-items-center">
            <div class="col-md-6 small text-muted">
                © <?= date('Y') ?> АИС-расписание.РФ. Все права защищены.
            </div>
            <div class="col-md-6 text-md-end small text-muted">
                Работает на официальных данных
                <a href="https://portal.kuzstu.ru" target="_blank" rel="noopener"
                   class="text-decoration-none">portal.kuzstu.ru</a>
            </div>
        </div>
    </div>
</footer>

<script>
(function() {
    const btn           = document.getElementById('pwa-install-footer-btn');
    const installedBadge= document.getElementById('pwa-installed-badge');
    const iosHint       = document.getElementById('pwa-ios-footer-hint');
    const manualHint    = document.getElementById('pwa-manual-footer-hint');

    const isStandalone = window.matchMedia('(display-mode: standalone)').matches
                      || window.navigator.standalone === true;
    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;

    if (isStandalone) {
        if (installedBadge) installedBadge.hidden = false;
        return;
    }

    let deferredPrompt = null;

    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredPrompt = e;
        if (btn) btn.hidden = false;
    });

    window.addEventListener('appinstalled', () => {
        deferredPrompt = null;
        if (btn) btn.hidden = true;
        if (installedBadge) installedBadge.hidden = false;
    });

    if (btn) {
        btn.addEventListener('click', async () => {
            if (!deferredPrompt) return;
            deferredPrompt.prompt();
            const { outcome } = await deferredPrompt.userChoice;
            deferredPrompt = null;
            if (btn) btn.hidden = true;
            if (outcome === 'accepted' && installedBadge) installedBadge.hidden = false;
        });
    }

    if (isIOS && iosHint) {
        iosHint.hidden = false;
        return;
    }

    if (!isIOS) {
        setTimeout(() => {
            if (btn && btn.hidden && manualHint) manualHint.hidden = false;
        }, 3000);
    }
})();
</script>

<style>
.pwa-install-btn {
    display: inline-flex; align-items: center; gap: 10px;
    padding: 12px 22px; border: none; border-radius: 980px;
    background: #0071e3; color: #fff; font-size: 0.95rem; font-weight: 600;
    font-family: inherit; cursor: pointer;
    transition: all 0.25s cubic-bezier(0.16,1,0.3,1);
    box-shadow: 0 8px 20px rgba(0,113,227,0.35);
}
.pwa-install-btn:hover { background: #0077ed; transform: translateY(-2px); box-shadow: 0 12px 28px rgba(0,113,227,0.45); }
.pwa-install-btn i { font-size: 1.1rem; }
.pwa-installed-badge {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 10px 18px; border-radius: 980px;
    background: rgba(48,209,88,0.15); color: #30d158;
    font-size: 0.9rem; font-weight: 600;
}
.pwa-ios-hint, .pwa-manual-hint {
    display: flex; align-items: flex-start; gap: 10px;
    padding: 12px 16px; border-radius: 14px;
    background: rgba(255,255,255,0.06); color: #a1a1a6;
    font-size: 0.85rem; line-height: 1.45; margin-top: 8px;
}
.pwa-ios-hint i, .pwa-manual-hint i { font-size: 1.1rem; color: #0071e3; flex-shrink: 0; margin-top: 2px; }
.pwa-ios-hint strong, .pwa-manual-hint strong { color: #fff; font-weight: 600; }
</style>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js"></script>
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js')
            .then(reg => console.log('[PWA] SW зарегистрирован:', reg.scope))
            .catch(err => console.warn('[PWA] SW ошибка:', err));
    });
}
</script>
</body>
</html>