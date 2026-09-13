<?php
require_once __DIR__ . '/i18n.php';

$meta_title = $meta_title ?? "Deseo Radio | Το Soundtrack της ζωής σου! | House Music";
$meta_desc = $meta_desc ?? "Άκου live το δεσεο radio. Το κορυφαίο ραδιόφωνο για House music, Afro House και Organic Tech. Ζωντανά από το Αιγάλεω σε όλο τον κόσμο.";
$meta_keywords = $meta_keywords ?? "ραδιόφωνο, δεσεο, deseo, radio, house music";

$assetVersion = 1;
foreach ([
    __DIR__ . '/../assets/css/style.css',
    __DIR__ . '/../manifest.json',
    __DIR__ . '/../sw.js',
    __DIR__ . '/../assets/img/bg.png',
] as $assetFile) {
    if (is_file($assetFile)) $assetVersion = max($assetVersion, (int)filemtime($assetFile));
}

if (!headers_sent()) {
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

$faqSchema = [];
for ($i = 1; $i <= 5; $i++) {
    $faqSchema[] = [
        '@type' => 'Question',
        'name' => deseo_t('faq.q' . $i),
        'acceptedAnswer' => [
            '@type' => 'Answer',
            'text' => deseo_t('faq.a' . $i),
        ],
    ];
}

$schema = [
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'RadioStation',
            '@id' => 'https://deseoradio.com/#radio',
            'name' => 'Deseo Radio',
            'url' => 'https://deseoradio.com/',
            'image' => 'https://deseoradio.com/assets/img/favicon.png',
            'description' => 'Το κορυφαίο ραδιόφωνο για house music, deep house και organic tech.',
            'genre' => ['House', 'Afro House', 'Organic House', 'Electronic music'],
            'areaServed' => 'Worldwide',
            'sameAs' => [
                'https://iluma.gr/radios/deseo',
                'https://www.instagram.com/deseoradio/',
                'https://www.facebook.com/deseoradiogr/',
                'https://play.iradios.gr/station/deseo-radio',
            ],
            'parentOrganization' => [
                '@type' => 'Organization',
                '@id' => 'https://iluma.gr/#organization',
                'name' => 'ILUMA Digital Agency',
                'url' => 'https://iluma.gr/',
            ],
        ],
        [
            '@type' => 'WebSite',
            '@id' => 'https://deseoradio.com/#website',
            'url' => 'https://deseoradio.com/',
            'name' => 'Deseo Radio',
            'publisher' => ['@id' => 'https://deseoradio.com/#radio'],
            'inLanguage' => deseo_lang() === 'en' ? 'en' : 'el',
        ],
        [
            '@type' => 'FAQPage',
            '@id' => 'https://deseoradio.com/#faq',
            'mainEntity' => $faqSchema,
        ],
    ],
];
?>
<!DOCTYPE html>
<html lang="<?= deseo_e(deseo_lang()) ?>" class="no-js">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#080808">
    <meta name="color-scheme" content="dark">

    <title><?= deseo_e($meta_title) ?></title>
    <meta name="description" content="<?= deseo_e($meta_desc) ?>">
    <meta name="keywords" content="<?= deseo_e($meta_keywords) ?>">
    <meta name="robots" content="index,follow,max-image-preview:large">

    <link rel="canonical" href="https://deseoradio.com/">
    <link rel="alternate" hreflang="el" href="https://deseoradio.com/">
    <link rel="alternate" hreflang="en" href="https://deseoradio.com/?lang=en">
    <link rel="alternate" hreflang="x-default" href="https://deseoradio.com/">

    <link rel="icon" type="image/png" href="/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="/assets/img/favicon.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://play.iradios.gr">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Google+Sans:400,500,700&display=swap">

    <link rel="manifest" href="/manifest.json?v=<?= $assetVersion ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Deseo Radio">

    <link rel="preload" href="/assets/img/bg.png?v=<?= $assetVersion ?>" as="image">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= $assetVersion ?>">

    <script>
    document.documentElement.className = document.documentElement.className.replace('no-js', 'js');
    window.DESEO_ASSET_VERSION = <?= json_encode((string)$assetVersion) ?>;
    </script>

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
            script.onerror = function () { window.DeseoAnalytics.loaded = false; };
            document.head.appendChild(script);

            window.gtag('js', new Date());
            window.gtag('config', window.DESEO_GA_MEASUREMENT_ID, { send_page_view: false });
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

    <script type="application/ld+json"><?= json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?></script>
</head>
<body>
<a class="skip-link" href="#main-content" data-i18n="skip.content"><?= deseo_e(deseo_t('skip.content')) ?></a>
