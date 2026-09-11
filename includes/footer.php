<footer class="site-footer deseo-footer">
    <div class="deseo-footer-inner">
        <div class="deseo-footer-main">
            <div class="deseo-footer-identity">
                <img class="deseo-footer-logo" src="/assets/img/deseoradio-logo.png" alt="Deseo Radio" width="220" height="62">
                <p class="deseo-footer-tagline" data-i18n="footer.tagline"><?= deseo_e(deseo_t('footer.tagline')) ?></p>

                <a class="deseo-footer-live-link" href="#main-content">
                    <span class="deseo-live-dot" aria-hidden="true"></span>
                    <span>LIVE RADIO</span>
                </a>
            </div>

            <div class="deseo-footer-nav">
                <div class="deseo-footer-group">
                    <span class="deseo-footer-label" data-i18n="footer.listen"><?= deseo_e(deseo_t('footer.listen')) ?></span>
                    <a href="#main-content" data-i18n="footer.live_player"><?= deseo_e(deseo_t('footer.live_player')) ?></a>
                    <a href="#program" data-i18n="footer.today_program"><?= deseo_e(deseo_t('footer.today_program')) ?></a>
                    <a href="#airplay" data-i18n="footer.weekly_airplay"><?= deseo_e(deseo_t('footer.weekly_airplay')) ?></a>
                </div>

                <div class="deseo-footer-group">
                    <span class="deseo-footer-label" data-i18n="footer.contact"><?= deseo_e(deseo_t('footer.contact')) ?></span>
                    <a href="tel:+302103000825">+30 210 300 0825</a>
                    <a href="mailto:radio@iluma.gr">radio@iluma.gr</a>
                    <p>1st Moschonision st.<br>Egaleo, 12242 GR</p>
                </div>

                <div class="deseo-footer-group">
                    <span class="deseo-footer-label" data-i18n="footer.follow"><?= deseo_e(deseo_t('footer.follow')) ?></span>
                    <a href="https://www.instagram.com/deseoradio/" target="_blank" rel="noopener noreferrer">Instagram ↗</a>
                    <a href="https://www.facebook.com/deseoradiogr/" target="_blank" rel="noopener noreferrer">Facebook ↗</a>
                    <a href="https://iluma.gr/radios/deseo" target="_blank" rel="noopener noreferrer">ILUMA Radios ↗</a>
                </div>
            </div>
        </div>

        <div class="deseo-footer-bottom">
            <span>© <?= date('Y') ?> Deseo Radio</span>
            <span><span data-i18n="footer.powered"><?= deseo_e(deseo_t('footer.powered')) ?></span> <a href="https://iluma.gr/" target="_blank" rel="noopener noreferrer">ILUMA Digital Agency</a></span>
        </div>
    </div>
</footer>

<button class="install-prompt" id="pwa-install-prompt" type="button" hidden>
    <span data-i18n="install.title"><?= deseo_e(deseo_t('install.title')) ?></span>
    <small data-i18n="install.subtitle"><?= deseo_e(deseo_t('install.subtitle')) ?></small>
</button>

<?php include_once __DIR__ . '/cookiebanner.php'; ?>

<?php
$clientKeys = ["skip.content","header.home","header.live","header.live_aria","header.instagram","header.facebook","meta.title","meta.description","meta.keywords","hero.sr_title","hero.now_playing","hero.now_on_air","hero.sponsor","hero.live_broadcast","hero.non_stop_mix","hero.auto_dj","program.eyebrow","program.heading","program.heading_em","program.description","program.on_air_now","program.live_set","program.empty_title","program.empty_fallback","program.empty_none","program.next_live","day.1","day.2","day.3","day.4","day.5","day.6","day.7","airplay.eyebrow","airplay.heading","airplay.heading_em","airplay.description","airplay.spotify_aria","airplay.empty_title","airplay.empty_text","partners.eyebrow","partners.heading","partners.heading_em","partners.description","advertise.eyebrow","advertise.heading","advertise.heading_em","advertise.text","advertise.cta","footer.tagline","footer.contact","footer.follow","footer.listen","footer.live_player","footer.today_program","footer.weekly_airplay","footer.powered","install.title","install.subtitle","cookie.title","cookie.text","cookie.analytics","cookie.marketing","cookie.accept","cookie.customize","cookie.reject"];
$currentLanguage = deseo_lang();
$clientTranslations = ['el' => [], 'en' => []];

foreach (['el', 'en'] as $language) {
    $GLOBALS['deseo_lang'] = $language;
    foreach ($clientKeys as $key) {
        $clientTranslations[$language][$key] = deseo_t($key);
    }
}
$GLOBALS['deseo_lang'] = $currentLanguage;
?>

