# 📚 Brightspace Agenda — Devoirs & Live Sessions

Suivi des devoirs et live sessions pour tout établissement utilisant **Brightspace by D2L**.
Un seul fichier HTML + un backend PHP léger, hébergeable sur ton propre serveur ou en statique.

🔗 **[github.com/MrTh0m/Brightspace_agenda](https://github.com/MrTh0m/Brightspace_agenda)**
📄 **Licence MIT** — libre d'utilisation, modification et redistribution.

---

## 📁 Fichiers

| Fichier | Rôle |
|---|---|
| `index.html` | Dashboard principal — toute l'interface |
| `proxy.php` | Proxy ICS pour le mode invité (contourne le CORS Brightspace) |
| `api.php` | Backend du mode connecté (auth, état rendus, URL ICS, partage) |
| `setup.php` | Configuration initiale — mot de passe, token de partage |
| `test-proxy.php` | Diagnostic réseau/PHP — **à supprimer après usage** |
| `manifest.json` | Manifest PWA — installation sur Android/iOS/Desktop |
| `sw.js` | Service Worker — cache offline |
| `icon-192.png` / `icon-512.png` | Icônes PWA |
| `apple-touch-icon.png` | Icône iOS |
| `data/` | Dossier créé automatiquement — `config.json`, `state.json` |

---

## 🔐 Modes de fonctionnement

### Mode invité (aucune configuration requise)
- ICS URL et état "rendu" stockés dans le `localStorage` du navigateur
- Utilise `proxy.php` pour récupérer le calendrier Brightspace (ou proxies publics en cascade)

### Mode connecté (1 compte, persistance serveur)
- Login par mot de passe (bcrypt PHP)
- URL ICS Brightspace stockée dans `data/config.json` — jamais exposée au navigateur
- État "rendu" stocké dans `data/state.json`, synchronisé sur tous les appareils
- **Nom personnalisé** du dashboard (ex. "Master Management 2026") configurable dans les paramètres
- L'ICS est fetché côté serveur via `api.php?action=fetch_ics`

### Mode lecture seule — lien de partage
- URL : `https://ton-domaine/index.html?share=TOKEN`
- Accès en lecture seule, sans connexion
- Toutes les actions masquées (URL privée Brightspace jamais exposée)
- Lien invalide → page d'erreur explicite
- Le bouton "Installer l'app" est masqué en mode share (le `start_url` du manifest ne contient pas le token — utiliser un bookmark à la place)

---

## 🚀 Installation sur serveur auto-hébergé

### Prérequis PHP

```bash
sudo apt install php8.2-curl
sudo systemctl restart apache2
```

Vérifie avec `test-proxy.php`, puis supprime ce fichier.

### Déploiement

1. Upload dans le dossier servi par Apache/Nginx :
   `index.html`, `proxy.php`, `api.php`, `setup.php`, `manifest.json`, `sw.js`, `icon-*.png`, `apple-touch-icon.png`

2. Visite `https://ton-domaine/setup.php` → définis ton mot de passe

3. Le dossier `data/` est créé automatiquement avec `.htaccess` bloquant l'accès direct

4. Supprime `setup.php` après configuration :
```apache
<Files "setup.php">
  Require all denied
</Files>
```

### Mises à jour

À chaque modification de `index.html`, incrémenter `SHELL_VER` dans `sw.js` (ex. `v4` → `v5`) pour invalider le cache PWA chez tous les utilisateurs.

---

## 📱 PWA — Installation en app

Fonctionne sur Android, iOS et Chrome Desktop.

- **Android** : Chrome → ⋮ → "Ajouter à l'écran d'accueil" ou bouton **↓ Installer l'app** dans le header
- **iOS** : Safari → bouton partage → "Sur l'écran d'accueil"
- **Desktop** : Chrome → icône d'installation dans la barre d'adresse

Mode offline : le Service Worker cache le dernier ICS et l'état des rendus. Une bannière apparaît si le réseau est indisponible.

---

## ☁️ Hébergement statique (GitHub Pages / Netlify)

Sans backend PHP, seul le **mode invité** est disponible.
Proxies CORS publics utilisés en cascade (corsproxy.io, allorigins.win, codetabs.com).

```
Dépôt GitHub privé → Settings → Pages → Branch: main
```

---

## ✨ Fonctionnalités

### Interface
- **Titre dynamique** : nom du calendrier (`X-WR-CALNAME`), institution extraite du sous-domaine Brightspace, ou nom personnalisé en mode connecté
- **Chip "Prochaine live session"** : affiche la prochaine session avec compte à rebours, heure et bouton Rejoindre. Orange si aujourd'hui ou demain.
- Thème clair / sombre / système (mémorisé)
- Design responsive — mobile, tablette, desktop

### Devoirs
- Détection automatique : `Assessment`, `Co-construction`, `à échéance`
- Compte à rebours coloré : rouge ≤ 3j · orange ≤ 7j · vert ≥ 15j (fuseau Europe/Paris)
- Devoirs passés = rendus automatiquement (sans cache)
- Case "Marquer comme rendu" persistante (localStorage ou serveur)
- Filtres par type (individuel / collectif) et par discipline avec compteurs
- Boutons **Casier** et **Afficher l'événement** (liens Brightspace)
- Pagination : 5 éléments par défaut

### Live Sessions
- Détection : `Cours distanciel`, `Live Session`, URL `virtual-room.em-lyon.com` dans LOCATION
- Extraction code matière + nom long depuis le titre (`2026_PGMC05_...`) ou parenthèse finale du LOCATION
- Bouton **Rejoindre** (salle virtuelle)
- Label "Aujourd'hui" avec badge orange sur la session du jour

### Onglet Progression
- Cartes par matière : barre de progression, rendus/total, répartition individuel/collectif
- Histogramme hebdomadaire avec segment hachuré (à rendre) vs plein (rendu)
- Semaine courante surlignée · tooltip au survol avec liste des devoirs
- **Gantt** : durées des cours sur la timeline, lignes de charge quotidienne, ligne "Aujourd'hui"

### Export & intégrations
- Export `.ics` filtré (importable dans Google Calendar, Outlook, Apple Calendar)
- Boutons Google Cal. et Outlook (crée un événement à la date d'échéance)
- Bouton "Copier tâche" → presse-papiers → Google Tasks / Microsoft To Do

---

## 🔒 Sécurité

| Élément | Protection |
|---|---|
| Token ICS Brightspace | Jamais exposé au navigateur en mode connecté/share |
| Mot de passe | `password_hash()` PHP (bcrypt) |
| Dossier `data/` | `.htaccess` Deny all |
| Token de partage | 32 caractères aléatoires, révocable |
| Lien invalide | Page d'erreur explicite (pas de fallback silencieux) |
| Anti-brute-force | Délai 1s sur mot de passe incorrect |
| SSRF | Seuls `brightspace.com` et `em-lyon.com` autorisés dans le proxy |

---

## 📄 Licence

MIT License — voir [LICENSE](LICENSE)

```
Copyright (c) 2025 MrTh0m
Permission is hereby granted, free of charge, to any person obtaining a copy
of this software... (MIT standard)
```

Compatible avec tout établissement utilisant Brightspace by D2L, quelle que soit la promotion ou le cursus.
