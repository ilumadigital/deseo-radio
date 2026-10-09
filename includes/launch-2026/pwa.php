<?php
declare(strict_types=1);
/* Independent PWA install controls for the redesigned public shell.
   Uses legacy install/dismiss keys and preserves iOS Add-to-Home-Screen help. */
?>
<div class="md-app-install" id="md-app-install" hidden aria-label="Deseo Radio App">
  <img src="/assets/img/favicon-nobg.png" alt="" width="42" height="42">
  <span class="md-app-install-copy"><strong>Deseo Radio App</strong><small><?= deseo_e(deseo_t('install.subtitle')) ?></small></span>
  <button type="button" class="md-app-install-action" id="md-app-install-action">INSTALL</button>
  <button type="button" class="md-app-install-dismiss" id="md-app-install-dismiss" aria-label="Dismiss">×</button>
</div>

<dialog class="md-app-ios-guide" id="md-app-ios-guide" aria-labelledby="md-app-ios-title">
  <button class="md-app-ios-close" id="md-app-ios-close" type="button" aria-label="Close">×</button>
  <span class="md-app-ios-kicker">DESEO RADIO / IPHONE &amp; IPAD</span>
  <h2 id="md-app-ios-title">Add Deseo to your Home Screen</h2>
  <p>In Safari, tap <strong>Share</strong>, choose <strong>Add to Home Screen</strong>, then tap <strong>Add</strong>.</p>
  <button type="button" id="md-app-ios-done" class="md-app-install-action">GOT IT</button>
</dialog>
<script>
(function () {
  'use strict';
  var box = document.getElementById('md-app-install');
  var launch = document.getElementById('md-app-install-action');
  var dismiss = document.getElementById('md-app-install-dismiss');
  var guide = document.getElementById('md-app-ios-guide');
  var guideClose = document.getElementById('md-app-ios-close');
  var guideDone = document.getElementById('md-app-ios-done');
  if (!box || !launch || !dismiss || !guide) return;

  var nativeEvent = null;
  var lastDismiss = 'deseoPwaDismissedAt';
  var installedKey = 'deseoPwaInstalledV2';
  var sevenDays = 7 * 86400000;
  var ready = false;
  function read(key) {
    try { return window.localStorage.getItem(key); } catch (_) { return null; }
  }
  function write(key, val) {
    try { window.localStorage.setItem(key, val); } catch (_) {}
  }
  function standalone() {
    return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
      || window.navigator.standalone === true;
  }
  function iosSafari() {
    var ua = navigator.userAgent || '';
    var ios = /iPhone|iPad|iPod/.test(ua) ||
      (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    return ios && /WebKit/.test(ua) && !/CriOS|FxiOS|EdgiOS|OPiOS/.test(ua);
  }
  function cookiesVisible() {
    var cookie = document.getElementById('cookie-banner');
    return !!(cookie && !cookie.hidden);
  }
  function showWhenEligible() {
    var last = Number(read(lastDismiss) || 0);
    box.hidden = !ready || standalone() || read(installedKey) === '1' ||
      (last > 0 && Date.now() - last < sevenDays) ||
      cookiesVisible() || (!nativeEvent && !iosSafari());
  }
  if (standalone()) {
    write(installedKey, '1');
  } else {
    ready = true;
    showWhenEligible();
  }
  window.addEventListener('beforeinstallprompt', function (event) {
    event.preventDefault();
    nativeEvent = event;
    showWhenEligible();
  });
  launch.addEventListener('click', function () {
    box.hidden = true;
    if (nativeEvent) {
      var event = nativeEvent;
      nativeEvent = null;
      event.prompt();
      Promise.resolve(event.userChoice).then(function (answer) {
        if (answer && answer.outcome === 'accepted') write(installedKey, '1');
        else write(lastDismiss, String(Date.now()));
        showWhenEligible();
      }).catch(function () { showWhenEligible(); });
    } else if (iosSafari()) {
      if (typeof guide.showModal === 'function') guide.showModal();
      else guide.setAttribute('open', '');
    }
  });
  dismiss.addEventListener('click', function () {
    write(lastDismiss, String(Date.now()));
    box.hidden = true;
  });
  function closeGuide() {
    if (typeof guide.close === 'function' && guide.open) guide.close();
    else guide.removeAttribute('open');
  }
  guideClose.addEventListener('click', function () {
    write(lastDismiss, String(Date.now()));
    closeGuide();
    showWhenEligible();
  });
  guideDone.addEventListener('click', function () {
    write(installedKey, '1');
    closeGuide();
    box.hidden = true;
  });
  window.addEventListener('appinstalled', function () {
    write(installedKey, '1');
    nativeEvent = null;
    box.hidden = true;
    closeGuide();
  });
  document.addEventListener('click', function (event) {
    if (event.target.closest('#cookie-accept, #cookie-reject')) {
      window.setTimeout(showWhenEligible, 380);
    }
  });
  window.addEventListener('pageshow', showWhenEligible);
}());
</script>
