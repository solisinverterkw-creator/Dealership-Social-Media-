const CACHE_NAME = 'rosp-dashboard-v5';
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

// Navigation requests: Network-first with offline fallback.
// Static assets: Cache-first.
// ALL other requests (PHP endpoints, API calls, Bright Data polling):
// DO NOT call event.respondWith() — let the mobile browser execute them
// natively! Wrapping fetch(event.request, {credentials}) inside SW throws
// TypeError in many mobile browsers (WebKit/Blink) when altering Request Init,
// which breaks refresh_fb.php, refresh_yt.php, refresh_gr.php entirely.
self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);

  // --- Navigation requests (page loads, pull-to-refresh) ---
  if (event.request.mode === 'navigate') {
    event.respondWith(
      fetch(event.request).catch(() => {
        return new Response(
          '<html><body style="font-family:sans-serif;text-align:center;padding:40px;">' +
          '<h2>You are offline</h2><p>Please check your internet connection and try again.</p></body></html>',
          { headers: { 'Content-Type': 'text/html' } }
        );
      })
    );
    return;
  }

  // --- Static assets (/assets/*): Cache-first ---
  const isStaticAsset = event.request.method === 'GET' && url.pathname.includes('/assets/');
  if (isStaticAsset) {
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
    return;
  }

  // Any other request (PHP endpoints like refresh_*.php, POST requests, etc.)
  // simply return without respondWith() so browser handles it natively with full cookies/credentials!
});
