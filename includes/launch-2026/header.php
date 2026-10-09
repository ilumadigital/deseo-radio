<header class="md-header">
  <div class="md-shell md-header-inner">
    <a class="md-logo" href="#home" aria-label="Deseo Radio demo home"><img src="/assets/img/deseoradio-logo.png" alt="Deseo Radio" width="220" height="100" fetchpriority="high"></a>
    <div class="md-header-actions">
      <div class="md-lang" aria-label="Language">
        <a <?= !$en ? 'aria-current="page"' : '' ?> href="<?= demo_e(deseo_lang_url('el')) ?>">EL</a>
        <span>/</span>
        <a <?= $en ? 'aria-current="page"' : '' ?> href="<?= demo_e(deseo_lang_url('en')) ?>">EN</a>
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

