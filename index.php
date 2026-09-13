<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/i18n.php';

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

require_once __DIR__ . '/includes/head-meta.php';
require_once __DIR__ . '/includes/header.php';

?>

<h1 class="sr-only" data-i18n="hero.sr_title"><?= deseo_e(deseo_t('hero.sr_title')) ?></h1>

<!-- =========================================================================
     2. MASTER HERO LAYER (Cinematic 3-Column Console)
========================================================================= -->
<main id="main-content" class="relative w-full min-h-screen flex flex-col items-center justify-center pt-36 pb-24 overflow-hidden">
    
    <!-- Background Gradient Setup -->
    <div class="deseo-hero-background pointer-events-none select-none" aria-hidden="true">
        <img src="/assets/img/bg.png" alt="" class="deseo-hero-background-image">
        <div class="deseo-hero-background-dim"></div>
        <div class="deseo-hero-background-gradient"></div>
    </div>

    <!-- 3-Column Pure Grid (Enforced Max Width at 1600px for Cinematic Desktops) -->
    <section class="deseo-hero-content w-full max-w-[1600px] mx-auto px-6 md:px-12 grid grid-cols-1 md:grid-cols-3 gap-8 lg:gap-12 items-start mt-6">
        
        <!-- Deck 01: NOW PLAYING (Native Iframe Player) -->
        <div class="gsap-hero-left w-full flex flex-col items-center">
            <div class="mb-5 flex items-center gap-2.5 opacity-80 tracking-[0.4em] text-[10px] font-bold text-white uppercase self-start">
                <span class="w-1.5 h-1.5 rounded-full bg-[#ccff00] shadow-[0_0_8px_#ccff00] animate-pulse"></span>
                # <span data-i18n="hero.now_playing"><?= deseo_e(deseo_t('hero.now_playing')) ?></span>
            </div>
            
            <div class="relative w-full aspect-square rounded-[2.5rem] overflow-hidden shadow-[0_30px_60px_-15px_rgba(0,0,0,0.9)] border border-white/5 bg-zinc-950">
                <!-- Native Iframe Player - Always Visible, Zero Hover Interference -->
                <iframe src="https://play.iradios.gr/widget/deseo-radio?autoplay=true" width="100%" frameborder="0" allow="autoplay; encrypted-media; clipboard-write;" style="border:none; width: 100%; max-width: 600px; aspect-ratio: 1 / 1; margin: 0 auto; display: block; box-shadow: 0 20px 40px rgba(0,0,0,0.5); border-radius: 32px; overflow: hidden;"></iframe>
            </div>
        </div>

        <!-- Deck 02: NOW ON AIR (Dedicated DJ Module) -->
        <div class="gsap-hero-left w-full flex flex-col items-center" style="animation-delay: 150ms;">
            <div class="mb-5 flex items-center gap-2.5 opacity-80 tracking-[0.4em] text-[10px] font-bold text-white uppercase self-start">
                <span class="w-1.5 h-1.5 rounded-full <?= $live_dj ? 'bg-[#ccff00] shadow-[0_0_8px_#ccff00]' : 'bg-zinc-600' ?>"></span>
                # <span data-i18n="hero.now_on_air"><?= deseo_e(deseo_t('hero.now_on_air')) ?></span>
            </div>
            
            <div class="relative w-full aspect-square rounded-[2.5rem] overflow-hidden shadow-[0_30px_60px_-15px_rgba(0,0,0,0.9)] border border-white/5 bg-zinc-950 flex flex-col justify-end">
                <?php if($live_dj): ?>
                    <!-- Live DJ Photo -->
                    <img src="<?= htmlspecialchars($live_dj['photo_path']) ?>" alt="<?= htmlspecialchars($live_dj['dj_name']) ?>" class="absolute inset-0 w-full h-full object-cover">
                    <!-- Elegant Bottom Slate Info -->
                    <div class="relative z-10 p-7 bg-gradient-to-t from-black via-black/70 to-transparent w-full">
                        <span class="text-[#ccff00] text-[9px] uppercase tracking-[0.3em] font-bold block mb-1" data-i18n="hero.live_broadcast"><?= deseo_e(deseo_t('hero.live_broadcast')) ?></span>
                        <h2 class="text-2xl lg:text-3xl font-bold text-white tracking-tight truncate"><?= htmlspecialchars($live_dj['dj_name']) ?></h2>
                    </div>
                <?php else: ?>
                    <!-- Fallback / Auto Mix Graphics Layout -->
                    <img src="/assets/img/bg.png" alt="<?= deseo_e(deseo_t('hero.auto_dj_alt')) ?>" class="absolute inset-0 w-full h-full object-cover filter brightness-50">
                    <div class="relative z-10 p-7 bg-gradient-to-t from-black via-black/80 to-transparent w-full">
                        <span class="text-zinc-500 text-[9px] uppercase tracking-[0.3em] font-bold block mb-1" data-i18n="hero.non_stop_mix"><?= deseo_e(deseo_t('hero.non_stop_mix')) ?></span>
                        <h2 class="text-2xl lg:text-3xl font-bold text-white tracking-tight" data-i18n="hero.auto_dj"><?= deseo_e(deseo_t('hero.auto_dj')) ?></h2>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Deck 03: SPONSOR (Bespoke Agency Banner) -->
        <div class="gsap-hero-right w-full flex flex-col items-center">
            <div class="mb-5 flex items-center gap-2.5 opacity-40 tracking-[0.4em] text-[10px] font-bold text-white uppercase self-start">
                # <span data-i18n="hero.sponsor"><?= deseo_e(deseo_t('hero.sponsor')) ?></span>
            </div>
            
            <a href="https://iluma.gr" target="_blank" rel="noopener" data-analytics-event="sponsor_click" class="block w-full aspect-square rounded-[2.5rem] overflow-hidden shadow-[0_30px_60px_-15px_rgba(0,0,0,0.9)] border border-white/5 transition-all duration-500 hover:scale-[1.015] hover:border-white/10 bg-zinc-950">
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
                    <span class="eyebrow" data-i18n="program.eyebrow"><?= deseo_e(deseo_t('program.eyebrow')) ?></span>
                    <h2><span data-i18n="program.heading"><?= deseo_e(deseo_t('program.heading')) ?></span> <em data-i18n="program.heading_em"><?= deseo_e(deseo_t('program.heading_em')) ?></em></h2>
                </div>
                <p data-i18n="program.description"><?= deseo_e(deseo_t('program.description')) ?></p>
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
                                <span data-i18n="<?= $isLiveRow ? 'program.on_air_now' : 'program.live_set' ?>"><?= deseo_e($isLiveRow ? deseo_t('program.on_air_now') : deseo_t('program.live_set')) ?></span>
                                <h3><?= deseo_e($show['dj_name']) ?></h3>
                            </div>
                            <?php if ($isLiveRow): ?><i class="live-ring" aria-hidden="true"></i><?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                <?php else: ?>
                    <article class="empty-state reveal">
                        <span class="empty-icon">24/7</span>
                        <div>
                            <h3 data-i18n="program.empty_title"><?= deseo_e(deseo_t('program.empty_title')) ?></h3>
                            <p data-i18n="<?= $dataStatus === 'fallback' ? 'program.empty_fallback' : 'program.empty_none' ?>"><?= deseo_e($dataStatus === 'fallback' ? deseo_t('program.empty_fallback') : deseo_t('program.empty_none')) ?></p>
                        </div>
                    </article>
                <?php endif; ?>
            </div>

            <?php if ($next_dj && !$live_dj): ?>
                <div class="next-show reveal">
                    <span data-i18n="program.next_live"><?= deseo_e(deseo_t('program.next_live')) ?></span>
                    <strong><?= deseo_e($next_dj['dj_name']) ?></strong>
                    <small><span data-i18n="day.<?= (int)$next_dj['day_of_week'] ?>"><?= deseo_e(deseo_t_day((int)$next_dj['day_of_week'])) ?></span> · <?= deseo_time($next_dj['start_time']) ?></small>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="section airplay-section" id="airplay">
        <div class="shell">
            <div class="section-heading reveal">
                <div>
                    <span class="eyebrow" data-i18n="airplay.eyebrow"><?= deseo_e(deseo_t('airplay.eyebrow')) ?></span>
                    <h2><span data-i18n="airplay.heading"><?= deseo_e(deseo_t('airplay.heading')) ?></span> <em data-i18n="airplay.heading_em"><?= deseo_e(deseo_t('airplay.heading_em')) ?></em></h2>
                </div>
                <p data-i18n="airplay.description"><?= deseo_e(deseo_t('airplay.description')) ?></p>
            </div>

            <div class="airplay-list">
                <?php if ($airplay_tracks): ?>
                    <?php foreach ($airplay_tracks as $track): ?>
                        <a class="track-row reveal"
                           data-analytics-event="spotify_track_click"
                           href="<?= deseo_e($track['spotify_url']) ?>"
                           target="_blank"
                           rel="noopener noreferrer">
                            <span class="track-rank"><?= (int)$track['position'] < 10 ? '0' . (int)$track['position'] : (int)$track['position'] ?></span>
                            <img src="<?= deseo_e($track['artwork_url'] ?: '/assets/img/favicon.png') ?>"
                                 data-fallback="/assets/img/favicon.png"
                                 alt="">
                            <span class="track-main">
                                <strong><?= deseo_e($track['track_name'] ?: 'Deseo Selection') ?></strong>
                                <small><?= deseo_e(($track['artist_name'] && $track['artist_name'] !== 'Διάφοροι / Μη διαθέσιμο') ? $track['artist_name'] : deseo_t('airplay.selection')) ?></small>
                            </span>
                            <span class="spotify-mark" aria-label="<?= deseo_e(deseo_t('airplay.spotify_aria')) ?>" data-i18n-aria="airplay.spotify_aria">Spotify ↗</span>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <article class="empty-state reveal">
                        <span class="empty-icon">TOP</span>
                        <div>
                            <h3 data-i18n="airplay.empty_title"><?= deseo_e(deseo_t('airplay.empty_title')) ?></h3>
                            <p data-i18n="airplay.empty_text"><?= deseo_e(deseo_t('airplay.empty_text')) ?></p>
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
                    <span class="eyebrow" data-i18n="partners.eyebrow"><?= deseo_e(deseo_t('partners.eyebrow')) ?></span>
                    <h2><span data-i18n="partners.heading"><?= deseo_e(deseo_t('partners.heading')) ?></span> <em data-i18n="partners.heading_em"><?= deseo_e(deseo_t('partners.heading_em')) ?></em></h2>
                </div>
                <p data-i18n="partners.description"><?= deseo_e(deseo_t('partners.description')) ?></p>
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
                    <a href="<?= deseo_e($partner['url']) ?>" data-analytics-event="radio_partner_click" target="_blank" rel="noopener noreferrer" aria-label="<?= deseo_e($partner['name']) ?>">
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
                <span class="eyebrow" data-i18n="advertise.eyebrow"><?= deseo_e(deseo_t('advertise.eyebrow')) ?></span>
                <h2><span data-i18n="advertise.heading"><?= deseo_e(deseo_t('advertise.heading')) ?></span><br><em data-i18n="advertise.heading_em"><?= deseo_e(deseo_t('advertise.heading_em')) ?></em></h2>
            </div>
            <div class="advertise-copy">
                <p data-i18n="advertise.text"><?= deseo_e(deseo_t('advertise.text')) ?></p>
                <a class="button button-primary" href="https://iluma.gr/contact/" target="_blank" rel="noopener noreferrer" data-i18n="advertise.cta" data-analytics-event="advertising_cta_click"><?= deseo_e(deseo_t('advertise.cta')) ?></a>
            </div>
        </div>
    </section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>