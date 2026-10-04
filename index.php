<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/i18n.php';

function deseo_e(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function deseo_time(?string $value): string {
    if (!$value) return '--:--';
    $ts = strtotime($value);
    return $ts === false ? '--:--' : date('H:i', $ts);
}

$airplay_tracks = [];
$program = [];
$todays_program = [];
$playlists = [];
$live_dj = null;
$next_dj = null;
$dataStatus = 'live';

$tz = new DateTimeZone('Europe/Athens');
$now = new DateTimeImmutable('now', $tz);

// Season 6 premiere: 14 October 2026, 20:00 Athens time.
$lineupStart = new DateTimeImmutable('2026-10-14 20:00:00', $tz);
$lineupRemainingSeconds = max(0, $lineupStart->getTimestamp() - $now->getTimestamp());
$lineupRemainingMinutes = (int)ceil($lineupRemainingSeconds / 60);
$lineupDays = intdiv($lineupRemainingMinutes, 1440);
$lineupHours = intdiv($lineupRemainingMinutes % 1440, 60);
$lineupMinutes = $lineupRemainingMinutes % 60;
$lineupOnAir = $lineupRemainingSeconds === 0;

try {
    require_once __DIR__ . '/iluma/connection.php';
    require_once __DIR__ . '/includes/mylive-email-reminders.php';
    deseo_mylive_maybe_run_email_scheduler($pdo);

    $airplay_tracks = $pdo->query(
        "SELECT id, spotify_url, track_name, artist_name, artwork_url, position
         FROM airplay
         WHERE position BETWEEN 1 AND 10
         ORDER BY position ASC
         LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    try {
        $program = $pdo->query(
            "SELECT id, dj_name, photo_path, mylive_account_id, day_of_week, start_time, end_time
             FROM program
             WHERE day_of_week BETWEEN 1 AND 7
             ORDER BY day_of_week ASC, start_time ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $programProfileColumnError) {
        $program = $pdo->query(
            "SELECT id, dj_name, photo_path, NULL AS mylive_account_id, day_of_week, start_time, end_time
             FROM program
             WHERE day_of_week BETWEEN 1 AND 7
             ORDER BY day_of_week ASC, start_time ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    $publishedProfiles = [];
    $profileAccountIds = array_values(array_unique(array_filter(array_map(
        static fn(array $show): int => (int)($show['mylive_account_id'] ?? 0),
        $program
    ))));

    if ($profileAccountIds) {
        try {
            $profilePlaceholders = implode(',', array_fill(0, count($profileAccountIds), '?'));
            $profileStmt = $pdo->prepare(
                "SELECT a.id AS account_id,
                        a.artist_name,
                        p.published_bio AS bio,
                        p.published_instagram AS instagram,
                        p.published_tiktok AS tiktok,
                        p.published_soundcloud AS soundcloud,
                        p.published_spotify AS spotify,
                        p.published_website AS website
                 FROM dj_portal_accounts a
                 INNER JOIN dj_public_profiles p ON p.account_id = a.id
                 WHERE a.id IN ($profilePlaceholders)
                   AND a.is_active = 1
                   AND a.account_status = 'active'
                   AND a.public_profile_enabled = 1
                   AND p.is_published = 1"
            );
            $profileStmt->execute($profileAccountIds);

            foreach ($profileStmt->fetchAll(PDO::FETCH_ASSOC) as $profileRow) {
                $publishedProfiles[(int)$profileRow['account_id']] = [
                    'artist_name' => (string)$profileRow['artist_name'],
                    'bio' => (string)$profileRow['bio'],
                    'instagram' => (string)$profileRow['instagram'],
                    'tiktok' => (string)$profileRow['tiktok'],
                    'soundcloud' => (string)$profileRow['soundcloud'],
                    'spotify' => (string)$profileRow['spotify'],
                    'website' => (string)$profileRow['website'],
                ];
            }
        } catch (Throwable $profileError) {
            error_log('Deseo public DJ profiles unavailable: ' . $profileError->getMessage());
            $publishedProfiles = [];
        }
    }

    foreach ($program as &$programShow) {
        $profileId = (int)($programShow['mylive_account_id'] ?? 0);
        $programShow['public_profile'] = $profileId > 0 && isset($publishedProfiles[$profileId])
            ? $publishedProfiles[$profileId]
            : null;
    }
    unset($programShow);

    try {
        $playlists = $pdo->query(
            "SELECT id, spotify_url, title, artwork_url, position
             FROM playlists
             ORDER BY position ASC, id DESC
             LIMIT 12"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $playlistError) {
        error_log('Deseo playlists unavailable: ' . $playlistError->getMessage());
        $playlists = [];
    }

    $weekStart = $now->modify('monday this week')->setTime(0, 0, 0);
    $occurrences = [];

    foreach ($program as $show) {
        $day = max(1, min(7, (int)($show['day_of_week'] ?? 1)));

        foreach ([-7, 0, 7] as $weekOffset) {
            $baseDate = $weekStart->modify(($weekOffset + $day - 1) . ' days');

            $startParts = array_map('intval', explode(':', (string)($show['start_time'] ?? '00:00:00')));
            $endParts = array_map('intval', explode(':', (string)($show['end_time'] ?? '23:59:59')));

            $start = $baseDate->setTime($startParts[0] ?? 0, $startParts[1] ?? 0, $startParts[2] ?? 0);
            $end = $baseDate->setTime($endParts[0] ?? 23, $endParts[1] ?? 59, $endParts[2] ?? 59);

            if ($end <= $start) $end = $end->modify('+1 day');

            $occurrences[] = [
                'show' => $show,
                'start' => $start,
                'end' => $end,
            ];
        }
    }

    usort($occurrences, static fn($a, $b) => $a['start'] <=> $b['start']);

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
        if ($occurrence['start']->format('Y-m-d') !== $todayIso) continue;

        $row = $occurrence['show'];
        $row['_start'] = $occurrence['start'];
        $row['_end'] = $occurrence['end'];
        $todays_program[] = $row;
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

if (isset($_GET['program_feed']) && $_GET['program_feed'] === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $serializeShow = static function (?array $show): ?array {
        if (!$show) return null;

        return [
            'id' => (int)($show['id'] ?? 0),
            'dj_name' => (string)($show['dj_name'] ?? ''),
            'mylive_account_id' => (int)($show['mylive_account_id'] ?? 0),
            'photo_path' => (string)($show['photo_path'] ?? ''),
            'start_time' => (string)($show['start_time'] ?? ''),
            'end_time' => (string)($show['end_time'] ?? ''),
            'profile' => is_array($show['public_profile'] ?? null) ? $show['public_profile'] : null,
        ];
    };

    $todayFeed = array_map(
        static function (array $show) use ($serializeShow, $live_dj): array {
            $row = $serializeShow($show) ?? [];
            $row['is_live'] = $live_dj && (int)$live_dj['id'] === (int)($show['id'] ?? 0);
            return $row;
        },
        $todays_program
    );

    echo json_encode([
        'ok' => $dataStatus === 'live',
        'generated_at' => $now->format(DATE_ATOM),
        'live' => $serializeShow($live_dj),
        'next' => $serializeShow($next_dj),
        'today' => $todayFeed,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require_once __DIR__ . '/includes/head-meta.php';
require_once __DIR__ . '/includes/header.php';

$partners = [
    1 => ['url' => 'https://play.iradios.gr/station/deseo-radio', 'name' => 'iRadios'],
    2 => ['url' => 'https://onlineradiobox.com/gr/deseo/', 'name' => 'Online Radio Box'],
    3 => ['url' => 'https://www.getmeradio.com/stations/deseoradiogr-4835/', 'name' => 'Get Me Radio'],
    5 => ['url' => 'https://tunein.com/radio/Deseo-Radio-s258242/', 'name' => 'TuneIn'],
    6 => ['url' => 'https://vradio.app/play?id=20739', 'name' => 'VRadio'],
    8 => ['url' => 'https://iluma.gr/radios', 'name' => 'ILUMA Radios'],
    9 => ['url' => 'https://streamee.com/fm_radio/deseo-radio/', 'name' => 'Streamee', 'image' => 'partner-9.svg'],
    10 => ['url' => 'https://mytuner-radio.com/radio/deseo-radio-479969/', 'name' => 'myTuner Radio', 'image' => 'partner-10.png'],
];
?>

<main id="main-content">
    <section class="classic-hero" id="live">
        <div class="classic-hero-bg" aria-hidden="true">
            <img src="/assets/img/bg.png?v=<?= $assetVersion ?>" alt="">
            <div class="classic-hero-dim"></div>
            <div class="classic-hero-gradient"></div>
        </div>

        <div class="wide-shell classic-hero-grid">
            <div class="hero-deck" id="player">
                <div class="hero-player-surface" id="deseo-live-player">
                    <div class="hero-deck-label">
                        <span class="live-pulse" aria-hidden="true"></span>
                        <span>NOW PLAYING</span>
                    </div>

                    <div class="hero-square hero-player-card">
                        <iframe title="Deseo Radio live player" src="https://play.iradios.gr/widget/deseo-radio?autoplay=true" width="100%" frameborder="0" allow="autoplay; encrypted-media; clipboard-write;" style="border:none; width: 100%; max-width: 600px; aspect-ratio: 1 / 1; margin: 0 auto; display: block; box-shadow: 0 20px 40px rgba(0,0,0,0.5); border-radius: 32px; overflow: hidden;"></iframe>
                    </div>
                </div>
            </div>

            <div class="hero-deck" id="live-program-deck" aria-live="polite">
                <div class="hero-onair-surface" id="deseo-onair-card">
                    <div class="hero-deck-label">
                    <span class="live-pulse <?= $live_dj ? '' : 'is-muted' ?>" aria-hidden="true"></span>
                    <span>NOW ON AIR</span>
                </div>

                <?php $liveProfile = is_array($live_dj['public_profile'] ?? null) ? $live_dj['public_profile'] : null; ?>
                <div class="hero-square hero-cms-card <?= $liveProfile ? 'has-dj-profile' : '' ?>"
                     <?= $liveProfile ? 'data-profile-open role="button" tabindex="0"' : '' ?>
                     <?php if ($liveProfile): ?>
                         data-profile-json="<?= deseo_e(json_encode($liveProfile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
                         data-profile-photo="<?= deseo_e($live_dj['photo_path'] ?: '/assets/img/bg.png') ?>"
                         data-profile-show="<?= deseo_e((string)$live_dj['dj_name']) ?>"
                         data-profile-time="<?= deseo_e(deseo_time($live_dj['start_time']) . ' — ' . deseo_time($live_dj['end_time'])) ?>"
                     <?php endif; ?>>
                    <?php if ($live_dj): ?>
                        <img src="<?= deseo_e($live_dj['photo_path'] ?: '/assets/img/bg.png') ?>"
                             data-fallback="/assets/img/bg.png?v=<?= $assetVersion ?>"
                             alt="<?= deseo_e($live_dj['dj_name']) ?>">

                        <div class="hero-cms-overlay">
                            <span data-i18n="hero.live_broadcast"><?= deseo_e(deseo_t('hero.live_broadcast')) ?></span>
                            <h2><?= deseo_e($live_dj['dj_name']) ?></h2>
                            <p><?= deseo_time($live_dj['start_time']) ?> — <?= deseo_time($live_dj['end_time']) ?></p>
                        </div>
                    <?php else: ?>
                        <img src="/assets/img/bg.png?v=<?= $assetVersion ?>" alt="Deseo Radio Auto DJ">
                        <div class="hero-cms-overlay">
                            <span>NON-STOP MIX</span>
                            <h2 data-i18n="hero.non_stop"><?= deseo_e(deseo_t('hero.non_stop')) ?></h2>
                            <p>24/7</p>
                        </div>
                    <?php endif; ?>
                </div>
                </div>
            </div>

            <div class="hero-deck" id="sponsor-deck">
                <div class="hero-sponsor-surface" id="deseo-sponsor-card">
                    <div class="hero-deck-label hero-deck-label-muted">
                        <span>SPONSOR</span>
                    </div>

                    <a class="hero-square hero-sponsor-card"
                       href="https://iluma.gr/"
                       target="_blank"
                       rel="noopener noreferrer"
                       data-analytics-event="sponsor_click"
                       data-iluma-signal-slot="hero-sponsor">
                        <img src="/assets/img/iluma-digital-agency-banner.jpg" alt="ILUMA Digital Agency" data-iluma-signal-image>
                    </a>
                </div>
            </div>
        </div>
    </section>

    <style>
    /* Homepage only: align header, hero, every content section and footer to 1300px.
       Full-bleed section backgrounds and lightboxes stay viewport-wide. */
    .wide-shell { max-width:1300px; }

    /* The three original hero cards form a single right-hand floating column.
       Critical styles live with the homepage so cached CSS cannot hide the iframe. */
    @media (min-width:781px) {
        .classic-hero { overflow:visible; isolation:auto; }
        .classic-hero-grid {
            z-index:auto;
            /* Cap at 218px but fit all three cards, two gaps and safe margins vertically. */
            --deseo-mini-card-size:min(218px, calc(33.333vh - 36px), calc(100vw - 40px));
            --deseo-mini-card-size:min(218px, calc(33.333dvh - 36px), calc(100vw - 40px));
            --deseo-mini-card-height:calc(var(--deseo-mini-card-size) + 20px);
        }
        #deseo-live-player.is-floating,
        #deseo-onair-card.is-floating,
        #deseo-sponsor-card.is-floating {
            position:fixed !important;
            display:block !important;
            visibility:visible !important;
            opacity:1 !important;
            z-index:10001 !important;
            top:auto !important;
            left:auto !important;
            right:calc(18px + var(--safe-right, 0px)) !important;
            width:var(--deseo-mini-card-size) !important;
            max-width:calc(100vw - 40px);
            transform-origin:top left;
            filter:drop-shadow(0 14px 32px rgba(0,0,0,.70));
        }
        /* From bottom to top: sponsor, on air, player. Compact 8px gaps preserve space on scaled laptop displays. */
        #deseo-sponsor-card.is-floating {
            bottom:calc(12px + var(--safe-bottom, 0px)) !important;
        }
        #deseo-onair-card.is-floating {
            bottom:calc(20px + var(--safe-bottom, 0px) + var(--deseo-mini-card-height)) !important;
        }
        #deseo-live-player.is-floating {
            bottom:calc(28px + var(--safe-bottom, 0px) + var(--deseo-mini-card-height) + var(--deseo-mini-card-height)) !important;
        }
        #deseo-live-player.is-floating .hero-deck-label,
        #deseo-onair-card.is-floating .hero-deck-label,
        #deseo-sponsor-card.is-floating .hero-deck-label {
            box-sizing:border-box;
            min-height:16px;
            height:16px;
            margin:0 0 4px 3px;
            padding-left:0;
            gap:8px;
            font-size:8px;
            letter-spacing:.14em;
        }
        #deseo-live-player.is-floating .hero-square,
        #deseo-onair-card.is-floating .hero-square,
        #deseo-sponsor-card.is-floating .hero-square {
            aspect-ratio:1 / 1;
            height:var(--deseo-mini-card-size);
            min-height:0;
            border-radius:17px;
            border-color:rgba(255,255,255,.16);
            box-shadow:0 14px 32px rgba(0,0,0,.62);
        }
        /* Render the provider widget at its normal desktop dimensions, then
           scale its entire view into the mini card. Directly narrowing the iframe
           makes the provider's own controls and captions overflow/crop. */
        #deseo-live-player.is-floating .hero-player-card {
            display:block;
            overflow:hidden;
        }
        #deseo-live-player.is-floating .hero-player-card iframe {
            display:block !important;
            flex:none !important;
            width:512px !important;
            height:512px !important;
            min-width:512px !important;
            max-width:none !important;
            max-height:none !important;
            aspect-ratio:1 / 1;
            margin:0 !important;
            transform:scale(var(--deseo-iframe-scale, .4));
            transform-origin:top left;
            border-radius:0 !important;
            box-shadow:none !important;
        }
        #deseo-onair-card.is-floating .hero-cms-overlay { padding:11px; }
        #deseo-onair-card.is-floating .hero-cms-overlay > span {
            font-size:7px;
            margin-bottom:3px;
        }
        #deseo-onair-card.is-floating .hero-cms-overlay h2 {
            font-size:15px;
            line-height:1.1;
            display:-webkit-box;
            -webkit-box-orient:vertical;
            -webkit-line-clamp:2;
            overflow:hidden;
        }
        #deseo-onair-card.is-floating .hero-cms-overlay p {
            font-size:9px;
            margin-top:4px;
        }
        #deseo-onair-card.is-floating .hero-cms-card.has-dj-profile::before { display:none; }
    }
    </style>

    <script>
    // Keep a single iRadios iframe and the existing CMS / sponsor links mounted.
    // All three cards enter and leave the floating column together.
    (() => {
        const heroGrid = document.querySelector('.classic-hero-grid');
        const cards = [
            { anchor:document.getElementById('player'), surface:document.getElementById('deseo-live-player'), labelSpace:39 },
            { anchor:document.getElementById('live-program-deck'), surface:document.getElementById('deseo-onair-card'), labelSpace:39 },
            { anchor:document.getElementById('sponsor-deck'), surface:document.getElementById('deseo-sponsor-card'), labelSpace:39 }
        ];
        if (!heroGrid || cards.some(card => !card.anchor || !card.surface)) return;

        const desktop = window.matchMedia('(min-width: 781px) and (hover: hover) and (pointer: fine)');
        const tabletLandscape = window.matchMedia('(min-width: 900px) and (min-height: 600px) and (orientation: landscape)');
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
        const canFloat = () => desktop.matches || tabletLandscape.matches;
        let floating = false;
        let initialized = false;
        let pendingFrame = false;
        const playerCard = cards[0].surface.querySelector('.hero-player-card');
        const syncPlayerScale = () => {
            if (!floating || !playerCard) {
                cards[0].surface.style.removeProperty('--deseo-iframe-scale');
                return;
            }
            // Offset width is independent of the FLIP animation transform.
            const square = playerCard.clientWidth;
            if (square > 0) {
                cards[0].surface.style.setProperty('--deseo-iframe-scale', String(square / 512));
            }
        };

        const setFloating = (next, animate) => {
            if (next === floating) return;
            // Measure all three before changing styles. Never reparent or clone an iframe.
            const before = cards.map(card => {
                if (card.surface.getAnimations) card.surface.getAnimations().forEach(animation => animation.cancel());
                return card.surface.getBoundingClientRect();
            });
            if (next) {
                cards.forEach((card, i) => {
                    card.labelSpace = before[i].height - before[i].width;
                    card.anchor.style.minHeight = Math.ceil(before[i].height) + 'px';
                });
            }
            cards.forEach(card => card.surface.classList.toggle('is-floating', next));
            floating = next;
            if (!next) cards.forEach(card => { card.anchor.style.minHeight = ''; });
            // Only CSS changes on the existing iframe: never change src or reparent it.
            syncPlayerScale();

            if (!animate || reducedMotion.matches) return;
            cards.forEach((card, i) => {
                if (!card.surface.animate) return;
                const after = card.surface.getBoundingClientRect();
                if (!after.width || !after.height) return;
                card.surface.animate([
                    {
                        transform:'translate3d(' + (before[i].left - after.left) + 'px,' + (before[i].top - after.top) + 'px,0) scale(' + (before[i].width / after.width) + ',' + (before[i].height / after.height) + ')',
                        opacity:.94
                    },
                    { transform:'translate3d(0,0,0) scale(1,1)', opacity:1 }
                ], { duration:440, easing:'cubic-bezier(.22,1,.36,1)' });
            });
        };

        const update = () => {
            pendingFrame = false;
            if (!canFloat()) {
                setFloating(false, false);
                initialized = true;
                return;
            }
            if (floating) {
                // Preserve the original grid cells and adapt the provider's artwork/
                // controls to the available height (including laptop display scaling).
                cards.forEach(card => {
                    card.anchor.style.minHeight = Math.ceil(card.anchor.getBoundingClientRect().width + card.labelSpace) + 'px';
                });
                syncPlayerScale();
            }
            const bottom = heroGrid.getBoundingClientRect().bottom;
            // One shared trigger prevents a missing player or overlapping right-side cards.
            const next = floating ? bottom < window.innerHeight * .75 : bottom < -8;
            setFloating(next, initialized);
            initialized = true;
        };

        const schedule = () => {
            if (pendingFrame) return;
            pendingFrame = true;
            window.requestAnimationFrame(update);
        };
        window.addEventListener('scroll', schedule, { passive:true });
        window.addEventListener('resize', schedule, { passive:true });
        window.addEventListener('pageshow', schedule);
        schedule();
    })();
    </script>

    <section class="deseo-dashboard-section" id="discover">
        <div class="wide-shell">
            <div class="deseo-dashboard-intro">
                <div>
                    <span class="kicker" data-i18n="discover.kicker"><?= deseo_e(deseo_t('discover.kicker')) ?></span>
                    <h2 class="section-title" data-i18n="discover.title"><?= deseo_e(deseo_t('discover.title')) ?></h2>
                </div>
                <p data-i18n="discover.text"><?= deseo_e(deseo_t('discover.text')) ?></p>
            </div>

            <div class="deseo-dashboard-grid">
                <article class="deseo-panel" id="airplay">
                    <header class="deseo-panel-header">
                        <h3>HOT TRACKS</h3>
                        <span>DESEO RADIO</span>
                    </header>

                    <div class="deseo-panel-body">
                        <?php if ($airplay_tracks): ?>
                            <?php foreach (array_slice($airplay_tracks, 0, 6) as $track): ?>
                                <a class="deseo-panel-row"
                                   href="<?= deseo_e($track['spotify_url']) ?>"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   data-analytics-event="spotify_track_click">
                                    <img class="deseo-row-cover"
                                         src="<?= deseo_e($track['artwork_url'] ?: '/assets/img/favicon.png') ?>"
                                         data-fallback="/assets/img/favicon.png"
                                         alt="">

                                    <span class="deseo-row-copy">
                                        <strong><?= deseo_e($track['track_name'] ?: 'Deseo Selection') ?></strong>
                                        <small><?= deseo_e(($track['artist_name'] && $track['artist_name'] !== 'Διάφοροι / Μη διαθέσιμο') ? $track['artist_name'] : 'Deseo Radio Selection') ?></small>
                                    </span>

                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="deseo-panel-empty" data-i18n="airplay.empty"><?= deseo_e(deseo_t('airplay.empty')) ?></div>
                        <?php endif; ?>
                    </div>
                </article>

                <article class="deseo-panel" id="program">
                    <header class="deseo-panel-header">
                        <h3 data-i18n="program.panel_title"><?= deseo_e(deseo_t('program.panel_title')) ?></h3>
                        <span>DESEO RADIO</span>
                    </header>

                    <div class="deseo-panel-body" id="program-panel-body" aria-live="polite">
                        <?php if ($todays_program): ?>
                            <?php foreach ($todays_program as $show):
                                $isLiveRow = $live_dj && (int)$live_dj['id'] === (int)$show['id'];
                                $showProfile = is_array($show['public_profile'] ?? null) ? $show['public_profile'] : null;
                            ?>
                                <div class="deseo-panel-row deseo-program-row <?= $isLiveRow ? 'is-live' : '' ?> <?= $showProfile ? 'has-dj-profile' : '' ?>"
                                     <?= $showProfile ? 'data-profile-open role="button" tabindex="0"' : '' ?>
                                     <?php if ($showProfile): ?>
                                         data-profile-json="<?= deseo_e(json_encode($showProfile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
                                         data-profile-photo="<?= deseo_e($show['photo_path'] ?: '/assets/img/bg.png') ?>"
                                         data-profile-show="<?= deseo_e((string)$show['dj_name']) ?>"
                                         data-profile-time="<?= deseo_e(deseo_time($show['start_time']) . ' — ' . deseo_time($show['end_time'])) ?>"
                                     <?php endif; ?>>
                                    <img class="deseo-row-cover"
                                         src="<?= deseo_e($show['photo_path'] ?: '/assets/img/bg.png') ?>"
                                         data-fallback="/assets/img/bg.png"
                                         alt="<?= deseo_e($show['dj_name']) ?>">

                                    <span class="deseo-row-copy">
                                        <small><?= deseo_time($show['start_time']) ?> — <?= deseo_time($show['end_time']) ?></small>
                                        <strong><?= deseo_e($show['dj_name']) ?></strong>
                                    </span>

                                    <?php if ($isLiveRow): ?>
                                        <span class="deseo-live-tag">LIVE</span>
                                    <?php elseif ($showProfile): ?>
                                        <span class="deseo-profile-tag">PROFILE</span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>

                            <?php if ($next_dj): ?>
                                <div class="deseo-next-pill">
                                    <span data-i18n="program.next"><?= deseo_e(deseo_t('program.next')) ?></span>
                                    <strong><?= deseo_e($next_dj['dj_name']) ?></strong>
                                    <small>· <?= deseo_time($next_dj['start_time']) ?></small>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="deseo-panel-empty" data-i18n="program.empty"><?= deseo_e(deseo_t('program.empty')) ?></div>
                        <?php endif; ?>
                    </div>
                </article>

                <article class="deseo-panel" id="playlists">
                    <header class="deseo-panel-header">
                        <h3>PLAYLISTS</h3>
                        <span>CMS</span>
                    </header>

                    <div class="deseo-panel-body">
                        <?php if ($playlists): ?>
                            <?php foreach (array_slice($playlists, 0, 6) as $playlist): ?>
                                <a class="deseo-panel-row deseo-playlist-row"
                                   href="<?= deseo_e($playlist['spotify_url']) ?>"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   data-analytics-event="playlist_click">
                                    <span class="deseo-row-index"><?= str_pad((string)(int)$playlist['position'], 2, '0', STR_PAD_LEFT) ?></span>

                                    <img class="deseo-row-cover"
                                         src="<?= deseo_e($playlist['artwork_url'] ?: '/assets/img/favicon.png') ?>"
                                         data-fallback="/assets/img/favicon.png"
                                         alt="<?= deseo_e($playlist['title']) ?>">

                                    <span class="deseo-row-copy">
                                        <strong><?= deseo_e($playlist['title']) ?></strong>
                                        <small>Spotify · Deseo Radio Playlist</small>
                                    </span>

                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="deseo-panel-empty" data-i18n="playlists.empty"><?= deseo_e(deseo_t('playlists.empty')) ?></div>
                        <?php endif; ?>
                    </div>
                </article>
            </div>
        </div>
    </section>

    <section class="content-section season-lineup-section" id="season-6">
        <div class="wide-shell season-lineup-shell">
            <div class="season-lineup-grid">
                <header class="season-lineup-copy reveal">
                    <span class="kicker" data-i18n="lineup.kicker"><?= deseo_e(deseo_t('lineup.kicker')) ?></span>
                    <h2 class="metal-title section-title" data-i18n="lineup.title"><?= deseo_e(deseo_t('lineup.title')) ?></h2>
                    <p class="season-lineup-tagline" data-i18n="lineup.tagline"><?= deseo_e(deseo_t('lineup.tagline')) ?></p>
                    <span class="season-lineup-meta" data-i18n="lineup.schedule"><?= deseo_e(deseo_t('lineup.schedule')) ?></span>

                    <div class="season-lineup-start" data-lineup-countdown-container data-lineup-target="<?= deseo_e($lineupStart->format(DATE_ATOM)) ?>">
                        <span data-i18n="lineup.start_label"><?= deseo_e(deseo_t('lineup.start_label')) ?></span>
                        <strong class="season-lineup-countdown" data-lineup-countdown role="timer" aria-live="off" <?= $lineupOnAir ? 'hidden' : '' ?>>
                            <span class="season-lineup-time-unit">
                                <span class="season-lineup-time-value" data-lineup-days><?= str_pad((string)$lineupDays, 2, '0', STR_PAD_LEFT) ?></span>
                                <span class="season-lineup-time-label" data-i18n="lineup.days"><?= deseo_e(deseo_t('lineup.days')) ?></span>
                            </span>
                            <span class="season-lineup-time-unit">
                                <span class="season-lineup-time-value" data-lineup-hours><?= str_pad((string)$lineupHours, 2, '0', STR_PAD_LEFT) ?></span>
                                <span class="season-lineup-time-label" data-i18n="lineup.hours"><?= deseo_e(deseo_t('lineup.hours')) ?></span>
                            </span>
                            <span class="season-lineup-time-unit">
                                <span class="season-lineup-time-value" data-lineup-minutes><?= str_pad((string)$lineupMinutes, 2, '0', STR_PAD_LEFT) ?></span>
                                <span class="season-lineup-time-label" data-i18n="lineup.minutes"><?= deseo_e(deseo_t('lineup.minutes')) ?></span>
                            </span>
                        </strong>
                        <strong class="season-lineup-onair" data-lineup-onair data-i18n="lineup.start_value" <?= $lineupOnAir ? '' : 'hidden' ?>><?= deseo_e(deseo_t('lineup.start_value')) ?></strong>
                    </div>

                    <div class="season-lineup-platforms" aria-label="Deseo Radio DJ Set platforms">
                        <span class="season-lineup-platforms-label" data-i18n="lineup.platforms"><?= deseo_e(deseo_t('lineup.platforms')) ?></span>
                        <div class="season-lineup-platform-links">
                            <a href="https://www.mixcloud.com/deseoradio/" target="_blank" rel="noopener noreferrer" aria-label="Mixcloud" title="Mixcloud" data-analytics-event="season_mixcloud_click">
                                <svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M3.5 20V12m4 11V9m4 15V7m4 17V11"/><path d="M19 23h6a4 4 0 0 0 .3-8 6 6 0 0 0-10.2-3"/>
                                </svg>
                            </a>
                            <a href="https://hearthis.at/deseoradio/set/season-6/" target="_blank" rel="noopener noreferrer" aria-label="hearthis.at" title="hearthis.at" data-analytics-event="season_hearthis_click">
                                <svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M16 27S4.5 20.2 4.5 12.5a6 6 0 0 1 11.5-2.4 6 6 0 0 1 11.5 2.4C27.5 20.2 16 27 16 27Z"/>
                                    <path d="M8.5 16h3l1.4-3.5 2.8 7 2.2-5 1.2 1.5h4.4"/>
                                </svg>
                            </a>
                            <a href="https://podcasts.apple.com/us/podcast/deseo-radioshows/id1711008342" target="_blank" rel="noopener noreferrer" aria-label="Apple Podcasts" title="Apple Podcasts" data-analytics-event="season_apple_podcasts_click">
                                <svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <circle cx="16" cy="14" r="2.3"/><path d="M13.2 21c.2-2 1.3-3.4 2.8-3.4s2.6 1.4 2.8 3.4l-.6 6h-4.4l-.6-6ZM9.5 19a9 9 0 1 1 13 0M12 17a5.5 5.5 0 1 1 8 0"/>
                                </svg>
                            </a>
                            <a class="season-lineup-podcast-link" href="https://open.spotify.com/show/2x8ceF2a3gMmzEJ8y6W1ue?si=_3btuPp0Qy-nHs950YFIpg" target="_blank" rel="noopener noreferrer" aria-label="Spotify · Deseo Podcasts · Coming Soon" title="Spotify · Deseo Podcasts · Coming Soon" data-analytics-event="deseo_podcasts_spotify_lineup_click">
                                <svg viewBox="0 0 32 32" width="27" height="27" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                                    <circle cx="16" cy="16" r="12.8"/><path d="M8.5 12.1c5.7-1.7 11.4-.9 16 2.1M9.3 16.4c4.8-1.4 9.9-.6 13.7 1.9M10.5 20.5c3.7-1 7.7-.5 10.7 1.5"/>
                                </svg>
                            </a>
                            <a href="https://www.deezer.com/en/show/1000338791" target="_blank" rel="noopener noreferrer" aria-label="Deezer" title="Deezer" data-analytics-event="season_deezer_click">
                                <svg viewBox="0 0 32 32" fill="currentColor" aria-hidden="true">
                                    <rect x="3" y="22" width="5" height="3" rx=".5"/><rect x="3" y="17" width="5" height="3" rx=".5"/>
                                    <rect x="10" y="22" width="5" height="3" rx=".5"/><rect x="10" y="17" width="5" height="3" rx=".5"/><rect x="10" y="12" width="5" height="3" rx=".5"/>
                                    <rect x="17" y="22" width="5" height="3" rx=".5"/><rect x="17" y="17" width="5" height="3" rx=".5"/><rect x="17" y="12" width="5" height="3" rx=".5"/><rect x="17" y="7" width="5" height="3" rx=".5"/>
                                    <rect x="24" y="22" width="5" height="3" rx=".5"/><rect x="24" y="17" width="5" height="3" rx=".5"/><rect x="24" y="12" width="5" height="3" rx=".5"/><rect x="24" y="7" width="5" height="3" rx=".5"/>
                                </svg>
                            </a>
                        </div>
                    </div>
                </header>

                <button class="season-lineup-poster reveal"
                        type="button"
                        data-lineup-open
                        data-i18n-aria="lineup.zoom"
                        aria-label="<?= deseo_e(deseo_t('lineup.zoom')) ?>">
                    <span class="season-lineup-poster-frame">
                        <picture>
                            <source media="(max-width: 700px)" srcset="/assets/img/s6lineup_mobile.png?v=<?= $assetVersion ?>">
                            <img src="/assets/img/season6%20lineup.png?v=<?= $assetVersion ?>"
                                 alt="Deseo Radio Season 6 Line Up"
                                 width="1500"
                                 height="1000">
                        </picture>
                    </span>
                    <span class="season-lineup-poster-action" data-i18n="lineup.open"><?= deseo_e(deseo_t('lineup.open')) ?></span>
                </button>
            </div>
        </div>
    </section>

    <section class="content-section section-dark" id="network">
        <div class="wide-shell">
            <div class="section-head reveal">
                <div>
                    <span class="kicker" data-i18n="partners.kicker"><?= deseo_e(deseo_t('partners.kicker')) ?></span>
                    <h2 class="metal-title section-title" data-i18n="partners.title"><?= deseo_e(deseo_t('partners.title')) ?></h2>
                </div>
                <p data-i18n="partners.text"><?= deseo_e(deseo_t('partners.text')) ?></p>
            </div>

            <div class="partner-grid-v3 reveal">
                <?php foreach ($partners as $id => $partner): ?>
                    <a href="<?= deseo_e($partner['url']) ?>"
                       target="_blank"
                       rel="noopener noreferrer"
                       aria-label="<?= deseo_e($partner['name']) ?>"
                       data-analytics-event="radio_partner_click">
                        <img src="/assets/img/<?= deseo_e($partner['image'] ?? ('partner-' . $id . '.png')) ?>"
                             data-fallback="/assets/img/deseoradio-logo.png"
                             alt="<?= deseo_e($partner['name']) ?>">
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>


    <section class="about-strip">
        <div class="wide-shell about-grid">
            <div class="reveal">
                <span class="kicker" data-i18n="about.kicker"><?= deseo_e(deseo_t('about.kicker')) ?></span>
                <h2 class="metal-title section-title" data-i18n="about.title"><?= deseo_e(deseo_t('about.title')) ?></h2>
            </div>
            <div class="about-copy reveal">
                <p data-i18n="about.intro"><?= deseo_e(deseo_t('about.intro')) ?></p>
                <p class="about-created"><span data-i18n="about.created.before"><?= deseo_e(deseo_t('about.created.before')) ?></span><strong>Deseo Radio</strong><span data-i18n="about.created.after"><?= deseo_e(deseo_t('about.created.after')) ?></span></p>
                <p data-i18n="about.music"><?= deseo_e(deseo_t('about.music')) ?></p>
                <p data-i18n="about.rhythm"><?= deseo_e(deseo_t('about.rhythm')) ?></p>
                <p data-i18n="about.moments"><?= deseo_e(deseo_t('about.moments')) ?></p>
                <div class="about-signoff">
                    <strong data-i18n="about.promise"><?= deseo_e(deseo_t('about.promise')) ?></strong>
                    <strong data-i18n="about.tagline"><?= deseo_e(deseo_t('about.tagline')) ?></strong>
                    <em>An ILUMA Radios Experience.</em>
                </div>
                <a href="https://iluma.gr/radios" target="_blank" rel="noopener noreferrer" data-i18n="about.iluma" data-analytics-event="iluma_network_click"><?= deseo_e(deseo_t('about.iluma')) ?></a>
            </div>
        </div>
    </section>

    <section class="deseo-podcasts-section" id="deseo-podcasts" aria-labelledby="deseo-podcasts-title">
        <div class="wide-shell">
            <div class="deseo-podcasts-panel">
                <div class="deseo-podcasts-content reveal">
                    <div class="deseo-podcasts-kicker">
                        <span class="deseo-podcasts-signal" aria-hidden="true"></span>
                        <span data-i18n="podcasts.kicker"><?= deseo_e(deseo_t('podcasts.kicker')) ?></span>
                    </div>
                    <div class="deseo-podcasts-heading">
                        <span class="deseo-podcasts-soon" data-i18n="podcasts.soon"><?= deseo_e(deseo_t('podcasts.soon')) ?></span>
                        <h2 id="deseo-podcasts-title" class="metal-title">
                            <span data-i18n="podcasts.title_first"><?= deseo_e(deseo_t('podcasts.title_first')) ?></span>
                            <span data-i18n="podcasts.title_second"><?= deseo_e(deseo_t('podcasts.title_second')) ?></span>
                        </h2>
                    </div>
                    <p class="deseo-podcasts-lead" data-i18n="podcasts.lead"><?= deseo_e(deseo_t('podcasts.lead')) ?></p>
                    <p class="deseo-podcasts-description" data-i18n="podcasts.description"><?= deseo_e(deseo_t('podcasts.description')) ?></p>
                    <div class="deseo-podcasts-topics" aria-label="<?= deseo_e(deseo_t('podcasts.topics_aria')) ?>">
                        <span data-i18n="podcasts.topic_djs"><?= deseo_e(deseo_t('podcasts.topic_djs')) ?></span>
                        <span data-i18n="podcasts.topic_events"><?= deseo_e(deseo_t('podcasts.topic_events')) ?></span>
                        <span data-i18n="podcasts.topic_backstage"><?= deseo_e(deseo_t('podcasts.topic_backstage')) ?></span>
                    </div>
                    <div class="deseo-podcasts-action">
                        <a class="deseo-podcasts-spotify-button" href="https://open.spotify.com/show/2x8ceF2a3gMmzEJ8y6W1ue?si=_3btuPp0Qy-nHs950YFIpg" target="_blank" rel="noopener noreferrer" data-analytics-event="deseo_podcasts_spotify_teaser_click" aria-label="<?= deseo_e(deseo_t('podcasts.cta_aria')) ?>">
                            <svg viewBox="0 0 32 32" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                                <circle cx="16" cy="16" r="13"/><path d="M8.5 12.1c5.7-1.7 11.4-.9 16 2.1M9.3 16.4c4.8-1.4 9.9-.6 13.7 1.9M10.5 20.5c3.7-1 7.7-.5 10.7 1.5"/>
                            </svg>
                            <span data-i18n="podcasts.cta"><?= deseo_e(deseo_t('podcasts.cta')) ?></span>
                        </a>
                        <span class="deseo-podcasts-exclusive" data-i18n="podcasts.exclusive"><?= deseo_e(deseo_t('podcasts.exclusive')) ?></span>
                    </div>
                </div>
                <div class="deseo-podcasts-art reveal" aria-hidden="true">
                    <span class="deseo-podcasts-art-overline">DESEO / ORIGINAL VOICES</span>
                    <div class="deseo-podcasts-art-orbit deseo-podcasts-art-orbit-outer"></div>
                    <div class="deseo-podcasts-art-orbit deseo-podcasts-art-orbit-mid"></div>
                    <div class="deseo-podcasts-art-orbit deseo-podcasts-art-orbit-inner"></div>
                    <img class="deseo-podcasts-art-logo" src="https://deseoradio.com/iluma/uploads/deseo-fav-nobg.png" alt="" width="220" height="220" loading="lazy" decoding="async">
                    <div class="deseo-podcasts-art-foot">
                        <span>THE STORIES BEHIND THE SOUND</span>
                        <strong>BEYOND<br>THE SET<span>.</span></strong>
                        <div class="deseo-podcasts-bars"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="content-section faq-section" id="faq">
        <div class="wide-shell">
            <div class="section-head reveal">
                <div>
                    <span class="kicker" data-i18n="faq.kicker"><?= deseo_e(deseo_t('faq.kicker')) ?></span>
                    <h2 class="metal-title section-title" data-i18n="faq.title"><?= deseo_e(deseo_t('faq.title')) ?></h2>
                </div>
                <p data-i18n="faq.text"><?= deseo_e(deseo_t('faq.text')) ?></p>
            </div>

            <div class="faq-list">
                <?php for ($i = 1; $i <= DESEO_PUBLIC_FAQ_COUNT; $i++): ?>
                    <details class="faq-item reveal">
                        <summary>
                            <span><?= str_pad((string)$i, 2, '0', STR_PAD_LEFT) ?></span>
                            <strong data-i18n="faq.q<?= $i ?>"><?= deseo_e(deseo_t('faq.q' . $i)) ?></strong>
                            <i aria-hidden="true">+</i>
                        </summary>
                        <p data-i18n="faq.a<?= $i ?>"><?= deseo_e(deseo_t('faq.a' . $i)) ?></p>
                    </details>
                <?php endfor; ?>
            </div>
        </div>
    </section>

    <section class="brand-cta">
        <div class="wide-shell brand-cta-card reveal">
            <div>
                <span class="kicker" data-i18n="advertise.kicker"><?= deseo_e(deseo_t('advertise.kicker')) ?></span>
                <h2 class="metal-title" data-i18n="advertise.title"><?= deseo_e(deseo_t('advertise.title')) ?></h2>
            </div>
            <div>
                <p data-i18n="advertise.text"><?= deseo_e(deseo_t('advertise.text')) ?></p>
                <a class="button button-red"
                   href="https://iluma.gr/contact/"
                   target="_blank"
                   rel="noopener noreferrer"
                   data-i18n="advertise.cta"
                   data-analytics-event="advertising_cta_click"><?= deseo_e(deseo_t('advertise.cta')) ?></a>
            </div>
        </div>
    </section>

    <?php require __DIR__ . '/includes/ai-discovery.php'; ?>
