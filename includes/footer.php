<footer class="site-footer">
    <div class="wide-shell footer-main">
        <div class="footer-brand">
            <img src="/assets/img/deseoradio-logo.png" alt="Deseo Radio" width="220" height="62">
            <p class="metal-title footer-tagline" data-i18n="footer.tagline"><?= deseo_e(deseo_t('footer.tagline')) ?></p>
            <a class="footer-iluma" href="https://iluma.gr/radios/deseo" target="_blank" rel="noopener noreferrer" data-analytics-event="iluma_network_click">ILUMA RADIOS ↗</a>
        </div>

        <div class="footer-links">
            <div>
                <span data-i18n="footer.listen"><?= deseo_e(deseo_t('footer.listen')) ?></span>
                <a href="#player" data-i18n="footer.live_player" data-analytics-event="live_radio_click"><?= deseo_e(deseo_t('footer.live_player')) ?></a>
                <a href="#program" data-i18n="footer.today_program"><?= deseo_e(deseo_t('footer.today_program')) ?></a>
                <a href="#playlists" data-i18n="footer.playlists"><?= deseo_e(deseo_t('footer.playlists')) ?></a>
                <a href="#faq" data-i18n="footer.faq"><?= deseo_e(deseo_t('footer.faq')) ?></a>
            </div>

            <div>
                <span data-i18n="footer.contact"><?= deseo_e(deseo_t('footer.contact')) ?></span>
                <a href="tel:+302103000825">+30 210 300 0825</a>
                <a href="mailto:radio@iluma.gr">radio@iluma.gr</a>
                <p>1st Moschonision st.<br>Egaleo, 12242 GR</p>
            </div>

            <div>
                <span data-i18n="footer.follow"><?= deseo_e(deseo_t('footer.follow')) ?></span>
                <a href="https://www.instagram.com/deseoradio/" target="_blank" rel="noopener noreferrer">Instagram ↗</a>
                <a href="https://www.facebook.com/deseoradiogr/" target="_blank" rel="noopener noreferrer">Facebook ↗</a>
                <a href="https://play.iradios.gr/station/deseo-radio" target="_blank" rel="noopener noreferrer">iRadios ↗</a>
            </div>
        </div>
    </div>

    <div class="wide-shell footer-bottom">
        <span>© <?= date('Y') ?> Deseo Radio · Athens</span>
        <span><span data-i18n="footer.powered"><?= deseo_e(deseo_t('footer.powered')) ?></span> <a href="https://iluma.gr/" target="_blank" rel="noopener noreferrer">ILUMA Digital Agency</a></span>
    </div>
</footer>

<button class="install-prompt" id="pwa-install-prompt" type="button" hidden aria-label="<?= deseo_e(deseo_t('install.title')) ?>">
    <span class="install-prompt-icon" aria-hidden="true">
        <img src="/assets/img/favicon.png" alt="">
    </span>

    <span class="install-prompt-copy">
        <strong data-i18n="install.title"><?= deseo_e(deseo_t('install.title')) ?></strong>
        <small data-i18n="install.subtitle"><?= deseo_e(deseo_t('install.subtitle')) ?></small>
    </span>

    <span class="install-prompt-cta" aria-hidden="true">
        <span>INSTALL</span>
        <i>↗</i>
    </span>
</button>


<div class="deseo-context-menu" id="deseo-context-menu" role="menu" aria-label="Deseo Radio quick menu" hidden>
    <div class="deseo-context-menu-head">
        <img src="/assets/img/favicon.png" alt="" aria-hidden="true">
        <div>
            <strong>DESEO RADIO</strong>
            <small>Quick access</small>
        </div>
    </div>

    <div class="deseo-context-menu-links">
        <a href="https://iluma.gr/radios/"
           target="_blank"
           rel="noopener noreferrer"
           role="menuitem"
           data-analytics-event="context_advertising_click">
            <span>
                <small>FOR BRANDS</small>
                <strong>Διαφήμιση</strong>
            </span>
            <i aria-hidden="true">↗</i>
        </a>

        <a href="https://play.iradios.gr/station/deseo-radio"
           target="_blank"
           rel="noopener noreferrer"
           role="menuitem"
           data-analytics-event="context_iradios_click">
            <span>
                <small>LIVE RADIO</small>
                <strong>Άκου στο iRadios</strong>
            </span>
            <i aria-hidden="true">↗</i>
        </a>

        <a href="https://iluma.gr/contact/"
           target="_blank"
           rel="noopener noreferrer"
           role="menuitem"
           data-analytics-event="context_contact_click">
            <span>
                <small>ILUMA DIGITAL AGENCY</small>
                <strong>Επικοινωνία</strong>
            </span>
            <i aria-hidden="true">↗</i>
        </a>
    </div>

    <div class="deseo-context-menu-foot">
        <span>DESEO · ATHENS</span>
        <span>24/7</span>
    </div>
