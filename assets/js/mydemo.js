/* /mydemo only: accessible tabs, published DJ profiles, live schedule refresh. */
(function () {
  'use strict';

  var countdown = document.getElementById('md-season-countdown');
  if (countdown) {
    var start = Number(countdown.getAttribute('data-start')) * 1000;
    function updateCountdown() {
      var remaining = Math.max(0, Math.ceil((start - Date.now()) / 60000));
      if (remaining <= 0) {
        if (!countdown.classList.contains('md-season-launched')) {
          countdown.className = 'md-season-launched';
          countdown.textContent = countdown.getAttribute('data-ended') || 'SEASON 6 · ON AIR NOW';
        }
        return;
      }
      var days = Math.floor(remaining / 1440);
      var hours = Math.floor((remaining % 1440) / 60);
      var minutes = remaining % 60;
      [['days', days], ['hours', hours], ['minutes', minutes]].forEach(function (item) {
        var number = countdown.querySelector('[data-counter="' + item[0] + '"]');
        if (number) number.textContent = String(item[1]).padStart(2, '0');
      });
    }
    updateCountdown();
    window.setInterval(updateCountdown, 30000);
  }

  var tabs = Array.prototype.slice.call(document.querySelectorAll('.md-day-tab'));
  function activateDay(button, focus) {
    if (!button) return;
    tabs.forEach(function (tab) {
      var selected = tab === button;
      tab.classList.toggle('is-active', selected);
      tab.setAttribute('aria-selected', selected ? 'true' : 'false');
      tab.tabIndex = selected ? 0 : -1;
      var panel = document.getElementById(tab.getAttribute('aria-controls'));
      if (panel) panel.hidden = !selected;
    });
    if (focus) button.focus();
  }
  tabs.forEach(function (tab, index) {
    tab.addEventListener('click', function () { activateDay(tab, false); });
    tab.addEventListener('keydown', function (event) {
      var nextIndex = null;
      if (event.key === 'ArrowRight') nextIndex = (index + 1) % tabs.length;
      if (event.key === 'ArrowLeft') nextIndex = (index + tabs.length - 1) % tabs.length;
      if (event.key === 'Home') nextIndex = 0;
      if (event.key === 'End') nextIndex = tabs.length - 1;
      if (nextIndex === null) return;
      event.preventDefault();
      activateDay(tabs[nextIndex], true);
    });
  });

  var dialog = document.getElementById('md-dj-dialog');
  var close = document.getElementById('md-dialog-close');
  var lastTrigger = null;
  function allowedUrl(url) {
    try {
      var parsed = new URL(url);
      return parsed.protocol === 'https:' ? parsed.href : '';
    } catch (error) {
      return '';
    }
  }
  if (dialog) {
    Array.prototype.slice.call(document.querySelectorAll('[data-profile]')).forEach(function (trigger) {
      trigger.addEventListener('click', function () {
        var profile;
        try {
          profile = JSON.parse(trigger.getAttribute('data-profile') || '{}');
        } catch (error) { return; }
        var name = dialog.querySelector('#md-dialog-title');
        var bio = dialog.querySelector('#md-dialog-bio');
        var image = dialog.querySelector('#md-dialog-photo');
        var links = dialog.querySelector('#md-dialog-links');
        if (name) name.textContent = profile.name || '';
        if (bio) {
          bio.textContent = profile.bio || '';
          bio.hidden = !profile.bio;
        }
        if (image) {
          image.src = typeof profile.photo === 'string' && (profile.photo[0] === '/' || allowedUrl(profile.photo))
            ? profile.photo : '/assets/img/bg.png';
          image.alt = profile.name || '';
          image.onerror = function () { image.onerror = null; image.src = '/assets/img/bg.png'; };
        }
        if (links) {
          links.replaceChildren();
          Object.keys(profile.links || {}).forEach(function (label) {
            var url = allowedUrl(profile.links[label]);
            if (!url) return;
            var a = document.createElement('a');
            a.href = url;
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
            a.textContent = label;
            var arrow = document.createElement('span');
            arrow.className = 'md-ui-arrow';
            arrow.setAttribute('aria-hidden', 'true');
            a.appendChild(arrow);
            links.appendChild(a);
          });
        }
        lastTrigger = trigger;
        if (typeof dialog.showModal === 'function') dialog.showModal();
        else dialog.setAttribute('open', '');
      });
    });
    function closeDialog() {
      if (typeof dialog.close === 'function') dialog.close();
      else dialog.removeAttribute('open');
      if (lastTrigger) lastTrigger.focus();
    }
    if (close) close.addEventListener('click', closeDialog);
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog) closeDialog();
    });
    dialog.addEventListener('close', function () {
      if (lastTrigger) lastTrigger.focus();
    });
  }

  // The backend is the same READ-ONLY CMS schedule as the main site.
  // Never show a fake "currently playing track": Hot Tracks are selections,
  // while this panel describes the current scheduled DJ slot.
  var heroLiveName = document.getElementById('md-hero-live-name');
  var dockShowName = document.getElementById('md-dock-show');
  var dockShowTime = document.getElementById('md-dock-time');
  var dockShowPhoto = document.getElementById('md-dock-photo');
  var heroLiveTime = document.getElementById('md-hero-live-time');
  var heroLivePhoto = document.getElementById('md-hero-live-photo');
  var heroNextName = document.getElementById('md-hero-next-name');
  var liveName = document.getElementById('md-live-name');
  var liveTime = document.getElementById('md-live-time');
  var livePhoto = document.getElementById('md-live-photo');
  var nextName = document.getElementById('md-next-name');
  var nextTime = document.getElementById('md-next-time');
  var liveLabel = document.querySelector('.md-onair-copy > .md-tag');
  var demoLanguage = document.documentElement.lang === 'en' ? 'en' : 'el';
  function formatAthens(dateString) {
    var date = new Date(dateString);
    if (Number.isNaN(date.getTime())) return '';
    return new Intl.DateTimeFormat(demoLanguage === 'el' ? 'el-GR' : 'en-GB', {
      timeZone: 'Europe/Athens',
      weekday: 'short', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit'
    }).format(date);
  }
  function refreshSchedule() {
    if (document.hidden) return;
    fetch('/mydemo?feed=1', { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
      .then(function (response) {
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.json();
      }).then(function (data) {
        if (!data || !data.ok) return;
        var live = data.live;
        var next = data.next;
        if (heroLiveName) heroLiveName.textContent = live ? live.name : 'DESEO NON-STOP';
        if (dockShowName) dockShowName.textContent = live ? live.name : 'DESEO NON-STOP';
        if (dockShowTime) dockShowTime.textContent = live ? live.time : '24 / 7';
        if (dockShowPhoto) dockShowPhoto.src = live && live.photo ? live.photo : '/assets/img/bg.png';
        if (heroLiveTime) heroLiveTime.textContent = live ? live.time : '24 / 7';
        if (heroLivePhoto) heroLivePhoto.src = live && live.photo ? live.photo : '/assets/img/bg.png';
        if (heroNextName) heroNextName.textContent = next ? next.name : '24/7 NON-STOP MUSIC';
        if (liveName) liveName.textContent = live ? live.name : 'DESEO NON-STOP';
        if (liveTime) liveTime.textContent = live ? live.time : '24 / 7';
        if (livePhoto) livePhoto.src = live && live.photo ? live.photo : '/assets/img/bg.png';
        if (liveLabel) liveLabel.innerHTML = '<span class="md-dot"></span> ' + (live ? 'ON AIR' : 'NON-STOP');
        if (nextName) nextName.textContent = next ? next.name : '24/7 NON-STOP MUSIC';
        if (nextTime) nextTime.textContent = next ? formatAthens(next.start) : '';
        Array.prototype.slice.call(document.querySelectorAll('.md-show-card')).forEach(function (card) {
          var heading = card.querySelector('h3');
          var active = !!live && heading && heading.textContent === live.name;
          card.classList.toggle('is-live', !!active);
          var status = card.querySelector('.md-show-details > span');
          if (status) status.textContent = active ? '● ON AIR' : 'DESEO RADIO / S06';
        });
      }).catch(function () {
        // Keep server-rendered CMS data visible if the network temporarily fails.
      });
  }
  if (liveName || heroLiveName) {
    window.setInterval(refreshSchedule, 60000);
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) refreshSchedule();
    });
  }
}());
