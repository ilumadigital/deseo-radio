/* /mydemo: tiny red pointer accent. No control interception or layout changes.
   Native cursor remains on touch, reduced-motion, inputs and external iframes. */
(function () {
  'use strict';
  var cursor = document.getElementById('md-custom-cursor');
  if (!cursor || !window.matchMedia ||
      !window.matchMedia('(hover: hover) and (pointer: fine) and (prefers-reduced-motion: no-preference)').matches) {
    return;
  }
  var active = false, pending = false, x = -100, y = -100;
  var interactiveSelector = 'a, button, summary, [role="tab"], [role="button"]';
  function render() {
    pending = false;
    cursor.style.transform = 'translate3d(' + x + 'px,' + y + 'px,0) translate(-50%,-50%)';
  }
  function hide() {
    active = false;
    cursor.classList.remove('is-visible','is-interactive');
  }
  document.documentElement.classList.add('md-has-custom-cursor');
  document.addEventListener('pointermove', function (event) {
    if (event.pointerType !== 'mouse') {
      hide();
      return;
    }
    var el = event.target;
    if (!(el instanceof Element) || el.closest('iframe, input, textarea, select, [contenteditable="true"]')) {
      hide();
      return;
    }
    x = event.clientX;
    y = event.clientY;
    if (!active) {
      active = true;
      cursor.classList.add('is-visible');
    }
    cursor.classList.toggle('is-interactive', !!el.closest(interactiveSelector));
    if (!pending) {
      pending = true;
      window.requestAnimationFrame(render);
    }
  }, {passive:true});
  document.addEventListener('pointerout', function (event) {
    if (!event.relatedTarget) hide();
  }, {passive:true});
  window.addEventListener('blur', hide);
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) hide();
  });
}());
