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
    'id="tracks"',
    'id="playlists"',
    'id="lineup"',
    'id="shows"',
    'play.iradios.gr/widget/deseo-radio',
    '/assets/css/mydemo.css',
    '/assets/js/mydemo.js',
    'id="md-season-countdown"',
    'class="md-masthead"',
    'THE SOUND',
    'IS LIVE',
    'Το Soundtrack της ζωής σου!',
    'id="md-hero-live-name"',
    'id="md-hero-live-time"',
    'id="md-hero-live-photo"',
    'class="md-sponsor-band"',
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
if (!str_contains($js, 'md-hero-live-name')) {
    fwrite(STDERR, "mydemo hero live refresh missing\n");
    exit(1);
}
if (strpos($html, 'class="md-sponsor-band"') > strpos($html, 'id="player"')) {
    fwrite(STDERR, "mydemo sponsor should sit immediately before the player\n");
    exit(1);
}
echo "mydemo: isolated preview, public sections, playback embed, and crawl exclusion OK\n";
