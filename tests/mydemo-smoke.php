<?php
declare(strict_types=1);

/**
 * Demo smoke test: verify the public preview contract with no database credentials.
 * iRadios is the sole audio engine. The sticky card never duplicates playback.
 */
$_GET = [];
$_COOKIE = [];
$_SERVER['REQUEST_URI'] = '/mydemo';
ob_start();
require __DIR__ . '/../mydemo.php';
$html = (string)ob_get_clean();

$required = [
    '<meta name="robots" content="noindex,nofollow',
    'id="player"',
    'id="md-iradios-player"',
    'https://play.iradios.gr/widget/deseo-radio?autoplay=true',
    'allow="autoplay; encrypted-media; clipboard-write"',
    'id="md-player-dock"',
    'id="md-dock-show"',
    'id="md-dock-time"',
    'id="md-dock-photo"',
    'id="md-hero-live-photo"',
    'id="md-hero-live-time"',
    'id="md-hero-live-name"',
    'class="md-dj-column"',
    'data-iluma-signal-slot="hero-sponsor"',
    'data-iluma-signal-slot="sticky-sponsor"',
    'data-iluma-signal-image',
    'https://radios.iluma.gr/signal/v1/signal.js?v=1.1.1',
    'id="faq"',
    'id="md-faq-title"',
    'class="md-faq-list"',
    'class="md-faq-item"',
    'id="ask-ai"',
    'id="ai-discovery-title"',
    'class="ai-discovery-grid"',
    'class="md-ai-wrap"',
    '/assets/css/mydemo-v7.css',
    'id="listen-everywhere"',
    'id="about"',
    'THE WEEKLY LINEUP',
    'id="schedule"',
    'id="lineup"',
    'id="tracks"',
    'RELEASE RADAR',
    'DISCOVERY.',
    'id="playlists"',
    'id="shows"',
    'class="md-partner-grid"',
    'class="md-about-grid"',
    'id="md-season-countdown"',
    '/assets/css/mydemo-v6.css',
    '/assets/js/mydemo-player.js',
    'class="md-preloader"',
    'md-preloading',
    'class="md-footer-social-grid"',
    'href="https://iluma.gr/radios/mediakit"',
    'Παίζουμε <strong>μόνο μουσικάρες</strong> για την κάθε σου στιγμή.',
];
foreach ($required as $needle) {
    if (!str_contains($html, $needle)) {
        fwrite(STDERR, "Missing mydemo contract: {$needle}\n");
        exit(1);
    }
}
$forbidden = [
    '<audio ',
    'id="md-audio-toggle"',
    'id="md-volume"',
    'widget-now/deseo-radio',
    '<nav class="md-nav"',
    'class="md-footer-links"',
    'id="md-mini-show-name"',
];
foreach ($forbidden as $needle) {
    if (str_contains($html, $needle)) {
        fwrite(STDERR, "Obsolete duplicate player / menu still present: {$needle}\n");
        exit(1);
    }
}
if (substr_count($html, 'id="md-iradios-player"') !== 1 ||
    substr_count($html, 'id="md-player-dock"') !== 1) {
    fwrite(STDERR, "Exactly one official iRadios iframe and one informational dock required\n");
    exit(1);
}
if (substr_count($html, 'class="md-faq-item"') !== DESEO_PUBLIC_FAQ_COUNT) {
    fwrite(STDERR, "Demo must use all public FAQ answers from shared translations\n");
    exit(1);
}
if (substr_count($html, 'class="ai-discovery-card"') !== 4 ||
    !str_contains($html, 'chatgpt.com/?q=') ||
    !str_contains($html, 'claude.ai/new?q=') ||
    !str_contains($html, 'www.perplexity.ai/search/new?q=')) {
    fwrite(STDERR, "Demo must reuse the four live AI provider links and official prompt\n");
    exit(1);
}
$faqPos = strpos($html, 'id="faq"');
$aiPos = strpos($html, 'id="ask-ai"');
$footerPos = strpos($html, '<footer class="md-footer"');
if ($faqPos === false || $aiPos === false || $footerPos === false ||
    !($faqPos < $aiPos && $aiPos < $footerPos)) {
    fwrite(STDERR, "FAQ and AI must render in that order before the footer\n");
    exit(1);
}
$demoSource = (string)file_get_contents(__DIR__ . '/../mydemo.php');
if (str_contains($demoSource, 'class="md-hero-cta"') ||
    str_contains($html, 'class="md-hero-cta"')) {
    fwrite(STDERR, "Demo hero CTAs should be removed completely\n");
    exit(1);
}
$v7Css = (string)file_get_contents(__DIR__ . '/../assets/css/mydemo-v7.css');
if (!str_contains($v7Css, '@media(max-width:1159px)') ||
    !str_contains($v7Css, '.md-hero-grid > .md-player-home') ||
    !str_contains($v7Css, 'order:1;') ||
    !str_contains($v7Css, '.md-hero-grid > .md-hero-copy') ||
    !str_contains($v7Css, 'order:2;')) {
    fwrite(STDERR, "Demo must show the player before hero titles at tablet/mobile breakpoints\n");
    exit(1);
}
if (substr_count($html, 'class="md-partner-card"') !== 8) {
    fwrite(STDERR, "Missing official listening partners\n");
    exit(1);
}
$style = (string)file_get_contents(__DIR__ . '/../assets/css/mydemo-v6.css');
foreach (['grid-template-columns:repeat(3,minmax(0,1fr))','aspect-ratio:1/1',
    '.md-player-squares','grid-template-columns:1fr!important','md-pre-line',
    '.md-footer-social-grid','#md-player-dock.md-dock'] as $needle) {
    if (!str_contains($style, $needle)) {
        fwrite(STDERR, "Missing responsive / preloader design: {$needle}\n");
        exit(1);
    }
}
$script = (string)file_get_contents(__DIR__ . '/../assets/js/mydemo-player.js');
if (str_contains($script, 'audio.play(') || str_contains($script, 'dock.appendChild(player)') ||
    !str_contains($script, 'hero.getBoundingClientRect().bottom')) {
    fwrite(STDERR, "Dock must not clone or restart audio playback\n");
    exit(1);
}
$demoJs = (string)file_get_contents(__DIR__ . '/../assets/js/mydemo.js');
foreach (['md-dock-photo','md-dock-show','md-dock-time','md-hero-live-photo'] as $id) {
    if (!str_contains($demoJs, $id)) {
        fwrite(STDERR, "Live CMS sync missing: {$id}\n");
        exit(1);
    }
}
$htaccess = (string)file_get_contents(__DIR__ . '/../.htaccess');
$robots = (string)file_get_contents(__DIR__ . '/../robots.txt');
if (!str_contains($htaccess, 'RewriteRule ^mydemo/?$ mydemo.php [L,QSA]') ||
    substr_count($robots, 'Disallow: /mydemo') < 2) {
    fwrite(STDERR, "Preview crawler restrictions missing\n");
    exit(1);
}
echo "mydemo: official iRadios player, sticky info, sponsor, sections, layout and noindex OK\n";