</div>

<?php include_once __DIR__ . '/cookiebanner.php'; ?>

<?php
$clientKeys = [
    'skip.content',
    'header.home','header.live','header.live_aria','header.instagram','header.facebook',
    'hero.kicker','hero.title','hero.text','hero.listen','hero.network','hero.now_on_air','hero.non_stop','hero.live_broadcast',
    'airplay.kicker','airplay.title','airplay.text','airplay.empty','airplay.spotify',
    'program.kicker','program.title','program.text','program.on_air','program.live_set','program.empty','program.next',
    'playlists.kicker','playlists.title','playlists.text','playlists.open','playlists.empty',
    'about.kicker','about.title','about.text','about.iluma',
    'partners.kicker','partners.title','partners.text',
    'faq.kicker','faq.title','faq.text',
    'faq.q1','faq.a1','faq.q2','faq.a2','faq.q3','faq.a3','faq.q4','faq.a4','faq.q5','faq.a5',
    'advertise.kicker','advertise.title','advertise.text','advertise.cta',
    'footer.tagline','footer.contact','footer.follow','footer.listen','footer.live_player','footer.today_program','footer.playlists','footer.faq','footer.powered',
    'install.title','install.subtitle',
    'cookie.title','cookie.text','cookie.analytics','cookie.marketing','cookie.accept','cookie.customize','cookie.reject',
    'day.1','day.2','day.3','day.4','day.5','day.6','day.7'
];

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

    function maybeShowInstallPrompt() {
        if (!installButton || !installEvent) return;

        var cookieBanner = document.getElementById('cookie-banner');
        var cookieVisible = cookieBanner
            && !cookieBanner.hidden
            && cookieBanner.classList.contains('is-visible');

        installButton.hidden = cookieVisible;
    }

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
                for (var j = 0; j < entries.length; j++) {
                    if (entries[j].isIntersecting) {
                        entries[j].classList.add('is-visible');
                        observer.unobserve(entries[j]);
                    }
                }
            }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });

            for (i = 0; i < revealItems.length; i++) observer.observe(revealItems[i]);
        } else {
            for (i = 0; i < revealItems.length; i++) revealItems[i].classList.add('is-visible');
        }

        bindLanguageSwitcher();
        bindAnalytics();

        var cookieBanner = document.getElementById('cookie-banner');
        if (cookieBanner && 'MutationObserver' in window) {
            new MutationObserver(function () {
                maybeShowInstallPrompt();
            }).observe(cookieBanner, {
                attributes: true,
                attributeFilter: ['class', 'hidden']
            });
        }
        maybeShowInstallPrompt();
    }

    function applyLanguage(language) {
        var catalog = window.DESEO_TRANSLATIONS && window.DESEO_TRANSLATIONS[language];
        if (!catalog) return;

        var nodes = document.querySelectorAll('[data-i18n]');
        var i;

        for (i = 0; i < nodes.length; i++) {
            var key = nodes[i].getAttribute('data-i18n');
            if (Object.prototype.hasOwnProperty.call(catalog, key)) nodes[i].textContent = catalog[key];
        }

        var ariaNodes = document.querySelectorAll('[data-i18n-aria]');
        for (i = 0; i < ariaNodes.length; i++) {
            var ariaKey = ariaNodes[i].getAttribute('data-i18n-aria');
            if (Object.prototype.hasOwnProperty.call(catalog, ariaKey)) ariaNodes[i].setAttribute('aria-label', catalog[ariaKey]);
        }

        document.documentElement.lang = language;

        var options = document.querySelectorAll('[data-lang-switch]');
        for (i = 0; i < options.length; i++) {
            var active = options[i].getAttribute('data-lang-switch') === language;
            options[i].classList.toggle('active', active);
            options[i].setAttribute('aria-current', active ? 'true' : 'false');
        }

        try {
            document.cookie = 'deseo_lang=' + language + '; Max-Age=31536000; Path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
        } catch (e) {}

        if (window.DESEO_LANGUAGE !== language && window.DeseoAnalytics) {
            window.DeseoAnalytics.event('language_change', { language: language });
        }

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

    function bindAnalytics() {
        document.addEventListener('click', function (event) {
            var target = event.target;
            while (target && target !== document && target.tagName !== 'A') target = target.parentNode;
            if (!target || target.tagName !== 'A' || !window.DeseoAnalytics) return;

            var eventName = target.getAttribute('data-analytics-event');
            if (!eventName) return;

            window.DeseoAnalytics.event(eventName, {
                link_url: target.href || '',
                link_text: (target.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 100),
                language: document.documentElement.lang || 'el'
            });
        });

        var radioFrame = document.querySelector('iframe[src*="play.iradios.gr/widget/deseo-radio"]');
        if (radioFrame) {
            radioFrame.addEventListener('load', function () {
                if (window.DeseoAnalytics) {
                    window.DeseoAnalytics.event('radio_player_loaded', { player_provider: 'iradios' });
                }
            });
        }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', onReady);
    else onReady();

    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        installEvent = event;
        maybeShowInstallPrompt();
    });

    if (installButton) {
        installButton.addEventListener('click', function () {
            if (!installEvent) return;
            installEvent.prompt();
            installEvent.userChoice.then(function () {
                installEvent = null;
                installButton.hidden = true;
            });
        });
    }

    window.addEventListener('appinstalled', function () {
        installEvent = null;
        if (installButton) installButton.hidden = true;
    });

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('/sw.js?v=' + encodeURIComponent(window.DESEO_ASSET_VERSION || '1')).catch(function () {});
        });
    }
}());
</script>

