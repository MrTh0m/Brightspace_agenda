/**
 * sw.js — Service Worker EMMGO Dashboard
 * Stratégie :
 *   - Shell (HTML, icônes) : réseau d'abord, cache en secours
 *   - CDN (fonts Tabler) : cache d'abord (immuable)
 *   - API (api.php, proxy.php) : réseau d'abord, cache en secours
 */

const SHELL_VER = 'emmgo-shell-v7';
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

// ── Clic sur une notification : gérer les actions ────────────────
self.addEventListener('notificationclick', event => {
  event.notification.close();
  const action  = event.action;        // 'open' | 'join' | '' (clic direct)
  const data    = event.notification.data || {};
  const joinUrl = action==='join' ? data.url : null;
  const openUrl = joinUrl || './';

  event.waitUntil(
    self.clients.matchAll({ type:'window', includeUncontrolled:true }).then(clients => {
      if (joinUrl) {
        // Ouvrir le lien Teams/virtual-room dans un nouvel onglet
        return self.clients.openWindow(joinUrl);
      }
      // Focus l'app si déjà ouverte, sinon ouvrir
      const existing = clients.find(c => 'focus' in c);
      if (existing) return existing.focus();
      return self.clients.openWindow('./');
    })
  );
});

// ════════════════════════════════════════════════════════════════
//  PERIODIC BACKGROUND SYNC
//  Rafraîchit le calendrier et envoie des notifications même
//  lorsque l'app est fermée.
//  Modes supportés : connecté (cookie auto) · partage (token IDB)
//  Mode invité : géré par setInterval dans l'app (app ouverte)
// ════════════════════════════════════════════════════════════════

// ── IndexedDB — base partagée SW ↔ app ──────────────────────────
const PBS_DB    = 'brightspace-pbs';
const PBS_VER   = 1;
const PBS_STORE = 'kv';

function idbOpen() {
  return new Promise((res, rej) => {
    const req = indexedDB.open(PBS_DB, PBS_VER);
    req.onupgradeneeded = e => {
      if (!e.target.result.objectStoreNames.contains(PBS_STORE))
        e.target.result.createObjectStore(PBS_STORE);
    };
    req.onsuccess = e => res(e.target.result);
    req.onerror   = e => rej(e.target.error);
  });
}
async function idbGet(key) {
  const db = await idbOpen();
  return new Promise((res, rej) => {
    const req = db.transaction(PBS_STORE,'readonly').objectStore(PBS_STORE).get(key);
    req.onsuccess = e => res(e.target.result ?? null);
    req.onerror   = e => rej(e.target.error);
  });
}
async function idbSet(key, val) {
  const db = await idbOpen();
  return new Promise((res, rej) => {
    const req = db.transaction(PBS_STORE,'readwrite').objectStore(PBS_STORE).put(val, key);
    req.onsuccess = () => res();
    req.onerror   = e => rej(e.target.error);
  });
}

// ── Parser ICS minimal (pas de dépendance à index.html) ─────────
function pbsUnfold(txt) {
  return txt.replace(/\r\n[ \t]/g,'').replace(/\n[ \t]/g,'');
}
function pbsParse(txt) {
  const evts = [];
  const blocks = pbsUnfold(txt).split('BEGIN:VEVENT');
  for (let i = 1; i < blocks.length; i++) {
    const ev = {};
    for (const line of blocks[i].split('\n')) {
      const ci = line.indexOf(':');
      if (ci < 1) continue;
      ev[line.slice(0, ci).split(';')[0].toUpperCase()] = line.slice(ci + 1).trimEnd();
    }
    evts.push(ev);
  }
  return evts;
}
function pbsDate(s) {
  if (!s) return null;
  const v = s.replace(/[TZ]/g,'');
  if (v.length < 8) return null;
  const Y=+v.slice(0,4),M=+v.slice(4,6)-1,D=+v.slice(6,8);
  const h=v.length>=12?+v.slice(8,10):0, m=v.length>=12?+v.slice(10,12):0;
  return s.endsWith('Z') ? new Date(Date.UTC(Y,M,D,h,m)) : new Date(Y,M,D,h,m);
}
function pbsDays(d) {
  const t=new Date(d); t.setHours(0,0,0,0);
  const n=new Date();  n.setHours(0,0,0,0);
  return Math.round((t-n)/86400000);
}
function pbsFmt(d) {
  return d.toLocaleDateString('fr-FR',{day:'numeric',month:'short'});
}
function pbsCleanTitle(s) {
  return s.replace(/^(assessment|co-construction)\s*[:\u2013\-]+\s*/i,'')
          .replace(/\s*\u00e0 \u00e9ch\u00e9ance\s*$/i,'')
          .replace(/\s*[\u2013\-:]+\s*$/,'').trim() || s;
}
function pbsIsDevoir(ev) {
  const s=(ev.SUMMARY||'').toLowerCase(), d=(ev.DESCRIPTION||'').toLowerCase();
  return s.includes('assessment')||s.includes('co-construction')||
         d.includes('assessment')||d.includes('co-construction')||
         s.includes('\u00e0 \u00e9ch\u00e9ance');
}
function pbsIsSession(ev) {
  const loc=(ev.LOCATION||'').toLowerCase(), s=(ev.SUMMARY||'').toLowerCase();
  return loc.includes('virtual-room')||loc.includes('teams')||
         s.includes('distanciel')||s.includes('live session');
}

