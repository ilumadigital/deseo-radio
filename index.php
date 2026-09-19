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
            'photo_path' => (string)($show['photo_path'] ?? ''),
            'start_time' => (string)($show['start_time'] ?? ''),
            'end_time' => (string)($show['end_time'] ?? ''),
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
                <div class="hero-deck-label">
                    <span class="live-pulse" aria-hidden="true"></span>
                    <span>NOW PLAYING</span>
                </div>

                <div class="hero-square hero-player-card">
                    <iframe src="https://play.iradios.gr/widget/deseo-radio?autoplay=true" width="100%" frameborder="0" allow="autoplay; encrypted-media; clipboard-write;" style="border:none; width: 100%; max-width: 600px; aspect-ratio: 1 / 1; margin: 0 auto; display: block; box-shadow: 0 20px 40px rgba(0,0,0,0.5); border-radius: 32px; overflow: hidden;"></iframe>
                </div>
            </div>

            <div class="hero-deck" id="live-program-deck" aria-live="polite">
                <div class="hero-deck-label">
                    <span class="live-pulse <?= $live_dj ? '' : 'is-muted' ?>" aria-hidden="true"></span>
                    <span>NOW ON AIR</span>
                </div>

                <div class="hero-square hero-cms-card">
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

            <div class="hero-deck">
                <div class="hero-deck-label hero-deck-label-muted">
                    <span>SPONSOR</span>
                </div>

                <a class="hero-square hero-sponsor-card"
                   href="https://iluma.gr/"
                   target="_blank"
                   rel="noopener noreferrer"
                   data-analytics-event="sponsor_click">
                    <img src="/assets/img/iluma-digital-agency-banner.jpg" alt="ILUMA Digital Agency">
                </a>
            </div>
        </div>
    </section>

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

                                    <span class="deseo-row-action" aria-hidden="true">↗</span>
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
                            ?>
                                <div class="deseo-panel-row deseo-program-row <?= $isLiveRow ? 'is-live' : '' ?>">
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

                                    <span class="deseo-row-action" aria-hidden="true">↗</span>
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

    <section class="about-strip">
        <div class="wide-shell about-grid">
            <div class="reveal">
                <span class="kicker" data-i18n="about.kicker"><?= deseo_e(deseo_t('about.kicker')) ?></span>
                <h2 class="metal-title section-title" data-i18n="about.title"><?= deseo_e(deseo_t('about.title')) ?></h2>
            </div>
            <div class="about-copy reveal">
                <p data-i18n="about.text"><?= deseo_e(deseo_t('about.text')) ?></p>
                <a href="https://iluma.gr/radios" target="_blank" rel="noopener noreferrer" data-i18n="about.iluma" data-analytics-event="iluma_network_click"><?= deseo_e(deseo_t('about.iluma')) ?></a>
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
                        <img src="/assets/img/partner-<?= $id ?>.png"
                             data-fallback="/assets/img/deseoradio-logo.png"
                             alt="<?= deseo_e($partner['name']) ?>">
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>


    <section class="content-section dj-call-section" id="dj-call">
        <div class="wide-shell">
            <div class="dj-call-grid reveal">
                <a class="dj-call-visual"
                   href="/dj"
                   target="_blank"
                   rel="noopener noreferrer"
                   aria-label="<?= deseo_e(deseo_t('djcall.cta')) ?>"
                   data-analytics-event="dj_call_image_click">
                    <img src="/assets/img/deseoradio-djcallwebsite.png?v=<?= $assetVersion ?>"
                         data-fallback="/assets/img/bg.png?v=<?= $assetVersion ?>"
                         alt="Deseo Radio DJs Call">
                </a>

                <div class="dj-call-copy">
                    <span class="kicker" data-i18n="djcall.kicker"><?= deseo_e(deseo_t('djcall.kicker')) ?></span>
                    <h2 class="metal-title section-title" data-i18n="djcall.title"><?= deseo_e(deseo_t('djcall.title')) ?></h2>
                    <p data-i18n="djcall.text"><?= deseo_e(deseo_t('djcall.text')) ?></p>

                    <a class="button button-red dj-call-cta"
                       href="/dj"
                       target="_blank"
                       rel="noopener noreferrer"
                       data-i18n="djcall.cta"
                       data-analytics-event="dj_call_cta_click"><?= deseo_e(deseo_t('djcall.cta')) ?></a>
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
                <?php for ($i = 1; $i <= 5; $i++): ?>
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
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