</main>

<div class="lineup-lightbox" id="lineup-lightbox" hidden role="dialog" aria-modal="true" aria-label="<?= deseo_e(deseo_t('lineup.zoom')) ?>">
    <button class="lineup-lightbox-close"
            type="button"
            data-lineup-close
            data-i18n-aria="lineup.close"
            aria-label="<?= deseo_e(deseo_t('lineup.close')) ?>">×</button>
    <div class="lineup-lightbox-scroll" data-lineup-scroll>
        <div class="lineup-lightbox-canvas">
            <img src="/assets/img/season6%20lineup.png?v=<?= $assetVersion ?>"
                 alt="Deseo Radio Season 6 Line Up">
        </div>
    </div>
</div>

<div class="dj-profile-modal" id="dj-profile-modal" hidden>
    <div class="dj-profile-modal-backdrop" data-dj-profile-close></div>
    <section class="dj-profile-modal-card" role="dialog" aria-modal="true" aria-labelledby="dj-profile-name">
        <button class="dj-profile-modal-close" type="button" data-dj-profile-close aria-label="Close">×</button>

        <div class="dj-profile-modal-visual">
            <img id="dj-profile-photo" src="/assets/img/bg.png" alt="">
            <div class="dj-profile-modal-visual-shade"></div>
            <div class="dj-profile-modal-show">
                <span>DESEO RADIO · DJ PROFILE</span>
                <small id="dj-profile-show"></small>
                <small id="dj-profile-time"></small>
            </div>
        </div>

        <div class="dj-profile-modal-copy">
            <span class="dj-profile-modal-kicker">NOW ON DESEO</span>
            <h2 id="dj-profile-name"></h2>
            <p id="dj-profile-bio"></p>
            <div class="dj-profile-socials" id="dj-profile-socials"></div>
        </div>
    </section>
