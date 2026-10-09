/* Deseo /mydemo V12 — contrast-aware custom cursor, including native top-layer DJ dialogs.
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