// ── Notification avec anti-doublon IDB ──────────────────────────
async function pbsNotify(id, title, body, opts={}) {
  const sent = (await idbGet('notif-sent')) || {};
  const cutoff = Date.now() - 3 * 86400000;
  Object.keys(sent).forEach(k => { if (sent[k] < cutoff) delete sent[k]; });
  if (sent[id]) return;
  await self.registration.showNotification(title, {
    body, tag: id, icon: './icon-192.png', badge: './icon-192.png',
    requireInteraction: false,
    ...opts,
  });
  sent[id] = Date.now();
  await idbSet('notif-sent', sent);
}

function fmtLongFR(d) {
  return d.toLocaleDateString('fr-FR',{weekday:'long',day:'numeric',month:'long'});
}
function fmtTimeFR(d) {
  return d.toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit'});
}
function capitalize(s) { return s.charAt(0).toUpperCase()+s.slice(1); }

// ── Cœur de la sync périodique ────────────────────────────────
async function runPeriodicSync() {
  const config = await idbGet('pbs-config');
  const prefs  = await idbGet('notif-prefs');

  if (!config || !prefs?.enabled) return;
  if (config.mode === 'guest')    return;

  // 1. Fetch ICS Brightspace
  const icsText = await pbsFetchICS(config);
  if (!icsText || !icsText.includes('BEGIN:VCALENDAR')) return;
  const events = pbsParse(icsText);

  // 2. Fetch état serveur (rendus + group_tags)
  const state = await pbsFetchState(config);

  // 3. Fetch ICS privé (ateliers)
  const privateText = await pbsFetchPrivateICS(config);
  const groupEvts   = privateText && privateText.includes('BEGIN:VCALENDAR')
    ? pbsParse(privateText).map(ev => ({ ...ev, _uid: ev.UID||'', _date: pbsDate(ev.DTSTART), _dateEnd: pbsDate(ev.DTEND) })).filter(e=>e._date)
    : [];

  const todayKey = new Date().toISOString().slice(0,10);

  // 4. Devoirs non rendus approchants — groupés par seuil
  if (prefs.deadline) {
    [1, 3].forEach(threshold => {
      const due = events.filter(pbsIsDevoir).map(ev => {
        const d = pbsDate(ev.DTSTART);
        return d ? { uid:ev.UID, title:pbsCleanTitle(ev.SUMMARY||''), date:d,
          courseName: ev.LOCATION && !ev.LOCATION.startsWith('http') ? ev.LOCATION : '',
          isRendu: !!state.rendus[ev.UID] || pbsDays(d)<0 } : null;
      }).filter(d => d && pbsDays(d.date)===threshold && !d.isRendu);

      if (!due.length) return;
      const emoji = threshold===1 ? '🔴' : '🟡';
      const label = threshold===1 ? 'À rendre demain !' : 'Devoir dans 3 jours';

      if (due.length === 1) {
        const d = due[0];
        const heure = d.date.getHours()>0 ? ` à ${fmtTimeFR(d.date)}` : '';
        pbsNotify(
          `deadline-${d.uid}-J${threshold}`,
          `${emoji} ${label}`,
          `${d.title}\n${d.courseName||''}\n${capitalize(fmtLongFR(d.date))}${heure}`.trim(),
          { actions:[{action:'open',title:'📋 Voir les devoirs'}], requireInteraction:threshold===1 }
        );
      } else {
        // Plusieurs devoirs → notif groupée
        const lines = due.map(d=>`• ${d.title}`).join('\n');
        pbsNotify(
          `deadline-group-J${threshold}-${todayKey}`,
          `${emoji} ${due.length} devoirs ${threshold===1?'demain':'dans 3 jours'}`,
          lines,
          { actions:[{action:'open',title:'📋 Voir les devoirs'}], requireInteraction:threshold===1 }
        );
      }
    });
  }

  // 5. Devoir collectif sans atelier (échéance ≤ 7j)
  if (prefs.noAtelier && groupEvts.length > 0) {
    events.filter(pbsIsDevoir).forEach(ev => {
      const cats = (ev.CATEGORIES||'').toLowerCase();
      const summ = (ev.SUMMARY||'').toLowerCase();
      if (!cats.includes('collectif') && !summ.includes('co-construction')) return;
      const d = pbsDate(ev.DTSTART);
      if (!d) return;
      const days    = pbsDays(d);
      const isRendu = !!state.rendus[ev.UID] || days < 0;
      if (days < 0 || days > 7 || isRendu) return;
      const linked = groupEvts.some(ge => {
        const tag = state.groupTags[ge._uid];
        return tag && tag.devoirUid === ev.UID && !tag.ignored;
      });
      if (!linked) {
        const urgence = days<=2?'🔴':days<=4?'🟠':'🟡';
        const title   = pbsCleanTitle(ev.SUMMARY||'');
        const loc     = ev.LOCATION && !ev.LOCATION.startsWith('http') ? ev.LOCATION : '';
        pbsNotify(
          `noatelier-${ev.UID}`,
          `${urgence} Devoir collectif sans atelier`,
          `"${title}"\n${loc}\nÉchéance ${capitalize(fmtLongFR(d))} (J-${days})`.trim(),
          { actions:[{action:'open',title:'📆 Planifier un atelier'}] }
        );
      }
    });
  }

  // 6. Programme du jour — liste les vrais événements avec leurs heures
  if (prefs.today) {
    const sessions = events.filter(pbsIsSession).map(ev=>({
      date:pbsDate(ev.DTSTART), dateEnd:pbsDate(ev.DTEND),
      title:ev.SUMMARY||'', loc:ev.LOCATION||''
    })).filter(e=>e.date&&pbsDays(e.date)===0).sort((a,b)=>a.date-b.date);

    const ateliers = groupEvts.filter(ge=>{
      const tag=state.groupTags[ge._uid];
      if(tag?.ignored)return false;
      const isSub=tag?.devoirUid==='__subgroup__'||
        groupEvts.some(s=>pbsIsSession(s)&&s._date&&Math.abs(ge._date-s._date)/60000<30);
      return !isSub&&pbsDays(ge._date)===0;
    }).sort((a,b)=>a._date-b._date);

    if ((sessions.length||ateliers.length)&&!(await idbGet('notif-sent'))?.[`today-${todayKey}`]) {
      const jdate = capitalize(fmtLongFR(new Date()));
      const lines  = [
        ...sessions.map(s=>`🎥 ${fmtTimeFR(s.date)} ${s.title.replace(/^.*?—\s*/,'').slice(0,45)}`),
        ...ateliers.map(e=>{
          const tag=state.groupTags[e._uid];
          const label=tag?.subjectName||tag?.subject||(e.SUMMARY||'').slice(0,40)||'Atelier';
          return `📚 ${fmtTimeFR(e._date)} ${label}`;
        }),
      ].join('\n');
      pbsNotify(`today-${todayKey}`, `📅 ${jdate}`, lines,
        {actions:[{action:'open',title:'Voir le programme'}]});
    }
  }

  // 7. Événement imminent ≤ 20 min (best-effort)
  if (prefs.imminent) {
    const now = Date.now();
    const allEvts=[
      ...events.filter(pbsIsSession).map(ev=>({ uid:ev.UID, d:pbsDate(ev.DTSTART), dEnd:pbsDate(ev.DTEND), title:ev.SUMMARY||'', url:ev.LOCATION?.startsWith('http')?ev.LOCATION:'' })),
      ...groupEvts.map(ge=>({ uid:ge._uid, d:ge._date, dEnd:ge._dateEnd, title:ge.SUMMARY||'', url:'' })),
    ];
    allEvts.forEach(e=>{
      if(!e.d||!e.uid)return;
      const mins=Math.round((e.d-now)/60000);
      if(mins<=0||mins>20)return;
      const end   = e.dEnd ? ` – ${fmtTimeFR(e.dEnd)}` : '';
      const dur   = e.dEnd ? ` · ${Math.round((e.dEnd-e.d)/60000)} min` : '';
      const actions = e.url
        ? [{action:'join',title:'🔗 Rejoindre'},{action:'open',title:'Voir'}]
        : [{action:'open',title:'📋 Voir'}];
      pbsNotify(
        `imminent-${e.uid}`,
        `🔔 Dans ${mins} min — ${e.title.slice(0,40)}`,
        `${fmtTimeFR(e.d)}${end}${dur}`,
        { actions, requireInteraction:true, vibrate:[300,100,300], data:{ url:e.url||'./' } }
      );
    });
  }
}
async function pbsFetchICS(config) {
  const opts = { credentials: 'same-origin', signal: AbortSignal.timeout(15000) };
  const base = './api.php';
  if (config.mode === 'connected') {
    const r = await fetch(`${base}?action=fetch_ics`, opts);
    if (!r.ok) throw new Error(`fetch_ics HTTP ${r.status}`);
    return r.text();
  }
  if (config.mode === 'share' && config.shareToken) {
    const r = await fetch(`${base}?action=fetch_ics&share=${encodeURIComponent(config.shareToken)}`, opts);
    if (!r.ok) throw new Error(`fetch_ics share HTTP ${r.status}`);
    return r.text();
  }
  return null; // mode invité → skip
}

