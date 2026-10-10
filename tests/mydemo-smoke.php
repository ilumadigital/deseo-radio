<?php
declare(strict_types=1);

/* Preview contract for the shared production homepage renderer. */
$_GET = [];
$_COOKIE = [];
$_SERVER['REQUEST_URI'] = '/mydemo';
ob_start();
require dirname(__DIR__) . '/mydemo.php';
$html = (string)ob_get_clean();

$required = [
    '<meta name="robots" content="noindex,nofollow',
    'Design Preview',
    '/assets/css/home.css',
    'class="md-preloader"',
    'md-preloader-token--red',
    'class="md-header"',
    'id="md-fs-menu"',
    'id="md-iradios-player"',
    'https://play.iradios.gr/widget/deseo-radio?autoplay=true',
    'allow="autoplay; encrypted-media; clipboard-write"',
    'class="md-player-squares"',
    'class="md-nowplaying-column"',
    'class="md-sponsor-column"',
    'class="md-dj-column"',
    'data-iluma-signal-slot="hero-sponsor"',
    'data-iluma-signal-slot="sticky-sponsor"',
    'https://radios.iluma.gr/signal/v1/signal.js?v=1.1.2',
    'id="md-dock-show"',
    'id="md-season-countdown"',
    'id="schedule"',
    'id="lineup"',
    'id="tracks"',
    'id="playlists"',
    'id="faq"',
    'id="ask-ai"',
    'id="md-dj-dialog"',
    'THE SOUNDTRACK',
    'DESEO RADIO',
];
foreach ($required as $needle) {
    if (!str_contains($html, $needle)) {
        fwrite(STDERR, "Missing preview contract: {$needle}\n");
        exit(1);
    }
}
if (substr_count($html, '<link rel="stylesheet"') !== 1 ||
    substr_count($html, 'id="md-iradios-player"') !== 1 ||
    substr_count($html, 'data-iluma-signal-slot="hero-sponsor"') !== 1 ||
    substr_count($html, 'class="md-player-square-label"') !== 3 ||
    str_contains($html, '<audio ') ||
    str_contains($html, 'rel="canonical"')) {
    fwrite(STDERR, "Preview duplicates players/styles or leaked production SEO\n");
    exit(1);
}
foreach (['includes/home-header.php','includes/home-footer.php','assets/css/home.css'] as $path) {
    if (!is_file(dirname(__DIR__) . '/' . $path)) {
        fwrite(STDERR, "Missing shared production asset: {$path}\n");
        exit(1);
    }
}
$css = (string)file_get_contents(dirname(__DIR__) . '/assets/css/home.css');
foreach (['.md-player-squares','.md-preloader-token','.md-fs-menu','.cookie-banner','.md-hero-photo'] as $rule) {
    if (!str_contains($css, $rule)) {
        fwrite(STDERR, "Missing consolidated CSS selector: {$rule}\n");
        exit(1);
    }
}
/* Mobile preloader must keep LIFE on the same line and display the complete
   staggered title + SOUNDTRACK sweep before uncovering the live player. */
$renderer = (string)file_get_contents(dirname(__DIR__) . '/mydemo.php');
if (!str_contains($css, 'font-size: clamp(17px, 5.6vw, 27px) !important;') ||
    !str_contains($css, 'flex-wrap: nowrap !important;') ||
    !str_contains($css, 'white-space: nowrap !important;') ||
    !str_contains($renderer, "event.animationName === 'md18-red-sweep'") ||
    !str_contains($renderer, 'window.setTimeout(finishIntro, 260);') ||
    !str_contains($renderer, 'fallback = window.setTimeout(finishIntro, 2850);')) {
    fwrite(STDERR, "Mobile slogan or full preloader animation regression\n");
    exit(1);
}
/* Minimal floating Now On Air dock + responsive branded context menu. */
if (!str_contains($html, 'id="md-player-dock"') ||
    !str_contains($html, 'class="md-dock-body"') ||
    str_contains($html, 'DESEO / NOW ON AIR') ||
    str_contains($html, 'class="md-dock-expand"') ||
    str_contains($html, 'class="md-dock-top"') ||
    substr_count($html, 'id="deseo-context-menu"') !== 1 ||
    !str_contains($html, '/assets/js/home-context-menu.js?v=') ||
    !str_contains($html, 'href="#schedule"') ||
    !str_contains($html, 'href="#ask-ai"') ||
    !str_contains($html, 'href="#listen-everywhere"')) {
    fwrite(STDERR, "Dock heading, branded context navigation, or integration regression\n");
    exit(1);
}
$contextJs = (string)file_get_contents(dirname(__DIR__) . '/assets/js/home-context-menu.js');
$homeCss = (string)file_get_contents(dirname(__DIR__) . '/assets/css/home.css');
if (!str_contains($contextJs, "document.addEventListener('contextmenu'") ||
    !str_contains($contextJs, "document.addEventListener('keydown'") ||
    !str_contains($contextJs, "['copy', 'cut', 'paste']") ||
    !str_contains($contextJs, 'if (editable(event.target)) return;') ||
    !str_contains($homeCss, '.deseo-context-menu[hidden]') ||
    !str_contains($homeCss, '#md-player-dock .md-dock-body')) {
    fwrite(STDERR, "Context-menu or shortcut deterrent assets missing\n");
    exit(1);
}
echo "mydemo: private preview, single CSS, native player, sponsors, CMS widgets and AI section OK\n";
