<footer class="site-footer">
    <div class="shell footer-grid">
        <div class="footer-brand">
            <img src="/assets/img/deseoradio-logo.png" alt="Deseo Radio" width="220" height="62">
            <p>The soundtrack of your life.<br>House music, live from Athens.</p>
        </div>

        <div>
            <span class="footer-label">Contact</span>
            <a href="tel:+302103000825">+30 210 300 0825</a>
            <a href="mailto:radio@iluma.gr">radio@iluma.gr</a>
            <p>1st Moschonision st.<br>Egaleo, 12242 GR</p>
        </div>

        <div>
            <span class="footer-label">Follow</span>
            <a href="https://www.instagram.com/deseoradio/" target="_blank" rel="noopener noreferrer">Instagram ↗</a>
            <a href="https://www.facebook.com/deseoradiogr/" target="_blank" rel="noopener noreferrer">Facebook ↗</a>
            <a href="https://iluma.gr/radios/deseo" target="_blank" rel="noopener noreferrer">ILUMA Radios ↗</a>
        </div>

        <div>
            <span class="footer-label">Listen</span>
            <a href="#live">Live player</a>
            <a href="#program">Today's program</a>
            <a href="#airplay">Weekly airplay</a>
        </div>
    </div>

    <div class="shell footer-bottom">
        <span>© <?= date('Y') ?> Deseo Radio</span>
        <span>Powered by <a href="https://iluma.gr/" target="_blank" rel="noopener noreferrer">ILUMA Digital Agency</a></span>
    </div>
</footer>

<button class="install-prompt" id="pwa-install-prompt" type="button" hidden>
    <span>Install Deseo App</span>
    <small>Faster access · Home screen</small>
</button>

<?php include_once __DIR__ . '/cookiebanner.php'; ?>

<script>
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

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', onReady);
    else onReady();

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
        window.addEventListener('load', function () {
            var reloading = false;
            navigator.serviceWorker.addEventListener('controllerchange', function () {
                if (reloading) return;
                reloading = true;
                window.location.reload();
            });

            navigator.serviceWorker.register('/sw.js?v=<?= $assetVersion ?>', { updateViaCache: 'none' })
                .then(function (registration) {
                    registration.update();
                    window.setInterval(function () { registration.update(); }, 3600000);
                })
                .catch(function () {
                    // The website remains fully functional without PWA support.
                });
        });
    }
}());
</script>
</body>
</html>