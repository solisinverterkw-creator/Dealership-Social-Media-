const CACHE_NAME = 'rosp-dashboard-v2';
const STATIC_ASSETS = [
  'assets/style.css',
  'assets/suzuki-logo.png',
  'assets/icon-192.png',
  'assets/icon-512.png',
  'manifest.json',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(STATIC_ASSETS))
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((names) =>
      Promise.all(names.filter((n) => n !== CACHE_NAME).map((n) => caches.delete(n)))
    )
  );
  self.clients.claim();
});

// Only static assets are cache-first (fast + offline-available). Every PHP
// page and API endpoint (refresh_*.php, check_*.php, etc.) is network-only —
// this dashboard's numbers must never be served stale from a cache.
self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  const isStaticAsset = event.request.method === 'GET' && url.pathname.includes('/assets/');

  if (!isStaticAsset) {
    return; // let the browser handle it normally (network-only)
  }

  event.respondWith(
    caches.match(event.request).then((cached) => {
      if (cached) return cached;
      return fetch(event.request).then((response) => {
        const clone = response.clone();
        caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone));
        return response;
      });
    })
  );
});
