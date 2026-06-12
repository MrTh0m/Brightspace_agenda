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
- Tous les onglets disponibles, y compris **Groupe** (URL ICS privée optionnelle, stockée localement)

### Mode connecté (1 compte, persistance serveur)
- Login par mot de passe (bcrypt PHP)
- URL ICS Brightspace + URL ICS privée stockées dans `data/config.json` — jamais exposées au navigateur
- État "rendu" et attributions d'ateliers stockés dans `data/state.json`, synchronisés sur tous les appareils
- **Nom personnalisé** du dashboard configurable dans les paramètres
- Onglet **Groupe de travail** disponible (calendrier privé Outlook/Google)

### Mode lecture seule — lien de partage
- URL : `https://ton-domaine/index.html?share=TOKEN`
- Accès en lecture seule à tous les onglets, dont **Groupe** (attributions visibles, sans édition)
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
À chaque modification de `index.html` ou `sw.js`, incrémenter `SHELL_VER` dans `sw.js` pour invalider le cache PWA chez tous les utilisateurs (une fermeture/réouverture de l'app peut être nécessaire sur mobile pour forcer la mise à jour du Service Worker).

---

## 📱 PWA — Installation en app

- **Android** : Chrome → ⋮ → "Ajouter à l'écran d'accueil" ou bouton **↓ Installer l'app**
- **iOS** : Safari → bouton partage → "Sur l'écran d'accueil"
- **Desktop** : Chrome → icône d'installation dans la barre d'adresse

Mode offline : Service Worker cache le dernier ICS et l'état des rendus.

---

## ✨ Fonctionnalités

### Interface globale
- **Titre dynamique** : `X-WR-CALNAME` de l'ICS, institution extraite du sous-domaine Brightspace, ou nom personnalisé en mode connecté
- **Chip "Prochain événement"** : affiche la prochaine live session OU le prochain atelier groupe, selon l'échéance la plus proche
  - Compte à rebours `"Dans X min"` quand < 60 min (rouge), orange si aujourd'hui/demain
  - Pour un atelier : rappelle la matière et le devoir liés si une attribution existe
  - Bouton Rejoindre (live sessions uniquement, masqué en lecture seule) · auto-refresh toutes les minutes
- Thème clair / sombre / système · Design responsive mobile

### Onglet Devoirs
- Détection automatique : `Assessment`, `Co-construction`, `à échéance`
- Nettoyage des titres : suppression du préfixe `Assessment :` seulement si suivi d'un séparateur (`:`, `–`, `-`), suppression du suffixe `à échéance` et des séparateurs résiduels en fin de titre
- Compte à rebours coloré : rouge ≤ 3j · orange ≤ 7j · vert ≥ 15j
- Cases "Marquer comme rendu" persistantes · devoirs passés = rendus automatiquement
- **Prochain atelier** sur les devoirs collectifs, **uniquement si explicitement lié à ce devoir précis** via l'onglet Groupe
- **"Aucun atelier lié — associe-en un dans l'onglet Groupe"** si calendrier groupe chargé mais aucun lien établi
- Filtres par type et discipline · Pagination

### Onglet Live Sessions
- Détection : `Cours distanciel`, `virtual-room.em-lyon.com`, URLs Teams
- Extraction code matière + nom depuis le titre ou la parenthèse finale du LOCATION
- Bouton **Rejoindre** · badge "Aujourd'hui" · filtre par discipline

### Onglet Groupe de travail *(tous modes — invité, connecté, lecture seule)*
- **Source** : calendrier ICS privé (Outlook 365, Google Calendar...)
  - Mode connecté : URL stockée côté serveur (`config.json`), fetchée par `api.php`, jamais exposée
  - Mode invité : URL + attributions stockées en `localStorage` (par appareil)
  - Mode partage : attributions visibles en lecture seule (synchronisées depuis le compte connecté)
- **Section Vue semaine** : grille 7j × 6 créneaux (08h-20h) ou liste chronologique (toggle)
  - 🟦 Teal = Live sessions Brightspace · 🟩 Vert = Ateliers · 🟧 Orange = Sous-groupe (même créneau ±30 min)
  - Navigation semaine ← → · code matière visible sur les ateliers en vue liste
- **Section Par matière** : tableau récap (ateliers, sous-groupes, prochain) par matière attribuée
- **Section Ateliers** : liste chronologique avec toggle "Passés"
  - **Attribution manuelle** : lier un atelier à une matière + devoir précis (mode connecté/invité)
  - Attribution visible en lecture seule (badge non-cliquable)
  - Année toujours affichée sur la date (ateliers pouvant s'étendre sur l'année suivante)
  - Nom du devoir lié affiché sur le bouton d'attribution

### Onglet Progression
- Cartes par matière : barre de progression, répartition individuel/collectif
- **Ateliers** : compteur `X ateliers · Y sous-gr.` sur chaque carte si des ateliers sont attribués à la matière
- Histogramme hebdomadaire avec segments plein/hachuré et tooltip devoirs
- **Gantt** : durées des cours, ligne "Aujourd'hui"

### 🔔 Notifications *(tous modes, réglages par appareil)*
Section dédiée dans ⚙ Paramètres — fonctionne tant qu'un onglet de l'app est ouvert (premier plan ou arrière-plan desktop).

| Notification | Déclencheur |
|---|---|
| Devoir non rendu approchant | J−3 et J−1 avant l'échéance |
| Devoir collectif sans atelier | Échéance ≤ 7j, aucun atelier lié |
| Programme du jour | Résumé une fois par jour (live sessions + ateliers du jour) |
| Événement imminent | 15 min avant une live session ou un atelier |

- Réglages stockés en `localStorage` (indépendants entre appareils — PC / téléphone)
- Vérification automatique toutes les 60s + après chaque chargement de calendrier
- Anti-doublon : chaque notification n'est envoyée qu'une fois (purge après 3 jours)
- Bouton "Tester une notification" pour valider la configuration
- Sur PWA Android installée : notifications via Service Worker (`showNotification`), tap → focus/ouvre l'app
- ⚠️ Ne fonctionne pas si l'app est totalement fermée (pas de push serveur — limitation assumée)

---

## 🔒 Sécurité

| Élément | Protection |
|---|---|
| Token ICS Brightspace | Jamais exposé au navigateur |
| URL ICS privée (groupe) | Mode connecté : jamais exposée · auth ou share token requis |
| Mot de passe | `password_hash()` bcrypt |
| Dossier `data/` | `.htaccess` Deny all |
| Token de partage | 32 caractères aléatoires, révocable |
| Anti-brute-force | Délai 1s sur mot de passe incorrect |
| SSRF Brightspace | Domaines `brightspace.com` / `em-lyon.com` uniquement |
| ICS privée | HTTPS requis, tout domaine autorisé (Outlook, Google...) |

---

## 🗂 Structure de `data/config.json`

```json
{
  "password_hash": "$2y$...",
  "share_token": "abc123...",
  "ics_url": "https://[school].brightspace.com/...",
  "private_ics_url": "https://outlook.office365.com/...",
  "dashboard_name": "Master Management 2026"
}
```

## 🗂 Structure de `data/state.json`

```json
{
  "rendus": { "uid-devoir": true },
  "group_tags": {
    "uid-atelier": {
      "subject": "PGMC05",
      "subjectName": "Management agile et responsable",
      "devoirUid": "uid-devoir-précis"
    }
  }
}
```

## 🗂 Stockage local (par appareil, `localStorage`)

| Clé | Contenu |
|---|---|
| `emmgo_ics_url_v2` | URL ICS Brightspace (mode invité) |
| `emmgo_rendu_v1` | État "rendu" des devoirs (mode invité) |
| `emmgo_private_ics_url_v1` | URL ICS privée groupe (mode invité) |
| `emmgo_group_tags_v1` | Attributions matière/devoir des ateliers (mode invité) |
| `emmgo_theme` | Thème (clair/sombre/système) |
| `emmgo_notif_settings_v1` | Préférences de notifications |
| `emmgo_notif_sent_v1` | Historique anti-doublon des notifications (purge 3j) |

---

## 📄 Licence

MIT License — Copyright (c) 2025 MrTh0m

Compatible avec tout établissement utilisant Brightspace by D2L, quelle que soit la promotion ou le cursus.
