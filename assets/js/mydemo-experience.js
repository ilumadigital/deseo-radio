/* Deseo /mydemo v10. Pure visual enhancement; never touches audio or CMS data. */
(function () {
  'use strict';

  var root = document.documentElement;
  var reducedMotion = window.matchMedia &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* Never wait for the network, iframe, artwork or audio to finish loading. */
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

  var carousel = document.querySelector('[data-md-carousel]');
  var menu = document.getElementById('md-fs-menu');
  if (!carousel || !menu) return;

  var slides = Array.prototype.slice.call(carousel.querySelectorAll('[data-md-slide]'));
  var dots = Array.prototype.slice.call(carousel.querySelectorAll('[data-md-dot]'));
  var prev = carousel.querySelector('[data-md-prev]');
  var next = carousel.querySelector('[data-md-next]');
  var counter = carousel.querySelector('[data-md-current]');
  if (!slides.length || dots.length !== slides.length || !prev || !next) return;

  var current = 0;
  var paused = false;
  var swipeStart = null;

  function show(index) {
    current = (index + slides.length) % slides.length;
    slides.forEach(function (slide, i) {
      var active = i === current;
      slide.classList.toggle('is-active', active);
      slide.tabIndex = active ? 0 : -1;
      if (active) slide.removeAttribute('aria-hidden');
      else slide.setAttribute('aria-hidden', 'true');
    });
    dots.forEach(function (dot, i) {
      dot.setAttribute('aria-selected', i === current ? 'true' : 'false');
      dot.tabIndex = i === current ? 0 : -1;
    });
    if (counter) counter.textContent = String(current + 1).padStart(2, '0');
  }

  prev.addEventListener('click', function () { show(current - 1); });
  next.addEventListener('click', function () { show(current + 1); });
  dots.forEach(function (dot, i) {
    dot.addEventListener('click', function () { show(i); });
    dot.addEventListener('keydown', function (event) {
      var nextIndex;
      if (event.key === 'ArrowRight') nextIndex = current + 1;
      else if (event.key === 'ArrowLeft') nextIndex = current - 1;
      else if (event.key === 'Home') nextIndex = 0;
      else if (event.key === 'End') nextIndex = slides.length - 1;
      else return;
      event.preventDefault();
      show(nextIndex);
      dots[current].focus();
    });
  });

  carousel.addEventListener('mouseenter', function () { paused = true; });
  carousel.addEventListener('mouseleave', function () { paused = false; });
  carousel.addEventListener('focusin', function () { paused = true; });
  carousel.addEventListener('focusout', function (event) {
    if (!event.relatedTarget || !carousel.contains(event.relatedTarget)) paused = false;
  });

  carousel.addEventListener('touchstart', function (event) {
    if (event.touches.length !== 1) return;
    swipeStart = event.touches[0].clientX;
    paused = true;
  }, { passive: true });
  carousel.addEventListener('touchend', function (event) {
    if (swipeStart === null || !event.changedTouches.length) return;
    var distance = event.changedTouches[0].clientX - swipeStart;
    swipeStart = null;
    if (Math.abs(distance) >= 45) {
      event.preventDefault();
      show(current + (distance < 0 ? 1 : -1));
    }
    paused = false;
  }, { passive: false });
  carousel.addEventListener('touchcancel', function () { swipeStart = null; paused = false; });

  /* Referenced DJSA artwork may live on the deployment filesystem, not in Git. */
  Array.prototype.forEach.call(carousel.querySelectorAll('img[data-fallback]'), function (img) {
    img.addEventListener('error', function () {
      var fallback = img.getAttribute('data-fallback');
      if (!fallback || img.getAttribute('src') === fallback) return;
      img.setAttribute('src', fallback);
    });
  });

  /* Reset to the DJ SA headline each time the menu opens. */
  if ('MutationObserver' in window) {
    new MutationObserver(function () {
      if (menu.classList.contains('is-open')) show(0);
    }).observe(menu, { attributes: true, attributeFilter: ['class'] });
  }

  if (!reducedMotion) {
    window.setInterval(function () {
      if (!menu.hidden && menu.classList.contains('is-open') &&
          !document.hidden && !paused) show(current + 1);
    }, 5700);
  }
  show(0);
}());
