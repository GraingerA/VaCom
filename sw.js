/*
 * VaCom — service worker.
 * Bewust minimaal: VaCom is een live-app, dus we cachen géén pagina's of API-
 * antwoorden (dat zou na een FTP-upload oude versies laten hangen). De worker
 * is er zodat de app installeerbaar is, om een nette offline-pagina te tonen,
 * en (v0.9) om pushmeldingen te ontvangen en te tonen.
 */
const VERSIE = 'vacom-0.9.3';
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

/* ---------- Pushmeldingen (v0.9) ---------- */
// De server stuurt {title, body, url, tag}. Zelfde tag = de vorige melding
// van dat kanaal wordt vervangen, zodat je geen stapel krijgt.
self.addEventListener('push', (e) => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch (err) { d = { body: e.data ? e.data.text() : '' }; }
  const titel = d.title || 'VaCom';
  e.waitUntil(self.registration.showNotification(titel, {
    body: d.body || '',
    icon: './assets/icon-192.png',
    badge: './assets/icon-192.png',
    tag: d.tag || 'vacom',
    renotify: true,
    data: { url: d.url || './index.php' },
  }));
});

// Klik op de melding: bestaand VaCom-venster naar voren halen (en naar het
// kanaal sturen), anders een nieuw venster openen.
self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const doel = new URL(e.notification.data && e.notification.data.url ? e.notification.data.url : './index.php', self.location.href).href;
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((lijst) => {
    for (const c of lijst) {
      if (c.url.indexOf('/vacom/') !== -1 && 'focus' in c) {
        return c.focus().then(() => ('navigate' in c ? c.navigate(doel) : null));
      }
    }
    return self.clients.openWindow(doel);
  }));
});
