/* Deseo /mydemo — three-stage logo preloader and static DJ SA feature.
   Visual enhancement only: the official iRadios player is never touched. */
(function () {
  'use strict';

  var root = document.documentElement;
  var reducedMotion = window.matchMedia &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* Do not delay listening while audio, CMS, or artwork downloads. */
  if (reducedMotion) {
    root.classList.remove('md-preloading');
  } else {
    window.setTimeout(function () {
      root.classList.remove('md-preloading');
    }, 2650);
  }
  window.addEventListener('pageshow', function (event) {
    if (event.persisted) root.classList.remove('md-preloading');
  });

  /* Static featured poster remains visible; fall back only if its image fails. */
  var feature = document.querySelector('.md-menu-feature-card');
  if (!feature) return;
  var image = feature.querySelector('img[data-fallback]');
  if (!image) return;
  image.addEventListener('error', function () {
    var fallback = image.getAttribute('data-fallback');
    if (fallback && image.getAttribute('src') !== fallback) {
      image.setAttribute('src', fallback);
    }
  });
}());
