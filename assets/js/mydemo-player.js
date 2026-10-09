/* Deseo Radio: one uninterrupted live <audio>, two layouts (hero + mini dock). */
(function () {
  'use strict';
  var audio = document.getElementById('md-live-audio');
  var player = document.getElementById('md-custom-player');
  var home = document.getElementById('player');
  var dock = document.getElementById('md-player-dock');
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
  var cover = el('md-current-cover');
  var track = el('md-current-track');
  var artist = el('md-current-artist');
  var provider = el('md-track-provider');
  var trackLabel = el('md-track-label');
  var english = document.documentElement.lang === 'en';
  var customMini = false;
  var isBelowHero = false;
  var pendingMetadata = false;
  var userPaused = false;

  function safeVolume() {
    try {
      var saved = Number(window.localStorage.getItem('deseoDemoVolume'));
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

  function changeLayout() {
    var shouldMini = customMini || isBelowHero;
    if (shouldMini) {
      if (player.parentNode !== dock) dock.appendChild(player);
      dock.removeAttribute('aria-hidden');
    } else {
      if (player.parentNode !== home) home.appendChild(player);
      dock.setAttribute('aria-hidden', 'true');
    }
    player.classList.toggle('is-mini', shouldMini);
  }
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
        home.getBoundingClientRect().top < 0;
      changeLayout();
    }, { threshold: 0, rootMargin: '-45px 0px 0px 0px' });
    observer.observe(home);
  } else {
    window.addEventListener('scroll', function () {
      isBelowHero = home.getBoundingClientRect().bottom < 0;
      changeLayout();
    }, { passive: true });
  }
  document.querySelectorAll('a[href="#player"]').forEach(function (a) {
    a.addEventListener('click', function () {
      customMini = false;
      if (!isBelowHero) changeLayout();
    });
  });

  function validArtwork(url) {
    if (typeof url !== 'string') return '';
    try {
      var parsed = new URL(url);
      return parsed.protocol === 'https:' &&
        /^(?:is\d+-ssl\.mzstatic\.com|i(?:\d+)?\.scdn\.co|lastfm\.freetls\.fastly\.net)$/.test(parsed.hostname)
        ? parsed.href : '';
    } catch (_) {
      return '';
    }
  }
  function resetTrack() {
    if (track) track.textContent = english ? 'THE SOUND OF DESEO' : 'Ο ΗΧΟΣ ΤΟΥ DESEO';
    if (artist) artist.textContent = english ? 'Live from Athens · 24/7' : 'Ζωντανά από την Αθήνα · 24/7';
    if (provider) provider.textContent = '';
    if (cover) {
      cover.onerror = null;
      cover.src = '/assets/img/favicon.png';
      cover.alt = '';
      cover.classList.remove('has-cover');
    }
    if (trackLabel) trackLabel.textContent = english ? 'LIVE RADIO' : 'ΖΩΝΤΑΝΑ';
  }
  function fetchMetadata() {
    if (document.hidden || pendingMetadata) return;
    pendingMetadata = true;
    fetch('/mydemo-nowplaying.php', { cache: 'no-store', credentials: 'same-origin' })
      .then(function (response) {
        if (!response.ok) throw new Error('metadata HTTP ' + response.status);
        return response.json();
      }).then(function (data) {
        if (!data || !data.ok || !data.track || !data.artist) {
          resetTrack();
          return;
        }
        if (track) track.textContent = String(data.track);
        if (artist) artist.textContent = String(data.artist);
        if (trackLabel) trackLabel.textContent = english ? 'NOW PLAYING' : 'ΠΑΙΖΕΙ ΤΩΡΑ';
        if (provider) provider.textContent = data.provider ? 'COVER · ' + data.provider : '';
        var url = validArtwork(data.artwork);
        if (cover && url) {
          cover.onerror = function () {
            cover.onerror = null;
            cover.src = '/assets/img/favicon.png';
            cover.classList.remove('has-cover');
            if (provider) provider.textContent = '';
          };
          cover.src = url;
          cover.alt = String(data.track) + ' — ' + String(data.artist);
          cover.classList.add('has-cover');
        } else if (cover) {
          cover.onerror = null;
          cover.src = '/assets/img/favicon.png';
          cover.alt = '';
          cover.classList.remove('has-cover');
        }
      }).catch(function () {
        // Stream may still be playing normally even if metadata source is offline.
        resetTrack();
      }).finally(function () {
        pendingMetadata = false;
      });
  }
  fetchMetadata();
  window.setInterval(fetchMetadata, 45000);
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) fetchMetadata();
  });
  renderAudio();
  // Browsers may reject audible autoplay; keep a real, clearly labelled play button.
  tryPlay();
}());
