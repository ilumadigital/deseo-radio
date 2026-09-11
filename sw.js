/* =========================================================================
   DESEO RADIO - MASTER COMBINED SERVICE WORKER (PWA + WEBPUSHR)
========================================================================= */
const CACHE_NAME = 'deseo-pwa-v1';
const ASSETS_TO_CACHE = [
  '/',
  '/assets/css/style.css',
  'https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js',
  'https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/ScrollTrigger.min.js',
  'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css'
];

// Install Event - Caching the key shell assets
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => {
      return cache.addAll(ASSETS_TO_CACHE);
    }).then(() => {
      return self.skipWaiting();
    })
  );
});

// Activate Event - Cleaning old caches if any
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames.map(cache => {
          if (cache !== CACHE_NAME) {
            return caches.delete(cache);
          }
        })
      );
    }).then(() => {
      return self.clients.claim();
    })
  );
});

// Fetch Event - Serve assets from cache or network fallback
self.addEventListener('fetch', event => {
  // Παράκαμψη για τα requests του Webpushr ώστε να μην μπλοκάρονται από το cache
  if (event.request.url.includes('webpushr.com') || event.request.url.includes('iradios.gr')) {
    return;
  }
  
  event.respondWith(
    caches.match(event.request).then(cachedResponse => {
      if (cachedResponse) {
        return cachedResponse;
      }
      return fetch(event.request);
    })
  );
});