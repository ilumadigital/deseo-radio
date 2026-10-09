/* /mydemo: iRadios owns the ONLY playback/DSP engine.
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
