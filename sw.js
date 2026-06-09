/**
 * sw.js — Service Worker EMMGO Dashboard
 * Stratégie :
 *   - Shell (HTML, icônes) : réseau d'abord, cache en secours
 *   - CDN (fonts Tabler) : cache d'abord (immuable)
 *   - API (api.php, proxy.php) : réseau d'abord, cache en secours
 */

const SHELL_VER = 'emmgo-shell-v4';
const DATA_VER  = 'emmgo-data-v4';
const ALL_CACHES = [SHELL_VER, DATA_VER];

// Ressources à pré-cacher à l'installation
const PRECACHE = [
  './index.html',
  './icon-192.png',
  './icon-512.png',
  'https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.x/dist/tabler-icons.min.css',
];

// ── Install ──────────────────────────────────────────────────────
self.addEventListener('install', event => {
  self.skipWaiting();
  event.waitUntil(
    caches.open(SHELL_VER).then(cache => {
      return Promise.allSettled(
        PRECACHE.map(url =>
          cache.add(url).catch(e => console.warn('[SW] Précache échoué :', url, e.message))
        )
      );
    })
  );
});

// ── Activate : nettoyage des anciens caches ──────────────────────
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => !ALL_CACHES.includes(k)).map(k => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

// ── Fetch ────────────────────────────────────────────────────────
self.addEventListener('fetch', event => {
  const { request } = event;
  const url = new URL(request.url);

  // Ignorer les requêtes non-GET et les extensions de navigateur
  if (request.method !== 'GET') return;
  if (!url.protocol.startsWith('http')) return;

  // CDN externe (fonts, CSS Tabler) → cache d'abord
  if (url.hostname.includes('jsdelivr.net') || url.hostname.includes('cdnjs.cloudflare.com')) {
    event.respondWith(cacheFirst(request, SHELL_VER));
    return;
  }

  // API et proxy ICS → réseau d'abord, cache en secours (offline)
  if (url.pathname.includes('api.php') || url.pathname.includes('proxy.php')) {
    event.respondWith(networkFirst(request, DATA_VER));
    return;
  }

  // App shell (index.html et assets locaux) → réseau d'abord
  if (url.origin === self.location.origin) {
    event.respondWith(networkFirst(request, SHELL_VER));
    return;
  }
});

// ── Stratégies ───────────────────────────────────────────────────

async function networkFirst(request, cacheName) {
  try {
    const response = await fetch(request.clone(), { signal: AbortSignal.timeout(8000) });
    if (response.ok || response.status === 0) {
      const cache = await caches.open(cacheName);
      cache.put(request, response.clone());
      // Notifier les clients qu'on est en ligne
      broadcastStatus('online');
    }
    return response;
  } catch (_) {
    // Réseau indisponible → servir depuis le cache
    const cached = await caches.match(request, { ignoreSearch: false });
    if (cached) {
      broadcastStatus('offline');
      return addOfflineHeader(cached.clone());
    }
    // Dernier recours : index.html pour les navigations
    if (request.mode === 'navigate') {
      const shell = await caches.match('./index.html');
      if (shell) { broadcastStatus('offline'); return shell; }
    }
    return new Response('Hors ligne et aucun cache disponible', {
      status: 503,
      headers: { 'Content-Type': 'text/plain; charset=utf-8' },
    });
  }
}

async function cacheFirst(request, cacheName) {
  const cached = await caches.match(request);
  if (cached) return cached;
  try {
    const response = await fetch(request.clone());
    if (response.ok) {
      const cache = await caches.open(cacheName);
      cache.put(request, response.clone());
    }
    return response;
  } catch (_) {
    return new Response('Ressource non disponible hors ligne', { status: 503 });
  }
}

// Ajoute un header personnalisé pour que l'app sache qu'elle est servie depuis le cache
function addOfflineHeader(response) {
  const headers = new Headers(response.headers);
  headers.set('X-Served-From-Cache', 'true');
  return new Response(response.body, {
    status:     response.status,
    statusText: response.statusText,
    headers,
  });
}

// Diffuse le statut réseau à tous les onglets ouverts
function broadcastStatus(status) {
  self.clients.matchAll({ includeUncontrolled: true }).then(clients =>
    clients.forEach(c => c.postMessage({ type: 'NETWORK_STATUS', status }))
  );
}

// ── Message depuis l'app (ex. "force refresh") ───────────────────
self.addEventListener('message', event => {
  if (event.data?.type === 'SKIP_WAITING') self.skipWaiting();
  if (event.data?.type === 'CLEAR_DATA_CACHE') {
    caches.delete(DATA_VER).then(() =>
      event.source?.postMessage({ type: 'DATA_CACHE_CLEARED' })
    );
  }
});
