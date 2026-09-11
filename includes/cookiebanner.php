<script>
(function () {
    'use strict';

    var STORAGE_KEY = 'deseoCookieConsentV2';

    function safeGet() {
        try { return window.localStorage.getItem(STORAGE_KEY); } catch (e) { return null; }
    }
    function safeSet(value) {
        try { window.localStorage.setItem(STORAGE_KEY, value); } catch (e) {}
    }
    function gtag() {
        window.dataLayer = window.dataLayer || [];
        window.dataLayer.push(arguments);
    }
    function loadWebpushr() {
        if (window.__deseoWebpushrLoaded) return;
        window.__deseoWebpushrLoaded = true;

        window.webpushr = window.webpushr || function () {
            (window.webpushr.q = window.webpushr.q || []).push(arguments);
        };

        var js = document.createElement('script');
        js.id = 'webpushr-jssdk';
        js.async = true;
        js.src = 'https://cdn.webpushr.com/app.min.js';
        js.onerror = function () { window.__deseoWebpushrLoaded = false; };
        (document.head || document.documentElement).appendChild(js);

        window.webpushr('setup', {
            key: 'BKgQeRKClX2ZYF6gJkeWih74UwVtgQ0F22w6ARHnINyalkH8KVKUFoGicN0aUEZAIsCc3cghGj3x3Daw85_cw8U'
        });
    }

    var raw = safeGet();
    var consent = null;
    if (raw) {
        try { consent = JSON.parse(raw); } catch (e) { consent = null; }
    }

    var defaults = consent || {
        analytics_storage: 'denied',
        ad_storage: 'denied',
        ad_user_data: 'denied',
        ad_personalization: 'denied'
    };

    gtag('consent', 'default', defaults);

    window.DeseoConsent = {
        current: defaults,
        save: function (analytics, marketing) {
            var next = {
                analytics_storage: analytics ? 'granted' : 'denied',
                ad_storage: marketing ? 'granted' : 'denied',
                ad_user_data: marketing ? 'granted' : 'denied',
                ad_personalization: marketing ? 'granted' : 'denied'
            };
            this.current = next;
            safeSet(JSON.stringify(next));
            gtag('consent', 'update', next);
            if (analytics) loadWebpushr();
        },
        loadWebpushr: loadWebpushr
    };

    if (consent && consent.analytics_storage === 'granted') {
        loadWebpushr();
    }
}());
</script>

<div class="cookie-banner" id="cookie-banner" role="dialog" aria-modal="false" aria-labelledby="cookie-title" hidden>
    <div class="cookie-icon" aria-hidden="true">◌</div>
    <div class="cookie-content">
        <h2 id="cookie-title" data-i18n="cookie.title"><?= deseo_e(deseo_t('cookie.title')) ?></h2>
        <p data-i18n="cookie.text"><?= deseo_e(deseo_t('cookie.text')) ?></p>

        <div class="cookie-options" id="cookie-options" hidden>
            <label><span data-i18n="cookie.analytics"><?= deseo_e(deseo_t('cookie.analytics')) ?></span><input type="checkbox" id="consent-analytics"></label>
            <label><span data-i18n="cookie.marketing"><?= deseo_e(deseo_t('cookie.marketing')) ?></span><input type="checkbox" id="consent-marketing"></label>
        </div>

        <div class="cookie-actions">
            <button class="button button-primary" type="button" id="cookie-accept" data-i18n="cookie.accept"><?= deseo_e(deseo_t('cookie.accept')) ?></button>
            <button class="button button-ghost" type="button" id="cookie-customize" data-i18n="cookie.customize"><?= deseo_e(deseo_t('cookie.customize')) ?></button>
            <button class="text-button" type="button" id="cookie-reject" data-i18n="cookie.reject"><?= deseo_e(deseo_t('cookie.reject')) ?></button>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
        else fn();
    }

    ready(function () {
        var banner = document.getElementById('cookie-banner');
        var options = document.getElementById('cookie-options');
        var analytics = document.getElementById('consent-analytics');
        var marketing = document.getElementById('consent-marketing');
        var accept = document.getElementById('cookie-accept');
        var reject = document.getElementById('cookie-reject');
        var customize = document.getElementById('cookie-customize');

        var hasChoice = false;
        try { hasChoice = !!window.localStorage.getItem('deseoCookieConsentV2'); } catch (e) {}

        if (!hasChoice) {
            banner.hidden = false;
            window.setTimeout(function () { banner.className += ' is-visible'; }, 200);
        }

        function closeBanner() {
            banner.className = banner.className.replace(' is-visible', '');
            window.setTimeout(function () { banner.hidden = true; }, 260);
        }

        customize.addEventListener('click', function () {
            options.hidden = !options.hidden;
        });

        accept.addEventListener('click', function () {
            if (options.hidden) window.DeseoConsent.save(true, true);
            else window.DeseoConsent.save(analytics.checked, marketing.checked);
            closeBanner();
        });

        reject.addEventListener('click', function () {
            window.DeseoConsent.save(false, false);
            closeBanner();
        });
    });
}());
</script>