<footer class="site-footer">
    <div class="wide-shell footer-main">
        <div class="footer-brand">
            <img src="/assets/img/deseoradio-logo.png" alt="Deseo Radio" width="220" height="62">
            <p class="metal-title footer-tagline" data-i18n="footer.tagline"><?= deseo_e(deseo_t('footer.tagline')) ?></p>
            <div class="footer-brand-actions">
                <a class="footer-iluma" href="https://iluma.gr/radios" target="_blank" rel="noopener noreferrer" data-analytics-event="iluma_network_click">ILUMA RADIOS ↗</a>
                <a class="footer-dj-call" href="/dj" data-analytics-event="dj_call_footer_click">The DJs Call ↗</a>
            </div>
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
                <a href="/privacy">Privacy</a>
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
        <a href="https://iluma.gr/radios"
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


<div class="pwa-ios-guide" id="pwa-ios-guide" hidden role="dialog" aria-modal="true" aria-labelledby="pwa-ios-guide-title">
    <div class="pwa-ios-guide-card">
        <button class="pwa-ios-guide-close" id="pwa-ios-guide-close" type="button" aria-label="Close">×</button>

        <div class="pwa-ios-guide-icon">
            <img src="/assets/img/favicon.png" alt="">
        </div>

        <span class="pwa-ios-guide-kicker">DESEO RADIO · iPHONE / iPAD</span>
        <h2 id="pwa-ios-guide-title">Εγκατάσταση στη συσκευή</h2>

        <ol>
            <li><span>1</span><p>Πάτησε το <strong>Share</strong> στο Safari.</p></li>
            <li><span>2</span><p>Επίλεξε <strong>Add to Home Screen</strong>.</p></li>
            <li><span>3</span><p>Πάτησε <strong>Add</strong> και άνοιξε το Deseo από την αρχική οθόνη.</p></li>
        </ol>

        <button class="pwa-ios-guide-done" id="pwa-ios-guide-done" type="button">ΤΟ ΕΓΚΑΤΕΣΤΗΣΑ</button>
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
    'dj.hero.lead','dj.need.1','dj.need.2','dj.need.3','dj.need.note',
    'dj.info.title','dj.info.p1','dj.info.p2',
    'dj.field.full_name','dj.field.photo','dj.field.photo_note','dj.field.set_type',
    'dj.set.new','dj.set.previous','dj.set.exclusive',
    'dj.sample.title','dj.sample.note','dj.slot.note','dj.slot.available','dj.slot.closed',
    'dj.confirm.age','dj.confirm.rights','dj.confirm.ai',
    'dj.confirm.terms_prefix','dj.confirm.terms_link',
    'dj.confirm.privacy_prefix','dj.confirm.privacy_link','dj.confirm.privacy_suffix',
    'dj.security.closed','dj.submit.note',
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
    var iosGuide = document.getElementById('pwa-ios-guide');
    var iosGuideClose = document.getElementById('pwa-ios-guide-close');
    var iosGuideDone = document.getElementById('pwa-ios-guide-done');
    var PWA_INSTALLED_KEY = 'deseoPwaInstalledV2';
    var PWA_DISMISS_KEY = 'deseoPwaDismissedAt';
    var INSTALL_DISMISS_MS = 7 * 24 * 60 * 60 * 1000;
    var installMode = 'none';
    var installCheckComplete = false;

    function isStandalonePwa() {
        var displayStandalone = window.matchMedia
            && window.matchMedia('(display-mode: standalone)').matches;
        var iosStandalone = window.navigator.standalone === true;
        var androidAppReferrer = document.referrer
            && document.referrer.indexOf('android-app://') === 0;

        return !!(displayStandalone || iosStandalone || androidAppReferrer);
    }

    function isIosSafari() {
        var ua = window.navigator.userAgent || '';
        var isIos = /iPad|iPhone|iPod/.test(ua)
            || (window.navigator.platform === 'MacIntel' && window.navigator.maxTouchPoints > 1);
        var isWebkit = /WebKit/.test(ua);
        var isOtherIosBrowser = /CriOS|FxiOS|EdgiOS|OPiOS/.test(ua);

        return !!(isIos && isWebkit && !isOtherIosBrowser);
    }

    function safeGet(key) {
        try { return window.localStorage.getItem(key); } catch (e) { return null; }
    }

    function safeSet(key, value) {
        try { window.localStorage.setItem(key, value); } catch (e) {}
    }

    function safeRemove(key) {
        try { window.localStorage.removeItem(key); } catch (e) {}
    }

    function markPwaInstalled() {
        safeSet(PWA_INSTALLED_KEY, '1');
        safeRemove(PWA_DISMISS_KEY);
    }

    function clearStaleInstalledState() {
        safeRemove(PWA_INSTALLED_KEY);
    }

    function wasRecentlyDismissed() {
        var value = parseInt(safeGet(PWA_DISMISS_KEY) || '0', 10);
        return value > 0 && (Date.now() - value) < INSTALL_DISMISS_MS;
    }

    function markPromptDismissed() {
        safeSet(PWA_DISMISS_KEY, String(Date.now()));
    }

    function queryInstalledApps() {
        if (!window.navigator.getInstalledRelatedApps) {
            return Promise.resolve(false);
        }

        return window.navigator.getInstalledRelatedApps()
            .then(function (apps) {
                return Array.isArray(apps) && apps.some(function (app) {
                    return app.platform === 'webapp'
                        || app.id === '/'
                        || (app.url && app.url.indexOf('deseoradio.com') !== -1);
                });
            })
            .catch(function () {
                return false;
            });
    }

    function cookieBannerVisible() {
        var cookieBanner = document.getElementById('cookie-banner');
        return !!(cookieBanner
            && !cookieBanner.hidden
            && cookieBanner.classList.contains('is-visible'));
    }

    function hideInstallPrompt() {
        if (installButton) installButton.hidden = true;
    }

    function updateInstallPrompt() {
        if (!installButton || !installCheckComplete) return;

        if (isStandalonePwa() || safeGet(PWA_INSTALLED_KEY) === '1') {
            hideInstallPrompt();
            return;
        }

        if (cookieBannerVisible() || wasRecentlyDismissed()) {
            hideInstallPrompt();
            return;
        }

        if (installMode === 'native' && installEvent) {
            installButton.hidden = false;
            return;
        }

        if (installMode === 'ios') {
            installButton.hidden = false;
            return;
        }

        hideInstallPrompt();
    }

    function initialiseInstallState() {
        if (!installButton) return;

        hideInstallPrompt();

        if (isStandalonePwa()) {
            markPwaInstalled();
            installCheckComplete = true;
            return;
        }

        queryInstalledApps().then(function (installed) {
            if (installed) {
                markPwaInstalled();
                installMode = 'none';
            } else {
                if (isIosSafari()) {
                    installMode = 'ios';
                }
            }

            installCheckComplete = true;
            updateInstallPrompt();
        });
    }

    function openIosGuide() {
        if (!iosGuide) return;
        iosGuide.hidden = false;
        window.requestAnimationFrame(function () {
            iosGuide.classList.add('is-open');
        });
    }

    function closeIosGuide() {
        if (!iosGuide) return;
        iosGuide.classList.remove('is-open');
        window.setTimeout(function () {
            iosGuide.hidden = true;
        }, 180);
    }

    var programRefreshTimer = null;
    var programRefreshInFlight = false;
    var programRefreshHour = Math.floor(Date.now() / 3600000);

    var djProfileModal = document.getElementById('dj-profile-modal');
    var djProfilePhoto = document.getElementById('dj-profile-photo');
    var djProfileName = document.getElementById('dj-profile-name');
    var djProfileBio = document.getElementById('dj-profile-bio');
    var djProfileShow = document.getElementById('dj-profile-show');
    var djProfileTime = document.getElementById('dj-profile-time');
    var djProfileSocials = document.getElementById('dj-profile-socials');

    function programTranslation(key, fallback) {
        var language = window.DESEO_LANGUAGE || document.documentElement.lang || 'el';
        var catalog = window.DESEO_TRANSLATIONS && window.DESEO_TRANSLATIONS[language];
        return catalog && Object.prototype.hasOwnProperty.call(catalog, key) ? catalog[key] : fallback;
    }

    function programTime(value) {
        if (!value || typeof value !== 'string') return '--:--';
        return value.slice(0, 5);
    }

    function createProgramImage(src, alt, className, fallback) {
        var image = document.createElement('img');
        image.className = className;
        image.src = src || fallback;
        image.alt = alt || '';
        image.setAttribute('data-fallback', fallback);
        image.addEventListener('error', function () {
            if (this.src.indexOf(fallback) === -1) this.src = fallback;
        });
        return image;
    }

    function attachProgramProfile(element, show) {
        if (!element) return;

        element.classList.remove('has-dj-profile');
        element.removeAttribute('data-profile-open');
        element.removeAttribute('data-profile-json');
        element.removeAttribute('data-profile-photo');
        element.removeAttribute('data-profile-show');
        element.removeAttribute('data-profile-time');
        element.removeAttribute('role');
        element.removeAttribute('tabindex');

        if (!show || !show.profile) return;

        element.classList.add('has-dj-profile');
        element.setAttribute('data-profile-open', '');
        element.setAttribute('data-profile-json', JSON.stringify(show.profile));
        element.setAttribute('data-profile-photo', show.photo_path || '/assets/img/bg.png');
        element.setAttribute('data-profile-show', show.dj_name || 'Deseo Radio');
        element.setAttribute('data-profile-time', programTime(show.start_time) + ' — ' + programTime(show.end_time));
        element.setAttribute('role', 'button');
        element.setAttribute('tabindex', '0');
    }

    function safeProfileUrl(value) {
        if (!value || typeof value !== 'string') return '';
        return /^https?:\/\//i.test(value) ? value : '';
    }

    function closeDjProfile() {
        if (!djProfileModal) return;
        djProfileModal.classList.remove('is-open');
        document.body.classList.remove('dj-profile-modal-open');
        window.setTimeout(function () {
            djProfileModal.hidden = true;
        }, 180);
    }

    function openDjProfile(element) {
        if (!djProfileModal || !element) return;

        var profile = null;
        try {
            profile = JSON.parse(element.getAttribute('data-profile-json') || 'null');
        } catch (e) {}

        if (!profile) return;

        var photo = element.getAttribute('data-profile-photo') || '/assets/img/bg.png';
        var showName = element.getAttribute('data-profile-show') || 'Deseo Radio';
        var time = element.getAttribute('data-profile-time') || '';

        if (djProfilePhoto) {
            djProfilePhoto.src = photo;
            djProfilePhoto.alt = profile.artist_name || showName;
        }
        if (djProfileName) djProfileName.textContent = profile.artist_name || showName;
        if (djProfileBio) djProfileBio.textContent = profile.bio || '';
        if (djProfileShow) djProfileShow.textContent = showName;
        if (djProfileTime) djProfileTime.textContent = time;

        if (djProfileSocials) {
            djProfileSocials.textContent = '';

            [
                ['Instagram', profile.instagram],
                ['TikTok', profile.tiktok],
                ['SoundCloud', profile.soundcloud],
                ['Spotify', profile.spotify],
                ['Website', profile.website]
            ].forEach(function (item) {
                var url = safeProfileUrl(item[1]);
                if (!url) return;

                var link = document.createElement('a');
                link.href = url;
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                link.textContent = item[0] + ' ↗';
                djProfileSocials.appendChild(link);
            });
        }

        djProfileModal.hidden = false;
        document.body.classList.add('dj-profile-modal-open');
        window.requestAnimationFrame(function () {
            djProfileModal.classList.add('is-open');
        });

        if (window.DeseoAnalytics) {
            window.DeseoAnalytics.event('dj_profile_open', {
                artist_name: profile.artist_name || '',
                show_name: showName
            });
        }
    }

    function renderLiveProgram(live) {
        var deck = document.getElementById('live-program-deck');
        if (!deck) return;

        var pulse = deck.querySelector('.live-pulse');
        if (pulse) pulse.classList.toggle('is-muted', !live);

        var card = deck.querySelector('.hero-cms-card');
        if (!card) return;
        card.textContent = '';
        attachProgramProfile(card, live);

        var image;
        var overlay = document.createElement('div');
        overlay.className = 'hero-cms-overlay';

        if (live) {
            image = createProgramImage(live.photo_path, live.dj_name, '', '/assets/img/bg.png');

            var liveLabel = document.createElement('span');
            liveLabel.setAttribute('data-i18n', 'hero.live_broadcast');
            liveLabel.textContent = programTranslation('hero.live_broadcast', 'LIVE BROADCAST');

            var title = document.createElement('h2');
            title.textContent = live.dj_name || 'Deseo Radio';

            var time = document.createElement('p');
            time.textContent = programTime(live.start_time) + ' — ' + programTime(live.end_time);

            overlay.appendChild(liveLabel);
            overlay.appendChild(title);
            overlay.appendChild(time);
        } else {
            image = createProgramImage('/assets/img/bg.png', 'Deseo Radio Auto DJ', '', '/assets/img/bg.png');

            var nonStopLabel = document.createElement('span');
            nonStopLabel.textContent = 'NON-STOP MIX';

            var nonStopTitle = document.createElement('h2');
            nonStopTitle.setAttribute('data-i18n', 'hero.non_stop');
            nonStopTitle.textContent = programTranslation('hero.non_stop', 'NON-STOP');

            var allDay = document.createElement('p');
            allDay.textContent = '24/7';

            overlay.appendChild(nonStopLabel);
            overlay.appendChild(nonStopTitle);
            overlay.appendChild(allDay);
        }

        card.appendChild(image);
        card.appendChild(overlay);
    }

    function renderTodayProgram(today, nextShow) {
        var body = document.getElementById('program-panel-body');
        if (!body) return;
        body.textContent = '';

        if (!Array.isArray(today) || !today.length) {
            var empty = document.createElement('div');
            empty.className = 'deseo-panel-empty';
            empty.setAttribute('data-i18n', 'program.empty');
            empty.textContent = programTranslation('program.empty', 'No scheduled shows today.');
            body.appendChild(empty);
            return;
        }

        today.forEach(function (show) {
            var row = document.createElement('div');
            row.className = 'deseo-panel-row deseo-program-row' + (show.is_live ? ' is-live' : '');
            attachProgramProfile(row, show);

            row.appendChild(createProgramImage(show.photo_path, show.dj_name, 'deseo-row-cover', '/assets/img/bg.png'));

            var copy = document.createElement('span');
            copy.className = 'deseo-row-copy';

            var time = document.createElement('small');
            time.textContent = programTime(show.start_time) + ' — ' + programTime(show.end_time);

            var name = document.createElement('strong');
            name.textContent = show.dj_name || 'Deseo Radio';

            copy.appendChild(time);
            copy.appendChild(name);
            row.appendChild(copy);

            if (show.is_live) {
                var liveTag = document.createElement('span');
                liveTag.className = 'deseo-live-tag';
                liveTag.textContent = 'LIVE';
                row.appendChild(liveTag);
            } else if (show.profile) {
                var profileTag = document.createElement('span');
                profileTag.className = 'deseo-profile-tag';
                profileTag.textContent = 'PROFILE';
                row.appendChild(profileTag);
            }

            body.appendChild(row);
        });

        if (nextShow) {
            var nextPill = document.createElement('div');
            nextPill.className = 'deseo-next-pill';

            var nextLabel = document.createElement('span');
            nextLabel.setAttribute('data-i18n', 'program.next');
            nextLabel.textContent = programTranslation('program.next', 'Next:');

            var nextName = document.createElement('strong');
            nextName.textContent = nextShow.dj_name || 'Deseo Radio';

            var nextTime = document.createElement('small');
            nextTime.textContent = '· ' + programTime(nextShow.start_time);

            nextPill.appendChild(nextLabel);
            nextPill.appendChild(nextName);
            nextPill.appendChild(nextTime);
            body.appendChild(nextPill);
        }
    }

    function refreshProgramComponent() {
        if (programRefreshInFlight || !document.getElementById('program')) return Promise.resolve();

        programRefreshInFlight = true;

        return fetch('/?program_feed=1&_=' + Date.now(), {
            method: 'GET',
            cache: 'no-store',
            headers: { 'Accept': 'application/json' }
        })
            .then(function (response) {
                if (!response.ok) throw new Error('Program feed request failed');
                return response.json();
            })
            .then(function (payload) {
                if (!payload || !Array.isArray(payload.today)) return;
                renderLiveProgram(payload.live || null);
                renderTodayProgram(payload.today, payload.next || null);
                programRefreshHour = Math.floor(Date.now() / 3600000);
            })
            .catch(function () {
                // Keep the currently rendered schedule if the network is temporarily unavailable.
            })
            .finally(function () {
                programRefreshInFlight = false;
            });
    }

    function scheduleNextProgramRefresh() {
        if (programRefreshTimer) window.clearTimeout(programRefreshTimer);

        var hour = 60 * 60 * 1000;
        var delay = hour - (Date.now() % hour) + 1200;

        programRefreshTimer = window.setTimeout(function () {
            refreshProgramComponent().finally(scheduleNextProgramRefresh);
        }, delay);
    }

    function initProgramAutoRefresh() {
        if (!document.getElementById('program')) return;

        scheduleNextProgramRefresh();

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState !== 'visible') return;

            var currentHour = Math.floor(Date.now() / 3600000);
            if (currentHour !== programRefreshHour) {
                refreshProgramComponent().finally(scheduleNextProgramRefresh);
            }
        });

        window.addEventListener('pageshow', function (event) {
            if (event.persisted) {
                refreshProgramComponent().finally(scheduleNextProgramRefresh);
            }
        });
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

        document.addEventListener('click', function (event) {
            var opener = event.target && event.target.closest ? event.target.closest('[data-profile-open]') : null;
            if (opener) {
                if (event.target && event.target.closest && event.target.closest('a')) return;
                openDjProfile(opener);
            }
        });

        document.addEventListener('keydown', function (event) {
            if ((event.key === 'Enter' || event.key === ' ') && event.target && event.target.matches && event.target.matches('[data-profile-open]')) {
                event.preventDefault();
                openDjProfile(event.target);
                return;
            }

            if (event.key === 'Escape' && djProfileModal && !djProfileModal.hidden) {
                closeDjProfile();
            }
        });

        document.querySelectorAll('[data-dj-profile-close]').forEach(function (button) {
            button.addEventListener('click', closeDjProfile);
        });

        bindLanguageSwitcher();
        bindAnalytics();
        initProgramAutoRefresh();

        var cookieBanner = document.getElementById('cookie-banner');
        if (cookieBanner && 'MutationObserver' in window) {
            new MutationObserver(function () {
                updateInstallPrompt();
            }).observe(cookieBanner, {
                attributes: true,
                attributeFilter: ['class', 'hidden']
            });
        }

        initialiseInstallState();
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

        if (isStandalonePwa()) {
            markPwaInstalled();
            hideInstallPrompt();
            return;
        }

        // If Chromium fires this event, the browser considers the app installable
        // and not currently installed. Trust the browser and clear stale local state.
        clearStaleInstalledState();
        installEvent = event;
        installMode = 'native';
        installCheckComplete = true;
        updateInstallPrompt();
    });

    if (installButton) {
        installButton.addEventListener('click', function () {
            if (installMode === 'ios') {
                openIosGuide();
                return;
            }

            if (installMode !== 'native' || !installEvent) {
                hideInstallPrompt();
                return;
            }

            var promptEvent = installEvent;
            installEvent = null;
            hideInstallPrompt();

            promptEvent.prompt();
            promptEvent.userChoice.then(function (choice) {
                if (choice && choice.outcome === 'accepted') {
                    markPwaInstalled();
                    installMode = 'none';
                } else {
                    markPromptDismissed();
                    installMode = 'none';
                }

                updateInstallPrompt();
            }).catch(function () {
                installMode = 'none';
                updateInstallPrompt();
            });
        });
    }

    if (iosGuideClose) {
        iosGuideClose.addEventListener('click', function () {
            markPromptDismissed();
            closeIosGuide();
            updateInstallPrompt();
        });
    }

    if (iosGuideDone) {
        iosGuideDone.addEventListener('click', function () {
            markPwaInstalled();
            installMode = 'none';
            closeIosGuide();
            hideInstallPrompt();
        });
    }

    if (iosGuide) {
        iosGuide.addEventListener('click', function (event) {
            if (event.target === iosGuide) {
                markPromptDismissed();
                closeIosGuide();
                updateInstallPrompt();
            }
        });
    }

    window.addEventListener('appinstalled', function () {
        markPwaInstalled();
        installEvent = null;
        installMode = 'none';
        hideInstallPrompt();
        closeIosGuide();
    });

    if (window.matchMedia) {
        var standaloneMedia = window.matchMedia('(display-mode: standalone)');
        var onDisplayModeChange = function (event) {
            if (event.matches) {
                markPwaInstalled();
                installEvent = null;
                installMode = 'none';
                hideInstallPrompt();
                closeIosGuide();
            }
        };

        if (standaloneMedia.addEventListener) {
            standaloneMedia.addEventListener('change', onDisplayModeChange);
        } else if (standaloneMedia.addListener) {
            standaloneMedia.addListener(onDisplayModeChange);
        }
    }

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('/sw.js?v=' + encodeURIComponent(window.DESEO_ASSET_VERSION || '1')).catch(function () {});
        });
    }
}());
</script>

<script src="/assets/js/deseo-lockdown.js?v=<?= @filemtime(dirname(__DIR__) . '/assets/js/deseo-lockdown.js') ?: 1 ?>"></script>
<script src="/assets/js/deseo-dialogs.js?v=<?= @filemtime(dirname(__DIR__) . '/assets/js/deseo-dialogs.js') ?: 1 ?>"></script>

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

<script src="https://radios.iluma.gr/signal/v1/signal.js?v=1.1.1"
        data-station="deseo"
        data-surface="station_website"
        defer></script>
<script>
(function () {
    'use strict';

    /* MyLive email automation tick */
    function tickMyLiveEmailAutomation() {
        fetch('/mylive/email-tick.php', {
            method: 'GET',
            cache: 'no-store',
            credentials: 'omit',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).catch(function () {});
    }

    window.setTimeout(tickMyLiveEmailAutomation, 12000);
    window.setInterval(tickMyLiveEmailAutomation, 60000);
}());
</script>

</body>
</html>
