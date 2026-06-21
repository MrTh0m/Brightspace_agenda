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
| `sw.js` | Service Worker — cache offline + notifications + sync périodique |
| `icon-192.png` / `icon-512.png` | Icônes PWA |
| `apple-touch-icon.png` | Icône iOS |
| `data/` | Dossier créé automatiquement — `config.json`, `state.json` |

---

## 🔐 Modes de fonctionnement

### Mode invité (aucune configuration requise)
- URL ICS et état "rendu" stockés dans le `localStorage` du navigateur
- Utilise `proxy.php` pour récupérer le calendrier Brightspace (proxies publics en cascade)
- Tous les onglets disponibles, y compris **Ateliers** (URL ICS privée optionnelle, stockée localement)

### Mode connecté (1 compte, persistance serveur)
- Login par mot de passe (bcrypt PHP)
- URL ICS Brightspace + URL ICS privée stockées dans `data/config.json` — jamais exposées au navigateur
- État "rendu", attributions d'ateliers et exclusions stockés dans `data/state.json`, synchronisés sur tous les appareils
- **Nom personnalisé** du dashboard configurable dans les paramètres
- Notifications en arrière-plan via Periodic Background Sync (app fermée, Chrome/Edge installés)

### Mode lecture seule — lien de partage
- URL : `https://ton-domaine/index.html?share=TOKEN`
- Accès en lecture seule à tous les onglets, dont **Ateliers** (attributions visibles, sans édition)
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
- **Badges semaine/total** sur les onglets Devoirs et Ateliers (ex. `3/12`) : le premier nombre exclut les éléments de la semaine déjà rendus ou passés
- Thème clair / sombre / système · Design responsive mobile et desktop

### Onglet Agenda *(premier onglet, toujours visible)*
- **Vue semaine** combinée : devoirs, live sessions et ateliers sur la même grille ou liste
  - Mode **Grille** : créneaux horaires (08h–20h) + ligne dédiée "tout-le-jour" pour les devoirs
  - Mode **Liste** : chronologique par jour, devoirs en tête
  - Légende : Live (teal) · Atelier (vert) · Sous-groupe (orange) · Devoir (violet)
  - Navigation semaine ← → · bouton retour à la semaine courante
- **Charge par semaine** : histogramme des échéances sur 8 semaines, tooltip détaillant chaque devoir
- **Planning des cours** (Gantt) : durées des live sessions par matière, ligne "Aujourd'hui", charge hebdomadaire

### Onglet Devoirs
- Détection automatique : `Assessment`, `Co-construction`, `à échéance`
- Nettoyage des titres (séparateurs résiduels, suffixe `à échéance`)
- Compte à rebours coloré : rouge ≤ 3j · orange ≤ 7j · vert ≥ 15j
- Filtres **Passés** et **Rendus** alignés à droite, persistants entre sessions
- Boutons Copier tâche / Google Cal. / Outlook masqués quand le devoir est rendu
- **Atelier lié** affiché sur tous les devoirs (individuels et collectifs), uniquement si lié explicitement via l'onglet Ateliers
- Filtres par type (Individuel/Collectif) et discipline, avec chips de discipline sticky

### Onglet Live Sessions
- Détection : `Cours distanciel`, `virtual-room`, URLs Teams
- **Sous-groupes** (depuis le calendrier privé) affichés avec badge orange, filtrables via chip "Sous-groupes"
- Boutons Rejoindre / Google Cal. / Outlook masqués pour les sessions passées
- Filtres : Toutes / Live Sessions / Sous-groupes · Passées

### Onglet Ateliers *(anciennement "Groupes", tous modes)*
- **Source** : calendrier ICS privé (Outlook 365, Google Calendar...)
  - Mode connecté : URL stockée côté serveur, jamais exposée
  - Mode invité : URL + attributions en `localStorage`
  - Mode partage : attributions visibles en lecture seule
- Liste avec filtres **Tous / Non lié** et **Passés / Masqués** (persistants), pagination
- **Attribution** : lier un événement à une matière + devoir précis, ou désigner formellement "Sous-groupe live session"
- **Règle override** : auto-détecté sous-groupe MAIS lié à un vrai devoir → traité comme atelier
- **Masquer** : exclut un événement non pertinent de tous les calculs et affichages (vue semaine, badges, notifications)
- Lien de réunion extrait automatiquement (Teams, virtual-room) → bouton **Rejoindre** + Google Cal. / Outlook
- Carte responsive mobile (même mise en page que Devoirs et Live Sessions)

### Onglet Progression
- Cartes par matière : barre de progression, répartition individuel/collectif
- Ateliers et sous-groupes comptés par matière (si attribués)
- **Ateliers par matière** : tableau récapitulatif (ateliers, sous-groupes, prochaine échéance) par matière attribuée — masqué si aucun calendrier privé n'est configuré

### 🔔 Notifications *(tous modes, réglages par appareil)*
Section dans ⚙ Paramètres.

| Déclencheur | Condition |
|---|---|
| Devoir non rendu approchant | J−3 et J−1, groupées si plusieurs devoirs |
| Devoir collectif sans atelier | Échéance ≤ 7j, aucun atelier lié |
| Programme du jour | Sessions + ateliers du jour avec horaires (une fois/jour) |
| Événement imminent | 15 min avant, avec bouton Rejoindre si lien de réunion |

- Réglages en `localStorage` (indépendants entre appareils)
- Anti-doublon avec purge automatique après 3 jours, partagé entre l'app et la sync en arrière-plan
- **Periodic Background Sync** (Chrome/Edge, PWA installée) : notifications même app fermée, en mode connecté ou partage
- PWA Android : notifications via Service Worker avec actions (Rejoindre, Voir), tap → ouvre l'app ou le lien de réunion
- ⚠️ Mode invité : fonctionne uniquement tant qu'un onglet de l'app est ouvert

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

### IndexedDB `brightspace-pbs` (partagée app ↔ Service Worker)

| Clé | Contenu |
|---|---|
| `pbs-config` | Mode (connecté/partage/invité) + token de partage |
| `notif-prefs` | Copie des préférences de notification pour la sync en arrière-plan |
| `notif-sent` | Anti-doublon partagé entre `setInterval` (app) et `periodicsync` (SW) |

---

## 📄 Licence

MIT License — Copyright (c) 2025 MrTh0m

Compatible avec tout établissement utilisant Brightspace by D2L.
