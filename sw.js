importScripts('https://cdn.webpushr.com/sw-server.min.js');

/* Deseo Radio PWA
   Static-assets-only caching. The live iRadios player is cross-origin and is
   intentionally never intercepted or cached by this service worker. */

var CACHE_NAME = 'deseo-static-v7';
var STATIC_ASSETS = [
  '/offline.html',
  '/assets/img/favicon.png',
  '/assets/img/deseoradio-logo.png'
];

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(function (cache) { return cache.addAll(STATIC_ASSETS); })
      .catch(function () {})
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
  try {
    url = new URL(request.url);
  } catch (e) {
    return;
  }

  if (url.origin !== self.location.origin) return;
  if (url.pathname.indexOf('/iluma/') === 0) return;
  if (url.pathname === '/webpushr-sw.js') return;

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request).catch(function () {
        return caches.match('/offline.html');
      })
    );
    return;
  }

  var isStatic =
    url.pathname.indexOf('/assets/') === 0 ||
    url.pathname === '/manifest.json' ||
    url.pathname === '/offline.html';

  if (!isStatic) return;

  event.respondWith(
    caches.match(request).then(function (cached) {
      var network = fetch(request).then(function (response) {
        if (response && response.ok) {
          var copy = response.clone();
          caches.open(CACHE_NAME).then(function (cache) {
            cache.put(request, copy);
          });
        }
        return response;
      }).catch(function () {
        return cached;
      });

      return cached || network;
    })
  );
});
