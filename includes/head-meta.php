<?php
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/i18n.php';

$meta_title = $meta_title ?? (deseo_lang() === 'en'
    ? 'Deseo Radio | The Soundtrack of your life · House & Electronic'
    : 'Deseo Radio | Το Soundtrack της ζωής σου · House & Electronic');
$meta_desc = $meta_desc ?? (deseo_lang() === 'en'
    ? 'Listen to Deseo Radio, the Athens-based 24/7 House, Afro House, Organic House, Indie Dance and Electronic Music station. Season 6 DJ Sets launch October 14, 2026, with Release Radar and Guest DJs.'
    : 'Το Deseo Radio εκπέμπει 24/7 από την Αθήνα με House, Afro House, Organic House, Indie Dance και Electronic Music. Season 6 DJ Sets από 14/10/2026, Release Radar και Guest DJs.');
$meta_keywords = $meta_keywords ?? "Deseo Radio, House, Afro House, Organic House, Indie Dance, Electronic Music, Athens online radio, digital radio, 24/7 music, Season 6, Resident DJ Sets, Guest DJs, Release Radar, radio streaming";
$meta_canonical = $meta_canonical ?? 'https://deseoradio.com/';
$meta_canonical_el = $meta_canonical;
$meta_canonical_en = $meta_canonical_el . (str_contains($meta_canonical_el, '?') ? '&' : '?') . 'lang=en';
if ($meta_canonical_el === 'https://deseoradio.com/' && deseo_lang() === 'en') {
    $meta_canonical = $meta_canonical_en;
}
$meta_robots = $meta_robots ?? 'index,follow,max-image-preview:large';
$meta_image_path = __DIR__ . '/../assets/img/deseoradio-seo-branded.png';
$meta_image_version = is_file($meta_image_path) ? (int)filemtime($meta_image_path) : 1;
$meta_image = $meta_image ?? ('https://deseoradio.com/assets/img/deseoradio-seo-branded.png?v=' . $meta_image_version);
$meta_image_alt = $meta_image_alt ?? 'Deseo Radio — Το Soundtrack της ζωής σου';
$faviconPath = __DIR__ . '/../assets/img/favicon.png';
$faviconHash = is_file($faviconPath) ? hash_file('sha256', $faviconPath) : false;
$faviconVersion = $faviconHash ? substr($faviconHash, 0, 16) : '20261010';
if (!isset($brandLogoUrl)) {
    $brandLogoPath = __DIR__ . '/../assets/img/deseoradio-logo.png';
    $brandLogoHash = is_file($brandLogoPath) ? hash_file('sha256', $brandLogoPath) : false;
    $brandLogoUrl = '/assets/img/deseoradio-logo.png?v=' . ($brandLogoHash ? substr($brandLogoHash, 0, 16) : 's6-20261010');
}
$private_page = !empty($private_page);
$deseo_home_redesign = !empty($deseo_home_redesign);
$extra_styles = isset($extra_styles) && is_array($extra_styles) ? $extra_styles : [];
$cloudflareAnalyticsToken = trim((string)(getenv('CLOUDFLARE_WEB_ANALYTICS_TOKEN') ?: ''));

$assetVersion = 1;
foreach ([
    __DIR__ . '/../assets/css/style.css',
    __DIR__ . '/../assets/css/home.css',
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
    header($deseo_home_redesign
        ? 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0'
        : 'Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    if ($private_page) {
        header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex', true);
    }
}

$faqSchema = [];
for ($i = 1; $i <= DESEO_PUBLIC_FAQ_COUNT; $i++) {
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
            'description' => deseo_lang() === 'en'
                ? 'Athens-based 24/7 digital radio featuring contemporary House, Afro House, Organic House, Indie Dance and selected Electronic Music, with curated programming, Resident and Guest DJ Sets and the weekly Release Radar.'
                : 'Digital radio από την Αθήνα, 24/7, με House, Afro House, Organic House, Indie Dance και επιλεγμένη Electronic Music. Μουσική επιμέλεια, Resident και Guest DJ Sets και εβδομαδιαίο Release Radar.',
            'genre' => ['House', 'Afro House', 'Organic House', 'Indie Dance', 'Electronic Music'],
            'slogan' => 'Το Soundtrack της ζωής σου',
            'areaServed' => 'Worldwide',
            'sameAs' => [
                'https://iluma.gr/radios',
                'https://www.instagram.com/deseoradio/',
                'https://www.facebook.com/deseoradiogr/',
                'https://www.mixcloud.com/deseoradio/',
                'https://open.spotify.com/show/2x8ceF2a3gMmzEJ8y6W1ue?si=_3btuPp0Qy-nHs950YFIpg',
                'https://podcasts.apple.com/us/podcast/deseo-radioshows/id1711008342',
                'https://hearthis.at/deseoradio/set/season-6/',
                'https://soundcloud.com/deseo-radio',
                'https://play.iradios.gr/station/deseo-radio',
            ],
            'parentOrganization' => [
                '@type' => 'Organization',
                '@id' => 'https://iluma.gr/#organization',
                'name' => 'ILUMA Digital Agency',
                'url' => 'https://iluma.gr/',
            ],
            'memberOf' => [
                '@type' => 'Organization',
                '@id' => 'https://iluma.gr/radios#network',
                'name' => 'ILUMA Radios',
                'url' => 'https://iluma.gr/radios',
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
            '@id' => $meta_canonical . '#faq',
            'url' => $meta_canonical . '#faq',
            'inLanguage' => deseo_lang() === 'en' ? 'en' : 'el',
            'mainEntity' => $faqSchema,
        ],
    ],
];

