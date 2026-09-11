/* Deseo Radio — service worker retirement
   Radio playback takes priority over PWA caching. This worker intentionally
   does not intercept fetches and removes old Deseo caches/registration. */

self.addEventListener('install', function (event) {
  self.skipWaiting();
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys()
      .then(function (keys) {
        return Promise.all(keys.map(function (key) {
          if (key.indexOf('deseo-') === 0) return caches.delete(key);
          return null;
        }));
      })
      .then(function () {
        return self.registration.unregister();
      })
  );
});
