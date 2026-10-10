<?php
declare(strict_types=1);

/** Regression check for the production home cutover and SEO/AI discovery. */
$_GET = [];
$_COOKIE = [];
$_SERVER['REQUEST_URI'] = '/';
ob_start();
require dirname(__DIR__) . '/index.php';
$html = (string)ob_get_clean();

foreach ([
  '<html lang="', '<title>Deseo Radio', 'name="description"',
  'name="robots" content="index,follow', 'rel="canonical" href="https://deseoradio.com/"',
  'rel="alternate" hreflang="el"', 'rel="alternate" hreflang="en"',
  'property="og:image"', 'name="twitter:card"', 'type="application/ld+json"',
  'id="md-iradios-player"', 'data-iluma-signal-slot="hero-sponsor"',
  'data-iluma-signal-slot="sticky-sponsor"', 'id="md-hero-live-name"',
  'id="schedule"', 'id="program"', 'id="season-6"',
  'id="tracks"', 'id="playlists"', 'id="faq"',
  'id="ask-ai"', 'class="ai-discovery-grid"',
  'id="cookie-banner"', '/assets/css/home.css?v=',
  'id="md-fs-menu"', 'id="md-dj-dialog"',
] as $needle) {
  if (!str_contains($html, $needle)) {
    fwrite(STDERR, "Production home missing: {$needle}\n");
    exit(1);
  }
}
foreach (['noindex,nofollow','Design Preview','/assets/css/mydemo-bundle-v1.css'] as $forbidden) {
  if (str_contains($html, $forbidden)) {
    fwrite(STDERR, "Production home leaked preview metadata: {$forbidden}\n");
    exit(1);
  }
}
if (substr_count($html, '<!DOCTYPE html>') !== 1 ||
    substr_count($html, '<link rel="stylesheet"') !== 1 ||
    substr_count($html, 'id="md-iradios-player"') !== 1 ||
    substr_count($html, 'data-iluma-signal-slot="hero-sponsor"') !== 1 ||
    substr_count($html, 'signal/v1/signal.js') !== 1 ||
    substr_count($html, 'id="cookie-banner"') !== 1) {
  fwrite(STDERR, "Production home has duplicate head, styles, streaming or tracking integration\n");
  exit(1);
}
if (!preg_match('~<script type="application/ld\+json">([\s\S]*?)</script>~', $html, $match)) {
  fwrite(STDERR, "Production home JSON-LD missing\n");
  exit(1);
}
$data = json_decode($match[1], true);
if (!is_array($data) || empty($data['@graph'])) {
  fwrite(STDERR, "Production home JSON-LD invalid\n");
  exit(1);
}
$types = [];
foreach ($data['@graph'] as $item) {
  foreach ((array)($item['@type'] ?? []) as $type) $types[$type] = true;
}
foreach (['RadioStation','WebSite','WebPage','FAQPage','EventSeries','MusicEvent'] as $type) {
  if (!isset($types[$type])) {
    fwrite(STDERR, "Missing structured data type: {$type}\n");
    exit(1);
  }
}
$feedSource = (string)file_get_contents(dirname(__DIR__) . '/includes/home-program-feed.php');
if (!str_contains($feedSource, "'today' => \$todayFeed") ||
    !str_contains($feedSource, "'generated_at'") ||
    !str_contains((string)file_get_contents(dirname(__DIR__) . '/index.php'), "program_feed")) {
  fwrite(STDERR, "The existing public program_feed shape must remain accessible\n");
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
/* Brand-cache regression: F5 must use content-addressed imagery, not the
   previously cached unversioned logo URL, and PWA cache must prefer network. */
if (substr_count($html, '/assets/img/deseoradio-logo.png?v=') < 4 ||
    str_contains($html, 'src="/assets/img/deseoradio-logo.png"') ||
    !str_contains($html, 'rel="preload" href="/assets/img/deseoradio-logo.png?v=') ||
    !str_contains($html, '/assets/js/home.js?v=') ||
    str_contains($html, '/assets/js/mydemo.js') ||
    str_contains($html, '/mydemo.php') ||
    !str_contains((string)file_get_contents(dirname(__DIR__) . '/index.php'), "includes/homepage.php")) {
    fwrite(STDERR, "Stale logo, retired preview, or missing production bundle detected\n");
    exit(1);
}
$worker = (string)file_get_contents(dirname(__DIR__) . '/sw.js');
$rules = (string)file_get_contents(dirname(__DIR__) . '/.htaccess');
$robots = (string)file_get_contents(dirname(__DIR__) . '/robots.txt');
$renderer = (string)file_get_contents(dirname(__DIR__) . '/includes/homepage.php');
if (!str_contains($worker, 'deseo-static-v10') ||
    !str_contains($worker, "fetch(request, { cache: 'no-cache' })") ||
    !str_contains($worker, 'assets/js/home.js') ||
    !str_contains($renderer, "hash_file('sha256', \$brandLogoPath)") ||
    !str_contains($rules, 'RewriteRule ^mydemo') ||
    !str_contains($rules, '[R=301,L,NE]') ||
    str_contains($robots, 'Disallow: /mydemo') ||
    !str_contains((string)file_get_contents(dirname(__DIR__) . '/manifest.json'), '20261010-s6') ||
    is_file(dirname(__DIR__) . '/mydemo.php')) {
    fwrite(STDERR, "PWA cache, retired preview redirect or brand version contract broken\n");
    exit(1);
}
/* Homepage editorial order: player > program > artists > radar > playlists
   > platforms, then all remaining sections in their previous relative order. */
$orderedSections = [
    '<section class="md-hero" id="home">',
    '<section class="md-section md-schedule" id="schedule">',
    '<section class="md-section md-season" id="lineup">',
    '<section class="md-section md-tracks" id="tracks">',
    '<section class="md-section md-playlists" id="playlists">',
    '<section class="md-section md-listen-everywhere" id="listen-everywhere"',
    '<section class="md-section md-about-experience" id="about"',
    '<div class="md-marquee"',
    '<section class="md-section md-shows" id="shows">',
    '<section class="md-manifesto"',
    '<section class="md-section md-faq-section" id="faq"',
    '<div class="md-ai-wrap">',
];
$lastOffset = -1;
foreach ($orderedSections as $marker) {
    $offset = strpos($html, $marker);
    if ($offset === false || $offset <= $lastOffset || substr_count($html, $marker) !== 1) {
        fwrite(STDERR, "Homepage section order incorrect or duplicated: {$marker}\n");
        exit(1);
    }
    $lastOffset = $offset;
}
if (!preg_match('~<nav class="md-fs-menu-links"[^>]*>(.*?)</nav>~s', $html, $menuMatch) ||
    !preg_match_all('~href="([^"]+)"~', $menuMatch[1], $menuLinks) ||
    $menuLinks[1] !== [
        '#player', '#schedule', '#lineup', '#tracks', '#playlists',
        '#listen-everywhere', '#about', '#shows', '#faq', 'mailto:radio@iluma.gr',
    ]) {
    fwrite(STDERR, "Fullscreen section navigation order is inconsistent\n");
    exit(1);
}
if (!preg_match('~<div class="deseo-context-links">(.*?)</div>~s', $html, $contextMatch) ||
    !preg_match_all('~href="([^"]+)"~', $contextMatch[1], $contextLinks) ||
    $contextLinks[1] !== [
        '#player', '#live', '#schedule', '#lineup', '#tracks', '#playlists',
        '#listen-everywhere', '#about', '#shows', '#faq', '#ask-ai', '#contact',
    ]) {
    fwrite(STDERR, "Context section navigation order is inconsistent\n");
    exit(1);
}
/* DJ modal must show a generous portrait on phones, preserving 3:4 ratio,
   normal scrolling for smaller viewports, and responsive desktop grid. */
$mobileModalStyles = (string)file_get_contents(dirname(__DIR__) . '/assets/css/home.css');
$profileJs = (string)file_get_contents(dirname(__DIR__) . '/assets/js/home.js');
if (!preg_match('~@media\\s*\\(max-width:760px\\)[\\s\\S]*?#md-dj-dialog>img\\s*\\{([^}]+)\\}~', $mobileModalStyles, $photoRules) ||
    !str_contains($photoRules[1], 'aspect-ratio:3 / 4!important;') ||
    !str_contains($photoRules[1], 'height:auto!important;') ||
    !str_contains($photoRules[1], 'max-height:none!important;') ||
    !str_contains($photoRules[1], 'object-fit:cover!important;') ||
    !str_contains($mobileModalStyles, 'max-height:calc(100dvh - 18px)!important;') ||
    !str_contains($profileJs, "image.loading = 'eager';") ||
    !str_contains($profileJs, "image.fetchPriority = 'high';")) {
    fwrite(STDERR, "Mobile DJ profile portrait or modal overflow regression\n");
    exit(1);
}
echo "Production home: SEO canonicals/OG/hreflang/JSON-LD, CMS content, Signal, consent and legacy feed OK\n";
