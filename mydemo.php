<?php
declare(strict_types=1);

/**
 * Deseo Radio /mydemo — isolated creative preview.
 * READ-ONLY CMS queries. No MyLive jobs, migrations or scheduled tasks.
 * Deliberately absent from the production navigation and search metadata.
 */
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex, max-image-preview:none', true);
header('Referrer-Policy: no-referrer');

require_once __DIR__ . '/includes/i18n.php';

function demo_e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
/* The shared AI discovery include also renders on the production homepage.
 * It expects deseo_e(), which is normally provided by the homepage bootstrap. */
if (!function_exists('deseo_e')) {
    function deseo_e($value): string {
        return demo_e($value);
    }
}
function demo_url($value): string {
    $url = trim((string)$value);
    if ($url === '') return '';
    if ($url[0] === '/' && !str_starts_with($url, '//') && !str_contains($url, '\\')) return $url;
    if (preg_match('~^https://[a-z0-9.-]+(?::[0-9]+)?(?:[/?#]|$)~i', $url)) return $url;
    return '';
}
function demo_photo($value): string {
    $value = trim((string)$value);
    if ($value !== '' && $value[0] !== '/' && !preg_match('~^https?://~i', $value)) {
        $value = '/' . ltrim($value, '/');
    }
    return demo_url($value) ?: '/assets/img/bg.png';
}
function demo_clock($time): string {
    return preg_match('/^\d{2}:\d{2}/', (string)$time) ? substr((string)$time, 0, 5) : '--:--';
}

$lang = deseo_lang();
$en = $lang === 'en';
$copy = $en ? [
    'hero' => 'The Soundtrack of your life!',
    'hero_sub' => 'Nothing but music that stays with you.',
    'listen' => 'LISTEN LIVE', 'explore' => 'EXPLORE THE SOUND',
    'now' => 'NOW ON AIR', 'auto' => 'DESEO NON-STOP', 'next' => 'UP NEXT',
    'schedule' => 'THE WEEKLY LINEUP', 'schedule_sub' => 'The DJs, the sets, the selections. A different soundtrack for every day of your week. All times Athens.',
    'premiere' => 'SEASON 6 STARTS IN', 'launched' => 'SEASON 6 · ON AIR NOW',
    'lineup' => 'MEET THE LINEUP', 'lineup_sub' => 'More than names on a schedule. The people who give each week its own sound. Choose a day, discover their sets.',
    'tracks' => 'FRESH SOUNDS', 'tracks_sub' => 'The sounds that stay with you. Discover the latest Deseo Hot Tracks, then find them on Spotify.',
    'playlists' => 'YOUR NEXT MOOD', 'playlists_sub' => 'From the first light to the last hours of the night. Curated playlists for every version of you.',
    'podcasts' => 'THE SHOW GOES ON.', 'podcasts_sub' => 'Some sets deserve another listen. Relive the moments through our DJ sets and official podcast channels.',
    'brand' => 'WE ARE DESEO.', 'brand_sub' => 'Every road, every night, every moment has its own rhythm. The soundtrack of your life.',
    'follow' => 'STAY ON OUR FREQUENCY', 'all' => 'ALL WEEK', 'nonstop' => '24/7 NON-STOP MUSIC',
    'open' => 'OPEN ON SPOTIFY', 'empty_tracks' => 'New Hot Tracks are coming soon.',
    'empty_playlists' => 'New playlists are coming soon.', 'empty_program' => 'Non-stop music all day.',
    'dj_info' => 'DJ PROFILE', 'dj_close' => 'Close DJ profile',
    'sponsor' => 'WITH THE SUPPORT OF', 'listen_anywhere' => 'LISTEN EVERYWHERE',
    'return' => 'ORIGINAL WEBSITE', 'preview' => 'PRIVATE DESIGN PREVIEW',
    'friday' => 'FRIDAY', 'read_more' => 'VIEW PROFILE',
] : [
    'hero' => 'Το Soundtrack της ζωής σου!',
    'hero_sub' => 'Παίζουμε μόνο μουσικάρες για την κάθε σου στιγμή.',
    'listen' => 'ΑΚΟΥ LIVE', 'explore' => 'ΑΝΑΚΑΛΥΨΕ ΤΟΝ ΗΧΟ',
    'now' => 'ΣΤΟΝ ΑΕΡΑ ΤΩΡΑ', 'auto' => 'DESEO NON-STOP', 'next' => 'ΣΤΗ ΣΥΝΕΧΕΙΑ',
    'schedule' => 'THE WEEKLY LINEUP', 'schedule_sub' => 'Οι DJs, τα sets και οι μουσικές επιλογές που δίνουν ρυθμό σε κάθε εβδομάδα. Όλες οι ώρες είναι ώρα Ελλάδας.',
    'premiere' => 'Η SEASON 6 ΞΕΚΙΝΑ ΣΕ', 'launched' => 'SEASON 6 · ON AIR NOW',
    'lineup' => 'ΓΝΩΡΙΣΕ ΤΟΥΣ DJs', 'lineup_sub' => 'Περισσότερο από ονόματα στο πρόγραμμα. Οι άνθρωποι που δίνουν σε κάθε εβδομάδα τη δική της μουσική ταυτότητα.',
    'tracks' => 'FRESH SOUNDS', 'tracks_sub' => 'Οι ήχοι που ξεχωρίζουν τώρα. Τα Hot Tracks που δίνουν ρυθμό στη μέρα και μένουν μαζί σου.',
    'playlists' => 'Η ΔΙΚΗ ΣΟΥ ΔΙΑΘΕΣΗ', 'playlists_sub' => 'Από το πρώτο φως μέχρι τις τελευταίες ώρες της νύχτας. Μουσική για κάθε διάθεση, με την υπογραφή του Deseo.',
    'podcasts' => 'THE SHOW GOES ON.', 'podcasts_sub' => 'Κάποια sets αξίζει να τα ξαναζήσεις. Οι στιγμές που ξεχώρισες συνεχίζονται στα επίσημα podcast κανάλια μας.',
    'brand' => 'WE ARE DESEO.', 'brand_sub' => 'Κάθε διαδρομή, κάθε βράδυ, κάθε στιγμή έχει τον δικό της ήχο. Το Soundtrack της ζωής σου!',
    'follow' => 'ΜΕΙΝΕ ΣΤΟΝ ΗΧΟ ΜΑΣ', 'all' => 'ΟΛΗ ΤΗΝ ΕΒΔΟΜΑΔΑ', 'nonstop' => '24/7 NON-STOP MUSIC',
    'open' => 'ΑΝΟΙΓΜΑ ΣΤΟ SPOTIFY', 'empty_tracks' => 'Νέα Hot Tracks έρχονται σύντομα.',
    'empty_playlists' => 'Νέες playlists έρχονται σύντομα.', 'empty_program' => 'Non-stop μουσική όλη μέρα.',
    'dj_info' => 'ΠΡΟΦΙΛ DJ', 'dj_close' => 'Κλείσιμο προφίλ',
    'sponsor' => 'ΜΕ ΤΗΝ ΥΠΟΣΤΗΡΙΞΗ', 'listen_anywhere' => 'ΑΚΟΥ ΠΑΝΤΟΥ',
    'return' => 'ΚΑΝΟΝΙΚΟ SITE', 'preview' => 'PRIVATE DESIGN PREVIEW',
    'friday' => 'ΠΑΡΑΣΚΕΥΗ', 'read_more' => 'ΔΕΣ ΠΡΟΦΙΛ',
];