</div>

<script>
(() => {
    const modal = document.getElementById('lineup-lightbox');
    const openers = document.querySelectorAll('[data-lineup-open]');
    const closer = modal ? modal.querySelector('[data-lineup-close]') : null;
    const scrollArea = modal ? modal.querySelector('[data-lineup-scroll]') : null;

    if (!modal || !openers.length) return;

    const closeLineup = () => {
        modal.classList.remove('is-open');
        document.body.classList.remove('lineup-lightbox-open');
        window.setTimeout(() => {
            modal.hidden = true;
        }, 180);
    };

    const openLineup = () => {
        modal.hidden = false;
        document.body.classList.add('lineup-lightbox-open');
        if (scrollArea) {
            scrollArea.scrollTop = 0;
            scrollArea.scrollLeft = 0;
        }
        window.requestAnimationFrame(() => {
            modal.classList.add('is-open');
            if (closer) closer.focus({ preventScroll: true });
        });
    };

    openers.forEach((opener) => opener.addEventListener('click', openLineup));
    if (closer) closer.addEventListener('click', closeLineup);

    modal.addEventListener('click', (event) => {
        if (event.target === modal || event.target === scrollArea) closeLineup();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) closeLineup();
    });
})();
</script>

<script>
(() => {
    const container = document.querySelector('[data-lineup-countdown-container]');
    if (!container) return;

    const countdown = container.querySelector('[data-lineup-countdown]');
    const onAir = container.querySelector('[data-lineup-onair]');
    const days = container.querySelector('[data-lineup-days]');
    const hours = container.querySelector('[data-lineup-hours]');
    const minutes = container.querySelector('[data-lineup-minutes]');
    const deadline = Date.parse(container.getAttribute('data-lineup-target') || '');
    if (!Number.isFinite(deadline) || !countdown || !onAir || !days || !hours || !minutes) return;

    let intervalId;
    const renderCountdown = () => {
        const remaining = deadline - Date.now();
        if (remaining <= 0) {
            countdown.hidden = true;
            onAir.hidden = false;
            window.clearInterval(intervalId);
            return;
        }

        // Round up so 00:00 is never displayed before the actual premiere.
        const totalMinutes = Math.ceil(remaining / 60000);
        days.textContent = String(Math.floor(totalMinutes / 1440)).padStart(2, '0');
        hours.textContent = String(Math.floor((totalMinutes % 1440) / 60)).padStart(2, '0');
        minutes.textContent = String(totalMinutes % 60).padStart(2, '0');
        onAir.hidden = true;
        countdown.hidden = false;
    };

    renderCountdown();
    if (!onAir.hidden) return;
    intervalId = window.setInterval(renderCountdown, 1000);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) renderCountdown();
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
