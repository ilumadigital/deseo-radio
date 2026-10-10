/* Deseo Radio Season 6 — unified production homepage interactions.
 * Keep the official iRadios iframe as the sole audio player.
 */

/* ---- mydemo.js ---- */
/* Shared Deseo homepage: accessible DJ tabs, profiles and live schedule refresh. */
(function () {
  'use strict';

  // On small screens, defer the heavy decorative backdrop until after
  // the live audio player and main content have received network priority.
  if (window.matchMedia && window.matchMedia('(max-width: 850px)').matches) {
    window.setTimeout(function () {
      var backdrop = document.querySelector('.md-hero-photo');
      if (!backdrop) return;
      var image = new Image();
      image.decoding = 'async';
      image.onload = function () { backdrop.classList.add('md-photo-ready'); };
      image.src = '/assets/img/bg.png';
    }, 1400);
  }

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
          // Photo loads only when the profile opens, then receives high priority.
          image.loading = 'eager';
          image.fetchPriority = 'high';
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
  function updatePhotoIfChanged(image, url) {
    // Avoid redundant image requests/decodes on every minute tick.
    if (image && image.getAttribute('src') !== url) image.src = url;
  }
  function refreshSchedule() {
    if (document.hidden) return;
    var feedPath = '/?feed=1';
    fetch(feedPath, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
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
        updatePhotoIfChanged(dockShowPhoto, live && live.photo ? live.photo : '/assets/img/bg.png');
        if (heroLiveTime) heroLiveTime.textContent = live ? live.time : '24 / 7';
        updatePhotoIfChanged(heroLivePhoto, live && live.photo ? live.photo : '/assets/img/bg.png');
        if (heroNextName) heroNextName.textContent = next ? next.name : '24/7 NON-STOP MUSIC';
        if (liveName) liveName.textContent = live ? live.name : 'DESEO NON-STOP';
        if (liveTime) liveTime.textContent = live ? live.time : '24 / 7';
        updatePhotoIfChanged(livePhoto, live && live.photo ? live.photo : '/assets/img/bg.png');
        if (liveLabel) liveLabel.innerHTML = '<span class="md-dot"></span> ' + (live ? 'ON AIR' : 'NON-STOP');
        if (nextName) nextName.textContent = next ? next.name : '24/7 NON-STOP MUSIC';
        if (nextTime) nextTime.textContent = next ? formatAthens(next.start) : '';
        Array.prototype.slice.call(document.querySelectorAll('.md-show-card')).forEach(function (card) {
          var heading = card.querySelector('h3');
          var active = !!live && heading && heading.textContent === live.name;
          card.classList.toggle('is-live', !!active);
          var details = card.querySelector('.md-show-details');
          if (!details) return;
          var status = details.querySelector('.md-show-status');
          if (active) {
            if (!status) {
              status = document.createElement('span');
              status.className = 'md-show-status';
              details.insertBefore(status, details.firstChild);
            }
            status.textContent = '● ON AIR';
          } else if (status) {
            status.remove();
          }
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

/* ---- mydemo-player.js ---- */
/* /: iRadios owns the ONLY playback/DSP engine.
   Sticky mini is an informational DJ + sponsor card, NEVER a second player. */
(function () {
  'use strict';
  var hero = document.getElementById('home');
  var dock = document.getElementById('md-player-dock');
  if (!hero || !dock) return;

  function syncDock() {
    // Sticky appears only once the complete hero has left the viewport.
    var offscreen = hero.getBoundingClientRect().bottom <= 0;
    dock.hidden = !offscreen;
  }

  if ('IntersectionObserver' in window) {
    var observer = new IntersectionObserver(syncDock, { threshold: 0 });
    observer.observe(hero);
  }
  window.addEventListener('scroll', syncDock, { passive: true });
  window.addEventListener('resize', syncDock, { passive: true });
  syncDock();

  // No iframe cloning/reparenting: never interrupt the iRadios stream.
  // The provider may block audible autoplay under browser media policies.
  // The visible embedded widget remains the fallback with its own Play control.
}());

/* ---- mydemo-header.js ---- */
/* Deseo / V14: transparent header over the hero; solid after scrolling.
   No scroll listeners mutate player elements, iframe or ILUMA Signal slots. */
(function () {
  'use strict';
  var header = document.querySelector('.md-header');
  if (!header) return;
  var ticking = false;

  function sync() {
    ticking = false;
    var scrolled = (window.scrollY || window.pageYOffset || 0) > 24;
    header.classList.toggle('is-scrolled', scrolled);
  }

  function onScroll() {
    if (ticking) return;
    ticking = true;
    if (window.requestAnimationFrame) window.requestAnimationFrame(sync);
    else sync();
  }

  sync();
  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('pageshow', sync);
  window.addEventListener('resize', onScroll, { passive: true });
}());

/* ---- mydemo-cursor.js ---- */
/* Deseo / V12 — contrast-aware custom cursor, including native top-layer DJ dialogs.
   Never intercepts the iRadios iframe or touch/keyboard input. */
(function () {
  'use strict';
  var cursor = document.getElementById('md-custom-cursor');
  var dialog = document.getElementById('md-dj-dialog');
  if (!cursor || !window.matchMedia ||
      !window.matchMedia('(hover: hover) and (pointer: fine) and (prefers-reduced-motion: no-preference)').matches) {
    return;
  }

  var active = false;
  var pending = false;
  var x = -100, y = -100;
  var interactiveSelector = 'a, button, summary, [role="tab"], [role="button"]';
  var excluded = 'iframe, input, textarea, select, [contenteditable="true"]';
  var originalParent = cursor.parentElement || document.body;

  // Native showModal() moves the dialog to the CSS top layer. No z-index outside
  // it can paint above the modal, so the custom cursor must follow it.
  function syncCursorLayer() {
    var open = !!(dialog && dialog.open);
    var parent = open ? dialog : originalParent;
    if (cursor.parentElement !== parent) parent.appendChild(cursor);
    cursor.classList.toggle('is-over-dialog', open);
  }
  if (dialog) {
    var modalObserver = new MutationObserver(syncCursorLayer);
    modalObserver.observe(dialog, { attributes: true, attributeFilter: ['open'] });
    dialog.addEventListener('close', syncCursorLayer);
  }
  syncCursorLayer();

  function isRedCssColor(value) {
    if (!value || value === 'transparent') return false;
    // Computed styles normally return rgb()/rgba(), but hexadecimal covers
    // occasional inline declarations. No pixel sampling or heavy observers.
    var match = value.match(/^rgba?\(\s*(\d+)[,\s]+\s*(\d+)[,\s]+\s*(\d+)(?:\s*[,/]\s*([\d.]+))?/i);
    if (match) {
      var red = Number(match[1]), green = Number(match[2]), blue = Number(match[3]);
      var alpha = match[4] == null ? 1 : Number(match[4]);
      return alpha >= 0.5 && red >= 180 && green <= 105 && blue <= 105 &&
        red > green * 1.8 && red > blue * 1.8;
    }
    if (/^#[\da-f]{3,8}$/i.test(value)) {
      var hex = value.slice(1);
      if (hex.length === 3 || hex.length === 4) {
        hex = hex.split('').map(function (digit) { return digit + digit; }).join('');
      }
      var alphaHex = hex.length === 8 ? parseInt(hex.slice(6, 8), 16) / 255 : 1;
      return alphaHex >= 0.5 && parseInt(hex.slice(0, 2), 16) >= 180 &&
        parseInt(hex.slice(2, 4), 16) <= 105 && parseInt(hex.slice(4, 6), 16) <= 105;
    }
    return false;
  }

  function isOnRedElement(element) {
    // Inspect the hovered element and its local ancestry. This includes red
    // type, selected tabs and buttons, including their hover background.
    var node = element, depth = 0;
    while (node && node instanceof Element && depth < 5 && node !== document.body) {
      if (node.matches('[data-cursor-contrast="light"]')) return true;
      var style = window.getComputedStyle(node);
      if (isRedCssColor(style.backgroundColor) || isRedCssColor(style.color)) return true;
      if (node.matches('.md-marquee, .md-day-tab.is-active, .md-button-red, .md-fs-title-red, .md-word-soundtrack')) {
        return true;
      }
      node = node.parentElement;
      depth++;
    }
    return false;
  }

  function render() {
    pending = false;
    cursor.style.transform = 'translate3d(' + x + 'px,' + y + 'px,0) translate(-50%,-50%)';
  }
  function hide() {
    active = false;
    cursor.classList.remove('is-visible', 'is-interactive', 'is-light');
  }

  document.documentElement.classList.add('md-has-custom-cursor');
  document.addEventListener('pointermove', function (event) {
    if (event.pointerType !== 'mouse') {
      hide();
      return;
    }
    var target = event.target;
    if (!(target instanceof Element) || target.closest(excluded)) {
      hide();
      return;
    }
    x = event.clientX;
    y = event.clientY;
    if (!active) {
      active = true;
      cursor.classList.add('is-visible');
    }
    cursor.classList.toggle('is-interactive', !!target.closest(interactiveSelector));
    cursor.classList.toggle('is-light', isOnRedElement(target));
    if (!pending) {
      pending = true;
      window.requestAnimationFrame(render);
    }
  }, { passive: true });

  document.addEventListener('pointerout', function (event) {
    if (!event.relatedTarget) hide();
  }, { passive: true });

  // Cross-origin iframe owns the actual playback controls; use native pointer.
  document.querySelectorAll('iframe').forEach(function (frame) {
    frame.addEventListener('pointerenter', hide, { passive: true });
  });
  window.addEventListener('blur', hide);
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) hide();
  });
}());

/* ---- mydemo-menu.js ---- */
/* Deseo / — fullscreen navigation and progressive on-scroll motion.
   Never alters or reinitializes the embedded official iRadios iframe. */
(function () {
  'use strict';

  var menu = document.getElementById('md-fs-menu');
  var trigger = document.getElementById('md-menu-trigger');
  var closeButton = document.getElementById('md-fs-close');
  if (!menu || !trigger || !closeButton) return;

  var lastFocus = null;
  var hiding = null;
  var prefersReduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  function isOpen() {
    return !menu.hidden && menu.classList.contains('is-open');
  }
  function focusable() {
    return Array.prototype.slice.call(
      menu.querySelectorAll('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])')
    ).filter(function (element) {
      return !element.hidden && element.getAttribute('aria-hidden') !== 'true';
    });
  }

  function openMenu() {
    if (isOpen()) return;
    window.clearTimeout(hiding);
    lastFocus = document.activeElement;
    menu.hidden = false;
    menu.removeAttribute('aria-hidden');
    trigger.setAttribute('aria-expanded', 'true');
    document.documentElement.classList.add('md-menu-open');
    // Let the browser apply the initial frame before animating the overlay.
    window.requestAnimationFrame(function () {
      menu.classList.add('is-open');
      closeButton.focus({ preventScroll: true });
    });
  }

  function closeMenu(restoreFocus) {
    if (menu.hidden) return;
    menu.classList.remove('is-open');
    trigger.setAttribute('aria-expanded', 'false');
    document.documentElement.classList.remove('md-menu-open');
    if (restoreFocus !== false && lastFocus && typeof lastFocus.focus === 'function') {
      lastFocus.focus({ preventScroll: true });
    } else {
      // Avoid leaving keyboard focus in a hidden aria-modal dialog.
      trigger.focus({ preventScroll: true });
    }
    menu.setAttribute('aria-hidden', 'true');
    window.clearTimeout(hiding);
    hiding = window.setTimeout(function () {
      if (!menu.classList.contains('is-open')) menu.hidden = true;
    }, prefersReduced ? 0 : 400);
  }

  trigger.addEventListener('click', function () {
    if (isOpen()) closeMenu(true);
    else openMenu();
  });
  closeButton.addEventListener('click', function () { closeMenu(true); });
  Array.prototype.forEach.call(document.querySelectorAll('[data-md-open-menu]'), function (button) {
    button.addEventListener('click', openMenu);
  });

  menu.addEventListener('click', function (event) {
    var link = event.target.closest('a[href]');
    if (!link) return;
    // Native href navigation remains intact (hash/HTTPS/mailto).
    closeMenu(false);
  });

  document.addEventListener('keydown', function (event) {
    if (!isOpen()) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      closeMenu(true);
      return;
    }
    if (event.key !== 'Tab') return;
    var list = focusable();
    if (!list.length) { event.preventDefault(); closeButton.focus(); return; }
    var first = list[0], last = list[list.length - 1];
    if (event.shiftKey && (document.activeElement === first || !menu.contains(document.activeElement))) {
      event.preventDefault(); last.focus();
    } else if (!event.shiftKey && (document.activeElement === last || !menu.contains(document.activeElement))) {
      event.preventDefault(); first.focus();
    }
  });

  // Controlled motion: if IntersectionObserver is unavailable, show all content.
  // Skip motion entirely for accessibility settings that request it.
  if (prefersReduced || !('IntersectionObserver' in window)) return;
  var selectors = [
    '.md-hero-copy', '.md-player-home',
    '.md-section-top', '.md-section-heading', '.md-show-card',
    '.md-partner-card', '.md-playlist', '.md-track-row',
    '.md-manifesto h2', '.md-manifesto-bottom',
    '.md-faq-item', '.md-ai-wrap .ai-discovery-card',
    '.md-footer-identity', '.md-footer-minimal'
  ];
  var items = Array.prototype.slice.call(document.querySelectorAll(selectors.join(',')));
  var observer = new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
      if (!entry.isIntersecting) return;
      entry.target.classList.add('is-visible');
      observer.unobserve(entry.target);
    });
  }, { rootMargin: '0px 0px -40px 0px', threshold: .08 });
  items.forEach(function (element, i) {
    // Hidden weekday panels are observed when the user switches tabs.
    element.classList.add('md-reveal');
    element.style.setProperty('--md-delay', ((i % 3) * 65) + 'ms');
    observer.observe(element);
  });
  document.documentElement.classList.add('md-motion-enabled');
}());
