/*
 * VaCom â€” service worker.
 * Bewust minimaal: VaCom is een live-app, dus we cachen gÃ©Ã©n pagina's of API-
 * antwoorden (dat zou na een FTP-upload oude versies laten hangen). De worker
 * is er zodat de app installeerbaar is en om een nette offline-pagina te tonen.
 * Pushnotificaties komen hier later bij.
 */
const VERSIE = 'vacom-0.5.3';
const OFFLINE = './offline.html';

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(VERSIE).then((c) => c.add(OFFLINE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then((ks) => Promise.all(ks.filter((k) => k !== VERSIE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  if (e.request.method !== 'GET' || e.request.mode !== 'navigate') return;
  e.respondWith(fetch(e.request).catch(() => caches.match(OFFLINE)));
});
