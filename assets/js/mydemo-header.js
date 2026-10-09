/* Deseo /mydemo V14: transparent header over the hero; solid after scrolling.
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
