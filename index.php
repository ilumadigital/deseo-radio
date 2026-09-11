<?php
declare(strict_types=1);

function deseo_e(?string $value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function deseo_time(?string $value): string {
    if (!$value) return '--:--';
    $ts = strtotime($value);
    return $ts === false ? '--:--' : date('H:i', $ts);
}

$airplay_tracks = [];
$program = [];
$todays_program = [];
$live_dj = null;
$next_dj = null;
$dataStatus = 'live';
$tz = new DateTimeZone('Europe/Athens');
$now = new DateTimeImmutable('now', $tz);

try {
    require_once __DIR__ . '/iluma/connection.php';

    $airplay_tracks = $pdo->query(
        "SELECT id, spotify_url, track_name, artist_name, artwork_url, position
         FROM airplay
         WHERE position BETWEEN 1 AND 10
         ORDER BY position ASC
         LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    $program = $pdo->query(
        "SELECT id, dj_name, photo_path, day_of_week, start_time, end_time
         FROM program
         WHERE day_of_week BETWEEN 1 AND 7
         ORDER BY day_of_week ASC, start_time ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $weekStart = $now->modify('monday this week')->setTime(0, 0, 0);
    $occurrences = [];

    foreach ($program as $show) {
        $day = max(1, min(7, (int) ($show['day_of_week'] ?? 1)));
        foreach ([-7, 0, 7] as $weekOffset) {
            $baseDate = $weekStart->modify(($weekOffset + $day - 1) . ' days');

            $startParts = array_map('intval', explode(':', (string) ($show['start_time'] ?? '00:00:00')));
            $endParts = array_map('intval', explode(':', (string) ($show['end_time'] ?? '23:59:59')));

            $start = $baseDate->setTime($startParts[0] ?? 0, $startParts[1] ?? 0, $startParts[2] ?? 0);
            $end = $baseDate->setTime($endParts[0] ?? 23, $endParts[1] ?? 59, $endParts[2] ?? 59);

            if ($end <= $start) {
                $end = $end->modify('+1 day');
            }

            $occurrences[] = [
                'show' => $show,
                'start' => $start,
                'end' => $end,
            ];
        }
    }

    usort($occurrences, static function ($a, $b) {
        return $a['start'] <=> $b['start'];
    });

    foreach ($occurrences as $occurrence) {
        if ($now >= $occurrence['start'] && $now < $occurrence['end']) {
            $live_dj = $occurrence['show'];
            $live_dj['_start'] = $occurrence['start'];
            $live_dj['_end'] = $occurrence['end'];
            break;
        }
    }

    foreach ($occurrences as $occurrence) {
        if ($occurrence['start'] > $now) {
            $next_dj = $occurrence['show'];
            $next_dj['_start'] = $occurrence['start'];
            $next_dj['_end'] = $occurrence['end'];
            break;
        }
    }

    $todayIso = $now->format('Y-m-d');
    foreach ($occurrences as $occurrence) {
        if ($occurrence['start']->format('Y-m-d') === $todayIso) {
            $row = $occurrence['show'];
            $row['_start'] = $occurrence['start'];
            $row['_end'] = $occurrence['end'];
            $todays_program[] = $row;
        }
    }

    $unique = [];
    $todays_program = array_values(array_filter($todays_program, static function ($row) use (&$unique) {
        $key = $row['id'] . '-' . $row['_start']->format('c');
        if (isset($unique[$key])) return false;
        $unique[$key] = true;
        return true;
    }));
} catch (Throwable $e) {
    $dataStatus = 'fallback';
    error_log('Deseo Radio data unavailable: ' . $e->getMessage());
}

$meta_title = 'Deseo Radio | Live House Music 24/7';
$meta_desc = 'House, Afro House, Deep House και electronic music 24/7. Άκου live Deseo Radio, δες ποιος DJ είναι on air, το σημερινό πρόγραμμα και το weekly airplay.';
require_once __DIR__ . '/includes/head-meta.php';
require_once __DIR__ . '/includes/header.php';

$daysEl = [
    1 => 'Δευτέρα', 2 => 'Τρίτη', 3 => 'Τετάρτη', 4 => 'Πέμπτη',
    5 => 'Παρασκευή', 6 => 'Σάββατο', 7 => 'Κυριακή'
];
?>

<h1 class="sr-only">Deseo Radio: Το Soundtrack της ζωής σου!</h1>

<!-- =========================================================================
     2. MASTER HERO LAYER (Cinematic 3-Column Console)
========================================================================= -->
<main id="main-content" class="relative w-full min-h-screen flex flex-col items-center justify-center pt-36 pb-24 overflow-hidden">
    
    <!-- Background Gradient Setup -->
    <div class="absolute inset-0 z-[-1] pointer-events-none select-none">
        <img src="/assets/img/bg.png" alt="Deseo Radio Deep Sunset Cover" class="w-full h-full object-cover filter brightness-[0.6] contrast-[1.05]">
        <div class="absolute inset-0 bg-black/40"></div>
        <div class="absolute inset-0 bg-gradient-to-b from-black/80 via-transparent to-black"></div>
    </div>

    <!-- 3-Column Pure Grid (Enforced Max Width at 1600px for Cinematic Desktops) -->
    <section class="w-full max-w-[1600px] mx-auto px-6 md:px-12 grid grid-cols-1 md:grid-cols-3 gap-8 lg:gap-12 items-start mt-6">
        
        <!-- Deck 01: NOW PLAYING (Native Iframe Player) -->
        <div class="gsap-hero-left w-full flex flex-col items-center">
            <div class="mb-5 flex items-center gap-2.5 opacity-80 tracking-[0.4em] text-[10px] font-bold text-white uppercase self-start">
                <span class="w-1.5 h-1.5 rounded-full bg-[#ccff00] shadow-[0_0_8px_#ccff00] animate-pulse"></span>
                # NOW PLAYING
            </div>
            
            <div class="relative w-full aspect-square rounded-[2.5rem] overflow-hidden shadow-[0_30px_60px_-15px_rgba(0,0,0,0.9)] border border-white/5 bg-zinc-950">
                <!-- Native Iframe Player - Always Visible, Zero Hover Interference -->
                <iframe src="https://play.iradios.gr/widget/deseo-radio?autoplay=true" width="100%" height="100%" frameborder="0" allow="autoplay; encrypted-media; clipboard-write;" class="w-full h-full object-cover block"></iframe>
            </div>
        </div>

        <!-- Deck 02: NOW ON AIR (Dedicated DJ Module) -->
        <div class="gsap-hero-left w-full flex flex-col items-center" style="animation-delay: 150ms;">
            <div class="mb-5 flex items-center gap-2.5 opacity-80 tracking-[0.4em] text-[10px] font-bold text-white uppercase self-start">
                <span class="w-1.5 h-1.5 rounded-full <?= $live_dj ? 'bg-[#ccff00] shadow-[0_0_8px_#ccff00]' : 'bg-zinc-600' ?>"></span>
                # NOW ON AIR
            </div>
            
            <div class="relative w-full aspect-square rounded-[2.5rem] overflow-hidden shadow-[0_30px_60px_-15px_rgba(0,0,0,0.9)] border border-white/5 bg-zinc-950 flex flex-col justify-end">
                <?php if($live_dj): ?>
                    <!-- Live DJ Photo -->
                    <img src="<?= htmlspecialchars($live_dj['photo_path']) ?>" alt="<?= htmlspecialchars($live_dj['dj_name']) ?>" class="absolute inset-0 w-full h-full object-cover">
                    <!-- Elegant Bottom Slate Info -->
                    <div class="relative z-10 p-7 bg-gradient-to-t from-black via-black/70 to-transparent w-full">
                        <span class="text-[#ccff00] text-[9px] uppercase tracking-[0.3em] font-bold block mb-1">Live Broadcast</span>
                        <h2 class="text-2xl lg:text-3xl font-bold text-white tracking-tight truncate"><?= htmlspecialchars($live_dj['dj_name']) ?></h2>
                    </div>
                <?php else: ?>
                    <!-- Fallback / Auto Mix Graphics Layout -->
                    <img src="/assets/img/bg.png" alt="Deseo Radio Auto DJ" class="absolute inset-0 w-full h-full object-cover filter brightness-50">
                    <div class="relative z-10 p-7 bg-gradient-to-t from-black via-black/80 to-transparent w-full">
                        <span class="text-zinc-500 text-[9px] uppercase tracking-[0.3em] font-bold block mb-1">Non-Stop Mix</span>
                        <h2 class="text-2xl lg:text-3xl font-bold text-white tracking-tight">DESEO AUTO DJ</h2>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Deck 03: SPONSOR (Bespoke Agency Banner) -->
        <div class="gsap-hero-right w-full flex flex-col items-center">
            <div class="mb-5 flex items-center gap-2.5 opacity-40 tracking-[0.4em] text-[10px] font-bold text-white uppercase self-start">
                # SPONSOR
            </div>
            
            <a href="https://iluma.gr" target="_blank" rel="noopener" class="block w-full aspect-square rounded-[2.5rem] overflow-hidden shadow-[0_30px_60px_-15px_rgba(0,0,0,0.9)] border border-white/5 transition-all duration-500 hover:scale-[1.015] hover:border-white/10 bg-zinc-950">
                <img src="/assets/img/iluma-digital-agency-banner.jpg" alt="Iluma Digital Agency - Bespoke Production" class="w-full h-full object-cover">
            </a>
        </div>

    </section>

    <!-- Invisible Scroll Cue -->
    <div class="gsap-scroll-indicator absolute bottom-6 left-1/2 -translate-x-1/2 opacity-20 pointer-events-none">
        <i class="fa-solid fa-chevron-down text-sm animate-bounce text-white"></i>
    </div>
</main>



    <section class="section section-dark" id="program">
        <div class="shell">
            <div class="section-heading reveal">
                <div>
                    <span class="eyebrow">Today's broadcast</span>
                    <h2>Το πρόγραμμα <em>σήμερα.</em></h2>
                </div>
                <p>Όλες οι ώρες εμφανίζονται σε ώρα Ελλάδας. Τα overnight sets υποστηρίζονται αυτόματα.</p>
            </div>

            <div class="schedule-grid">
                <?php if ($todays_program): ?>
                    <?php foreach ($todays_program as $show):
                        $isLiveRow = $live_dj && (int)$live_dj['id'] === (int)$show['id'];
                    ?>
                        <article class="schedule-card reveal <?= $isLiveRow ? 'current' : '' ?>">
                            <div class="schedule-time">
                                <strong><?= deseo_time($show['start_time']) ?></strong>
                                <span><?= deseo_time($show['end_time']) ?></span>
                            </div>
                            <img src="<?= deseo_e($show['photo_path'] ?: '/assets/img/bg.png') ?>"
                                 data-fallback="/assets/img/bg.png"
                                 alt="<?= deseo_e($show['dj_name']) ?>">
                            <div class="schedule-info">
                                <span><?= $isLiveRow ? 'ON AIR NOW' : 'LIVE SET' ?></span>
                                <h3><?= deseo_e($show['dj_name']) ?></h3>
                            </div>
                            <?php if ($isLiveRow): ?><i class="live-ring" aria-hidden="true"></i><?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                <?php else: ?>
                    <article class="empty-state reveal">
                        <span class="empty-icon">24/7</span>
                        <div>
                            <h3>Non-stop Deseo mix</h3>
                            <p><?= $dataStatus === 'fallback' ? 'Το live πρόγραμμα ενημερώνεται. Το stream παραμένει διαθέσιμο κανονικά.' : 'Δεν υπάρχει προγραμματισμένο live set σήμερα. Το Deseo συνεχίζει non-stop.' ?></p>
                        </div>
                    </article>
                <?php endif; ?>
            </div>

            <?php if ($next_dj && !$live_dj): ?>
                <div class="next-show reveal">
                    <span>Next live</span>
                    <strong><?= deseo_e($next_dj['dj_name']) ?></strong>
                    <small><?= $daysEl[(int)$next_dj['day_of_week']] ?? '' ?> · <?= deseo_time($next_dj['start_time']) ?></small>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="section airplay-section" id="airplay">
        <div class="shell">
            <div class="section-heading reveal">
                <div>
                    <span class="eyebrow">Weekly rotation</span>
                    <h2>Deseo <em>Airplay.</em></h2>
                </div>
                <p>Τα tracks που ξεχωρίζουν αυτή την εβδομάδα στο Deseo Radio.</p>
            </div>

            <div class="airplay-list">
                <?php if ($airplay_tracks): ?>
                    <?php foreach ($airplay_tracks as $track): ?>
                        <a class="track-row reveal"
                           href="<?= deseo_e($track['spotify_url']) ?>"
                           target="_blank"
                           rel="noopener noreferrer">
                            <span class="track-rank"><?= (int)$track['position'] < 10 ? '0' . (int)$track['position'] : (int)$track['position'] ?></span>
                            <img src="<?= deseo_e($track['artwork_url'] ?: '/assets/img/favicon.png') ?>"
                                 data-fallback="/assets/img/favicon.png"
                                 alt="">
                            <span class="track-main">
                                <strong><?= deseo_e($track['track_name'] ?: 'Deseo Selection') ?></strong>
                                <small><?= deseo_e(($track['artist_name'] && $track['artist_name'] !== 'Διάφοροι / Μη διαθέσιμο') ? $track['artist_name'] : 'Deseo Radio Selection') ?></small>
                            </span>
                            <span class="spotify-mark" aria-label="Άνοιγμα στο Spotify">Spotify ↗</span>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <article class="empty-state reveal">
                        <span class="empty-icon">TOP</span>
                        <div>
                            <h3>Το νέο chart ετοιμάζεται</h3>
                            <p>Το stream λειτουργεί κανονικά. Η λίστα Airplay θα εμφανιστεί μόλις ανανεωθεί από το studio.</p>
                        </div>
                    </article>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="section partners-section" id="partners">
        <div class="shell">
            <div class="section-heading centered reveal">
                <div>
                    <span class="eyebrow">Listen everywhere</span>
                    <h2>Find Deseo <em>everywhere.</em></h2>
                </div>
                <p>Άκου Deseo από το site ή από τις μεγαλύτερες radio platforms.</p>
            </div>

            <?php
            $partners = [
                1 => ['url' => 'https://play.iradios.gr/station/deseo-radio', 'name' => 'iRadios'],
                2 => ['url' => 'https://onlineradiobox.com/gr/deseo/', 'name' => 'Online Radio Box'],
                3 => ['url' => 'https://www.getmeradio.com/stations/deseoradiogr-4835/', 'name' => 'Get Me Radio'],
                4 => ['url' => 'https://isavior.gr/', 'name' => 'iSavior'],
                5 => ['url' => 'https://tunein.com/radio/Deseo-Radio-s258242/', 'name' => 'TuneIn'],
                6 => ['url' => 'https://vradio.app/play?id=20739', 'name' => 'VRadio'],
                7 => ['url' => 'https://streema.com/radios/Deseo_Radio', 'name' => 'Streema'],
                8 => ['url' => 'https://iluma.gr/radios/deseo', 'name' => 'ILUMA Radios'],
            ];
            ?>
            <div class="partner-grid reveal">
                <?php foreach ($partners as $id => $partner): ?>
                    <a href="<?= deseo_e($partner['url']) ?>" target="_blank" rel="noopener noreferrer" aria-label="<?= deseo_e($partner['name']) ?>">
                        <img src="/assets/img/partner-<?= $id ?>.png"
                             data-fallback="/assets/img/deseoradio-logo.png"
                             alt="<?= deseo_e($partner['name']) ?>">
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="advertise">
        <div class="shell advertise-card reveal">
            <div>
                <span class="eyebrow">Advertise on Deseo</span>
                <h2>Βάλε το brand σου<br><em>μέσα στον ήχο.</em></h2>
            </div>
            <div class="advertise-copy">
                <p>Σύνδεσε το brand σου με ένα focused κοινό που αγαπά House και electronic music, μέσα από tailor-made radio campaigns της ILUMA.</p>
                <a class="button button-primary" href="https://iluma.gr/contact/" target="_blank" rel="noopener noreferrer">Start a campaign ↗</a>
            </div>
        </div>
    </section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>