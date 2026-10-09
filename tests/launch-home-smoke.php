<?php
declare(strict_types=1);

/**
 * Launch candidate: public homepage metadata + modular dynamic UI contract.
 * Runs without secrets and explicitly suppresses mail scheduler side effects.
 */
$_GET = [];
$_COOKIE = [];
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['HTTP_HOST'] = 'deseoradio.com';
$deseo_launch_disable_jobs = true;

ob_start();
require __DIR__ . '/../index-launch.php';
$html = (string)ob_get_clean();

$required = [
    '<html lang="el"',
    '<link rel="canonical" href="https://deseoradio.com/">',
    '<meta name="robots" content="index,follow',
    'hreflang="el"',
    'hreflang="en"',
    'hreflang="x-default"',
    '<meta property="og:image"',
    '<meta name="twitter:card" content="summary_large_image">',
    '<meta name="theme-color"',
    'id="md-fs-menu"',
    'id="md-iradios-player"',
    'id="md-hero-live-photo"',
    'id="md-hero-next-name"',
    'id="md-player-dock"',
    'data-iluma-signal-slot="hero-sponsor"',
    'data-iluma-signal-slot="sticky-sponsor"',
    'id="schedule"',
    'id="tracks"',
    'id="playlists"',
    'id="faq"',
    'id="ask-ai"',
    '/assets/css/mydemo-v16.css',
    '/assets/js/deseo-next.js',
    'deseoCookieConsentV2',
    'id="cookie-banner"',
    'id="md-app-install"',
    'id="md-app-ios-guide"',
    "navigator.serviceWorker.register('/sw.js?v='",
];
foreach ($required as $needle) {
    if (!str_contains($html, $needle)) {
        fwrite(STDERR, "Launch homepage requirement missing: {$needle}\n");
        exit(1);
    }
}
if (str_contains($html, 'name="robots" content="noindex') ||
    str_contains($html, '<meta name="googlebot" content="noindex') ||
    str_contains($html, '/assets/css/style.css?v=') ||
    str_contains($html, '/mydemo?feed=1')) {
    fwrite(STDERR, "Public launch must be indexable, not load legacy CSS or depend on /mydemo\n");
    exit(1);
}
if (substr_count($html, 'radios.iluma.gr/signal/v1/signal.js') !== 1) {
    fwrite(STDERR, "ILUMA Signal must load exactly once on public homepage\n");
    exit(1);
}
if (substr_count($html, 'id="md-iradios-player"') !== 1) {
    fwrite(STDERR, "Only one official iRadios player allowed\n");
    exit(1);
}
if (!preg_match('~<script type="application/ld\+json">([\s\S]*?)</script>~', $html, $jsonMatch)) {
    fwrite(STDERR, "Public JSON-LD schema missing\n");
    exit(1);
}
$schema = json_decode($jsonMatch[1], true);
if (!is_array($schema) || !is_array($schema['@graph'] ?? null)) {
    fwrite(STDERR, "Public JSON-LD not valid\n");
    exit(1);
}
$nodeTypes = array_map(static fn(array $node): string => (string)($node['@type'] ?? ''), $schema['@graph']);
foreach (['RadioStation', 'WebSite', 'WebPage', 'FAQPage', 'EventSeries'] as $nodeType) {
    if (!in_array($nodeType, $nodeTypes, true)) {
        fwrite(STDERR, "Missing schema node: {$nodeType}\n");
        exit(1);
    }
}
foreach ($schema['@graph'] as $node) {
    if (($node['@type'] ?? '') === 'MusicEvent') {
        fwrite(STDERR, "Launch should never publish outdated hardcoded DJ MusicEvents\n");
        exit(1);
    }
}
$bootstrap = (string)file_get_contents(__DIR__ . '/../includes/launch-2026/bootstrap.php');
$nextScript = (string)file_get_contents(__DIR__ . '/../assets/js/deseo-next.js');
foreach (['program_feed', 'generated_at', "'today' => array_values", 'mylive-email-reminders.php'] as $needle) {
    if (!str_contains($bootstrap, $needle)) {
        fwrite(STDERR, "Legacy program feed or scheduler contract missing: {$needle}\n");
        exit(1);
    }
}
if (!str_contains($nextScript, "window.DESEO_HOME_FEED_URL || '/?feed=1'")) {
    fwrite(STDERR, "The new homepage must refresh data from the production endpoint\n");
    exit(1);
}
echo "Launch candidate: canonical, hreflang, OG, JSON-LD, CMS, iRadios, Signal, consent and feed contracts OK\n";
