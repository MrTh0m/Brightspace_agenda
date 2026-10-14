/**
 * cors-worker.js : proxy CORS perso pour Brightspace Agenda (Cloudflare Workers, offre gratuite)
 *
 * Déploiement : dash.cloudflare.com → Workers & Pages → Create → coller ce fichier → Deploy.
 * Puis dans l'app, champ « Proxy perso » : https://NOM.TON-COMPTE.workers.dev/?url={url}
 *
 * Sécurité : seuls les hôtes de ALLOWED_HOSTS sont relayés (même règle que proxy.php),
 * et seules les origines de ALLOWED_ORIGINS reçoivent les en-têtes CORS.
 */
const ALLOWED_HOSTS   = ['brightspace.com'];
const ALLOWED_ORIGINS = ['https://mrth0m.github.io']; // ajoute ton domaine NAS si besoin

export default {
  async fetch(request) {
    const origin = request.headers.get('Origin') || '';
    const cors = {
      'Access-Control-Allow-Origin': ALLOWED_ORIGINS.includes(origin) ? origin : ALLOWED_ORIGINS[0],
      'Access-Control-Allow-Methods': 'GET, OPTIONS',
      'Vary': 'Origin',
    };
    if (request.method === 'OPTIONS') return new Response(null, { status: 204, headers: cors });

    const target = new URL(request.url).searchParams.get('url');
    if (!target) return new Response('Parametre url manquant', { status: 400, headers: cors });

    let u;
    try { u = new URL(target); } catch (_) { return new Response('URL invalide', { status: 400, headers: cors }); }
    const ok = u.protocol === 'https:' &&
      ALLOWED_HOSTS.some(h => u.hostname === h || u.hostname.endsWith('.' + h));
    if (!ok) return new Response('Domaine non autorise', { status: 403, headers: cors });

    const r = await fetch(u.toString(), { headers: { 'Accept': 'text/calendar, */*' } });
    return new Response(r.body, {
      status: r.status,
      headers: { ...cors, 'Content-Type': r.headers.get('Content-Type') || 'text/calendar; charset=utf-8' },
    });
  },
};