if (isset($program) && is_array($program)) {
$season6Lineup = [
    ['day' => 'Wednesday', 'schema_day' => 'https://schema.org/Wednesday', 'start' => '20:00:00', 'dj' => 'Katty Belle'],
    ['day' => 'Wednesday', 'schema_day' => 'https://schema.org/Wednesday', 'start' => '21:00:00', 'dj' => 'DemiX Music'],
    ['day' => 'Wednesday', 'schema_day' => 'https://schema.org/Wednesday', 'start' => '22:00:00', 'dj' => 'Harris Gabriel (GR)'],
    ['day' => 'Wednesday', 'schema_day' => 'https://schema.org/Wednesday', 'start' => '23:00:00', 'dj' => 'VGRENADE'],

    ['day' => 'Thursday', 'schema_day' => 'https://schema.org/Thursday', 'start' => '20:00:00', 'dj' => 'DJ pmelgidis'],
    ['day' => 'Thursday', 'schema_day' => 'https://schema.org/Thursday', 'start' => '21:00:00', 'dj' => 'Lena'],
    ['day' => 'Thursday', 'schema_day' => 'https://schema.org/Thursday', 'start' => '22:00:00', 'dj' => 'TWEEK UC'],
    ['day' => 'Thursday', 'schema_day' => 'https://schema.org/Thursday', 'start' => '23:00:00', 'dj' => 'DJ Kyriakos Gavakis'],

    ['day' => 'Friday', 'schema_day' => 'https://schema.org/Friday', 'start' => '18:00:00', 'dj' => 'Evripos F'],
    ['day' => 'Friday', 'schema_day' => 'https://schema.org/Friday', 'start' => '19:00:00', 'dj' => 'Greg Lef'],
    ['day' => 'Friday', 'schema_day' => 'https://schema.org/Friday', 'start' => '20:00:00', 'dj' => 'ANDØR'],
    ['day' => 'Friday', 'schema_day' => 'https://schema.org/Friday', 'start' => '21:00:00', 'dj' => 'Coup (GR)'],
    ['day' => 'Friday', 'schema_day' => 'https://schema.org/Friday', 'start' => '22:00:00', 'dj' => 'Johnny Mak'],
    ['day' => 'Friday', 'schema_day' => 'https://schema.org/Friday', 'start' => '23:00:00', 'dj' => 'Cobo B'],

    ['day' => 'Saturday', 'schema_day' => 'https://schema.org/Saturday', 'start' => '18:00:00', 'dj' => 'Valentino_S'],
    ['day' => 'Saturday', 'schema_day' => 'https://schema.org/Saturday', 'start' => '19:00:00', 'dj' => 'ANSS'],
    ['day' => 'Saturday', 'schema_day' => 'https://schema.org/Saturday', 'start' => '20:00:00', 'dj' => 'DeepK'],
    ['day' => 'Saturday', 'schema_day' => 'https://schema.org/Saturday', 'start' => '21:00:00', 'dj' => 'Oblivion'],
    ['day' => 'Saturday', 'schema_day' => 'https://schema.org/Saturday', 'start' => '22:00:00', 'dj' => 'Cross Mit'],
    ['day' => 'Saturday', 'schema_day' => 'https://schema.org/Saturday', 'start' => '23:00:00', 'dj' => 'Monorism'],

    ['day' => 'Sunday', 'schema_day' => 'https://schema.org/Sunday', 'start' => '18:00:00', 'dj' => 'Michael Poulidis'],
    ['day' => 'Sunday', 'schema_day' => 'https://schema.org/Sunday', 'start' => '19:00:00', 'dj' => 'Kremasia'],
    ['day' => 'Sunday', 'schema_day' => 'https://schema.org/Sunday', 'start' => '20:00:00', 'dj' => 'loco (GR)'],
    ['day' => 'Sunday', 'schema_day' => 'https://schema.org/Sunday', 'start' => '21:00:00', 'dj' => 'WHATABOUT'],
    ['day' => 'Sunday', 'schema_day' => 'https://schema.org/Sunday', 'start' => '22:00:00', 'dj' => 'g spice'],
    ['day' => 'Sunday', 'schema_day' => 'https://schema.org/Sunday', 'start' => '23:00:00', 'dj' => 'Rokhai'],
];

$season6SeriesId = 'https://deseoradio.com/#season-6-lineup';
$schema['@graph'][] = [
    '@type' => 'EventSeries',
    '@id' => $season6SeriesId,
    'name' => 'Deseo Radio Season 6 — Weekly DJ Sets',
    'description' => 'Season 6 weekly Resident DJ Sets begin on October 14, 2026 at 20:00 Athens time. The announced weekly lineup runs Wednesday through Sunday in Europe/Athens time, alongside separate Guest DJ programming.',
    'startDate' => '2026-10-14T20:00:00+03:00',
    'url' => 'https://deseoradio.com/#season-6',
    'image' => 'https://deseoradio.com/assets/img/season6%20lineup.png',
    'eventAttendanceMode' => 'https://schema.org/OnlineEventAttendanceMode',
    'location' => [
        '@type' => 'VirtualLocation',
        'url' => 'https://deseoradio.com/#player',
    ],
    'organizer' => [
        '@id' => 'https://iluma.gr/#organization',
    ],
    'about' => [
        '@id' => 'https://deseoradio.com/#radio',
    ],
];

$season6FirstAiring = [
    'Wednesday' => '2026-10-14',
    'Thursday' => '2026-10-15',
    'Friday' => '2026-10-16',
    'Saturday' => '2026-10-17',
    'Sunday' => '2026-10-18',
];

foreach ($season6Lineup as $slotIndex => $slot) {
    $slotId = sprintf(
        'https://deseoradio.com/#season-6-slot-%02d',
        $slotIndex + 1
    );

    $schema['@graph'][] = [
        '@type' => 'MusicEvent',
        '@id' => $slotId,
        'name' => $slot['dj'] . ' — Deseo Radio Season 6',
        'description' => 'Scheduled weekly DJ set by ' . $slot['dj'] . ' on Deseo Radio Season 6. First airing: ' . $season6FirstAiring[$slot['day']] . '; weekly start times follow Europe/Athens.',
        'url' => 'https://deseoradio.com/#season-6',
        'eventAttendanceMode' => 'https://schema.org/OnlineEventAttendanceMode',
        'location' => [
            '@type' => 'VirtualLocation',
            'url' => 'https://deseoradio.com/#player',
        ],
        'organizer' => [
            '@id' => 'https://iluma.gr/#organization',
        ],
        'superEvent' => [
            '@id' => $season6SeriesId,
        ],
        'about' => [
            '@id' => 'https://deseoradio.com/#radio',
        ],
        'performer' => [
            '@type' => 'Person',
            'name' => $slot['dj'],
        ],
        'eventSchedule' => [
            '@type' => 'Schedule',
            'startDate' => $season6FirstAiring[$slot['day']],
            'repeatFrequency' => 'P1W',
            'byDay' => $slot['schema_day'],
            'startTime' => $slot['start'],
            'scheduleTimezone' => 'Europe/Athens',
        ],
    ];
}

if (isset($live_dj) && is_array($live_dj) && trim((string)($live_dj['dj_name'] ?? '')) !== '') {
    $livePublicProfile = is_array($live_dj['public_profile'] ?? null)
        ? $live_dj['public_profile']
        : null;

    $liveArtistName = trim((string)($livePublicProfile['artist_name'] ?? $live_dj['dj_name'] ?? ''));
    $liveAccountId = (int)($live_dj['mylive_account_id'] ?? 0);
    $livePersonId = $liveAccountId > 0
        ? 'https://deseoradio.com/#dj-profile-' . $liveAccountId
        : 'https://deseoradio.com/#current-live-dj';

    $livePersonNode = [
        '@type' => 'Person',
        '@id' => $livePersonId,
        'name' => $liveArtistName !== '' ? $liveArtistName : (string)$live_dj['dj_name'],
        'url' => 'https://deseoradio.com/#live',
    ];

    if ($livePublicProfile) {
        $liveBio = trim((string)($livePublicProfile['bio'] ?? ''));
        if ($liveBio !== '') {
            $livePersonNode['description'] = $liveBio;
        }

        $liveSameAs = [];
        foreach (['instagram', 'tiktok', 'soundcloud', 'spotify', 'website'] as $profileLinkKey) {
            $profileLink = trim((string)($livePublicProfile[$profileLinkKey] ?? ''));
            if ($profileLink !== '' && filter_var($profileLink, FILTER_VALIDATE_URL)) {
                $liveSameAs[] = $profileLink;
            }
        }
        if ($liveSameAs) {
            $livePersonNode['sameAs'] = array_values(array_unique($liveSameAs));
        }
    }

    $schema['@graph'][] = $livePersonNode;

    $liveBroadcastNode = [
        '@type' => 'BroadcastEvent',
        '@id' => 'https://deseoradio.com/#current-broadcast',
        'name' => (string)$live_dj['dj_name'] . ' live on Deseo Radio',
        'url' => 'https://deseoradio.com/#live',
        'isLiveBroadcast' => true,
        'eventAttendanceMode' => 'https://schema.org/OnlineEventAttendanceMode',
        'location' => [
            '@type' => 'VirtualLocation',
            'url' => 'https://deseoradio.com/#player',
        ],
        'performer' => [
            '@id' => $livePersonId,
        ],
        'about' => [
            '@id' => 'https://deseoradio.com/#radio',
        ],
    ];

    if (($live_dj['_start'] ?? null) instanceof DateTimeInterface) {
        $liveBroadcastNode['startDate'] = $live_dj['_start']->format(DATE_ATOM);
    }
    if (($live_dj['_end'] ?? null) instanceof DateTimeInterface) {
        $liveBroadcastNode['endDate'] = $live_dj['_end']->format(DATE_ATOM);
    }

    $schema['@graph'][] = $liveBroadcastNode;
}
}
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

    <link rel="alternate" hreflang="el" href="<?= deseo_e($meta_canonical_el) ?>">
    <link rel="alternate" hreflang="en" href="<?= deseo_e($meta_canonical_en) ?>">
    <link rel="alternate" hreflang="x-default" href="<?= deseo_e($meta_canonical_el) ?>">

    <link rel="icon" type="image/png" href="/assets/img/favicon.png?v=<?= $faviconVersion ?>">
    <link rel="apple-touch-icon" href="/assets/img/favicon.png?v=<?= $faviconVersion ?>">

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
        <?php if (!$deseo_home_redesign): ?>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Google+Sans:400,500,700&display=swap">
        <?php endif; ?>
    <?php endif; ?>

    <link rel="manifest" href="/manifest.json?v=<?= $assetVersion ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Deseo Radio">

    <?php if ($deseo_home_redesign): ?>
    <link rel="preconnect" href="https://radios.iluma.gr" crossorigin>
    <link rel="preload" href="<?= deseo_e($brandLogoUrl) ?>" as="image" fetchpriority="high">
    <link rel="stylesheet" href="/assets/css/home.css?v=<?= $assetVersion ?>">
    <?php else: ?>
    <link rel="preload" href="/assets/img/bg.png?v=<?= $assetVersion ?>" as="image">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= $assetVersion ?>">
    <?php endif; ?>
    <?php foreach ($extra_styles as $extraStyle): ?>
        <link rel="stylesheet" href="<?= deseo_e((string)$extraStyle) ?>?v=<?= $assetVersion ?>">
    <?php endforeach; ?>

    <script>
    document.documentElement.className = document.documentElement.className.replace('no-js', 'js');
    window.DESEO_ASSET_VERSION = <?= json_encode((string)$assetVersion) ?>;
    <?php if ($deseo_home_redesign): ?>document.documentElement.classList.add('md-preloading');<?php endif; ?>
    </script>

    <?php if (!$private_page): ?>
    <script src="https://radios.iluma.gr/signal/v1/signal.js?v=<?= $deseo_home_redesign ? '1.1.2' : '1.1.1' ?>" data-station="deseo" data-surface="station_website" defer></script>
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
<a class="<?= $deseo_home_redesign ? 'md-skip' : 'skip-link' ?>" href="<?= $deseo_home_redesign ? '#main' : '#main-content' ?>" data-i18n="skip.content"><?= deseo_e(deseo_t('skip.content')) ?></a>

