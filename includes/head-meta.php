<?php
require_once __DIR__ . '/i18n.php';

$meta_title = $meta_title ?? "Deseo Radio | Το Soundtrack της ζωής σου! | House Music";
$meta_desc = $meta_desc ?? "Άκου live το δεσεο radio. Το κορυφαίο ραδιόφωνο για House music, Afro House και Organic Tech. Ζωντανά από το Αιγάλεω σε όλο τον κόσμο.";
$meta_keywords = $meta_keywords ?? "ραδιόφωνο, δεσεο, deseo, radio, house music";

$assetVersion = is_file(__DIR__ . '/../assets/css/style.css')
    ? (int) filemtime(__DIR__ . '/../assets/css/style.css')
    : 1;

if (!headers_sent()) {
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}
?>
<!DOCTYPE html>
<html lang="<?= deseo_e(deseo_lang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?php echo deseo_e($meta_title); ?></title>
    <meta name="description" content="<?php echo deseo_e($meta_desc); ?>">
    <meta name="keywords" content="<?php echo deseo_e($meta_keywords); ?>">

    <link rel="icon" type="image/png" href="/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="/assets/img/favicon.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Google+Sans:400,500,700&display=swap">

    <link rel="manifest" href="/manifest.json">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Deseo Radio">

    <link rel="stylesheet" href="/assets/css/style.css?v=<?= $assetVersion ?>">

    <!-- Google Analytics 4 — consent-aware -->
    <script>
    window.dataLayer = window.dataLayer || [];
    window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };
    window.DESEO_GA_MEASUREMENT_ID = 'G-5TYWQ2E64K';
    window.DESEO_GA_STREAM_ID = '13665767557';

    window.gtag('consent', 'default', {
        analytics_storage: 'denied',
        ad_storage: 'denied',
        ad_user_data: 'denied',
        ad_personalization: 'denied',
        wait_for_update: 500
    });

    window.DeseoAnalytics = {
        loaded: false,
        pageviewSent: false,

        load: function () {
            if (this.loaded) {
                this.sendPageView();
                return;
            }

            this.loaded = true;

            var script = document.createElement('script');
            script.async = true;
            script.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(window.DESEO_GA_MEASUREMENT_ID);
            script.onerror = function () {
                window.DeseoAnalytics.loaded = false;
            };
            document.head.appendChild(script);

            window.gtag('js', new Date());
            window.gtag('config', window.DESEO_GA_MEASUREMENT_ID, {
                send_page_view: false
            });

            this.sendPageView();
        },

        sendPageView: function () {
            if (!this.loaded || this.pageviewSent) return;

            window.gtag('event', 'page_view', {
                page_title: document.title,
                page_location: window.location.href,
                language: document.documentElement.lang || 'el'
            });

            this.pageviewSent = true;
        },

        event: function (name, params) {
            if (!this.loaded || !name) return;
            window.gtag('event', name, params || {});
        }
    };
    </script>

    <script type="application/ld+json">
    [
        {
            "@context": "https://schema.org",
            "@type": "RadioStation",
            "name": "Deseo Radio",
            "url": "https://deseoradio.com",
            "image": "https://deseoradio.com/assets/img/favicon.png",
            "description": "Το κορυφαίο ραδιόφωνο για house music, deep house και organic tech."
        },
        {
            "@context": "https://schema.org",
            "@type": "LocalBusiness",
            "name": "Deseo Radio Broadcast Studio",
            "image": "https://deseoradio.com/assets/img/favicon.png",
            "telephone": "2103000825",
            "email": "radio@iluma.gr",
            "address": {
                "@type": "PostalAddress",
                "streetAddress": "1st Moschonision st.",
                "addressLocality": "Egaleo",
                "postalCode": "12242",
                "addressCountry": "GR"
            }
        }
    ]
    </script>
</head>
<body>
<a class="skip-link" href="#main-content" data-i18n="skip.content"><?= deseo_e(deseo_t('skip.content')) ?></a>