<script>
window.DESEO_TRANSLATIONS = <?= json_encode($clientTranslations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.DESEO_LANGUAGE = <?= json_encode(deseo_lang()) ?>;

(function () {
    'use strict';

    var installEvent = null;
    var installButton = document.getElementById('pwa-install-prompt');

    function onReady() {
        var images = document.querySelectorAll('img[data-fallback]');
        var i;
        for (i = 0; i < images.length; i++) {
            images[i].addEventListener('error', function () {
                var fallback = this.getAttribute('data-fallback');
                if (fallback && this.src.indexOf(fallback) === -1) this.src = fallback;
            });
        }

        var revealItems = document.querySelectorAll('.reveal');
        if ('IntersectionObserver' in window) {
            var observer = new IntersectionObserver(function (entries) {
                var j;
                for (j = 0; j < entries.length; j++) {
                    if (entries[j].isIntersecting) {
                        entries[j].target.className += ' is-visible';
                        observer.unobserve(entries[j].target);
                    }
                }
            }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });

            for (i = 0; i < revealItems.length; i++) observer.observe(revealItems[i]);
        } else {
            for (i = 0; i < revealItems.length; i++) revealItems[i].className += ' is-visible';
        }
    }

    function applyLanguage(language) {
        var catalog = window.DESEO_TRANSLATIONS && window.DESEO_TRANSLATIONS[language];
        if (!catalog) return;

        var nodes = document.querySelectorAll('[data-i18n]');
        var i;
        for (i = 0; i < nodes.length; i++) {
            var key = nodes[i].getAttribute('data-i18n');
            if (Object.prototype.hasOwnProperty.call(catalog, key)) {
                nodes[i].textContent = catalog[key];
            }
        }

        var ariaNodes = document.querySelectorAll('[data-i18n-aria]');
        for (i = 0; i < ariaNodes.length; i++) {
            var ariaKey = ariaNodes[i].getAttribute('data-i18n-aria');
            if (Object.prototype.hasOwnProperty.call(catalog, ariaKey)) {
                ariaNodes[i].setAttribute('aria-label', catalog[ariaKey]);
            }
        }

        document.documentElement.lang = language;
        var options = document.querySelectorAll('[data-lang-switch]');
        for (i = 0; i < options.length; i++) {
            var isActive = options[i].getAttribute('data-lang-switch') === language;
            if (isActive) options[i].classList.add('active');
            else options[i].classList.remove('active');
            options[i].setAttribute('aria-current', isActive ? 'true' : 'false');
        }

        try {
            document.cookie = 'deseo_lang=' + language + '; Max-Age=31536000; Path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
        } catch (e) {}

        try {
            var url = new URL(window.location.href);
            if (language === 'el') url.searchParams.delete('lang');
            else url.searchParams.set('lang', 'en');
            window.history.replaceState({}, '', url.pathname + (url.search || '') + (url.hash || ''));
        } catch (e) {}

        window.DESEO_LANGUAGE = language;
    }

    function bindLanguageSwitcher() {
        var switches = document.querySelectorAll('[data-lang-switch]');
        for (var i = 0; i < switches.length; i++) {
            switches[i].addEventListener('click', function (event) {
                event.preventDefault();
                applyLanguage(this.getAttribute('data-lang-switch'));
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            onReady();
            bindLanguageSwitcher();
        });
    } else {
        onReady();
        bindLanguageSwitcher();
    }

    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        installEvent = event;
        installButton.hidden = false;
    });

    installButton.addEventListener('click', function () {
        if (!installEvent) return;
        installEvent.prompt();
        installEvent.userChoice.then(function () {
            installEvent = null;
            installButton.hidden = true;
        });
    });

    window.addEventListener('appinstalled', function () {
        installEvent = null;
        installButton.hidden = true;
    });

    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.getRegistrations().then(function (registrations) {
            for (var r = 0; r < registrations.length; r++) {
                var reg = registrations[r];
                var worker = reg.active || reg.waiting || reg.installing;
                var scriptUrl = worker && worker.scriptURL ? worker.scriptURL : '';
                if (scriptUrl.indexOf('/sw.js') !== -1 && scriptUrl.indexOf('/webpushr-sw.js') === -1) {
                    reg.unregister();
                }
            }
        }).catch(function () {});
    }

    if ('caches' in window) {
        caches.keys().then(function (keys) {
            for (var c = 0; c < keys.length; c++) {
                if (keys[c].indexOf('deseo-') === 0) caches.delete(keys[c]);
            }
        }).catch(function () {});
    }
}());
</script>
</body>
</html>