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

require_once __DIR__ . '/includes/head-meta.php';
require_once __DIR__ . '/includes/header.php';

$partners = [
    1 => ['url' => 'https://play.iradios.gr/station/deseo-radio', 'name' => 'iRadios'],
    2 => ['url' => 'https://onlineradiobox.com/gr/deseo/', 'name' => 'Online Radio Box'],
    3 => ['url' => 'https://www.getmeradio.com/stations/deseoradiogr-4835/', 'name' => 'Get Me Radio'],
    5 => ['url' => 'https://tunein.com/radio/Deseo-Radio-s258242/', 'name' => 'TuneIn'],
    6 => ['url' => 'https://vradio.app/play?id=20739', 'name' => 'VRadio'],
    8 => ['url' => 'https://iluma.gr/radios/deseo', 'name' => 'ILUMA Radios'],
];
?>

<main id="main-content">
    <section class="hero-v3" id="live">
        <div class="hero-v3-media" aria-hidden="true">
            <img src="/assets/img/bg.png" alt="">
            <div class="hero-v3-shade"></div>
            <div class="hero-v3-edge"></div>
        </div>

        <div class="wide-shell hero-v3-grid">
            <div class="hero-v3-copy reveal">
                <span class="kicker" data-i18n="hero.kicker"><?= deseo_e(deseo_t('hero.kicker')) ?></span>
                <h1 class="metal-title" data-i18n="hero.title"><?= deseo_e(deseo_t('hero.title')) ?></h1>
                <p data-i18n="hero.text"><?= deseo_e(deseo_t('hero.text')) ?></p>

                <div class="hero-v3-actions">
                    <a class="button button-red" href="#player" data-i18n="hero.listen" data-analytics-event="live_radio_click"><?= deseo_e(deseo_t('hero.listen')) ?></a>
                    <a class="button button-glass" href="https://iluma.gr/radios/deseo" target="_blank" rel="noopener noreferrer" data-i18n="hero.network" data-analytics-event="iluma_network_click"><?= deseo_e(deseo_t('hero.network')) ?> ↗</a>
                </div>

                <div class="hero-v3-status">
                    <span class="live-pulse" aria-hidden="true"></span>
                    <div>
                        <small data-i18n="hero.now_on_air"><?= deseo_e(deseo_t('hero.now_on_air')) ?></small>
                        <strong><?= deseo_e($live_dj['dj_name'] ?? deseo_t('hero.non_stop')) ?></strong>
                        <?php if ($live_dj): ?>
                            <span><?= deseo_time($live_dj['start_time']) ?> — <?= deseo_time($live_dj['end_time']) ?></span>
                        <?php else: ?>
                            <span>24/7</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="radio-stage reveal" id="player">
                <div class="radio-stage-label">
                    <span class="live-pulse" aria-hidden="true"></span>
                    <span>DESEO RADIO · LIVE</span>
                </div>

                <div class="radio-frame">
                    <iframe src="https://play.iradios.gr/widget/deseo-radio?autoplay=true" width="100%" frameborder="0" allow="autoplay; encrypted-media; clipboard-write;" style="border:none; width: 100%; max-width: 600px; aspect-ratio: 1 / 1; margin: 0 auto; display: block; box-shadow: 0 20px 40px rgba(0,0,0,0.5); border-radius: 32px; overflow: hidden;"></iframe>
                </div>
            </div>
        </div>
    </section>

    <section class="content-section" id="airplay">
        <div class="wide-shell">
            <div class="section-head reveal">
                <div>
                    <span class="kicker" data-i18n="airplay.kicker"><?= deseo_e(deseo_t('airplay.kicker')) ?></span>
                    <h2 class="metal-title section-title" data-i18n="airplay.title"><?= deseo_e(deseo_t('airplay.title')) ?></h2>
                </div>
                <p data-i18n="airplay.text"><?= deseo_e(deseo_t('airplay.text')) ?></p>
            </div>

            <?php if ($airplay_tracks): ?>
                <div class="track-stack">
                    <?php foreach (array_slice($airplay_tracks, 0, 6) as $track): ?>
                        <a class="track-line reveal"
                           href="<?= deseo_e($track['spotify_url']) ?>"
                           target="_blank"
                           rel="noopener noreferrer"
                           data-analytics-event="spotify_track_click">
                            <span class="track-index"><?= str_pad((string)(int)$track['position'], 2, '0', STR_PAD_LEFT) ?></span>
                            <img src="<?= deseo_e($track['artwork_url'] ?: '/assets/img/favicon.png') ?>"
                                 data-fallback="/assets/img/favicon.png"
                                 alt="">
                            <span class="track-copy">
                                <strong><?= deseo_e($track['track_name'] ?: 'Deseo Selection') ?></strong>
                                <small><?= deseo_e(($track['artist_name'] && $track['artist_name'] !== 'Διάφοροι / Μη διαθέσιμο') ? $track['artist_name'] : 'Deseo Radio Selection') ?></small>
                            </span>
                            <span class="track-open" data-i18n="airplay.spotify"><?= deseo_e(deseo_t('airplay.spotify')) ?> ↗</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-box" data-i18n="airplay.empty"><?= deseo_e(deseo_t('airplay.empty')) ?></div>
            <?php endif; ?>
        </div>
    </section>

    <section class="content-section section-dark" id="program">
        <div class="wide-shell">
            <div class="section-head reveal">
                <div>
                    <span class="kicker" data-i18n="program.kicker"><?= deseo_e(deseo_t('program.kicker')) ?></span>
                    <h2 class="metal-title section-title" data-i18n="program.title"><?= deseo_e(deseo_t('program.title')) ?></h2>
                </div>
                <p data-i18n="program.text"><?= deseo_e(deseo_t('program.text')) ?></p>
            </div>

            <?php if ($todays_program): ?>
                <div class="program-grid">
                    <?php foreach ($todays_program as $show):
                        $isLiveRow = $live_dj && (int)$live_dj['id'] === (int)$show['id'];
                    ?>
                        <article class="program-card reveal <?= $isLiveRow ? 'is-live' : '' ?>">
                            <img src="<?= deseo_e($show['photo_path'] ?: '/assets/img/bg.png') ?>"
                                 data-fallback="/assets/img/bg.png"
                                 alt="<?= deseo_e($show['dj_name']) ?>">
                            <div class="program-card-copy">
                                <span data-i18n="<?= $isLiveRow ? 'program.on_air' : 'program.live_set' ?>"><?= deseo_e($isLiveRow ? deseo_t('program.on_air') : deseo_t('program.live_set')) ?></span>
                                <h3><?= deseo_e($show['dj_name']) ?></h3>
                                <p><?= deseo_time($show['start_time']) ?> — <?= deseo_time($show['end_time']) ?></p>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-box" data-i18n="program.empty"><?= deseo_e(deseo_t('program.empty')) ?></div>
            <?php endif; ?>

            <?php if ($next_dj && !$live_dj): ?>
                <div class="next-live reveal">
                    <span data-i18n="program.next"><?= deseo_e(deseo_t('program.next')) ?></span>
                    <strong><?= deseo_e($next_dj['dj_name']) ?></strong>
                    <small><span data-i18n="day.<?= (int)$next_dj['day_of_week'] ?>"><?= deseo_e(deseo_t_day((int)$next_dj['day_of_week'])) ?></span> · <?= deseo_time($next_dj['start_time']) ?></small>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="content-section" id="playlists">
        <div class="wide-shell">
            <div class="section-head reveal">
                <div>
                    <span class="kicker" data-i18n="playlists.kicker"><?= deseo_e(deseo_t('playlists.kicker')) ?></span>
                    <h2 class="metal-title section-title" data-i18n="playlists.title"><?= deseo_e(deseo_t('playlists.title')) ?></h2>
                </div>
                <p data-i18n="playlists.text"><?= deseo_e(deseo_t('playlists.text')) ?></p>
            </div>

            <?php if ($playlists): ?>
                <div class="playlist-grid">
                    <?php foreach ($playlists as $playlist): ?>
                        <a class="playlist-card reveal"
                           href="<?= deseo_e($playlist['spotify_url']) ?>"
                           target="_blank"
                           rel="noopener noreferrer"
                           data-analytics-event="playlist_click">
                            <div class="playlist-art">
                                <img src="<?= deseo_e($playlist['artwork_url'] ?: '/assets/img/favicon.png') ?>"
                                     data-fallback="/assets/img/favicon.png"
                                     alt="<?= deseo_e($playlist['title']) ?>">
                                <span><?= str_pad((string)(int)$playlist['position'], 2, '0', STR_PAD_LEFT) ?></span>
                            </div>
                            <div class="playlist-copy">
                                <strong><?= deseo_e($playlist['title']) ?></strong>
                                <small data-i18n="playlists.open"><?= deseo_e(deseo_t('playlists.open')) ?></small>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-box" data-i18n="playlists.empty"><?= deseo_e(deseo_t('playlists.empty')) ?></div>
            <?php endif; ?>
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
                <a href="https://iluma.gr/radios/deseo" target="_blank" rel="noopener noreferrer" data-i18n="about.iluma" data-analytics-event="iluma_network_click"><?= deseo_e(deseo_t('about.iluma')) ?></a>
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
