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
echo "Production home: SEO canonicals/OG/hreflang/JSON-LD, CMS content, Signal, consent and legacy feed OK\n";