<script>
(function () {
    'use strict';

    var menu = document.getElementById('deseo-context-menu');
    if (!menu) return;

    var closeTimer = null;

    function openMenu(x, y) {
        if (closeTimer) {
            window.clearTimeout(closeTimer);
            closeTimer = null;
        }

        menu.hidden = false;
        menu.classList.remove('is-open');

        var margin = 12;
        var rect = menu.getBoundingClientRect();
        var left = Math.min(Math.max(margin, x), Math.max(margin, window.innerWidth - rect.width - margin));
        var top = Math.min(Math.max(margin, y), Math.max(margin, window.innerHeight - rect.height - margin));

        menu.style.left = left + 'px';
        menu.style.top = top + 'px';

        window.requestAnimationFrame(function () {
            menu.classList.add('is-open');
            var first = menu.querySelector('a');
            if (first) first.focus({ preventScroll: true });
        });
    }

    function closeMenu(immediate) {
        menu.classList.remove('is-open');

        if (closeTimer) window.clearTimeout(closeTimer);

        if (immediate) {
            menu.hidden = true;
            return;
        }

        closeTimer = window.setTimeout(function () {
            menu.hidden = true;
            closeTimer = null;
        }, 140);
    }

    document.addEventListener('contextmenu', function (event) {
        event.preventDefault();
        openMenu(event.clientX, event.clientY);
    }, true);

    document.addEventListener('pointerdown', function (event) {
        if (!menu.hidden && !menu.contains(event.target)) closeMenu(false);
    }, true);

    document.addEventListener('keydown', function (event) {
        var key = String(event.key || '').toLowerCase();
        var ctrlOrMeta = event.ctrlKey || event.metaKey;
        var devShortcut =
            event.key === 'F12' ||
            (ctrlOrMeta && event.shiftKey && ['i', 'j', 'c', 'k'].indexOf(key) !== -1) ||
            (ctrlOrMeta && ['u', 's'].indexOf(key) !== -1);

        if (devShortcut) {
            event.preventDefault();
            event.stopPropagation();
            return false;
        }

        if (event.key === 'Escape' && !menu.hidden) {
            event.preventDefault();
            closeMenu(false);
        }
    }, true);

    menu.addEventListener('click', function (event) {
        var target = event.target;
        while (target && target !== menu && target.tagName !== 'A') target = target.parentNode;
        if (target && target.tagName === 'A') closeMenu(true);
    });

    window.addEventListener('blur', function () { closeMenu(true); });
    window.addEventListener('resize', function () { closeMenu(true); });
    window.addEventListener('scroll', function () { closeMenu(true); }, { passive: true });
}());
</script>

</body>
</html>
