/*
 * CLARA Service Worker — PWA layer
 *
 * Aplikasi ini berbasis sesi & multi-user. Maka HTML hasil render (yang berisi
 * data milik user) TIDAK PERNAH di-cache, agar tidak ada data basi / bocor antar
 * user. Yang di-cache hanya aset statis (CSS/JS/ikon/font) dan halaman offline.
 *
 * Strategi:
 *  - Navigasi (buka halaman)      → network-only, fallback ke offline.html bila gagal.
 *  - Aset statis (/assets/...)    → cache-first (stale-while-revalidate ringan).
 *  - Lainnya (POST, ?r=data, dll) → network-only, tidak disentuh.
 */

const VERSION    = 'clara-v1';
const ASSETCACHE = 'clara-assets-' + VERSION;
const SHELLCACHE = 'clara-shell-' + VERSION;

// Path absolut berbasis lokasi SW (mendukung install di subfolder).
const OFFLINE_URL = new URL('./offline.html', self.location).href;
const PRECACHE = [
  OFFLINE_URL,
  new URL('./assets/app.css', self.location).href,
  new URL('./assets/icon-192.png', self.location).href,
  new URL('./assets/icon-512.png', self.location).href,
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(SHELLCACHE)
      .then((cache) => cache.addAll(PRECACHE).catch(() => {}))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys
          .filter((k) => k !== ASSETCACHE && k !== SHELLCACHE)
          .map((k) => caches.delete(k))
      )
    ).then(() => self.clients.claim())
  );
});

function isStaticAsset(url) {
  return url.pathname.includes('/assets/') &&
    /\.(css|js|png|jpe?g|gif|svg|webp|woff2?|ico|json)$/i.test(url.pathname);
}

self.addEventListener('fetch', (event) => {
  const req = event.request;

  // Hanya tangani GET same-origin. Sisanya biarkan ke jaringan.
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // Navigasi halaman → network-only, fallback offline.
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(() =>
        caches.match(OFFLINE_URL).then((r) => r || Response.error())
      )
    );
    return;
  }

  // Aset statis → cache-first + refresh di belakang layar.
  if (isStaticAsset(url)) {
    event.respondWith(
      caches.open(ASSETCACHE).then((cache) =>
        cache.match(req).then((cached) => {
          const network = fetch(req)
            .then((res) => {
              if (res && res.status === 200) cache.put(req, res.clone());
              return res;
            })
            .catch(() => cached);
          return cached || network;
        })
      )
    );
    return;
  }

  // Selain itu (?r=... data, ekspor, dsb) → biarkan langsung ke jaringan.
});
