/* Deseo /mydemo — fullscreen navigation and progressive on-scroll motion.
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
  var carousel = menu.querySelector('.md-menu-showcase');
  var carouselTrack = document.getElementById('md-menu-carousel-track');
  var carouselSlides = carousel ? Array.prototype.slice.call(carousel.querySelectorAll('.md-menu-promo')) : [];
  var carouselDots = carousel ? Array.prototype.slice.call(carousel.querySelectorAll('[data-carousel-to]')) : [];
  var carouselCounter = document.getElementById('md-menu-carousel-current');
  var carouselIndex = 0;
  var carouselTimer = null;

  function showCarouselSlide(index) {
    if (!carouselTrack || !carouselSlides.length) return;
    carouselIndex = (index + carouselSlides.length) % carouselSlides.length;
    carouselTrack.style.transform = 'translate3d(-' + (carouselIndex * 100) + '%,0,0)';
    carouselSlides.forEach(function (slide, i) {
      var selected = i === carouselIndex;
      slide.tabIndex = selected ? 0 : -1;
      slide.setAttribute('aria-hidden', selected ? 'false' : 'true');
      slide.classList.toggle('is-current', selected);
    });
    carouselDots.forEach(function (dot, i) {
      var active = i === carouselIndex;
      dot.classList.toggle('is-active', active);
      dot.setAttribute('aria-current', active ? 'true' : 'false');
    });
    if (carouselCounter) {
      carouselCounter.textContent = String(carouselIndex + 1).padStart(2, '0');
    }
  }
  function stopCarouselRotation() {
    if (carouselTimer) window.clearInterval(carouselTimer);
    carouselTimer = null;
  }
  function startCarouselRotation() {
    stopCarouselRotation();
    if (prefersReduced || !isOpen() || carouselSlides.length < 2) return;
    carouselTimer = window.setInterval(function () {
      showCarouselSlide(carouselIndex + 1);
    }, 5600);
  }
  if (carousel) {
    showCarouselSlide(0);
    var prev = carousel.querySelector('.md-menu-carousel-prev');
    var next = carousel.querySelector('.md-menu-carousel-next');
    if (prev) prev.addEventListener('click', function () {
      showCarouselSlide(carouselIndex - 1);
      startCarouselRotation();
    });
    if (next) next.addEventListener('click', function () {
      showCarouselSlide(carouselIndex + 1);
      startCarouselRotation();
    });
    carouselDots.forEach(function (dot) {
      dot.addEventListener('click', function () {
        showCarouselSlide(Number(dot.getAttribute('data-carousel-to')));
        startCarouselRotation();
      });
    });
    carousel.addEventListener('mouseenter', stopCarouselRotation);
    carousel.addEventListener('mouseleave', startCarouselRotation);
    carousel.addEventListener('focusin', stopCarouselRotation);
    carousel.addEventListener('focusout', function (event) {
      if (!carousel.contains(event.relatedTarget)) startCarouselRotation();
    });
  }


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
      startCarouselRotation();
      closeButton.focus({ preventScroll: true });
    });
  }

  function closeMenu(restoreFocus) {
    if (menu.hidden) return;
    menu.classList.remove('is-open');
    stopCarouselRotation();
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
