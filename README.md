# 📚 Brightspace Agenda — Devoirs & Live Sessions

Suivi des devoirs, live sessions et ateliers de groupe pour tout établissement utilisant **Brightspace by D2L**.
Un seul fichier HTML + un backend PHP léger, hébergeable sur ton propre serveur ou en statique (mode invité uniquement).

🔗 **[github.com/MrTh0m/Brightspace_agenda](https://github.com/MrTh0m/Brightspace_agenda)**
📄 **Licence MIT** — libre d'utilisation, modification et redistribution.

---

## 📁 Fichiers

| Fichier | Rôle |
|---|---|
| `index.html` | Dashboard principal — toute l'interface |
| `proxy.php` | Proxy ICS pour le mode invité (contourne le CORS Brightspace) |
| `api.php` | Backend du mode connecté (auth, état, URL ICS, partage) |
| `setup.php` | Configuration initiale — mot de passe, token de partage |
| `test-proxy.php` | Diagnostic réseau/PHP — **à supprimer après usage** |
| `manifest.json` | Manifest PWA — installation sur Android/iOS/Desktop |
| `sw.js` | Service Worker — cache offline + notifications |
| `icon-192.png` / `icon-512.png` | Icônes PWA |
| `apple-touch-icon.png` | Icône iOS |
| `data/` | Dossier créé automatiquement — `config.json`, `state.json` |

---

## 🔐 Modes de fonctionnement

### Mode invité (aucune configuration requise)
- URL ICS et état "rendu" stockés dans le `localStorage` du navigateur
- Utilise `proxy.php` pour récupérer le calendrier Brightspace (proxies publics en cascade)
- Tous les onglets disponibles, y compris **Groupes** (URL ICS privée optionnelle, stockée localement)

### Mode connecté (1 compte, persistance serveur)
- Login par mot de passe (bcrypt PHP)
- URL ICS Brightspace + URL ICS privée stockées dans `data/config.json` — jamais exposées au navigateur
- État "rendu", attributions d'ateliers et exclusions stockés dans `data/state.json`, synchronisés sur tous les appareils
- **Nom personnalisé** du dashboard configurable dans les paramètres

### Mode lecture seule — lien de partage
- URL : `https://ton-domaine/index.html?share=TOKEN`
- Accès en lecture seule à tous les onglets, dont **Groupes** (attributions visibles, sans édition)
- URLs privées Brightspace et groupe jamais exposées
- ⚙ Paramètres accessible pour configurer les **notifications** (réglages propres à l'appareil)
- Bouton "Installer l'app" masqué (le start_url du manifest ne contient pas le token)

---

## 🚀 Installation sur serveur auto-hébergé

### Prérequis PHP
```bash
sudo apt install php8.2-curl
sudo systemctl restart apache2
```

### Déploiement
1. Upload tous les fichiers dans le dossier servi (Apache/Nginx)
2. Visite `https://ton-domaine/setup.php` → définis ton mot de passe
3. Le dossier `data/` est créé automatiquement avec `.htaccess` bloquant l'accès direct
4. Supprime `setup.php` et `test-proxy.php` après configuration

### Mises à jour
À chaque modification de `index.html` ou `sw.js`, incrémenter `SHELL_VER` dans `sw.js` pour invalider le cache PWA.

---

## 📱 PWA — Installation en app

- **Android** : Chrome → ⋮ → "Ajouter à l'écran d'accueil" ou bouton **↓ Installer l'app**
- **iOS** : Safari → bouton partage → "Sur l'écran d'accueil"
- **Desktop** : Chrome → icône d'installation dans la barre d'adresse

Mode offline : Service Worker cache le dernier ICS et l'état des rendus.

---

## ✨ Fonctionnalités

### Interface globale
- **Bouton ℹ️ "À propos"** : description de l'app, notice d'utilisation, lien GitHub
- **Entête sticky compacte** au scroll (titre + badge mode toujours visibles), barre d'onglets et filtres sticky
- **Chip "Prochain événement"** : prochaine live session OU atelier groupe, selon l'échéance la plus proche ; compte à rebours `"Dans X min"` quand < 60 min
- **Navigation par swipe** gauche/droite entre les onglets (mobile) ; changement d'onglet revient au début du contenu
- **Bouton ↑ retour en haut** (flottant, apparaît après 300 px)
- Thème clair / sombre / système · Design responsive mobile et desktop

### Onglet Devoirs
- Détection automatique : `Assessment`, `Co-construction`, `à échéance`
- Nettoyage des titres (séparateurs résiduels, suffixe `à échéance`)
- Compte à rebours coloré : rouge ≤ 3j · orange ≤ 7j · vert ≥ 15j
- Filtres **Passés** et **Rendus** alignés à droite, persistants entre sessions
- Boutons Copier tâche / Google Cal. / Outlook masqués quand le devoir est rendu
- **Atelier lié** sur les devoirs collectifs (futur ou passé), uniquement si lié explicitement via l'onglet Groupes
- Filtres par type (Individuel/Collectif) et discipline, avec chips de discipline sticky

### Onglet Live Sessions
- Détection : `Cours distanciel`, `virtual-room`, URLs Teams
- **Sous-groupes** (depuis le calendrier privé) affichés avec badge orange, filtrables via chip "Sous-groupes"
- Boutons Rejoindre / Google Cal. / Outlook masqués pour les sessions passées
- Filtres : Toutes / Live Sessions / Sous-groupes · Passées

### Onglet Groupes *(tous modes)*
- **Source** : calendrier ICS privé (Outlook 365, Google Calendar...)
  - Mode connecté : URL stockée côté serveur, jamais exposée
  - Mode invité : URL + attributions en `localStorage`
  - Mode partage : attributions visibles en lecture seule
- **Section Vue semaine** : grille (08h–20h) ou liste chronologique avec toggle
  - Teal = Live sessions · Vert = Ateliers · Orange = Sous-groupe (même créneau ±30 min)
- **Section Par matière** : ateliers et sous-groupes par matière attribuée
- **Section Ateliers** : liste avec filtres Passés / Masqués (persistants), pagination
  - **Attribution** : lier à une matière + devoir précis, ou désigner formellement "Sous-groupe live session"
  - **Règle override** : auto-détecté sous-groupe MAIS lié à un vrai devoir → traité comme atelier
  - **Masquer** : exclut un événement non pertinent de tous les calculs et affichages
  - Lien de réunion extrait automatiquement (Teams, virtual-room) → bouton **Rejoindre** + Google Cal. / Outlook
  - Carte responsive mobile (même mise en page que Devoirs et Live Sessions)

### Onglet Progression
- Cartes par matière : barre de progression, répartition individuel/collectif
- Ateliers et sous-groupes comptés par matière (si attribués)
- Histogramme hebdomadaire · Gantt

### 🔔 Notifications *(tous modes, réglages par appareil)*
Section dans ⚙ Paramètres — fonctionne tant qu'un onglet est ouvert.

| Déclencheur | Condition |
|---|---|
| Devoir non rendu approchant | J−3 et J−1 |
| Devoir collectif sans atelier | Échéance ≤ 7j, aucun atelier lié |
| Programme du jour | Sessions + ateliers du jour (une fois/jour) |
| Événement imminent | 15 min avant |

- Réglages en `localStorage` (indépendants entre appareils)
- Anti-doublon avec purge automatique après 3 jours
- PWA Android : notifications via Service Worker, tap → ouvre l'app
- ⚠️ Ne fonctionne pas si l'app est totalement fermée

---

## 🔒 Sécurité

| Élément | Protection |
|---|---|
| Token ICS Brightspace | Jamais exposé au navigateur |
| URL ICS privée | Auth ou share token requis |
| Mot de passe | bcrypt |
| Dossier `data/` | `.htaccess` Deny all |
| Token de partage | 32 caractères aléatoires, révocable |
| Anti-brute-force | Délai 1s |
| SSRF Brightspace | Domaines `brightspace.com` / `em-lyon.com` uniquement |
| ICS privée | HTTPS requis, tout domaine |

---

## 🗂 Structures de données

### `data/config.json`
```json
{
  "password_hash": "$2y$...",
  "share_token": "abc123...",
  "ics_url": "https://[school].brightspace.com/...",
  "private_ics_url": "https://outlook.office365.com/...",
  "dashboard_name": "Master Management 2026"
}
```

### `data/state.json`
```json
{
  "rendus": { "uid-devoir": true },
  "group_tags": {
    "uid-atelier": { "subject": "PGMC05", "subjectName": "...", "devoirUid": "uid" },
    "uid-sous-groupe-manuel": { "subject": "PGMC09", "subjectName": "...", "devoirUid": "__subgroup__" },
    "uid-ignoré": { "ignored": true }
  }
}
```

### `localStorage` (par appareil)

| Clé | Contenu |
|---|---|
| `emmgo_ics_url_v2` | URL ICS Brightspace (invité) |
| `emmgo_rendu_v1` | Rendus (invité) |
| `emmgo_private_ics_url_v1` | URL ICS privée (invité) |
| `emmgo_group_tags_v1` | Attributions + exclusions (invité) |
| `emmgo_theme` | Thème |
| `emmgo_notif_settings_v1` | Préférences notifications |
| `emmgo_notif_sent_v1` | Anti-doublon notifications |
| `emmgo_filter_prefs_v1` | État des checkboxes (Passés/Rendus/Masqués) |

---

## 📄 Licence

MIT License — Copyright (c) 2025 MrTh0m

Compatible avec tout établissement utilisant Brightspace by D2L.
