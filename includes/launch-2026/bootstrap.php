<?php
declare(strict_types=1);

/** Deseo Radio public next-generation homepage data bootstrap. */
require_once dirname(__DIR__, 2) . '/includes/i18n.php';

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
    require dirname(__DIR__, 2) . '/iluma/connection.php';
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
if (($_GET['program_feed'] ?? '') === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    $serializeLegacy = static function (?array $item) use ($profiles): ?array {
        if (!$item) return null;
        $row = $item['row'];
        $profileId = (int)($row['mylive_account_id'] ?? 0);
        $profile = $profiles[$profileId] ?? null;
        return [
            'id' => (int)($row['id'] ?? 0),
            'dj_name' => (string)($row['dj_name'] ?? ''),
            'mylive_account_id' => $profileId,
            'photo_path' => (string)($row['photo_path'] ?? ''),
            'start_time' => (string)($row['start_time'] ?? ''),
            'end_time' => (string)($row['end_time'] ?? ''),
            'profile' => is_array($profile) ? $profile : null,
        ];
    };
    $todayShows = [];
    foreach ($occurrences as $occurrence) {
        if ($occurrence['start']->format('Y-m-d') !== $now->format('Y-m-d')) continue;
        $serialized = $serializeLegacy($occurrence);
        if ($serialized === null) continue;
        $serialized['is_live'] = $live !== null
            && (int)$live['row']['id'] === (int)$occurrence['row']['id']
            && $live['start'] == $occurrence['start'];
        $todayShows[$serialized['id'] . ':' . $occurrence['start']->format(DATE_ATOM)] = $serialized;
    }
    echo json_encode([
        'ok' => $dbOnline,
        'generated_at' => $now->format(DATE_ATOM),
        'live' => $serializeLegacy($live),
        'next' => $serializeLegacy($next),
        'today' => array_values($todayShows),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
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
$cssVersion = is_file(dirname(__DIR__, 2) . '/assets/css/mydemo.css') ? (int)filemtime(dirname(__DIR__, 2) . '/assets/css/mydemo.css') : 1;
$jsVersion = is_file(dirname(__DIR__, 2) . '/assets/js/mydemo.js') ? (int)filemtime(dirname(__DIR__, 2) . '/assets/js/mydemo.js') : 1;
/* Reuse the established public RadioStation/FAQ/live-event JSON-LD nodes. */
foreach ($program as &$nextProgramRow) {
    $nextAccountId = (int)($nextProgramRow['mylive_account_id'] ?? 0);
    $nextProgramRow['public_profile'] = $profiles[$nextAccountId] ?? null;
}
unset($nextProgramRow);
$live_dj = $live ? array_merge($live['row'], [
    '_start' => $live['start'],
    '_end' => $live['end'],
    'public_profile' => $profiles[(int)($live['row']['mylive_account_id'] ?? 0)] ?? null,
]) : null;

if (!empty($deseo_launch_public) && empty($deseo_launch_disable_jobs) && $dbOnline) {
    // Preserve the throttled MyLive scheduler previously invoked from index.php.
    try {
        require_once dirname(__DIR__, 2) . '/includes/mylive-email-reminders.php';
        deseo_mylive_maybe_run_email_scheduler($pdo);
    } catch (Throwable $schedulerError) {
        error_log('Deseo public scheduler unavailable: ' . $schedulerError->getMessage());
    }
}
