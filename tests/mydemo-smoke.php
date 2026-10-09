<?php
declare(strict_types=1);

/**
 * Read-only /mydemo contract smoke test.
 * Runs without DB credentials to verify that the isolated preview fails gracefully.
 */
$_GET = [];
$_COOKIE = [];
$_SERVER['REQUEST_URI'] = '/mydemo';
ob_start();
require __DIR__ . '/../mydemo.php';
$html = (string)ob_get_clean();

$expect = [
    '<meta name="robots" content="noindex,nofollow,noarchive,nosnippet,noimageindex',
    'THE SOUND',
    'id="player"',
    'id="schedule"',
    'THE WEEKLY LINEUP',
    'id="md-tab-1"',
    'id="tracks"',
    'id="playlists"',
    'id="lineup"',
    'id="shows"',
    'id="listen-everywhere"',
    'id="about"',
    'class="md-partner-grid"',
    'class="md-about-grid"',
    'https://mytuner-radio.com/radio/deseo-radio-479969/',
    'https://play.iradios.gr/widget-now/deseo-radio',
    'https://ec4.yesstreaming.net:2090/stream',
    'id="md-live-audio"',
    'id="md-custom-player"',
    'id="md-audio-toggle"',
    'id="md-volume"',
    'id="md-player-dock"',
    'id="md-mini-show-name"',
    '/assets/css/mydemo-player.css',
    '/assets/js/mydemo-player.js',
    '/assets/css/mydemo.css',
    '/assets/js/mydemo.js',
    'id="md-season-countdown"',
    'class="md-masthead md-brand-headline"',
    'THE SOUND',
    'THE SOUNDTRACK',
    'OF YOUR',
    'LIFE',
    'id="md-hero-live-name"',
    'id="md-hero-live-time"',
    'id="md-hero-live-photo"',
    'class="md-dj-column"',
    'class="md-dj-artwork"',
    'class="md-control-symbol"',
    'class="md-control-symbol" aria-hidden="true"',
    '/assets/css/mydemo-refinements.css',
    'class="md-custom-sponsor"',
    'data-iluma-signal-slot="hero-sponsor"',
    'data-iluma-signal-image',
    'https://radios.iluma.gr/signal/v1/signal.js?v=1.1.1',
    'iluma-digital-agency-banner.jpg',
    'class="md-hero-gridlines"',
    'class="md-footer-statement"',
    'THE SOUND',
    'THE SOUNDTRACK',
    'OF YOUR',
    'class="md-footer-directory"',
    'Παίζουμε <strong>μόνο μουσικάρες</strong> για την κάθε σου στιγμή.',
    'href="https://iluma.gr/radios/mediakit"',
];
foreach ($expect as $needle) {
    if (!str_contains($html, $needle)) {
        fwrite(STDERR, 'Missing /mydemo contract: ' . $needle . PHP_EOL);
        exit(1);
    }
}
$htaccess = (string)file_get_contents(__DIR__ . '/../.htaccess');
$robots = (string)file_get_contents(__DIR__ . '/../robots.txt');
if (!str_contains($htaccess, 'RewriteRule ^mydemo/?$ mydemo.php [L,QSA]')) {
    fwrite(STDERR, "/mydemo rewrite not installed\n");
    exit(1);
}
if (substr_count($robots, 'Disallow: /mydemo') < 2) {
    fwrite(STDERR, "/mydemo missing from robots groups\n");
    exit(1);
}
$css = (string)file_get_contents(__DIR__ . '/../assets/css/mydemo.css');
$js = (string)file_get_contents(__DIR__ . '/../assets/js/mydemo.js');
if (!str_contains($css, "'Barlow Condensed'") || !str_contains($css, "'Google Sans Flex'")) {
    fwrite(STDERR, "mydemo typography families missing\n");
    exit(1);
}
if (!str_contains($css, "'Barlow','Google Sans'") || !str_contains($css, '.md-show-photo') || !str_contains($css, '.md-footer-statement')) {
    fwrite(STDERR, "mydemo urban visual and bilingual typography rules missing\n");
    exit(1);
}
if (!str_contains($js, 'md-hero-live-name')) {
    fwrite(STDERR, "mydemo hero live refresh missing\n");
    exit(1);
}
if (substr_count($html, 'id="md-live-audio"') !== 1 || substr_count($html, 'id="md-audio-toggle"') !== 1) {
    fwrite(STDERR, "mydemo must have exactly one direct audio and one custom play/pause control\n");
    exit(1);
}
if (strpos($html, 'class="md-custom-sponsor"') < strpos($html, 'id="md-custom-player"')) {
    fwrite(STDERR, "mydemo original sponsor must be inside the player\n");
    exit(1);
}
$playerJs = (string)file_get_contents(__DIR__ . '/../assets/js/mydemo-player.js');
if (!str_contains($playerJs, 'audio.play()') ||
    !str_contains($playerJs, 'hero.getBoundingClientRect().bottom') ||
    !str_contains($playerJs, "dock.appendChild(player)") ||
    str_contains($playerJs, '/mydemo-nowplaying.php')) {
    fwrite(STDERR, "mydemo player must own direct audio, dock below hero and use the official track widget\n");
    exit(1);
}
$playerCss = (string)file_get_contents(__DIR__ . '/../assets/css/mydemo-player.css');
if (!str_contains($playerCss, 'height:100vh!important') ||
    !str_contains($playerCss, '.md-player-squares') ||
    !str_contains($playerCss, '.md-custom-player.is-mini .md-sponsor-column')) {
    fwrite(STDERR, "mydemo full-height hero, dual-square sponsor or sticky layout missing\n");
    exit(1);
}

if (!str_contains($playerCss, '.md-custom-player.is-mini') || !str_contains($playerCss, '.md-nowplaying-frame iframe')) {
    fwrite(STDERR, "mydemo custom player responsive styles missing\n");
    exit(1);
}
$demoSource = (string)file_get_contents(__DIR__ . '/../mydemo.php');
if (!str_contains($demoSource, 'class="md-show-hours"')) {
    fwrite(STDERR, "mydemo must put CMS showtimes on the right of each weekly lineup card\n");
    exit(1);
}
$refinements = (string)file_get_contents(__DIR__ . '/../assets/css/mydemo-refinements.css');
if (!str_contains($refinements, '--red:#ff0000') ||
    !str_contains($refinements, 'position:fixed!important') ||
    !str_contains($refinements, 'aspect-ratio:1/1') ||
    !str_contains($refinements, '.md-show-card:hover .md-show-photo img') ||
    !str_contains($refinements, 'object-fit:contain') ||
    !str_contains($refinements, '.md-partner-grid')) {
    fwrite(STDERR, "mydemo brand red, sticky header, hover, square photos, partner section or undistorted playlists missing\n");
    exit(1);
}
if (!str_contains($playerJs, "symbol.classList.toggle('is-pause', playing)") ||
    str_contains($playerJs, "symbol.textContent = playing ? 'Ⅱ'")) {
    fwrite(STDERR, "mydemo player pause must use the accessible CSS two-bar pause icon\n");
    exit(1);
}
if (substr_count($html, 'class="md-partner-card"') !== 8) {
    fwrite(STDERR, "mydemo must render all eight confirmed streaming partners\n");
    exit(1);
}
if (preg_match('/class="md-day-tab[^"]*"[^>]*>[^<]*<span/u', $html)) {
    fwrite(STDERR, "mydemo day tabs must not contain episode counters\n");
    exit(1);
}
echo "mydemo: isolated preview, public sections, playback embed, and crawl exclusion OK\n";
