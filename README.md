# 📚 EMMGO Dashboard — Devoirs & Live Sessions

Suivi des devoirs et live sessions emlyon business school.
Un fichier HTML + un backend PHP léger, hébergeable sur ton propre serveur ou en statique.

---

## 📁 Fichiers

| Fichier | Rôle |
|---|---|
| `index.html` | Dashboard principal — toute l'interface |
| `proxy.php` | Proxy ICS pour le mode invité (contourne le CORS Brightspace) |
| `api.php` | Backend du mode connecté (auth, état rendus, URL ICS, partage) |
| `setup.php` | Configuration initiale — mot de passe, token de partage |
| `test-proxy.php` | Diagnostic réseau/PHP — **à supprimer après usage** |
| `data/` | Dossier créé automatiquement — `config.json`, `state.json` |

---

## 🔐 Modes de fonctionnement

### Mode invité (aucune configuration requise)
- ICS URL stockée dans le `localStorage` du navigateur
- État "rendu" stocké dans le `localStorage`
- Utilise `proxy.php` pour récupérer le calendrier Brightspace
- Si `proxy.php` absent ou inaccessible : cascade vers des proxies publics

### Mode connecté (1 compte, persistance serveur)
- Login par mot de passe (bcrypt PHP)
- URL ICS Brightspace stockée dans `data/config.json` (jamais exposée au navigateur)
- État "rendu" stocké dans `data/state.json`
- L'ICS est fetché côté serveur via `api.php?action=fetch_ics` (token Brightspace invisible)
- Synchronisé sur tous les appareils/navigateurs

### Mode lecture seule — lien de partage
- URL : `https://ton-domaine/index.html?share=TOKEN`
- Accès en lecture seule, sans connexion
- Toutes les actions sont masquées (pas de cases à cocher, pas de boutons, pas d'URL visible)
- Lien invalide ou expiré → page d'erreur explicite avec bouton "Aller à l'accueil"
- Token désactivable ou régénérable depuis les paramètres
- **Le bouton "Installer l'app" est masqué** en mode lecture seule : le manifest PWA utilise `start_url: ./index.html` sans le token — l'app installée s'ouvrirait en mode invité. Pour accéder rapidement au lien de partage, utiliser un **bookmark** plutôt que l'installation PWA.

---

## 🚀 Installation sur serveur auto-hébergé

### 1. Prérequis PHP

Le backend nécessite l'extension cURL et OpenSSL. Vérifie avec `test-proxy.php`, puis installe si absent :

```bash
sudo apt install php8.2-curl
sudo systemctl restart apache2   # ou nginx / php8.2-fpm
```

### 2. Upload des fichiers

Copie dans le dossier servi par Apache/Nginx :
```
index.html  proxy.php  api.php  setup.php
```

### 3. Configuration initiale

Visite `https://ton-domaine/setup.php` :
- Définis un mot de passe (min. 6 caractères)
- Le dossier `data/` est créé automatiquement avec `.htaccess` bloquant l'accès direct
- Un token de partage est généré automatiquement

### 4. Premier login

1. Ouvre `index.html`
2. Clique sur l'icône 🔑 → entre ton mot de passe
3. Saisis l'URL ICS Brightspace dans les paramètres → **Charger**
4. L'URL est sauvegardée côté serveur ; sur tout nouveau navigateur elle est chargée automatiquement

### 5. Sécuriser setup.php

```bash
rm setup.php
# ou dans .htaccess :
```
```apache
<Files "setup.php">
  Require all denied
</Files>
```

---

## ☁️ Hébergement statique (GitHub Pages / Netlify)

Sans backend PHP, seul le mode invité est disponible.
Le dashboard tente automatiquement plusieurs proxies CORS en cascade :

1. `proxy.php` (local — échoue si absent)
2. `corsproxy.io`
3. `allorigins.win`
4. `codetabs.com`
5. Paste manuelle du fichier `.ics`

### GitHub Pages
1. Crée un dépôt GitHub (**privé** recommandé — l'URL ICS contient un token personnel)
2. Upload `index.html` à la racine
3. **Settings → Pages → Branch: main → Save**
4. Accessible sur `https://pseudo.github.io/nom-du-repo`

### Netlify
1. [netlify.com](https://netlify.com) → *Add new site → Deploy manually*
2. Glisse-dépose `index.html`

---

## ✨ Fonctionnalités

### Devoirs
- Détection automatique via mots-clés : `Assessment`, `Co-construction`, `à échéance`
- Compte à rebours coloré : rouge ≤ 3j · orange ≤ 7j · vert ≥ 15j (fuseau Europe/Paris)
- Devoirs passés automatiquement marqués comme rendus (sans cache)
- Case "Marquer comme rendu" pour les devoirs à venir (persistante)
- Boutons **Casier** (déposer le rendu) et **Afficher l'événement** (page Brightspace)

### Live Sessions
- Détection via : `Cours distanciel`, `Live Session`, URL `virtual-room.em-lyon.com` dans LOCATION
- Extraction du code matière et du nom long depuis le titre (`2026_PGMC05_2026-03 Nom matière`)
- Bouton **Rejoindre** pointant vers l'URL de la salle virtuelle (champ LOCATION)
- Intervenants extraits du champ DESCRIPTION

### Filtres & navigation
- Filtres par type (individuel / collectif) et par discipline avec compteurs
- Affichage des passés on/off
- Pagination : 5 éléments par défaut, "Afficher X de plus" en bas

### Onglet Progression
- Carte par matière : barre de progression, rendus/total, répartition individuel/collectif
- Histogramme hebdomadaire (8 semaines) avec segment plein (rendus) et hachuré (à rendre)
- Semaine courante mise en évidence
- Tooltip au survol : liste des devoirs de la semaine avec statut

### Interface
- Thème clair / sombre / système (bouton ☀/🌙, mémorisé)
- Export `.ics` filtré importable dans Google Calendar ou Outlook
- Boutons Google Cal. et Outlook (crée un événement à la date d'échéance)
- Bouton "Copier tâche" → presse-papiers → coller dans Google Tasks / Microsoft To Do
- Tout masqué en mode lecture seule (aucune URL, aucune action)

---

## 🔒 Sécurité

| Élément | Protection |
|---|---|
| Token ICS Brightspace | Jamais exposé au navigateur en mode connecté/share |
| Mot de passe | Stocké avec `password_hash()` PHP (bcrypt) |
| Dossier `data/` | `.htaccess` interdisant l'accès direct |
| Token de partage | 32 caractères aléatoires, révocable depuis les paramètres |
| Lien de partage invalide | Page d'erreur explicite, pas de fallback silencieux |
| Anti-brute-force | Délai de 1s sur mot de passe incorrect |
| SSRF | Seuls les domaines `emlyon.brightspace.com` et `em-lyon.com` sont autorisés |
