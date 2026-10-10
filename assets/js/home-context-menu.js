/* Deseo Radio homepage — branded secondary-click navigation and best-effort
   browser-shortcut deterrents. Source/devtools cannot be secured by JavaScript.
   Intentionally scoped to the shared / and /mydemo renderer only. */
(function () {
  'use strict';

  var menu = document.getElementById('deseo-context-menu');
  if (!menu || window.__DESEO_HOME_CONTEXT_MENU__) return;
  window.__DESEO_HOME_CONTEXT_MENU__ = true;

  var closeButton = menu.querySelector('.deseo-context-close');
  var links = Array.prototype.slice.call(menu.querySelectorAll('a[role="menuitem"]'));
  var lastFocus = null;

  function editable(target) {
    return target instanceof Element && !!target.closest(
      'input, textarea, select, [contenteditable]:not([contenteditable="false"]), [role="textbox"]'
    );
  }

  function closeMenu(restoreFocus) {
    if (menu.hidden) return;
    menu.hidden = true;
    if (restoreFocus && lastFocus && typeof lastFocus.focus === 'function') {
      lastFocus.focus({ preventScroll: true });
    }
  }

  function openMenu(x, y) {
    lastFocus = document.activeElement;
    menu.hidden = false;
    // Measure visible dimensions first, then keep within the viewport.
    var rect = menu.getBoundingClientRect();
    var margin = 12;
    var viewWidth = document.documentElement.clientWidth;
    var viewHeight = window.innerHeight;
    menu.style.left = Math.max(margin, Math.min(x, viewWidth - rect.width - margin)) + 'px';
    menu.style.top = Math.max(margin, Math.min(y, viewHeight - rect.height - margin)) + 'px';
    if (links.length) links[0].focus({ preventScroll: true });
  }

  document.addEventListener('contextmenu', function (event) {
    // Native editing menus must still work in forms and accessible text fields.
    if (editable(event.target)) return;
    if (window.matchMedia && window.matchMedia('(pointer: coarse)').matches) return;
    event.preventDefault();
    openMenu(event.clientX, event.clientY);
  }, true);

  document.addEventListener('pointerdown', function (event) {
    if (!menu.hidden && !menu.contains(event.target)) closeMenu(false);
  }, true);

  if (closeButton) {
    closeButton.addEventListener('click', function () { closeMenu(true); });
  }

  menu.addEventListener('click', function (event) {
    var target = event.target;
    var link = target instanceof Element ? target.closest('a[href]') : null;
    if (!link) return;
    // Leave native anchor navigation, back/forward history and keyboard support.
    closeMenu(false);
  });

  function blockedShortcut(event) {
    var key = String(event.key || '').toLowerCase();
    var ctrlOrMeta = event.ctrlKey || event.metaKey;
    if (event.key === 'F12' || event.keyCode === 123) return true;
    if (!ctrlOrMeta) return false;
    if (event.shiftKey && ['i', 'j', 'c', 'k'].indexOf(key) !== -1) return true;
    if (event.metaKey && event.altKey && ['i', 'j', 'c', 'u'].indexOf(key) !== -1) return true;
    // Only block copying/pasting when the focus is not in an editable control.
    if (!editable(event.target) && !event.altKey &&
        ['u', 's', 'c', 'x', 'v'].indexOf(key) !== -1) return true;
    return false;
  }

  document.addEventListener('keydown', function (event) {
    if (!menu.hidden) {
      if (event.key === 'Escape') {
        event.preventDefault();
        closeMenu(true);
        return;
      }
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        var current = links.indexOf(document.activeElement);
        var next = (current + (event.key === 'ArrowDown' ? 1 : -1) + links.length) % links.length;
        if (links[next]) links[next].focus({ preventScroll: true });
        return;
      }
      if (event.key === 'Home' || event.key === 'End') {
        event.preventDefault();
        var to = event.key === 'Home' ? links[0] : links[links.length - 1];
        if (to) to.focus({ preventScroll: true });
        return;
      }
      if (event.key === 'Tab') closeMenu(false);
    }
    if (!blockedShortcut(event)) return;
    event.preventDefault();
  }, true);

  ['copy', 'cut', 'paste'].forEach(function (type) {
    document.addEventListener(type, function (event) {
      if (!editable(event.target)) event.preventDefault();
    }, true);
  });

  window.addEventListener('resize', function () { closeMenu(false); });
  document.addEventListener('scroll', function () { closeMenu(false); }, true);
  window.addEventListener('blur', function () { closeMenu(false); });
}());
