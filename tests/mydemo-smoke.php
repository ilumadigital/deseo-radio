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
echo "mydemo: isolated preview, public sections, playback embed, and crawl exclusion OK\n";
