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
<!-- The production PWA keeps its existing manifest and offline fallback. -->
<script>
(function () {
  'use strict';
  if (!('serviceWorker' in navigator)) return;
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('/sw.js?v=' + encodeURIComponent(window.DESEO_ASSET_VERSION || '1'))
      .catch(function () { /* Non-blocking: radio must play without PWA support. */ });
  }, { once: true });
}());
</script>