async function pbsFetchPrivateICS(config) {
  const opts = { credentials: 'same-origin', signal: AbortSignal.timeout(15000) };
  const base = './api.php';
  try {
    if (config.mode === 'connected') {
      const r = await fetch(`${base}?action=fetch_private_ics`, opts);
      if (r.ok) return r.text();
    } else if (config.mode === 'share' && config.shareToken) {
      const r = await fetch(`${base}?action=fetch_private_ics&share=${encodeURIComponent(config.shareToken)}`, opts);
      if (r.ok) return r.text();
    }
  } catch(_) {}
  return null;
}

async function pbsFetchState(config) {
  const opts = { credentials: 'same-origin', signal: AbortSignal.timeout(8000) };
  const base = './api.php';
  try {
    const url = config.mode === 'share' && config.shareToken
      ? `${base}?action=get_state&share=${encodeURIComponent(config.shareToken)}`
      : `${base}?action=get_state`;
    const r = await fetch(url, opts);
    if (r.ok) {
      const d = await r.json();
      return { rendus: d.state?.rendus || {}, groupTags: d.state?.group_tags || {} };
    }
  } catch(_) {}
  return { rendus: {}, groupTags: {} };
}

// ── Cœur de la sync périodique ────────────────────────────────
async function runPeriodicSync() {
  const config = await idbGet('pbs-config');
  const prefs  = await idbGet('notif-prefs');

  // Vérifications préliminaires
  if (!config || !prefs?.enabled) return;
  if (config.mode === 'guest')    return; // mode invité = setInterval dans l'app

  // 1. Fetch ICS Brightspace
  const icsText = await pbsFetchICS(config);
  if (!icsText || !icsText.includes('BEGIN:VCALENDAR')) return;
  const events = pbsParse(icsText);

  // 2. Fetch état serveur (rendus + group_tags)
  const state = await pbsFetchState(config);

  // 3. Fetch ICS privé (pour check ateliers)
  const privateText = await pbsFetchPrivateICS(config);
  const groupEvts   = privateText && privateText.includes('BEGIN:VCALENDAR')
    ? pbsParse(privateText).map(ev => ({ ...ev, _uid: ev.UID||'', _date: pbsDate(ev.DTSTART) })).filter(e=>e._date)
    : [];

  const todayKey = new Date().toISOString().slice(0,10);

  // 4. Devoirs non rendus approchants (J-3 et J-1)
  if (prefs.deadline) {
    events.filter(pbsIsDevoir).forEach(ev => {
      const d = pbsDate(ev.DTSTART);
      if (!d) return;
      const days = pbsDays(d);
      const isRendu = !!state.rendus[ev.UID] || days < 0;
      if ((days === 3 || days === 1) && !isRendu) {
        const title = pbsCleanTitle(ev.SUMMARY||'');
        const loc   = (ev.LOCATION && !ev.LOCATION.startsWith('http')) ? ev.LOCATION : '';
        pbsNotify(
          `deadline-${ev.UID}-J${days}`,
          days === 1 ? 'Devoir à rendre demain' : 'Devoir dans 3 jours',
          `${title}${loc?' · '+loc:''} — ${pbsFmt(d)}`
        );
      }
    });
  }

  // 5. Devoir collectif sans atelier lié (échéance ≤ 7j)
  if (prefs.noAtelier && groupEvts.length > 0) {
    events.filter(pbsIsDevoir).forEach(ev => {
      // Déterminer si collectif (catégories ou title co-construction)
      const cats  = (ev.CATEGORIES||'').toLowerCase();
      const summ  = (ev.SUMMARY||'').toLowerCase();
      const isCol = cats.includes('collectif') || summ.includes('co-construction');
      if (!isCol) return;
      const d = pbsDate(ev.DTSTART);
      if (!d) return;
      const days   = pbsDays(d);
      const isRendu = !!state.rendus[ev.UID] || days < 0;
      if (days >= 0 && days <= 7 && !isRendu) {
        // Y a-t-il un atelier lié à ce devoir ?
        const linked = groupEvts.some(ge => {
          const tag = state.groupTags[ge._uid];
          return tag && tag.devoirUid === ev.UID && !tag.ignored;
        });
        if (!linked) {
          const title = pbsCleanTitle(ev.SUMMARY||'');
          pbsNotify(
            `noatelier-${ev.UID}`,
            'Aucun atelier planifié',
            `"${title}" — échéance le ${pbsFmt(d)} (J-${days})`
          );
        }
      }
    });
  }

  // 6. Programme du jour (une fois par jour)
  if (prefs.today) {
    const todaySessions = events.filter(ev => {
      if (!pbsIsSession(ev)) return false;
      const d = pbsDate(ev.DTSTART);
      return d && pbsDays(d) === 0;
    });
    const todayGroup = groupEvts.filter(ge => {
      const tag = state.groupTags[ge._uid];
      if (tag?.ignored) return false;
      // sous-groupe manuel ?
      const isSubgroup = tag?.devoirUid === '__subgroup__' ||
        groupEvts.some(s => pbsIsSession(s) && s._date && Math.abs(ge._date - s._date)/60000 < 30);
      return !isSubgroup && pbsDays(ge._date) === 0;
    });
    if (todaySessions.length || todayGroup.length) {
      const parts = [];
      if (todaySessions.length) parts.push(`${todaySessions.length} live session${todaySessions.length>1?'s':''}`);
      if (todayGroup.length)    parts.push(`${todayGroup.length} atelier${todayGroup.length>1?'s':''}`);
      pbsNotify(`today-${todayKey}`, "Programme d'aujourd'hui", parts.join(' · '));
    }
  }

  // 7. Événement imminent ≤ 20 min (best-effort — PBS est approximatif)
  if (prefs.imminent) {
    const now = Date.now();
    const allEvts = [
      ...events.filter(pbsIsSession).map(ev=>({ uid:ev.UID, d:pbsDate(ev.DTSTART), title:ev.SUMMARY||'' })),
      ...groupEvts.map(ge=>({ uid:ge._uid, d:ge._date, title:ge.SUMMARY||'' })),
    ];
    allEvts.forEach(e => {
      if (!e.d || !e.uid) return;
      const mins = Math.round((e.d - now) / 60000);
      if (mins > 0 && mins <= 20) {
        pbsNotify(`imminent-${e.uid}`, `Dans ${mins} min`, e.title);
      }
    });
  }
}

// ── Handler periodicsync ─────────────────────────────────────────
self.addEventListener('periodicsync', event => {
  if (event.tag === 'brightspace-refresh') {
    event.waitUntil(runPeriodicSync().catch(e => console.warn('[SW PBS]', e)));
  }
});

// ── Message depuis l'app : mise à jour de la config PBS ─────────
self.addEventListener('message', event => {
  // Handlers déjà définis plus haut (SKIP_WAITING, CLEAR_DATA_CACHE)
  if (event.data?.type === 'PBS_CONFIG') {
    idbSet('pbs-config',  event.data.config).catch(()=>{});
    idbSet('notif-prefs', event.data.prefs).catch(()=>{});
  }
  if (event.data?.type === 'PBS_MERGE_SENT') {
    // L'app passe son historique localStorage pour fusionner avec le IDB
    idbGet('notif-sent').then(idbSent => {
      const merged = { ...(idbSent||{}), ...(event.data.sent||{}) };
      idbSet('notif-sent', merged).catch(()=>{});
    });
  }
});
