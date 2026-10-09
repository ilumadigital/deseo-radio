<?php
declare(strict_types=1);

$_GET = ['lang' => 'en'];
$_COOKIE = [];
$_SERVER['REQUEST_URI'] = '/index-launch.php?lang=en';
$_SERVER['SCRIPT_NAME'] = '/index-launch.php';
$deseo_launch_disable_jobs = true;

ob_start();
require __DIR__ . '/../index-launch.php';
$html = (string)ob_get_clean();
foreach ([
    '<html lang="en"',
    '<meta name="robots" content="noindex,nofollow',
    '<meta name="googlebot" content="noindex',
    'href="https://deseoradio.com/"',
    'hreflang="en"',
    'href="/index-launch.php?lang=el"',
    'href="/index-launch.php?lang=en"',
] as $needle) {
    if (!str_contains($html, $needle)) {
        fwrite(STDERR, "Launch private/English variant missing: {$needle}\n");
        exit(1);
    }
}
if (str_contains($html, '<script type="application/ld+json">')
    || str_contains($html, 'id="cookie-banner"')
    || substr_count($html, 'radios.iluma.gr/signal/v1/signal.js') !== 1) {
    fwrite(STDERR, "Private launch page must have one sponsor script but no public indexing/analytics UX\n");
    exit(1);
}
echo "Launch preview: English, private/noindex, alternate language URLs and Signal OK\n";
