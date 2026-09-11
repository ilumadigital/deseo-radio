/* Deseo Radio — resilient, update-first service worker */
var VERSION = '1';
try {
  VERSION = new URL(self.location.href).searchParams.get('v') || VERSION;
} catch (e) {}

var CACHE_NAME = 'deseo-runtime-' + VERSION;
var OFFLINE_URL = '/offline.html';
var PRECACHE = [
  OFFLINE_URL,
  '/assets/img/deseoradio-logo.png',
  '/assets/img/favicon.png',
  '/assets/img/bg.png'
];

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(function (cache) {
        return Promise.all(PRECACHE.map(function (url) {
          return cache.add(url).catch(function () { return null; });
        }));
      })
      .then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys()
      .then(function (keys) {
        return Promise.all(keys.map(function (key) {
          if (key.indexOf('deseo-runtime-') === 0 && key !== CACHE_NAME) {
            return caches.delete(key);
          }
          return null;
        }));
      })
      .then(function () { return self.clients.claim(); })
  );
});

function networkFirst(request) {
  return fetch(request, { cache: 'no-cache' })
    .then(function (response) {
      if (response && response.ok) {
        var copy = response.clone();
        caches.open(CACHE_NAME).then(function (cache) { cache.put(request, copy); });
      }
      return response;
    })
    .catch(function () {
      return caches.match(request);
    });
}

function staleWhileRevalidate(request) {
  return caches.match(request).then(function (cached) {
    var update = fetch(request).then(function (response) {
      if (response && response.ok) {
        var copy = response.clone();
        caches.open(CACHE_NAME).then(function (cache) { cache.put(request, copy); });
      }
      return response;
    }).catch(function () { return cached; });

    return cached || update;
  });
}

self.addEventListener('fetch', function (event) {
  var request = event.request;
  if (request.method !== 'GET') return;

  var url;
  try { url = new URL(request.url); } catch (e) { return; }

  if (url.origin !== self.location.origin) return;
  if (url.pathname.indexOf('/iluma/') === 0) return;

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request, { cache: 'no-cache' })
        .catch(function () {
          return caches.match(request).then(function (cached) {
            return cached || caches.match(OFFLINE_URL);
          });
        })
    );
    return;
  }

  if (url.pathname === '/sw.js' || url.pathname.endsWith('.css') || url.pathname.endsWith('.js')) {
    event.respondWith(networkFirst(request));
    return;
  }

  if (/\.(png|jpg|jpeg|webp|svg|ico)$/i.test(url.pathname)) {
    event.respondWith(staleWhileRevalidate(request));
  }
});