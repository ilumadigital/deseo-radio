var MYLIVE_CACHE = 'mylive-app-v1';
var MYLIVE_STATIC = [
  '/mylive/offline.html',
  '/mylive/style.css',
  '/mylive/app.js',
  '/mylive/manifest.json',
  '/assets/img/favicon.png',
  '/assets/img/deseoradio-logo.png',
  '/assets/js/deseo-lockdown.js',
  '/assets/js/deseo-dialogs.js'
];

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(MYLIVE_CACHE)
      .then(function (cache) {
        return cache.addAll(MYLIVE_STATIC);
      })
      .catch(function () {})
  );
  self.skipWaiting();
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.map(function (key) {
        if (key.indexOf('mylive-app-') === 0 && key !== MYLIVE_CACHE) {
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
  } catch (error) {
    return;
  }

  if (url.origin !== self.location.origin) return;

  if (request.mode === 'navigate') {
    if (url.pathname.indexOf('/mylive/') !== 0) return;

    event.respondWith(
      fetch(request).catch(function () {
        return caches.match('/mylive/offline.html');
      })
    );
    return;
  }

  var isMyLiveStatic =
    url.pathname === '/mylive/style.css' ||
    url.pathname === '/mylive/app.js' ||
    url.pathname === '/mylive/manifest.json' ||
    url.pathname === '/mylive/offline.html' ||
    url.pathname === '/assets/img/favicon.png' ||
    url.pathname === '/assets/img/deseoradio-logo.png' ||
    url.pathname === '/assets/js/deseo-lockdown.js' ||
    url.pathname === '/assets/js/deseo-dialogs.js';

  if (!isMyLiveStatic) return;

  event.respondWith(
    caches.match(request).then(function (cached) {
      var network = fetch(request).then(function (response) {
        if (response && response.ok) {
          var copy = response.clone();
          caches.open(MYLIVE_CACHE).then(function (cache) {
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
