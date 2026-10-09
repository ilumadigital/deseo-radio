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
    'id="md-fs-menu"',
    'id="md-menu-trigger"',
    'id="md-fs-close"',
    'class="md-fs-menu-links"',
    'class="md-menu-showcase md-menu-feature"',
    'class="md-footer-directory md-footer-connections"',
    'class="md-footer-social-grid"',
    '/assets/css/mydemo-v9.css',
    '/assets/js/mydemo-menu.js',
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
/* Signal inserts logo + QR below the anchor. Fallback sizing must not affect them. */
$refinedStyle = (string)file_get_contents(__DIR__ . '/../assets/css/mydemo-refinements.css');
if (!str_contains($style, '.md-custom-player .md-custom-sponsor > img') ||
    !str_contains($refinedStyle, '.md-custom-sponsor > img') ||
    preg_match('/\\.md-custom-sponsor\\s+img\\s*\\{/', $style . "\n" . $refinedStyle)) {
    fwrite(STDERR, "Sponsor sizing must target the fallback image directly, never Signal's QR and logo\n");
    exit(1);
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
// The browser / installed app keeps favicon.png; on-page artwork uses only favicon-nobg.png.
$demoPath = __DIR__ . '/../mydemo.php';
$demoMarkup = (string)file_get_contents($demoPath);
$headMarkup = (string)file_get_contents(__DIR__ . '/../includes/head-meta.php');
$footerMarkup = (string)file_get_contents(__DIR__ . '/../includes/footer.php');
$homeMarkup = (string)file_get_contents(__DIR__ . '/../index.php');
$manifestContent = (string)file_get_contents(__DIR__ . '/../manifest.json');
$manifestData = json_decode($manifestContent, true);
if (!is_array($manifestData) || empty($manifestData['icons']) ||
    !is_file(__DIR__ . '/../assets/img/favicon.png') ||
    !is_file(__DIR__ . '/../assets/img/favicon-nobg.png')) {
    fwrite(STDERR, "Expected both approved favicon assets and PWA metadata\n");
    exit(1);
}
if (!str_contains($html, '<link rel="icon" type="image/png" href="/assets/img/favicon.png?v=') ||
    preg_match('~<img\\b[^>]*\\bsrc="/assets/img/favicon\\.png~i', $html) ||
    preg_match_all('~<img[^>]+src="/assets/img/favicon-nobg\.png"~', $demoMarkup) !== 3) {
    fwrite(STDERR, "Demo must use the regular file solely as browser favicon and the transparent emblem in content\n");
    exit(1);
}
if (!str_contains($headMarkup, '/assets/img/favicon.png?v=<?= $faviconVersion ?>') ||
    !str_contains($headMarkup, 'rel="apple-touch-icon"') ||
    !str_contains((string)($manifestData['icons'][0]['src'] ?? ''), '/assets/img/favicon.png?v=')) {
    fwrite(STDERR, "Domain tab, Apple icon and installed PWA must keep the canonical background favicon\n");
    exit(1);
}
if (str_contains($footerMarkup, '/assets/img/favicon.png') ||
    str_contains($homeMarkup, '/assets/img/favicon.png') ||
    !str_contains($footerMarkup, '/assets/img/favicon-nobg.png') ||
    !str_contains($homeMarkup, '/assets/img/favicon-nobg.png')) {
    fwrite(STDERR, "Page-visible fallback emblems must be transparent\n");
    exit(1);
}
/* V8 design: consistent width, compact seven-day navigation, typography-only
 * arrows, sponsor-safe central attribution and accessible cursor fallback. */
foreach ([
    '/assets/css/mydemo-v8.css',
    '/assets/js/mydemo-cursor.js',
    'class="md-footer-credit"',
    'Handcrafted by',
    'ILUMA Digital Agency',
    'href="https://iluma.gr/"',
    'SOMETHING IN THE AIR...',
    'DESEO RADIO <b>✦</b> SEASON 6 <b>✦</b> THE SOUNDTRACK OF YOUR LIFE',
    'ILUMA RADIOS <b>✦</b> GUEST DJ ZONE <b>✦</b> RESIDENT DJS',
    'id="md-custom-cursor"',
    'class="md-ui-arrow"',
] as $needle) {
    if (!str_contains($html, $needle)) {
        fwrite(STDERR, "Missing V8 visual or brand contract: {$needle}\n");
        exit(1);
    }
}
$demoSource = (string)file_get_contents(__DIR__ . '/../mydemo.php');
if (str_contains($demoSource, 'DESEO / <?= demo_e($copy[\'preview\']) ?> / NOINDEX') ||
    str_contains($demoSource, 'THIS IS YOUR FREQUENCY.') ||
    str_contains($demoSource, 'HOUSE IS A FEELING <b>✦</b> ATHENS') ||
    preg_match('~<b>↗</b>|<span>↗</span>~u', $demoSource)) {
    fwrite(STDERR, "Old branded copy or emoji-style arrow controls remain\n");
    exit(1);
}
foreach (['MO','TU','WE','TH','FR','SA','SU'] as $abbr) {
    if (!preg_match('~<button[^>]*class="md-day-tab[^"]*"[^>]*>' . $abbr . '</button>~', $html)) {
        fwrite(STDERR, "Seven English day initials must be present in every language: {$abbr}\n");
        exit(1);
    }
}
$cssV8 = (string)file_get_contents(__DIR__ . '/../assets/css/mydemo-v8.css');
foreach ([
    '--shell:1850px',
    '.md-ai-wrap .wide-shell',
    'grid-template-columns:repeat(7,minmax(0,1fr))!important',
    'overflow:visible!important',
    '.md-ai-wrap .ai-discovery-card:after',
    'content:""!important',
    '.md-footer-credit',
    'grid-column:2',
    'scrollbar-color:#ff0000',
    '::-webkit-scrollbar-thumb',
    '.md-custom-cursor',
] as $needle) {
    if (!str_contains($cssV8, $needle)) {
        fwrite(STDERR, "Missing design consistency / responsive / icon styling: {$needle}\n");
        exit(1);
    }
}
$cursorJs = (string)file_get_contents(__DIR__ . '/../assets/js/mydemo-cursor.js');
if (!str_contains($cursorJs, 'pointer: fine') ||
    !str_contains($cursorJs, 'pointermove') ||
    !str_contains($cursorJs, 'requestAnimationFrame') ||
    !str_contains($cursorJs, 'prefers-reduced-motion') ||
    !str_contains($cursorJs, "iframe, input, textarea")) {
    fwrite(STDERR, "Custom cursor must only operate safely on fine pointers\n");
    exit(1);
}

/* V9: fullscreen accessible menu and its links, red label cleanup and animation. */
$menuStart = strpos($html, 'id="md-fs-menu"');
$menuEnd = strpos($html, '<main id="main">');
if ($menuStart === false || $menuEnd === false || $menuStart > $menuEnd) {
    fwrite(STDERR, "Fullscreen menu must be outside the main and before the hero\n");
    exit(1);
}
$menuHtml = substr($html, $menuStart, $menuEnd - $menuStart);
$menuLinks = [
    'JUST LISTEN' => '#player',
    'PARTNERS' => '#listen-everywhere',
    'ABOUT US' => '#about',
    'LINEUP' => '#lineup',
    'PROGRAM' => '#schedule',
    'RELEASE RADAR' => '#tracks',
    'PLAYLISTS' => '#playlists',
    'RADIOSHOWS' => '#shows',
    'FAQ' => '#faq',
    'CONTACT' => 'mailto:radio@iluma.gr',
];
foreach ($menuLinks as $label => $href) {
    if (!str_contains($menuHtml, 'href="' . $href . '"') ||
        !str_contains($menuHtml, $label)) {
        fwrite(STDERR, "Fullscreen menu section link missing: {$label}\n");
        exit(1);
    }
}
$footerHtml = substr($html, strpos($html, '<footer class="md-footer"'));
foreach ([
    'instagram.com/deseoradio/',
    'facebook.com/deseoradiogr/',
    'mixcloud.com/deseoradio/',
    'podcasts.apple.com/us/podcast/deseo-radioshows',
    'open.spotify.com/show/2x8ceF2a3gMmzEJ8y6W1ue',
    'iluma.gr/radios/mediakit',
] as $external) {
    if (!str_contains($footerHtml, $external) || str_contains($menuHtml, $external)) {
        fwrite(STDERR, "Social links must appear in the footer, not the fullscreen menu: {$external}\n");
        exit(1);
    }
}
if (!str_contains($footerHtml, 'class="md-footer-social-grid"') ||
    !str_contains($menuHtml, 'class="md-menu-showcase md-menu-feature"')) {
    fwrite(STDERR, "Social footer and menu promotional carousel missing\n");
    exit(1);
}
$demoTemplate = (string)file_get_contents(__DIR__ . '/../mydemo.php');
if (str_contains($demoTemplate, 'RESIDENT / DJ SET') ||
    str_contains($demoTemplate, 'DESEO RADIO / S06') ||
    str_contains($demoTemplate, '<span>DESEO / SEASON 06</span>') ||
    !str_contains($demoTemplate, 'DESEO RADIOSHOW')) {
    fwrite(STDERR, "Program cards must only describe DESEO RADIOSHOW, without old red tags\n");
    exit(1);
}
$demoJsV9 = (string)file_get_contents(__DIR__ . '/../assets/js/mydemo.js');
if (str_contains($demoJsV9, "'DESEO RADIO / S06'")) {
    fwrite(STDERR, "CMS schedule updates must not reintroduce old radio season labels\n");
    exit(1);
}
$menuJs = (string)file_get_contents(__DIR__ . '/../assets/js/mydemo-menu.js');
foreach (['Escape', 'aria-expanded', 'is-open', 'md-menu-open',
          'IntersectionObserver', 'prefers-reduced-motion', 'closeMenu', 'Tab'] as $needle) {
    if (!str_contains($menuJs, $needle)) {
        fwrite(STDERR, "Menu accessibility or progressive section animation missing: {$needle}\n");
        exit(1);
    }
}
$cssV9 = (string)file_get_contents(__DIR__ . '/../assets/css/mydemo-v9.css');
foreach (['.md-fs-menu.is-open', 'position:fixed', 'overflow-y:auto',
          '.md-fs-menu-links', '.md-fs-menu-side', '.md-reveal',
          '@media(max-width:700px)', '@media(prefers-reduced-motion:reduce)'] as $needle) {
    if (!str_contains($cssV9, $needle)) {
        fwrite(STDERR, "Fullscreen menu/mobile animation stylesheet incomplete: {$needle}\n");
        exit(1);
    }
}

/* V11: the actual live contract, no carousel or obsolete menu copy. */
foreach ([
    '/assets/css/mydemo-v11.css',
    'content="v11-menu-brand-feature"',
    'class="md-fs-menu-brand"',
    '<h2 class="md-fs-menu-title" id="md-fs-menu-title">',
    'class="md-fs-title-red"',
    'class="md-menu-showcase md-menu-feature"',
    'src="/assets/img/favicon-nobg.png"',
    'src="/assets/img/deseo-logo.png"',
    'src="/assets/img/deseoradio-logo.png"',
    'class="md-preloader-final"',
    'class="md-word-the"',
    'class="md-word-soundtrack"',
    'class="md-word-of"',
    'class="md-word-life"',
    '>NOW PLAYING</div>',
    '>SPONSOR</div>',
    '>ONAIR NOW</div>',
    'COMING UP NEXT',
    'DJ SA',
    'EVERY WEEKEND',
    '@ 17:00',
    'profile-85-DJ_SA_RADIOSHOW-20261007-144654-0c15cd.png',
] as $needle) {
    if (!str_contains($html, $needle)) {
        fwrite(STDERR, "V11 demo requirement missing: {$needle}\n");
        exit(1);
    }
}
$menuHtml = substr($html, strpos($html, 'id="md-fs-menu"'), strpos($html, '<main id="main">') - strpos($html, 'id="md-fs-menu"'));
if (str_contains($menuHtml, 'FIND YOUR') ||
    str_contains($menuHtml, 'AFTER DARK') ||
    str_contains($menuHtml, 'md-menu-carousel-track') ||
    str_contains($menuHtml, 'md-menu-carousel-next') ||
    str_contains($menuHtml, 'md-menu-carousel-dots')) {
    fwrite(STDERR, "V11 fullscreen menu must not contain outdated copy or a slideshow\n");
    exit(1);
}
if (substr_count($menuHtml, 'class="md-menu-showcase md-menu-feature"') !== 1 ||
    substr_count($menuHtml, 'class="md-fs-menu-brand"') !== 1) {
    fwrite(STDERR, "V11 menu must show exactly one featured DJ SA and one logo\n");
    exit(1);
}
$preloaderStart = strpos($html, '<div class="md-preloader"');
$headerStart = strpos($html, '<header class="md-header">');
$preloaderHtml = substr($html, $preloaderStart, $headerStart - $preloaderStart);
$logoNames = ['/assets/img/favicon-nobg.png', '/assets/img/deseo-logo.png', '/assets/img/deseoradio-logo.png'];
$lastPos = -1;
foreach ($logoNames as $src) {
    $p = strpos($preloaderHtml, 'src="' . $src . '"');
    if ($p === false || $p <= $lastPos) {
        fwrite(STDERR, "V11 preloader logos must appear in order: {$src}\n");
        exit(1);
    }
    $lastPos = $p;
}
// Logos must be rendered at their supplied aspect ratio, never cropped/enlarged.
if (!str_contains($html, 'alt="Deseo Radio" width="220" height="100" fetchpriority="high"') ||
    !str_contains($html, 'id="md-fs-menu-title"><span class="md-fs-title-line">') ||
    substr_count($html, 'class="md-fs-title-line"') !== 2 ||
    str_contains($html, 'width="330" height="100"')) {
    fwrite(STDERR, "Header logo size or exactly-two-line menu title regression\n");
    exit(1);
}
$cssV11 = (string)file_get_contents(__DIR__ . '/../assets/css/mydemo-v11.css');
foreach ([
    '.md-header .md-logo img',
    'width:220px!important',
    'height:100px!important',
    'transform:none!important',
    'overflow:visible!important',
    'height:100dvh!important',
    '.md-fs-menu-brand img',
    '.md-fs-title-line',
    '.md-fs-title-outline',
    '.md-menu-feature-art',
    'md-v11-mark',
    'md-v11-word',
    'md-v11-radio',
    'font-size:clamp(98px,6.43vw,110px)',
    '@media(max-width:600px)',
    'prefers-reduced-motion',
] as $needle) {
    if (!str_contains($cssV11, $needle)) {
        fwrite(STDERR, "V11 design sizing / motion missing: {$needle}\n");
        exit(1);
    }
}
$menuScript = (string)file_get_contents(__DIR__ . '/../assets/js/mydemo-menu.js');
if (str_contains($menuScript, 'carouselSlides') ||
    str_contains($menuScript, 'showCarouselSlide') ||
    str_contains($menuScript, 'startCarouselRotation') ||
    !str_contains($menuScript, 'closeMenu')) {
    fwrite(STDERR, "V11 menu behavior must not auto-rotate or duplicate a carousel\n");
    exit(1);
}
/* V12: Live Radio removed from header, high-contrast top-layer cursor and DJ bios. */
$headerStart = strpos($html, '<header class="md-header">');
$headerEnd = strpos($html, '</header>', $headerStart);
if ($headerStart === false || $headerEnd === false ||
    str_contains(substr($html, $headerStart, $headerEnd - $headerStart), 'LIVE RADIO') ||
    str_contains(substr($html, $headerStart, $headerEnd - $headerStart), 'md-header-live')) {
    fwrite(STDERR, "The V12 header must not contain the Live Radio button\n");
    exit(1);
}
foreach ([
    '/assets/css/mydemo-v12.css',
    'class="md-dj-details"',
    'id="md-dj-dialog"',
    'id="md-dialog-bio"',
    'id="md-dialog-title"',
] as $needle) {
    if (!str_contains($html, $needle)) {
        fwrite(STDERR, "V12 DJ modal markup or CSS missing: {$needle}\n");
        exit(1);
    }
}
$cursorV12 = (string)file_get_contents(__DIR__ . '/../assets/js/mydemo-cursor.js');
foreach ([
    'isRedCssColor', 'isOnRedElement', 'is-light', 'pointermove',
    'MutationObserver', "attributeFilter: ['open']", 'dialog.open',
    'dialog.addEventListener', 'parent.appendChild(cursor)',
    'iframe, input, textarea', 'prefers-reduced-motion',
] as $needle) {
    if (!str_contains($cursorV12, $needle)) {
        fwrite(STDERR, "V12 cursor contrast, DJ top-layer sync or pointer safety missing: {$needle}\n");
        exit(1);
    }
}
$v12Css = (string)file_get_contents(__DIR__ . '/../assets/css/mydemo-v12.css');
foreach ([
    '.md-custom-cursor.is-light',
    '.md-custom-cursor.is-light.is-interactive',
    '#md-dj-dialog > .md-custom-cursor',
    '#md-dj-dialog[open]',
    '#md-dj-dialog > .md-dj-details',
    '#md-dj-dialog #md-dialog-title',
    'font-size:clamp(24px,2.35vw,39px)',
    '#md-dj-dialog #md-dialog-bio',
    'max-height:clamp(145px,33dvh,350px)',
    'overflow-y:auto',
    '@media(max-width:760px)',
    '.md-header .md-header-live{display:none!important}',
] as $needle) {
    if (!str_contains($v12Css, $needle)) {
        fwrite(STDERR, "V12 modal responsiveness or cursor visibility style missing: {$needle}\n");
        exit(1);
    }
}
// Regression: the cursor becomes a direct <div> child of a native DJ
// dialog, where legacy CSS added huge padding. It must keep the exact
// compact geometry of the regular pointer even after being re-parented.
$cursorRuleStart = strpos($v12Css, '#md-dj-dialog > .md-custom-cursor{');
$cursorRuleEnd = $cursorRuleStart !== false ? strpos($v12Css, '}', $cursorRuleStart) : false;
$modalCursorRule = $cursorRuleStart !== false && $cursorRuleEnd !== false
    ? substr($v12Css, $cursorRuleStart, $cursorRuleEnd - $cursorRuleStart)
    : '';
foreach ([
    'width:9px!important;',
    'height:9px!important;',
    'padding:0!important;',
    'margin:0!important;',
    'box-sizing:border-box!important;',
    'position:fixed!important;',
    'z-index:2147483647!important;',
] as $needle) {
    if (!str_contains($modalCursorRule, $needle)) {
        fwrite(STDERR, "DJ modal cursor enlarged by layout: {$needle}\n");
        exit(1);
    }
}
$hoverCursorRuleStart = strpos($v12Css, '#md-dj-dialog > .md-custom-cursor.is-interactive{');
$hoverCursorRule = $hoverCursorRuleStart !== false
    ? substr($v12Css, $hoverCursorRuleStart,
        (int)strpos($v12Css, '}', $hoverCursorRuleStart) - $hoverCursorRuleStart)
    : '';
if (!str_contains($hoverCursorRule, 'width:20px!important;') ||
    !str_contains($hoverCursorRule, 'height:20px!important;')) {
    fwrite(STDERR, "DJ modal cursor hover size differs from the rest of the site\n");
    exit(1);
}
echo "mydemo: official iRadios player, sticky info, sponsor, sections, layout and noindex OK\n";
