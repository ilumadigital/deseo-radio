importScripts('https://cdn.webpushr.com/sw-server.min.js');

/* Deseo Radio production service worker.
 * Live iRadios playback is cross-origin and is never intercepted.
 * Navigations are online-first. Brand, CSS and JS are network-first to
 * prevent a cached logo/style from appearing after a regular F5.
 */
var CACHE_NAME = 'deseo-static-v10';
var STATIC_ASSETS = [
  '/offline.html',
  '/assets/img/favicon.png',
  '/assets/img/deseoradio-logo.png',
  '/assets/css/home.css',
  '/assets/js/home.js'
];

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(CACHE_NAME).then(function (cache) {
      return cache.addAll(STATIC_ASSETS);
    }).catch(function () {})
  );
  self.skipWaiting();
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.map(function (key) {
        if (key.indexOf('deseo-') === 0 && key !== CACHE_NAME) {
          return caches.delete(key);
        }
        return null;
      }));
    }).then(function () {
      return self.clients.claim();
    })
  );
});

self.addEventListener('fetch', function (event) {
  var request = event.request;
  if (request.method !== 'GET') return;
  var url;
  try { url = new URL(request.url); } catch (error) { return; }
  if (url.origin !== self.location.origin) return;
  if (url.pathname.indexOf('/iluma/') === 0 || url.pathname === '/webpushr-sw.js') return;

  // Always request live HTML/JSON from the origin, not from CacheStorage.
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request, { cache: 'no-store' }).catch(function () {
        return caches.match('/offline.html');
      })
    );
    return;
  }

  var isStatic = url.pathname.indexOf('/assets/') === 0 ||
    url.pathname === '/manifest.json' || url.pathname === '/offline.html';
  if (!isStatic) return;

  var critical = /\.(?:css|js)$/i.test(url.pathname) ||
    /^\/assets\/img\/(?:deseoradio-logo|favicon|favicon-nobg)\.(?:png|webp|svg)$/i.test(url.pathname);

  // Prioritize the fresh network response for the logo and render-critical code.
  if (critical) {
    event.respondWith(
      fetch(request, { cache: 'no-cache' }).then(function (response) {
        if (response.ok) {
          var copy = response.clone();
          event.waitUntil(caches.open(CACHE_NAME).then(function (cache) {
            return cache.put(request, copy);
          }).catch(function () {}));
        }
        return response;
      }).catch(function () {
        return caches.match(request).then(function (cached) {
          return cached || Response.error();
        });
      })
    );
    return;
  }

  // Other static assets remain cache-first for fast repeat mobile visits.
  event.respondWith(caches.match(request).then(function (cached) {
    if (cached) return cached;
    return fetch(request).then(function (response) {
      if (response.ok) {
        var copy = response.clone();
        event.waitUntil(caches.open(CACHE_NAME).then(function (cache) {
          return cache.put(request, copy);
        }).catch(function () {}));
      }
      return response;
    });
  }));
});
