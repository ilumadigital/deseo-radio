<?php
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/i18n.php';

$meta_title = $meta_title ?? "Deseo Radio | Το Soundtrack της ζωής σου! | House Music";
$meta_desc = $meta_desc ?? "Άκου live το δεσεο radio. Το κορυφαίο ραδιόφωνο για House music, Afro House και Organic Tech. Ζωντανά από το Αιγάλεω σε όλο τον κόσμο.";
$meta_keywords = $meta_keywords ?? "deseo radio, ραδιόφωνο, δεσεο, deseo, radio, house music, afro house, fly104, best radio, radio must, house radio, online radio, internet radio, live radio, web radio, greek radio, athens radio, electronic radio, electronic music, deep house, organic house, tech house, melodic house, dance radio, dj radio, house music radio, afro house radio, 24/7 radio, radio streaming, live streaming radio, music radio";
$meta_canonical = $meta_canonical ?? 'https://deseoradio.com/';
$meta_robots = $meta_robots ?? 'index,follow,max-image-preview:large';
$meta_image_path = __DIR__ . '/../assets/img/deseoradio-seo-branded.png';
$meta_image_version = is_file($meta_image_path) ? (int)filemtime($meta_image_path) : 1;
$meta_image = $meta_image ?? ('https://deseoradio.com/assets/img/deseoradio-seo-branded.png?v=' . $meta_image_version);
$meta_image_alt = $meta_image_alt ?? 'Deseo Radio — Το Soundtrack της ζωής σου';
$private_page = !empty($private_page);
$extra_styles = isset($extra_styles) && is_array($extra_styles) ? $extra_styles : [];
$cloudflareAnalyticsToken = trim((string)(getenv('CLOUDFLARE_WEB_ANALYTICS_TOKEN') ?: ''));

$assetVersion = 1;
foreach ([
    __DIR__ . '/../assets/css/style.css',
    __DIR__ . '/../manifest.json',
    __DIR__ . '/../sw.js',
    __DIR__ . '/../assets/img/bg.png',
    __DIR__ . '/../assets/img/deseoradio-seo-branded.png',
] as $assetFile) {
    if (is_file($assetFile)) $assetVersion = max($assetVersion, (int)filemtime($assetFile));
}
foreach ($extra_styles as $extraStyle) {
    $extraPath = __DIR__ . '/..' . '/' . ltrim((string)$extraStyle, '/');
    if (is_file($extraPath)) $assetVersion = max($assetVersion, (int)filemtime($extraPath));
}

if (!headers_sent()) {
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    if ($private_page) {
        header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex', true);
    }
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
            'image' => [
                '@id' => 'https://deseoradio.com/#primaryimage',
            ],
            'logo' => 'https://deseoradio.com/assets/img/favicon.png',
            'description' => 'Το κορυφαίο ραδιόφωνο για house music, deep house και organic tech.',
            'genre' => ['House', 'Afro House', 'Organic House', 'Electronic music'],
            'areaServed' => 'Worldwide',
            'sameAs' => [
                'https://iluma.gr/radios',
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
            'image' => ['@id' => 'https://deseoradio.com/#primaryimage'],
            'inLanguage' => deseo_lang() === 'en' ? 'en' : 'el',
        ],
        [
            '@type' => 'ImageObject',
            '@id' => 'https://deseoradio.com/#primaryimage',
            'url' => $meta_image,
            'contentUrl' => $meta_image,
            'caption' => $meta_image_alt,
            'inLanguage' => deseo_lang() === 'en' ? 'en' : 'el',
        ],
        [
            '@type' => 'WebPage',
            '@id' => $meta_canonical . '#webpage',
            'url' => $meta_canonical,
            'name' => $meta_title,
            'description' => $meta_desc,
            'isPartOf' => ['@id' => 'https://deseoradio.com/#website'],
            'primaryImageOfPage' => ['@id' => 'https://deseoradio.com/#primaryimage'],
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
    <meta name="robots" content="<?= deseo_e($meta_robots) ?>">
    <?php if ($private_page): ?>
        <meta name="googlebot" content="noindex,nofollow,noarchive,nosnippet,noimageindex">
        <meta name="googlebot-news" content="noindex,nofollow,noarchive,nosnippet">
    <?php endif; ?>

    <link rel="canonical" href="<?= deseo_e($meta_canonical) ?>">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Deseo Radio">
    <meta property="og:title" content="<?= deseo_e($meta_title) ?>">
    <meta property="og:description" content="<?= deseo_e($meta_desc) ?>">
    <meta property="og:url" content="<?= deseo_e($meta_canonical) ?>">
    <meta property="og:image" content="<?= deseo_e($meta_image) ?>">
    <meta property="og:image:secure_url" content="<?= deseo_e($meta_image) ?>">
    <meta property="og:image:type" content="image/png">
    <meta property="og:image:alt" content="<?= deseo_e($meta_image_alt) ?>">
    <meta property="og:locale" content="<?= deseo_lang() === 'en' ? 'en_US' : 'el_GR' ?>">
    <meta property="og:locale:alternate" content="<?= deseo_lang() === 'en' ? 'el_GR' : 'en_US' ?>">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= deseo_e($meta_title) ?>">
    <meta name="twitter:description" content="<?= deseo_e($meta_desc) ?>">
    <meta name="twitter:image" content="<?= deseo_e($meta_image) ?>">
    <meta name="twitter:image:alt" content="<?= deseo_e($meta_image_alt) ?>">

    <link rel="alternate" hreflang="el" href="<?= deseo_e($meta_canonical) ?>">
    <link rel="alternate" hreflang="en" href="<?= deseo_e($meta_canonical . (str_contains($meta_canonical, '?') ? '&' : '?') . 'lang=en') ?>">
    <link rel="alternate" hreflang="x-default" href="<?= deseo_e($meta_canonical) ?>">

    <link rel="icon" type="image/png" href="/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="/assets/img/favicon.png">

    <?php if ($cloudflareAnalyticsToken !== ''): ?>
    <!-- Cloudflare Web Analytics -->
    <script type="module"
            src="https://static.cloudflareinsights.com/beacon.min.js"
            data-cf-beacon='<?= htmlspecialchars(json_encode(['token' => $cloudflareAnalyticsToken], JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>'></script>
    <!-- End Cloudflare Web Analytics -->
    <?php endif; ?>

    <?php if (!$private_page): ?>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="preconnect" href="https://play.iradios.gr">
        <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Google+Sans:400,500,700&display=swap">
    <?php endif; ?>

    <link rel="manifest" href="/manifest.json?v=<?= $assetVersion ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Deseo Radio">

    <link rel="preload" href="/assets/img/bg.png?v=<?= $assetVersion ?>" as="image">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= $assetVersion ?>">
    <?php foreach ($extra_styles as $extraStyle): ?>
        <link rel="stylesheet" href="<?= deseo_e((string)$extraStyle) ?>?v=<?= $assetVersion ?>">
    <?php endforeach; ?>

    <script>
    document.documentElement.className = document.documentElement.className.replace('no-js', 'js');
    window.DESEO_ASSET_VERSION = <?= json_encode((string)$assetVersion) ?>;
    </script>

    <?php if (!$private_page): ?>
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
    <?php else: ?>
    <script>
    window.DeseoAnalytics = { loaded: false, pageviewSent: false, load: function(){}, sendPageView: function(){}, event: function(){} };
    </script>
    <?php endif; ?>
</head>
<body>
<a class="skip-link" href="#main-content" data-i18n="skip.content"><?= deseo_e(deseo_t('skip.content')) ?></a>
