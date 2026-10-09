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
    <div class="md-section-top"><span class="md-index">THE ARTISTS</span><span>SEASON 06 — 2026</span></div>
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
    <div class="md-section-top"><span class="md-index">THE PROGRAM</span><span>TIMEZONE / EUROPE — ATHENS</span></div>
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
    <div class="md-section-top"><span class="md-index">MUSIC DISCOVERY</span><span>THE DESEO SELECTION</span></div>
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
    <div class="md-section-top"><span class="md-index">DESEO CURATED</span><span>LISTEN BEYOND RADIO</span></div>
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
      <div class="md-section-top"><span class="md-index">DESEO ORIGINALS</span></div>
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
      <span class="md-index">DESEO UNFILTERED</span>
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
  <?php require dirname(__DIR__) . '/ai-discovery.php'; ?>
</div>

</main>