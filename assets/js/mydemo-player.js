/* Deseo Radio: one uninterrupted live <audio>, two layouts (hero + mini dock). */
(function () {
  'use strict';
  var audio = document.getElementById('md-live-audio');
  var player = document.getElementById('md-custom-player');
  var home = document.getElementById('player');
  var dock = document.getElementById('md-player-dock');
  var hero = document.getElementById('home');
  if (!audio || !player || !home || !dock) return;

  var el = function (id) { return document.getElementById(id); };
  var playButton = el('md-audio-toggle');
  var heroPlay = el('md-hero-play');
  var symbol = el('md-audio-symbol');
  var status = el('md-playback-label');
  var volume = el('md-volume');
  var mute = el('md-volume-mute');
  var minify = el('md-player-minify');
  var expand = el('md-player-expand');
  var english = document.documentElement.lang === 'en';
  var customMini = false;
  var isBelowHero = false;
  var userPaused = false;

  function safeVolume() {
    try {
      var raw = window.localStorage.getItem('deseoDemoVolume');
      if (raw === null) return .75;
      var saved = Number(raw);
      return Number.isFinite(saved) && saved >= 0 && saved <= 1 ? saved : .75;
    } catch (_) {
      return .75;
    }
  }
  audio.volume = safeVolume();
  if (volume) volume.value = Math.round(audio.volume * 100);
  function storeVolume() {
    try { window.localStorage.setItem('deseoDemoVolume', String(audio.volume)); } catch (_) {}
  }
  function setStatus(message) {
    if (status) status.textContent = message;
  }
  function renderAudio() {
    var playing = !audio.paused && !audio.ended;
    player.classList.toggle('is-playing', playing);
    if (symbol) symbol.textContent = playing ? 'Ⅱ' : '▶';
    if (playButton) {
      playButton.setAttribute('aria-pressed', playing ? 'true' : 'false');
      playButton.setAttribute('aria-label', playing ? (english ? 'Pause radio' : 'Παύση ραδιοφώνου')
        : (english ? 'Play radio' : 'Έναρξη ραδιοφώνου'));
    }
    if (heroPlay) heroPlay.textContent = playing
      ? (english ? 'PAUSE LIVE ↗' : 'ΠΑΥΣΗ LIVE ↗')
      : (english ? 'LISTEN LIVE ↗' : 'ΑΚΟΥ LIVE ↗');
  }
  function tryPlay() {
    userPaused = false;
    setStatus(english ? 'CONNECTING…' : 'ΣΥΝΔΕΣΗ…');
    var promise;
    try { promise = audio.play(); } catch (_) { promise = Promise.reject(_); }
    if (promise && typeof promise.catch === 'function') {
      promise.catch(function (error) {
        renderAudio();
        var blocked = error && error.name === 'NotAllowedError';
        setStatus(blocked
          ? (english ? 'TAP PLAY TO LISTEN' : 'ΠΑΤΗΣΕ PLAY ΓΙΑ ΑΚΡΟΑΣΗ')
          : (english ? 'STREAM UNAVAILABLE — RETRY' : 'ΠΡΟΣΩΡΙΝΑ ΕΚΤΟΣ — ΔΟΚΙΜΑΣΕ ΞΑΝΑ'));
      });
    }
  }
  function toggleAudio() {
    if (audio.paused) {
      tryPlay();
    } else {
      userPaused = true;
      audio.pause();
      setStatus(english ? 'PAUSED' : 'ΣΕ ΠΑΥΣΗ');
    }
    renderAudio();
  }
  if (playButton) playButton.addEventListener('click', toggleAudio);
  if (heroPlay) heroPlay.addEventListener('click', toggleAudio);
  audio.addEventListener('playing', function () {
    setStatus(english ? 'LIVE / PLAYING' : 'LIVE / ΠΑΙΖΕΙ');
    renderAudio();
  });
  audio.addEventListener('pause', function () {
    renderAudio();
    if (userPaused) setStatus(english ? 'PAUSED' : 'ΣΕ ΠΑΥΣΗ');
  });
  audio.addEventListener('waiting', function () { if (!audio.paused) setStatus(english ? 'BUFFERING…' : 'ΦΟΡΤΩΣΗ…'); });
  audio.addEventListener('stalled', function () { if (!audio.paused) setStatus(english ? 'RECONNECTING…' : 'ΕΠΑΝΑΣΥΝΔΕΣΗ…'); });
  audio.addEventListener('error', function () {
    renderAudio();
    setStatus(english ? 'CONNECTION LOST — RETRY' : 'ΑΠΩΛΕΙΑ ΣΥΝΔΕΣΗΣ — ΞΑΝΑΔΟΚΙΜΑΣΕ');
  });
  if (volume) volume.addEventListener('input', function () {
    audio.volume = Math.max(0, Math.min(1, Number(volume.value) / 100));
    audio.muted = false;
    if (mute) mute.textContent = audio.volume === 0 ? '×' : '♫';
    storeVolume();
  });
  if (mute) mute.addEventListener('click', function () {
    audio.muted = !audio.muted;
    mute.textContent = audio.muted || audio.volume === 0 ? '×' : '♫';
    mute.setAttribute('aria-label', audio.muted
      ? (english ? 'Unmute' : 'Ενεργοποίηση ήχου')
      : (english ? 'Mute' : 'Σίγαση'));
  });

  // Preserve the original hero height during DOM reparenting so the page
  // never jumps or repeatedly triggers the viewport observer.
  var expandedHeight = 0;
  function rememberExpandedHeight() {
    if (player.parentNode === home && !player.classList.contains('is-mini')) {
      expandedHeight = Math.ceil(player.getBoundingClientRect().height);
      if (expandedHeight > 0) home.style.minHeight = expandedHeight + 'px';
    }
  }
  function changeLayout() {
    var shouldMini = customMini || isBelowHero;
    if (shouldMini) {
      rememberExpandedHeight();
      if (player.parentNode !== dock) dock.appendChild(player);
      dock.removeAttribute('aria-hidden');
    } else {
      if (player.parentNode !== home) home.appendChild(player);
      dock.setAttribute('aria-hidden', 'true');
    }
    player.classList.toggle('is-mini', shouldMini);
    if (!shouldMini) rememberExpandedHeight();
  }
  rememberExpandedHeight();
  window.addEventListener('resize', function () {
    if (player.parentNode === home) {
      home.style.minHeight = '';
      rememberExpandedHeight();
    }
  }, { passive: true });
  if (minify) minify.addEventListener('click', function () {
    customMini = true;
    changeLayout();
  });
  if (expand) expand.addEventListener('click', function () {
    customMini = false;
    home.scrollIntoView({ behavior: 'smooth', block: 'center' });
    // On scroll to the hero the observer restores the player automatically.
    if (!isBelowHero) changeLayout();
  });
  // The audio remains OUTSIDE the moving card, so it is never reloaded.
  if ('IntersectionObserver' in window) {
    var observer = new IntersectionObserver(function (entries) {
      isBelowHero = !entries[0].isIntersecting &&
        hero.getBoundingClientRect().bottom <= 0;
      changeLayout();
    }, { threshold: 0 });
    observer.observe(hero);
  } else {
    window.addEventListener('scroll', function () {
      isBelowHero = hero.getBoundingClientRect().bottom < 0;
      changeLayout();
    }, { passive: true });
  }
  document.querySelectorAll('a[href="#player"]').forEach(function (a) {
    a.addEventListener('click', function () {
      customMini = false;
      if (!isBelowHero) changeLayout();
    });
  });

  renderAudio();
  // Browsers may reject audible autoplay; keep a real, clearly labelled play button.
  tryPlay();
}());
