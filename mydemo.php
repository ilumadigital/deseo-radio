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
    'hero' => 'THE SOUNDTRACK OF YOUR LIFE.',
    'hero_sub' => 'From Athens, for everywhere. A different kind of radio experience: House, Afro House, Organic House, Indie Dance and electronic culture, 24/7.',
    'listen' => 'LISTEN LIVE', 'explore' => 'EXPLORE THE SOUND',
    'now' => 'CURRENT RADIO SLOT', 'auto' => 'DESEO NON-STOP', 'next' => 'UP NEXT',
    'schedule' => 'THE SCHEDULE', 'schedule_sub' => 'Your week. Our frequencies. All showtimes are in Athens time (Europe/Athens).',
    'premiere' => 'SEASON 6 STARTS IN', 'launched' => 'SEASON 6 · ON AIR NOW',
    'lineup' => 'MEET THE LINEUP', 'lineup_sub' => 'The people behind the frequency. Select a day and discover the resident DJ sets.',
    'tracks' => 'FRESH SOUNDS', 'tracks_sub' => 'The Hot Tracks selected by Deseo. Open each track on Spotify.',
    'playlists' => 'YOUR NEXT MOOD', 'playlists_sub' => 'Carefully curated playlists, straight from Deseo.',
    'podcasts' => 'THE SHOW GOES ON.', 'podcasts_sub' => 'Catch up on DJ sets and radio shows through our official channels.',
    'brand' => 'WE ARE DESEO.', 'brand_sub' => 'Not just a station. A space for the music that stays with you.',
    'follow' => 'STAY ON OUR FREQUENCY', 'all' => 'ALL WEEK', 'nonstop' => '24/7 NON-STOP MUSIC',
    'open' => 'OPEN ON SPOTIFY', 'empty_tracks' => 'New Hot Tracks are coming soon.',
    'empty_playlists' => 'New playlists are coming soon.', 'empty_program' => 'Non-stop music all day.',
    'dj_info' => 'DJ PROFILE', 'dj_close' => 'Close DJ profile',
    'sponsor' => 'WITH THE SUPPORT OF', 'listen_anywhere' => 'LISTEN EVERYWHERE',
    'return' => 'ORIGINAL WEBSITE', 'preview' => 'PRIVATE DESIGN PREVIEW',
    'friday' => 'FRIDAY', 'read_more' => 'VIEW PROFILE',
] : [
    'hero' => 'ΤΟ SOUNDTRACK ΤΗΣ ΖΩΗΣ ΣΟΥ.',
    'hero_sub' => 'Από την Αθήνα, παντού. House, Afro House, Organic House, Indie Dance και ηλεκτρονική μουσική με χαρακτήρα. 24 ώρες την ημέρα.',
    'listen' => 'ΑΚΟΥ LIVE', 'explore' => 'ΑΝΑΚΑΛΥΨΕ ΤΟΝ ΗΧΟ',
    'now' => 'ΤΡΕΧΟΥΣΑ ΖΩΝΗ', 'auto' => 'DESEO NON-STOP', 'next' => 'ΣΤΗ ΣΥΝΕΧΕΙΑ',
    'schedule' => 'ΤΟ ΠΡΟΓΡΑΜΜΑ', 'schedule_sub' => 'Η εβδομάδα σου, στη συχνότητά μας. Όλες οι ώρες είναι ώρα Ελλάδας.',
    'premiere' => 'Η SEASON 6 ΞΕΚΙΝΑ ΣΕ', 'launched' => 'SEASON 6 · ON AIR NOW',
    'lineup' => 'ΓΝΩΡΙΣΕ ΤΟΥΣ DJs', 'lineup_sub' => 'Οι άνθρωποι πίσω από τον ήχο. Επίλεξε ημέρα και ανακάλυψε τα resident DJ sets.',
    'tracks' => 'FRESH SOUNDS', 'tracks_sub' => 'Τα Hot Tracks που ξεχωρίζει το Deseo. Άνοιξε κάθε κομμάτι στο Spotify.',
    'playlists' => 'Η ΔΙΚΗ ΣΟΥ ΔΙΑΘΕΣΗ', 'playlists_sub' => 'Επιλεγμένες playlists με την υπογραφή του Deseo.',
    'podcasts' => 'THE SHOW GOES ON.', 'podcasts_sub' => 'Άκου ξανά DJ sets και radio shows από τα επίσημα κανάλια μας.',
    'brand' => 'WE ARE DESEO.', 'brand_sub' => 'Όχι απλά ένας σταθμός. Ένας χώρος για τη μουσική που μένει μαζί σου.',
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
$days = [1 => ['MON', 'ΔΕΥ'], 2 => ['TUE', 'ΤΡΙ'], 3 => ['WED', 'ΤΕΤ'], 4 => ['THU', 'ΠΕΜ'], 5 => ['FRI', 'ΠΑΡ'], 6 => ['SAT', 'ΣΑΒ'], 7 => ['SUN', 'ΚΥΡ']];
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
  <link rel="icon" href="/assets/img/favicon.png">
  <link rel="stylesheet" href="/assets/css/mydemo.css?v=<?= $cssVersion ?>">
  <script src="/assets/js/mydemo.js?v=<?= $jsVersion ?>" defer></script>
</head>
<body>
<a class="md-skip" href="#main">Skip to content</a>
<div class="md-noise" aria-hidden="true"></div>

<header class="md-header">
  <div class="md-shell md-header-inner">
    <a class="md-logo" href="#home" aria-label="Deseo Radio demo home"><img src="/assets/img/deseoradio-logo.png" alt="Deseo Radio" width="150" height="43"></a>
    <nav class="md-nav" aria-label="Main navigation">
      <a href="#schedule"><?= $en ? 'SCHEDULE' : 'ΠΡΟΓΡΑΜΜΑ' ?></a>
      <a href="#tracks">HOT TRACKS</a>
      <a href="#lineup">SEASON 06</a>
      <a href="#shows">PODCASTS</a>
    </nav>
    <div class="md-header-actions">
      <div class="md-lang" aria-label="Language">
        <a <?= !$en ? 'aria-current="page"' : '' ?> href="/mydemo?lang=el">EL</a>
        <span>/</span>
        <a <?= $en ? 'aria-current="page"' : '' ?> href="/mydemo?lang=en">EN</a>
      </div>
      <a class="md-pill md-header-live" href="#player"><span class="md-dot"></span> LIVE RADIO <span aria-hidden="true">↗</span></a>
    </div>
  </div>
</header>

<main id="main">
<section class="md-hero" id="home">
  <div class="md-hero-photo" aria-hidden="true"></div>
  <div class="md-hero-rings" aria-hidden="true"><span></span><span></span><span></span></div>
  <div class="md-shell md-hero-content">
    <div class="md-hero-copy">
      <div class="md-eyebrow"><span class="md-redline"></span> DESEO RADIO <span class="md-split"></span> ATHENS / WORLDWIDE <span class="md-split"></span> 24/7 SOUND</div>
      <h1 class="md-masthead">FEEL <span>THE</span><em>FREQUENCY<span class="md-period">.</span></em></h1>
      <div class="md-hero-bottom">
        <div>
          <p class="md-overline">HOUSE MUSIC. NO COMPROMISES.</p>
          <h2><?= demo_e($copy['hero']) ?></h2>
          <p class="md-hero-description"><?= demo_e($copy['hero_sub']) ?></p>
          <div class="md-hero-cta">
            <a class="md-button md-button-red" href="#player"><?= demo_e($copy['listen']) ?> <span aria-hidden="true">↗</span></a>
            <a class="md-text-link" href="#tracks"><?= demo_e($copy['explore']) ?> <span aria-hidden="true">↘</span></a>
          </div>
        </div>
        <div class="md-hero-serial" aria-hidden="true"><strong>06</strong><span>THE NEW<br>SEASON</span></div>
      </div>
    </div>
  </div>
  <div class="md-hero-border md-shell"><span>SCROLL TO FEEL IT</span><span>DESEO — ALWAYS IN YOUR ELEMENT.</span><span>↓</span></div>
</section>

<section class="md-player-band" id="player" aria-label="Live audio">
  <div class="md-shell md-player-grid">
    <div class="md-player-intro">
      <span class="md-tag"><span class="md-dot"></span> ON AIR / 24·7</span>
      <h2>THE SOUND<br><em>IS LIVE.</em></h2>
      <p><?= $en ? 'Your 24/7 connection to the music you love.' : 'Η μουσική που αγαπάς, ζωντανά κάθε στιγμή.' ?></p>
      <div class="md-bars" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div>
    </div>
    <div class="md-player-embed">
      <div class="md-player-top"><span>DESEO / LIVE PLAYER</span><span>↗ IRADIOS</span></div>
      <iframe title="Deseo Radio official live audio player" loading="eager" src="https://play.iradios.gr/widget/deseo-radio?autoplay=false" allow="autoplay; encrypted-media; clipboard-write" referrerpolicy="origin" ></iframe>
    </div>
    <div class="md-onair">
      <span class="md-small-label"><?= demo_e($copy['now']) ?></span>
      <div class="md-onair-portrait"><img id="md-live-photo" src="<?= demo_e($liveShow['photo'] ?? '/assets/img/bg.png') ?>" alt="" loading="lazy"></div>
      <div class="md-onair-copy">
        <div class="md-tag"><span class="md-dot"></span> <?= $liveShow ? 'ON AIR' : 'NON-STOP' ?></div>
        <h3 id="md-live-name"><?= demo_e($liveShow['name'] ?? $copy['auto']) ?></h3>
        <p id="md-live-time"><?= demo_e($liveShow['time'] ?? '24 / 7') ?></p>
        <div class="md-next"><span><?= demo_e($copy['next']) ?></span><strong id="md-next-name"><?= demo_e($nextShow['name'] ?? $copy['nonstop']) ?></strong><small id="md-next-time"><?= $nextShow ? demo_e($next['start']->format('D d/m · H:i')) : '' ?></small></div>
      </div>
    </div>
  </div>
</section>

<div class="md-marquee" aria-hidden="true"><div>DESEO RADIO <b>✦</b> HOUSE IS A FEELING <b>✦</b> ATHENS TO EVERYWHERE <b>✦</b> DESEO RADIO <b>✦</b> HOUSE IS A FEELING <b>✦</b> ATHENS TO EVERYWHERE <b>✦</b></div></div>

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
        <a href="#schedule" class="md-text-link"><?= demo_e($copy['schedule']) ?> ↗</a>
      </div>
      <div class="md-season-art" aria-label="Season 6 official lineup artwork">
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
        <button id="md-tab-<?= $dayNumber ?>" type="button" class="md-day-tab <?= $dayNumber === $activeDay ? 'is-active' : '' ?>" role="tab" aria-controls="md-panel-<?= $dayNumber ?>" aria-selected="<?= $dayNumber === $activeDay ? 'true' : 'false' ?>" tabindex="<?= $dayNumber === $activeDay ? '0' : '-1' ?>" data-day="<?= $dayNumber ?>"><?= $en ? $dayNames[0] : $dayNames[1] ?><span><?= count($showsByDay[$dayNumber]) ?></span></button>
      <?php endforeach; ?>
    </div>
    <div class="md-program-panels">
      <?php foreach ($days as $dayNumber => $dayNames): ?>
      <div id="md-panel-<?= $dayNumber ?>" class="md-program-panel" role="tabpanel" aria-labelledby="md-tab-<?= $dayNumber ?>" <?= $dayNumber !== $activeDay ? 'hidden' : '' ?>>
        <?php if (!$showsByDay[$dayNumber]): ?>
        <div class="md-empty"><span>∞</span><strong><?= demo_e($copy['nonstop']) ?></strong><p><?= demo_e($copy['empty_program']) ?></p><a href="#player"><?= demo_e($copy['listen']) ?> ↗</a></div>
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
              <div class="md-show-photo"><img src="<?= demo_e($picture) ?>" alt="<?= demo_e($slot['dj_name']) ?>" loading="lazy" onerror="this.onerror=null;this.src='/assets/img/bg.png'"><span><?= demo_clock($slot['start_time']) ?> — <?= demo_clock($slot['end_time']) ?></span></div>
              <div class="md-show-details"><span><?= $isLive ? '● ON AIR' : 'DESEO RADIO / S06' ?></span><h3><?= demo_e($slot['dj_name']) ?></h3><small><?= $profile ? demo_e($copy['read_more']) . ' ↗' : 'RESIDENT / DJ SET' ?></small></div>
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
    <div class="md-section-heading"><h2>FRESH<br><em>SOUNDS.</em></h2><p><?= demo_e($copy['tracks_sub']) ?></p></div>
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
        <?php if ($href): ?><a class="md-circle-link" href="<?= demo_e($href) ?>" target="_blank" rel="noopener noreferrer" aria-label="<?= demo_e($copy['open'] . ' ' . $track['track_name']) ?>">↗</a><?php endif; ?>
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
          <div class="md-playlist-art"><?php if ($cover): ?><img src="<?= demo_e($cover) ?>" alt="" loading="lazy" onerror="this.style.display='none'"><?php endif; ?><span class="md-playlist-arrow">↗</span></div>
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
        <a href="https://hearthis.at/deseoradio/set/season-6/" target="_blank" rel="noopener noreferrer">HEARTHIS <span>↗</span></a>
        <a href="https://www.mixcloud.com/deseoradio/" target="_blank" rel="noopener noreferrer">MIXCLOUD <span>↗</span></a>
        <a href="https://podcasts.apple.com/us/podcast/deseo-radioshows/id1711008342" target="_blank" rel="noopener noreferrer">APPLE PODCASTS <span>↗</span></a>
        <a href="https://open.spotify.com/show/2x8ceF2a3gMmzEJ8y6W1ue" target="_blank" rel="noopener noreferrer">SPOTIFY <span>↗</span></a>
      </div>
    </div>
    <div class="md-shows-art"><div class="md-disc"><span><img src="/assets/img/favicon.png" alt="Deseo Radio" loading="lazy"></span></div><span class="md-disc-caption">DESEO RADIO / ALL THE FEELS / SEASON 06</span></div>
  </div>
</section>

<section class="md-manifesto">
  <div class="md-shell">
    <p>THIS IS YOUR FREQUENCY.</p>
    <h2>MORE THAN<br><em>MUSIC.</em><br>IT'S <span>A FEELING.</span></h2>
    <div class="md-manifesto-bottom"><span>DESEO RADIO / ATHENS</span><p><?= demo_e($copy['brand_sub']) ?></p><a href="#player" class="md-button md-button-red"><?= demo_e($copy['listen']) ?> ↗</a></div>
  </div>
</section>

<section class="md-sponsor md-shell">
  <span class="md-small-label"><?= demo_e($copy['sponsor']) ?></span>
  <a href="https://iluma.gr/" target="_blank" rel="noopener noreferrer" aria-label="ILUMA Digital Agency"><img src="/assets/img/iluma-digital-agency-banner.jpg" alt="ILUMA Digital Agency" loading="lazy"></a>
</section>
</main>

<footer class="md-footer">
  <div class="md-shell">
    <div class="md-footer-main"><div><img src="/assets/img/deseoradio-logo.png" alt="Deseo Radio" width="175" height="50"><p>STAY TUNED, ΣΤΟ SOUNDTRACK ΤΗΣ ΖΩΗΣ ΣΟΥ.</p></div><nav aria-label="Social channels"><a href="https://www.instagram.com/deseoradio/" target="_blank" rel="noopener noreferrer">INSTAGRAM ↗</a><a href="https://www.facebook.com/deseoradiogr/" target="_blank" rel="noopener noreferrer">FACEBOOK ↗</a><a href="https://iluma.gr/radios/mediakit" target="_blank" rel="noopener noreferrer">MEDIA KIT ↗</a><a href="/" ><?= demo_e($copy['return']) ?> ↗</a></nav></div>
    <div class="md-footer-bottom"><span>© <?= $now->format('Y') ?> DESEO RADIO / ATHENS</span><span><?= demo_e($copy['preview']) ?> · NOINDEX</span><span>DESIGNED BY <a href="https://iluma.gr/" target="_blank" rel="noopener noreferrer">ILUMA</a></span></div>
  </div>
</footer>
<dialog id="md-dj-dialog" aria-labelledby="md-dialog-title">
  <button type="button" id="md-dialog-close" aria-label="<?= demo_e($copy['dj_close']) ?>">×</button>
  <img id="md-dialog-photo" src="/assets/img/bg.png" alt="">
  <div><span class="md-index"><?= demo_e($copy['dj_info']) ?> / SEASON 06</span><h2 id="md-dialog-title"></h2><p id="md-dialog-bio"></p><div id="md-dialog-links"></div></div>
</dialog>
</body>
</html>
