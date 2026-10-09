<?php
declare(strict_types=1);

/**
 * DESEO RADIO — 12 Oct 2026 launch candidate.
 *
 * Safe by default: /index-launch.php remains private/noindex and never
 * runs scheduled email jobs. When the reviewed entrypoint replaces index.php
 * at the public root, the canonical, JSON-LD, indexing and job scheduler
 * are enabled automatically. Do NOT merge into production as index.php
 * until the release checklist is green.
 */
$launchPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/index-launch.php'), PHP_URL_PATH) ?: '/index-launch.php');
$deseo_launch_public = $launchPath === '/'
    && basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'index.php';

require_once __DIR__ . '/includes/launch-2026/bootstrap.php';

$private_page = !$deseo_launch_public;
$meta_robots = $private_page
    ? 'noindex,nofollow,noarchive,nosnippet,noimageindex'
    : 'index,follow,max-image-preview:large';
$meta_canonical = 'https://deseoradio.com/';
$meta_title = deseo_lang() === 'en'
    ? 'Deseo Radio | The Soundtrack of Your Life — House Music 24/7'
    : 'Deseo Radio | Το Soundtrack της ζωής σου — House Music 24/7';
$meta_desc = deseo_lang() === 'en'
    ? 'Deseo Radio, live 24/7 from Athens. House, Afro House, Organic House, Indie Dance, the Season 6 weekly lineup, Release Radar and exclusive DJ sets.'
    : 'Deseo Radio από την Αθήνα, live 24/7 με House, Afro House, Organic House και Indie Dance. Πρόγραμμα Season 6, Release Radar και αποκλειστικά DJ sets.';
$deseo_next_layout = true;
$extra_styles = [
    '/assets/css/mydemo.css',
    '/assets/css/mydemo-player.css',
    '/assets/css/mydemo-refinements.css',
    '/assets/css/mydemo-v6.css',
    '/assets/css/mydemo-v7.css',
    '/assets/css/mydemo-v8.css',
    '/assets/css/mydemo-v9.css',
    '/assets/css/mydemo-v10.css',
    '/assets/css/mydemo-v11.css',
    '/assets/css/mydemo-v12.css',
    '/assets/css/mydemo-v13.css',
    '/assets/css/mydemo-v14.css',
    '/assets/css/mydemo-v15.css',
    '/assets/css/mydemo-v16.css',
    '/assets/css/deseo-next-consent.css',
];
$deseo_next_scripts = [
    '/assets/js/deseo-next.js',
    '/assets/js/mydemo-player.js',
    '/assets/js/mydemo-header.js',
    '/assets/js/mydemo-cursor.js',
    '/assets/js/mydemo-menu.js',
];

require_once __DIR__ . '/includes/head-meta.php';
require __DIR__ . '/includes/launch-2026/intro.php';
require __DIR__ . '/includes/launch-2026/header.php';
require __DIR__ . '/includes/launch-2026/content.php';
require __DIR__ . '/includes/launch-2026/footer.php';

if ($deseo_launch_public) {
    // Shared GA consent + marketing permission, same storage keys as legacy homepage.
    require_once __DIR__ . '/includes/cookiebanner.php';
    require __DIR__ . '/includes/launch-2026/pwa.php';
}
?>
</body>
</html>