$tz = new DateTimeZone('Europe/Athens');
$now = new DateTimeImmutable('now', $tz);
$seasonStart = new DateTimeImmutable('2026-10-14 20:00:00', $tz);
$days = [1 => ['MO', 'Monday'], 2 => ['TU', 'Tuesday'], 3 => ['WE', 'Wednesday'], 4 => ['TH', 'Thursday'], 5 => ['FR', 'Friday'], 6 => ['SA', 'Saturday'], 7 => ['SU', 'Sunday']];
$activeDay = (int)$now->format('N');

$program = [];
$tracks = [];
$playlists = [];
$profiles = [];
$dbOnline = false;

try {
    require __DIR__ . '/iluma/connection.php';
    $dbOnline = true;
    try {
        $program = $pdo->query(
            'SELECT id, dj_name, photo_path, mylive_account_id, day_of_week, start_time, end_time
             FROM program WHERE day_of_week BETWEEN 1 AND 7
             ORDER BY day_of_week ASC, start_time ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $columnError) {
        $program = $pdo->query(
            'SELECT id, dj_name, photo_path, NULL AS mylive_account_id, day_of_week, start_time, end_time
             FROM program WHERE day_of_week BETWEEN 1 AND 7
             ORDER BY day_of_week ASC, start_time ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }
    try {
        $tracks = $pdo->query(
            'SELECT id, spotify_url, track_name, artist_name, artwork_url, position
             FROM airplay WHERE position BETWEEN 1 AND 10 ORDER BY position ASC LIMIT 10'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $error) {
        error_log('mydemo airplay unavailable: ' . $error->getMessage());
    }
    try {
        $playlists = $pdo->query(
            'SELECT id, spotify_url, title, artwork_url, position
             FROM playlists ORDER BY position ASC, id DESC LIMIT 12'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $error) {
        error_log('mydemo playlists unavailable: ' . $error->getMessage());
    }
    $ids = array_values(array_unique(array_filter(array_map(
        static fn(array $row): int => (int)($row['mylive_account_id'] ?? 0),
        $program
    ))));
    if ($ids) {
        try {
            $stmt = $pdo->prepare(
                'SELECT a.id AS account_id, a.artist_name, p.published_bio AS bio,
                        p.published_instagram AS instagram, p.published_tiktok AS tiktok,
                        p.published_soundcloud AS soundcloud, p.published_spotify AS spotify,
                        p.published_website AS website
                 FROM dj_portal_accounts a
                 INNER JOIN dj_public_profiles p ON p.account_id = a.id
                 WHERE a.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
                   AND a.is_active = 1 AND a.account_status = "active"
                   AND a.public_profile_enabled = 1 AND p.is_published = 1'
            );
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $profile) {
                $profiles[(int)$profile['account_id']] = $profile;
            }
        } catch (Throwable $error) {
            error_log('mydemo DJ profiles unavailable: ' . $error->getMessage());
        }
    }
} catch (Throwable $error) {
    error_log('mydemo read-only CMS unavailable: ' . $error->getMessage());
}

$weekStart = $now->modify('monday this week')->setTime(0, 0);
$occurrences = [];
$showsByDay = array_fill_keys(array_keys($days), []);
foreach ($program as $show) {
    $day = (int)$show['day_of_week'];
    if (!isset($days[$day])) continue;
    $showsByDay[$day][] = $show;
    $startPieces = array_map('intval', explode(':', (string)$show['start_time']));
    $endPieces = array_map('intval', explode(':', (string)$show['end_time']));
    foreach ([-7, 0, 7, 14] as $offset) {
        $base = $weekStart->modify(($offset + $day - 1) . ' days');
        $start = $base->setTime($startPieces[0] ?? 0, $startPieces[1] ?? 0, $startPieces[2] ?? 0);
        $end = $base->setTime($endPieces[0] ?? 23, $endPieces[1] ?? 59, $endPieces[2] ?? 59);
        if ($end <= $start) $end = $end->modify('+1 day');
        // Like the production homepage, keep daily music zones live before the Season 6 premiere.
        $occurrences[] = ['row' => $show, 'start' => $start, 'end' => $end];
    }
}
usort($occurrences, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);
$live = null;
$next = null;
foreach ($occurrences as $item) {
    if ($live === null && $item['start'] <= $now && $now < $item['end']) {
        $live = $item;
    } elseif ($item['start'] > $now && $next === null) {
        $next = $item;
    }
}
$toPublicShow = static function (?array $item): ?array {
    if (!$item) return null;
    return [
        'id' => (int)$item['row']['id'],
        'name' => (string)$item['row']['dj_name'],
        'photo' => demo_photo($item['row']['photo_path'] ?? ''),
        'start' => $item['start']->format(DATE_ATOM),
        'end' => $item['end']->format(DATE_ATOM),
        'time' => demo_clock($item['row']['start_time']) . '—' . demo_clock($item['row']['end_time']),
    ];
};
if (($_GET['feed'] ?? '') === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok' => $dbOnline,
        'now' => $now->format(DATE_ATOM),
        'live' => $toPublicShow($live),
        'next' => $toPublicShow($next),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
$liveShow = $toPublicShow($live);
$nextShow = $toPublicShow($next);
$cssVersion = is_file(__DIR__ . '/assets/css/mydemo.css') ? (int)filemtime(__DIR__ . '/assets/css/mydemo.css') : 1;
$jsVersion = is_file(__DIR__ . '/assets/js/mydemo.js') ? (int)filemtime(__DIR__ . '/assets/js/mydemo.js') : 1;
?>
<!doctype html>
<html lang="<?= demo_e($lang) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="robots" content="noindex,nofollow,noarchive,nosnippet,noimageindex,max-image-preview:none">
  <meta name="googlebot" content="noindex,nofollow,noarchive,nosnippet,noimageindex">
  <meta name="bingbot" content="noindex,nofollow,noarchive,nosnippet,noimageindex">
  <meta name="referrer" content="no-referrer">
  <meta name="theme-color" content="#090808">
  <meta name="color-scheme" content="dark">
  <title>Deseo Radio — Design Preview / Season 06</title>
  <meta name="deseo-demo-build" content="v15-menu-spacing-and-widgets">
  <link rel="icon" type="image/png" href="/assets/img/favicon.png?v=<?= is_file(__DIR__ . '/assets/img/favicon.png') ? (int)filemtime(__DIR__ . '/assets/img/favicon.png') : 1 ?>">
  <link rel="stylesheet" href="/assets/css/mydemo.css?v=<?= $cssVersion ?>">
  <link rel="stylesheet" href="/assets/css/mydemo-player.css?v=<?= is_file(__DIR__ . '/assets/css/mydemo-player.css') ? (int)filemtime(__DIR__ . '/assets/css/mydemo-player.css') : 1 ?>">
  <link rel="stylesheet" href="/assets/css/mydemo-refinements.css?v=<?= is_file(__DIR__ . '/assets/css/mydemo-refinements.css') ? (int)filemtime(__DIR__ . '/assets/css/mydemo-refinements.css') : 1 ?>">
  <link rel="stylesheet" href="/assets/css/mydemo-v6.css?v=<?= is_file(__DIR__ . '/assets/css/mydemo-v6.css') ? (int)filemtime(__DIR__ . '/assets/css/mydemo-v6.css') : 1 ?>">
  <link rel="stylesheet" href="/assets/css/mydemo-v7.css?v=<?= is_file(__DIR__ . '/assets/css/mydemo-v7.css') ? (int)filemtime(__DIR__ . '/assets/css/mydemo-v7.css') : 1 ?>">
  <link rel="stylesheet" href="/assets/css/mydemo-v8.css?v=<?= is_file(__DIR__ . '/assets/css/mydemo-v8.css') ? (int)filemtime(__DIR__ . '/assets/css/mydemo-v8.css') : 1 ?>">
  <link rel="stylesheet" href="/assets/css/mydemo-v9.css?v=<?= is_file(__DIR__ . '/assets/css/mydemo-v9.css') ? (int)filemtime(__DIR__ . '/assets/css/mydemo-v9.css') : 1 ?>">
  <link rel="stylesheet" href="/assets/css/mydemo-v10.css?v=<?= is_file(__DIR__ . '/assets/css/mydemo-v10.css') ? (int)filemtime(__DIR__ . '/assets/css/mydemo-v10.css') : 1 ?>">
  <link rel="stylesheet" href="/assets/css/mydemo-v11.css?v=<?= is_file(__DIR__ . '/assets/css/mydemo-v11.css') ? (int)filemtime(__DIR__ . '/assets/css/mydemo-v11.css') : 1 ?>">
  <link rel="stylesheet" href="/assets/css/mydemo-v12.css?v=<?= is_file(__DIR__ . '/assets/css/mydemo-v12.css') ? (int)filemtime(__DIR__ . '/assets/css/mydemo-v12.css') : 1 ?>">
  <link rel="stylesheet" href="/assets/css/mydemo-v13.css?v=<?= is_file(__DIR__ . '/assets/css/mydemo-v13.css') ? (int)filemtime(__DIR__ . '/assets/css/mydemo-v13.css') : 1 ?>">
  <link rel="stylesheet" href="/assets/css/mydemo-v14.css?v=<?= is_file(__DIR__ . '/assets/css/mydemo-v14.css') ? (int)filemtime(__DIR__ . '/assets/css/mydemo-v14.css') : 1 ?>">
  <link rel="stylesheet" href="/assets/css/mydemo-v15.css?v=<?= is_file(__DIR__ . '/assets/css/mydemo-v15.css') ? (int)filemtime(__DIR__ . '/assets/css/mydemo-v15.css') : 1 ?>">
  <link rel="preload" as="image" href="/assets/img/favicon-nobg.png">
  <link rel="preload" as="image" href="/assets/img/deseo-logo.png">
  <link rel="preload" as="image" href="/assets/img/deseoradio-logo.png">
  <script>document.documentElement.classList.add('md-preloading');window.setTimeout(function(){document.documentElement.classList.remove('md-preloading');},910);</script>
  <script src="/assets/js/mydemo.js?v=<?= $jsVersion ?>" defer></script>
  <script src="/assets/js/mydemo-player.js?v=<?= is_file(__DIR__ . '/assets/js/mydemo-player.js') ? (int)filemtime(__DIR__ . '/assets/js/mydemo-player.js') : 1 ?>" defer></script>
  <script src="/assets/js/mydemo-header.js?v=<?= is_file(__DIR__ . '/assets/js/mydemo-header.js') ? (int)filemtime(__DIR__ . '/assets/js/mydemo-header.js') : 1 ?>" defer></script>
  <script src="/assets/js/mydemo-cursor.js?v=<?= is_file(__DIR__ . '/assets/js/mydemo-cursor.js') ? (int)filemtime(__DIR__ . '/assets/js/mydemo-cursor.js') : 1 ?>" defer></script>
  <script src="/assets/js/mydemo-menu.js?v=<?= is_file(__DIR__ . '/assets/js/mydemo-menu.js') ? (int)filemtime(__DIR__ . '/assets/js/mydemo-menu.js') : 1 ?>" defer></script>
  <script src="https://radios.iluma.gr/signal/v1/signal.js?v=1.1.2" data-station="deseo" data-surface="station_website" defer></script>
</head>
<body>
<a class="md-skip" href="#main">Skip to content</a>
<div class="md-noise" aria-hidden="true"></div>
<div class="md-custom-cursor" id="md-custom-cursor" aria-hidden="true"></div>
<div class="md-preloader" aria-hidden="true">
  <div class="md-preloader-stage">
    <div class="md-preloader-glow" aria-hidden="true"></div>
    <div class="md-preloader-symbols">
      <img class="md-preloader-mark" src="/assets/img/favicon-nobg.png" width="100" height="100" alt="">
      <img class="md-preloader-wordmark" src="/assets/img/deseo-logo.png" width="220" height="100" alt="">
      <img class="md-preloader-final" src="/assets/img/deseoradio-logo.png" width="220" height="100" alt="">
    </div>
    <span class="md-preloader-caption">DESEO / FEEL THE FREQUENCY</span>
    <span class="md-preloader-line"></span>
  </div>
</div>

<header class="md-header">
  <div class="md-shell md-header-inner">
    <a class="md-logo" href="#home" aria-label="Deseo Radio demo home"><img src="/assets/img/deseoradio-logo.png" alt="Deseo Radio" width="220" height="100" fetchpriority="high"></a>
    <div class="md-header-actions">
      <div class="md-lang" aria-label="Language">
        <a <?= !$en ? 'aria-current="page"' : '' ?> href="/mydemo?lang=el">EL</a>
        <span>/</span>
        <a <?= $en ? 'aria-current="page"' : '' ?> href="/mydemo?lang=en">EN</a>
      </div>
      <button type="button" class="md-menu-trigger" id="md-menu-trigger" aria-controls="md-fs-menu" aria-expanded="false" aria-haspopup="dialog" aria-label="Open menu"><span class="md-menu-trigger-label">MENU</span><span class="md-menu-bars" aria-hidden="true"><i></i><i></i></span></button>
    </div>
  </div>
</header>
<div class="md-fs-menu" id="md-fs-menu" role="dialog" aria-modal="true" aria-labelledby="md-fs-menu-title" aria-hidden="true" hidden>
  <div class="md-fs-menu-glow" aria-hidden="true"></div>
  <div class="md-fs-menu-inner">
    <div class="md-fs-menu-top">
      <a class="md-fs-menu-brand" href="#home" aria-label="Deseo Radio — home"><img src="/assets/img/deseoradio-logo.png" alt="Deseo Radio" width="220" height="100" fetchpriority="high"></a>
      <span class="md-fs-menu-overline"><span class="md-dot"></span> DESEO RADIO / SEASON 06</span>
      <button type="button" class="md-fs-close" id="md-fs-close" aria-label="<?= $en ? 'Close menu' : 'Κλείσιμο μενού' ?>"><span><?= $en ? 'CLOSE' : 'ΚΛΕΙΣΙΜΟ' ?></span><i aria-hidden="true"></i></button>
    </div>
    <div class="md-fs-menu-main">
      <div class="md-fs-menu-nav"><h2 class="md-fs-menu-title" id="md-fs-menu-title"><span class="md-fs-title-line"><span class="md-fs-title-outline">THE</span> <span class="md-fs-title-red">SOUNDTRACK</span></span><span class="md-fs-title-line"><span class="md-fs-title-outline">OF YOUR</span> <span class="md-fs-title-white">LIFE</span></span></h2>
        <nav class="md-fs-menu-links" aria-label="Deseo Radio sections">
          <a href="#player"><span class="md-fs-menu-count">01</span>JUST LISTEN<span class="md-fs-link-mark" aria-hidden="true"></span></a>
          <a href="#listen-everywhere"><span class="md-fs-menu-count">02</span>PARTNERS<span class="md-fs-link-mark" aria-hidden="true"></span></a>
          <a href="#about"><span class="md-fs-menu-count">03</span>ABOUT US<span class="md-fs-link-mark" aria-hidden="true"></span></a>
          <a href="#lineup"><span class="md-fs-menu-count">04</span>LINEUP<span class="md-fs-link-mark" aria-hidden="true"></span></a>
          <a href="#schedule"><span class="md-fs-menu-count">05</span>PROGRAM<span class="md-fs-link-mark" aria-hidden="true"></span></a>
          <a href="#tracks"><span class="md-fs-menu-count">06</span>RELEASE RADAR<span class="md-fs-link-mark" aria-hidden="true"></span></a>
          <a href="#playlists"><span class="md-fs-menu-count">07</span>PLAYLISTS<span class="md-fs-link-mark" aria-hidden="true"></span></a>
          <a href="#shows"><span class="md-fs-menu-count">08</span>RADIOSHOWS<span class="md-fs-link-mark" aria-hidden="true"></span></a>
          <a href="#faq"><span class="md-fs-menu-count">09</span>FAQ<span class="md-fs-link-mark" aria-hidden="true"></span></a>
          <a href="mailto:radio@iluma.gr"><span class="md-fs-menu-count">10</span>CONTACT<span class="md-fs-link-mark" aria-hidden="true"></span></a>
        </nav>
      </div>
      <aside class="md-fs-menu-side">
        <a class="md-menu-showcase md-menu-feature" href="#schedule" aria-label="DJ SA Radioshow — Every weekend at 17:00">
          <span class="md-menu-showcase-head">DESEO / FEATURED ON AIR <span class="md-fs-link-mark" aria-hidden="true"></span></span>
          <span class="md-menu-feature-art">
            <img src="https://deseoradio.com/iluma/uploads/djs/profile-85-DJ_SA_RADIOSHOW-20261007-144654-0c15cd.png" alt="DJ SA Radioshow" loading="eager" decoding="async" onerror="this.onerror=null;this.src='/assets/img/deseoradio-djcallwebsite.png'">
            <span class="md-menu-promo-shade"></span>
            <span class="md-menu-promo-content"><small>DESEO / RESIDENT DJS</small>
              <strong>DJ SA<br>RADIOSHOW</strong><em>EVERY WEEKEND <b>@ 17:00</b></em></span>
          </span>
        </a>
      </aside>
    </div>
    <div class="md-fs-menu-bottom"><span>ATHENS / WORLDWIDE — 24/7 SOUND</span><span>AN <a href="https://iluma.gr/" target="_blank" rel="noopener noreferrer">ILUMA RADIOS</a> EXPERIENCE</span></div>
  </div>
</div>

<main id="main">
<section class="md-hero" id="home">
  <div class="md-hero-photo" aria-hidden="true"></div>
  <div class="md-hero-rings" aria-hidden="true"><span></span><span></span><span></span></div>
  <div class="md-hero-gridlines" aria-hidden="true"></div>
  <div class="md-hero-citymark" aria-hidden="true"><span>DESEO / ATH</span><strong>06</strong><span>THE CITY HAS A SOUND.</span></div>
  <div class="md-shell md-hero-content">
    <div class="md-hero-grid">
      <div id="player" class="md-player-home">
        <aside class="md-custom-player" id="md-custom-player" aria-label="<?= $en ? 'Deseo live player' : 'Ζωντανή ακρόαση Deseo Radio' ?>">
          <div class="md-custom-inner">
            <div class="md-player-squares">
              <div class="md-nowplaying-column">
                <div class="md-player-square-label">NOW PLAYING</div>
                <div class="md-nowplaying-frame">
                  <iframe id="md-iradios-player" src="https://play.iradios.gr/widget/deseo-radio?autoplay=true"
                    width="100%" frameborder="0" loading="eager"
                    title="<?= $en ? 'Official Deseo Radio live player' : 'Επίσημος ζωντανός player Deseo Radio' ?>"
                    allow="autoplay; encrypted-media; clipboard-write"
                    referrerpolicy="strict-origin-when-cross-origin"></iframe>
                </div>
              </div>
              <div class="md-sponsor-column">
                <div class="md-player-square-label">SPONSOR</div>
                <a class="md-custom-sponsor" href="https://iluma.gr/" target="_blank" rel="noopener noreferrer"
                  data-iluma-signal-slot="hero-sponsor" aria-label="ILUMA Digital Agency — sponsor">
                  <img src="/assets/img/iluma-digital-agency-banner.jpg" alt="ILUMA Digital Agency"
                    data-iluma-signal-image loading="eager">
                </a>
              </div>
              <div class="md-dj-column">
                <div class="md-player-square-label">ONAIR NOW</div>
                <div class="md-dj-artwork">
                  <img id="md-hero-live-photo" src="<?= demo_e($liveShow['photo'] ?? '/assets/img/bg.png') ?>"
                    alt="" loading="eager" onerror="this.onerror=null;this.src='/assets/img/bg.png'">
                  <div class="md-dj-artwork-caption">
                    <span class="md-dj-live-tag"><i></i> ON AIR / DESEO</span>
                    <strong id="md-hero-live-name"><?= demo_e($liveShow['name'] ?? $copy['auto']) ?></strong>
                    <small id="md-hero-live-time"><?= demo_e($liveShow['time'] ?? '24 / 7') ?></small>
                  </div>
                </div>
                <div class="md-custom-next">
                  <span>COMING UP NEXT</span>
                  <strong id="md-hero-next-name"><?= demo_e($nextShow['name'] ?? $copy['nonstop']) ?></strong>
                </div>
              </div>
            </div>
          </div>
        </aside>
      </div>
      <div class="md-hero-copy">
        <h1 class="md-masthead md-brand-headline" aria-label="The Soundtrack of Your Life">
          <span class="md-word-the">THE</span>
          <span class="md-word-soundtrack">SOUNDTRACK</span>
          <span class="md-word-of">OF YOUR</span>
          <span class="md-word-life">LIFE</span>
        </h1>
      </div>
    </div>
  </div>
  <div class="md-hero-border md-shell"><a class="md-hero-powered" href="https://radios.iluma.gr/" target="_blank" rel="noopener noreferrer" aria-label="Powered by ILUMA Radios — radios.iluma.gr">Powered by <strong>ILUMA Radios</strong><span class="md-hero-powered-arrow" aria-hidden="true">↗</span></a></div>
</section>

<div id="md-player-dock" class="md-dock" hidden>
  <div class="md-dock-top"><span><i class="md-dot"></i> DESEO / NOW ON AIR</span>
    <a href="#player" class="md-dock-expand" aria-label="<?= $en ? 'Back to live player' : 'Επιστροφή στον player' ?>"><span class="md-ui-arrow" aria-hidden="true"></span></a>
  </div>
  <div class="md-dock-body">
    <a href="#player" class="md-dock-program">
      <img id="md-dock-photo" src="<?= demo_e($liveShow['photo'] ?? '/assets/img/bg.png') ?>" alt=""
        loading="lazy" onerror="this.onerror=null;this.src='/assets/img/bg.png'">
      <span class="md-dock-program-copy"><small>DESEO / LIVE</small>
        <strong id="md-dock-show"><?= demo_e($liveShow['name'] ?? $copy['auto']) ?></strong>
        <span id="md-dock-time"><?= demo_e($liveShow['time'] ?? '24 / 7') ?></span>
      </span>
    </a>
    <a class="md-dock-sponsor" href="https://iluma.gr/" target="_blank" rel="noopener noreferrer"
       data-iluma-signal-slot="sticky-sponsor" aria-label="ILUMA Digital Agency — sponsor">
       <img src="/assets/img/iluma-digital-agency-banner.jpg" data-iluma-signal-image
         alt="ILUMA Digital Agency" loading="lazy">
    </a>
  </div>
</div>

<section class="md-section md-listen-everywhere" id="listen-everywhere" aria-labelledby="md-listen-title">
  <div class="md-shell">
    <div class="md-section-top"><span class="md-index">DESEO / LISTEN EVERYWHERE</span><span>THE SOUND GOES WITH YOU</span></div>
    <div class="md-section-heading md-discovery-heading">
      <h2 id="md-listen-title"><?= $en ? 'TAKE DESEO<br><em>EVERYWHERE.</em>' : 'ΑΚΟΥ DESEO<br><em>ΠΑΝΤΟΥ.</em>' ?></h2>
      <p><?= demo_e(deseo_t('partners.text')) ?></p>
    </div>
    <?php $demoPartners = [
        ['url' => 'https://play.iradios.gr/station/deseo-radio', 'name' => 'iRadios', 'asset' => 'partner-1.png'],
        ['url' => 'https://onlineradiobox.com/gr/deseo/', 'name' => 'Online Radio Box', 'asset' => 'partner-2.png'],
        ['url' => 'https://www.getmeradio.com/stations/deseoradiogr-4835/', 'name' => 'Get Me Radio', 'asset' => 'partner-3.png'],
        ['url' => 'https://tunein.com/radio/Deseo-Radio-s258242/', 'name' => 'TuneIn', 'asset' => 'partner-5.png'],
        ['url' => 'https://vradio.app/play?id=20739', 'name' => 'VRadio', 'asset' => 'partner-6.png'],
        ['url' => 'https://iluma.gr/radios', 'name' => 'ILUMA Radios', 'asset' => 'partner-8.png'],
        ['url' => 'https://streamee.com/fm_radio/deseo-radio/', 'name' => 'Streamee', 'asset' => 'partner-9.svg'],
        ['url' => 'https://mytuner-radio.com/radio/deseo-radio-479969/', 'name' => 'myTuner Radio', 'asset' => 'partner-10.png'],
    ]; ?>
    <div class="md-partner-grid">
      <?php foreach ($demoPartners as $partner): ?>
      <a class="md-partner-card" href="<?= demo_e($partner['url']) ?>" target="_blank"
         rel="noopener noreferrer" aria-label="<?= demo_e($partner['name']) ?>">
        <img src="/assets/img/<?= demo_e($partner['asset']) ?>"
             alt="<?= demo_e($partner['name']) ?>" loading="lazy"
             onerror="this.onerror=null;this.src='/assets/img/deseoradio-logo.png'">
        <span class="md-partner-arrow md-ui-arrow" aria-hidden="true"></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<section class="md-section md-about-experience" id="about" aria-labelledby="md-about-title">
  <div class="md-shell">
    <div class="md-section-top"><span class="md-index">ABOUT / DESEO RADIO</span><span>AN ILUMA RADIOS EXPERIENCE</span></div>
    <div class="md-about-grid">
      <div class="md-about-statement">
        <h2 id="md-about-title"><?= demo_e(deseo_t('about.title')) ?></h2>
        <span class="md-about-ghost" aria-hidden="true">D/06</span>
      </div>
      <div class="md-about-body">
        <p class="md-about-lead"><?= demo_e(deseo_t('about.intro')) ?></p>
        <p><?= demo_e(deseo_t('about.created.before')) ?><strong>Deseo Radio</strong><?= demo_e(deseo_t('about.created.after')) ?></p>
        <p><?= demo_e(deseo_t('about.music')) ?></p>
        <p><?= demo_e(deseo_t('about.rhythm')) ?></p>
        <p><?= demo_e(deseo_t('about.moments')) ?></p>
        <div class="md-about-signoff"><strong><?= demo_e(deseo_t('about.promise')) ?></strong><span><?= demo_e(deseo_t('about.tagline')) ?></span></div>
        <a href="https://iluma.gr/radios" target="_blank" rel="noopener noreferrer"
           class="md-text-link"><?= demo_e(deseo_t('about.iluma')) ?> <span class="md-ui-arrow" aria-hidden="true"></span></a>
      </div>
    </div>
  </div>
</section>


<div class="md-marquee" aria-hidden="true"><div>DESEO RADIO <b>✦</b> SEASON 6 <b>✦</b> THE SOUNDTRACK OF YOUR LIFE <b>✦</b> ILUMA RADIOS <b>✦</b> GUEST DJ ZONE <b>✦</b> RESIDENT DJS <b>✦</b> DESEO RADIO <b>✦</b> SEASON 6 <b>✦</b> THE SOUNDTRACK OF YOUR LIFE <b>✦</b> ILUMA RADIOS <b>✦</b> GUEST DJ ZONE <b>✦</b> RESIDENT DJS <b>✦</b></div></div>

<section class="md-section md-season" id="lineup">
  <div class="md-shell">
    <div class="md-section-top"><span class="md-index">01 / THE ARTISTS</span><span>SEASON 06 — 2026</span></div>
    <div class="md-section-heading"><h2>NOT JUST DJs.<br><em>CULTURE MAKERS.</em></h2><p><?= demo_e($copy['lineup_sub']) ?></p></div>
    <div class="md-season-banner">
      <div class="md-season-info">
        <span class="md-tag">DESEO RADIO / SEASON 06</span>
        <p><?= demo_e($copy['premiere']) ?></p>
        <div id="md-season-countdown" data-start="<?= $seasonStart->getTimestamp() ?>" data-ended="<?= demo_e($copy['launched']) ?>">
          <div class="md-timebox"><strong data-counter="days">--</strong><span>DAYS</span></div><div class="md-timebox"><strong data-counter="hours">--</strong><span>HOURS</span></div><div class="md-timebox"><strong data-counter="minutes">--</strong><span>MINUTES</span></div>
        </div>
        <a href="#schedule" class="md-text-link"><?= demo_e($copy['schedule']) ?> <span class="md-ui-arrow" aria-hidden="true"></span></a>
      </div>
      <div class="md-season-art" aria-label="Season 6 official lineup artwork">
        <span class="md-season-vertical" aria-hidden="true">SOUND CULTURE / ATHENS</span>
        <img src="/assets/img/season6%20lineup.png" alt="Deseo Radio Season 6 official lineup" loading="lazy">

      </div>
    </div>
  </div>
</section>

<section class="md-section md-schedule" id="schedule">
  <div class="md-shell">
    <div class="md-section-top"><span class="md-index">02 / THE PROGRAM</span><span>TIMEZONE / EUROPE — ATHENS</span></div>
    <div class="md-section-heading"><h2><?= demo_e($copy['schedule']) ?><span class="md-period">.</span></h2><p><?= demo_e($copy['schedule_sub']) ?></p></div>
    <div class="md-day-tabs" role="tablist" aria-label="<?= demo_e($copy['schedule']) ?>">
      <?php foreach ($days as $dayNumber => $dayNames): ?>
        <button id="md-tab-<?= $dayNumber ?>" type="button" class="md-day-tab <?= $dayNumber === $activeDay ? 'is-active' : '' ?>" role="tab" aria-controls="md-panel-<?= $dayNumber ?>" aria-selected="<?= $dayNumber === $activeDay ? 'true' : 'false' ?>" tabindex="<?= $dayNumber === $activeDay ? '0' : '-1' ?>" data-day="<?= $dayNumber ?>" aria-label="<?= demo_e($dayNames[1]) ?>"><?= demo_e($dayNames[0]) ?></button>
      <?php endforeach; ?>
    </div>
    <div class="md-program-panels">
      <?php foreach ($days as $dayNumber => $dayNames): ?>
      <div id="md-panel-<?= $dayNumber ?>" class="md-program-panel" role="tabpanel" aria-labelledby="md-tab-<?= $dayNumber ?>" <?= $dayNumber !== $activeDay ? 'hidden' : '' ?>>
        <?php if (!$showsByDay[$dayNumber]): ?>
        <div class="md-empty"><span>∞</span><strong><?= demo_e($copy['nonstop']) ?></strong><p><?= demo_e($copy['empty_program']) ?></p><a href="#player"><?= demo_e($copy['listen']) ?> <span class="md-ui-arrow" aria-hidden="true"></span></a></div>
        <?php else: ?>
          <div class="md-show-grid">
          <?php foreach ($showsByDay[$dayNumber] as $slot):
            $profile = $profiles[(int)($slot['mylive_account_id'] ?? 0)] ?? null;
            $isLive = $liveShow && (int)$liveShow['id'] === (int)$slot['id'];
            $picture = demo_photo($slot['photo_path'] ?? '');
            $profileJson = $profile ? json_encode([
                'name' => (string)$slot['dj_name'],
                'photo' => $picture,
                'bio' => (string)($profile['bio'] ?? ''),
                'links' => array_filter([
                    'Instagram' => demo_url($profile['instagram'] ?? ''),
                    'TikTok' => demo_url($profile['tiktok'] ?? ''),
                    'SoundCloud' => demo_url($profile['soundcloud'] ?? ''),
                    'Spotify' => demo_url($profile['spotify'] ?? ''),
                    'Website' => demo_url($profile['website'] ?? ''),
                ]),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) : '';
          ?>
            <<?= $profile ? 'button' : 'article' ?> class="md-show-card <?= $isLive ? 'is-live' : '' ?>" <?= $profile ? 'type="button" data-profile="' . demo_e($profileJson) . '" aria-label="' . demo_e($copy['read_more'] . ': ' . $slot['dj_name']) . '"' : '' ?>>
              <div class="md-show-photo"><img src="<?= demo_e($picture) ?>" alt="<?= demo_e($slot['dj_name']) ?>" loading="lazy" onerror="this.onerror=null;this.src='/assets/img/bg.png'"></div>
              <div class="md-show-details">
                 <?php if ($isLive): ?><span class="md-show-status">● ON AIR</span><?php endif; ?>
                 <h3><?= demo_e($slot['dj_name']) ?></h3>
                 <div class="md-show-hours"><?= demo_clock($slot['start_time']) ?> — <?= demo_clock($slot['end_time']) ?> <small>ATHENS TIME</small></div>
                 <small>DESEO RADIOSHOW</small>
               </div>
            </<?= $profile ? 'button' : 'article' ?>>
          <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="md-section md-tracks" id="tracks">
  <div class="md-shell">
    <div class="md-section-top"><span class="md-index">03 / MUSIC DISCOVERY</span><span>THE DESEO SELECTION</span></div>
    <div class="md-section-heading"><h2>RELEASE RADAR<br><em>DISCOVERY.</em></h2><p><?= demo_e($copy['tracks_sub']) ?></p></div>
    <div class="md-track-list">
      <?php if (!$tracks): ?><p class="md-list-empty"><?= demo_e($copy['empty_tracks']) ?></p><?php endif; ?>
      <?php foreach ($tracks as $track):
        $href = demo_url($track['spotify_url'] ?? '');
        $cover = demo_url($track['artwork_url'] ?? '');
      ?>
      <div class="md-track-row">
        <span class="md-track-number"><?= str_pad((string)(int)$track['position'], 2, '0', STR_PAD_LEFT) ?></span>
        <div class="md-track-cover"><?php if ($cover): ?><img src="<?= demo_e($cover) ?>" alt="" loading="lazy" onerror="this.style.display='none'"><?php endif; ?></div>
        <div class="md-track-info"><strong><?= demo_e($track['track_name']) ?></strong><span><?= demo_e($track['artist_name']) ?></span></div>
        <span class="md-track-category">HOT TRACK / DESEO</span>
        <?php if ($href): ?><a class="md-circle-link" href="<?= demo_e($href) ?>" target="_blank" rel="noopener noreferrer" aria-label="<?= demo_e($copy['open'] . ' ' . $track['track_name']) ?>"><span class="md-ui-arrow" aria-hidden="true"></span></a><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="md-section md-playlists" id="playlists">
  <div class="md-shell">
    <div class="md-section-top"><span class="md-index">04 / DESEO CURATED</span><span>LISTEN BEYOND RADIO</span></div>
    <div class="md-section-heading"><h2>CHOOSE<br><em>YOUR MOOD.</em></h2><p><?= demo_e($copy['playlists_sub']) ?></p></div>
    <?php if (!$playlists): ?><p class="md-list-empty"><?= demo_e($copy['empty_playlists']) ?></p><?php endif; ?>
    <div class="md-playlist-grid">
      <?php foreach (array_slice($playlists, 0, 6) as $playlist):
        $href = demo_url($playlist['spotify_url'] ?? '');
        $cover = demo_url($playlist['artwork_url'] ?? '');
      ?>
      <div class="md-playlist">
        <?php if ($href): ?><a href="<?= demo_e($href) ?>" target="_blank" rel="noopener noreferrer" aria-label="<?= demo_e($copy['open'] . ': ' . $playlist['title']) ?>"><?php endif; ?>
          <div class="md-playlist-art"><?php if ($cover): ?><img src="<?= demo_e($cover) ?>" alt="" loading="lazy" onerror="this.style.display='none'"><?php endif; ?><span class="md-playlist-arrow"><i class="md-ui-arrow" aria-hidden="true"></i></span></div>
          <div class="md-playlist-meta"><span>DESEO SELECTION / <?= demo_e(str_pad((string)(int)($playlist['position'] ?? 0), 2, '0', STR_PAD_LEFT)) ?></span><strong><?= demo_e($playlist['title']) ?></strong></div>
        <?php if ($href): ?></a><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="md-section md-shows" id="shows">
  <div class="md-shell md-shows-grid">
    <div>
      <div class="md-section-top"><span class="md-index">05 / DESEO ORIGINALS</span></div>
      <h2>THE SHOW<br><em>GOES ON.</em></h2>
      <p><?= demo_e($copy['podcasts_sub']) ?></p>
      <div class="md-platform-links">
        <a href="https://hearthis.at/deseoradio/set/season-6/" target="_blank" rel="noopener noreferrer">HEARTHIS <span class="md-ui-arrow" aria-hidden="true"></span></a>
        <a href="https://www.mixcloud.com/deseoradio/" target="_blank" rel="noopener noreferrer">MIXCLOUD <span class="md-ui-arrow" aria-hidden="true"></span></a>
        <a href="https://podcasts.apple.com/us/podcast/deseo-radioshows/id1711008342" target="_blank" rel="noopener noreferrer">APPLE PODCASTS <span class="md-ui-arrow" aria-hidden="true"></span></a>
        <a href="https://open.spotify.com/show/2x8ceF2a3gMmzEJ8y6W1ue" target="_blank" rel="noopener noreferrer">SPOTIFY <span class="md-ui-arrow" aria-hidden="true"></span></a>
      </div>
    </div>
    <div class="md-shows-art"><div class="md-disc"><span><img src="/assets/img/favicon-nobg.png" alt="Deseo Radio" loading="lazy"></span></div><span class="md-disc-caption">DESEO RADIO / ALL THE FEELS / SEASON 06</span></div>
  </div>
</section>

<section class="md-manifesto" aria-label="Deseo Radio manifesto">
  <div class="md-shell">
    <p>SOMETHING IN THE AIR...</p>
    <h2 class="md-brand-monument">THE SOUNDTRACK<br><em>OF YOUR</em><br>LIFE<span>!</span></h2>
    <div class="md-manifesto-bottom"><span class="md-manifesto-origin">DESEO RADIO / ATHENS</span><p><?= demo_e($copy['brand_sub']) ?></p><a href="#player" class="md-button md-button-red"><?= demo_e($copy['listen']) ?> <span class="md-ui-arrow" aria-hidden="true"></span></a></div>
  </div>
</section>

<section class="md-section md-faq-section" id="faq" aria-labelledby="md-faq-title">
  <div class="md-shell">
    <div class="md-section-top">
      <span class="md-index">06 / DESEO UNFILTERED</span>
      <span><?= $en ? 'THE ANSWERS BEHIND THE SOUND' : 'ΟΛΑ ΓΙΑ ΤΟΝ ΗΧΟ ΜΑΣ' ?></span>
    </div>
    <div class="md-section-heading md-faq-heading">
      <h2 id="md-faq-title"><?= demo_e(deseo_t('faq.title')) ?></h2>
      <p><?= demo_e(deseo_t('faq.text')) ?></p>
    </div>
    <div class="md-faq-list">
      <?php for ($i = 1; $i <= DESEO_PUBLIC_FAQ_COUNT; $i++): ?>
        <details class="md-faq-item">
          <summary>
            <span class="md-faq-number"><?= str_pad((string)$i, 2, '0', STR_PAD_LEFT) ?></span>
            <strong><?= demo_e(deseo_t('faq.q' . $i)) ?></strong>
            <span class="md-faq-toggle" aria-hidden="true"></span>
          </summary>
          <div class="md-faq-answer"><p><?= demo_e(deseo_t('faq.a' . $i)) ?></p></div>
        </details>
      <?php endfor; ?>
    </div>
  </div>
</section>

<div class="md-ai-wrap">
  <?php require __DIR__ . '/includes/ai-discovery.php'; ?>
</div>

</main>

<footer class="md-footer" id="contact">
  <div class="md-shell">
    <div class="md-footer-top">
      <span>DESEO RADIO / ATHENS / WORLDWIDE</span>
      <span><span class="md-dot"></span> LIVE 24/7 <span class="md-footer-top-separator">·</span> HOUSE MUSIC &amp; MORE</span>
    </div>
    <div class="md-footer-main">
      <div class="md-footer-identity">
        <a class="md-footer-logo" href="#home" aria-label="Deseo Radio — home"><img src="/assets/img/deseoradio-logo.png" alt="Deseo Radio" width="175" height="50" loading="lazy"></a>
        <p class="md-footer-eyebrow">STAY TUNED. KEEP FEELING.</p>
        <h2 class="md-footer-statement">THE<br><span class="md-footer-soundtrack">SOUNDTRACK</span><br><em>OF YOUR</em><br>LIFE</h2>
      </div>
      <div class="md-footer-directory md-footer-connections">
        <span class="md-footer-connect-overline">FIND US / STAY CONNECTED</span>
        <h3><?= $en ? 'FOLLOW THE SOUND.' : 'ΜΕΙΝΕ ΣΤΟΝ ΗΧΟ.' ?></h3>
        <div class="md-footer-social-grid">
          <a href="https://www.instagram.com/deseoradio/" target="_blank" rel="noopener noreferrer"><span>INSTAGRAM</span><b class="md-ui-arrow" aria-hidden="true"></b></a>
          <a href="https://www.facebook.com/deseoradiogr/" target="_blank" rel="noopener noreferrer"><span>FACEBOOK</span><b class="md-ui-arrow" aria-hidden="true"></b></a>
          <a href="https://www.mixcloud.com/deseoradio/" target="_blank" rel="noopener noreferrer"><span>MIXCLOUD</span><b class="md-ui-arrow" aria-hidden="true"></b></a>
          <a href="https://podcasts.apple.com/us/podcast/deseo-radioshows/id1711008342" target="_blank" rel="noopener noreferrer"><span>APPLE PODCASTS</span><b class="md-ui-arrow" aria-hidden="true"></b></a>
          <a href="https://open.spotify.com/show/2x8ceF2a3gMmzEJ8y6W1ue" target="_blank" rel="noopener noreferrer"><span>SPOTIFY</span><b class="md-ui-arrow" aria-hidden="true"></b></a>
          <a href="https://iluma.gr/radios/mediakit" target="_blank" rel="noopener noreferrer"><span>MEDIA KIT</span><b class="md-ui-arrow" aria-hidden="true"></b></a>
        </div>
        <a class="md-footer-contact" href="mailto:radio@iluma.gr">GET IN TOUCH <span class="md-ui-arrow" aria-hidden="true"></span></a>
        <div class="md-footer-icon" aria-hidden="true"><img src="/assets/img/favicon-nobg.png" alt=""></div>
      </div>
    </div>
    <div class="md-footer-bottom">
      <span>© <?= $now->format('Y') ?> DESEO RADIO / ATHENS</span>
      <span class="md-footer-credit">Handcrafted by <a href="https://iluma.gr/" target="_blank" rel="noopener noreferrer">ILUMA Digital Agency</a></span>
    </div>
  </div>
</footer>
<dialog id="md-dj-dialog" aria-labelledby="md-dialog-title">
  <button type="button" id="md-dialog-close" aria-label="<?= demo_e($copy['dj_close']) ?>">×</button>
  <img id="md-dialog-photo" src="/assets/img/bg.png" alt="">
  <div class="md-dj-details"><span class="md-index"><?= demo_e($copy['dj_info']) ?> / SEASON 06</span><h2 id="md-dialog-title"></h2><p id="md-dialog-bio"></p><div id="md-dialog-links"></div></div>
</dialog>
</body>
</html>
