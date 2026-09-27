# Page « Santé de l'API » : réponses complètes, console de lecture, connexion de démonstration

**Date** : 2026-09-27
**Branche** : `feat/health-console`
**Duree estimee** : 1h30 de travail assiste

---

## La demande

Dans la page Santé, voir le JSON réellement renvoyé par chaque appel, et pouvoir interroger ce
qui est derrière une authentification : se connecter, ou récupérer le jeton, pour faire les
appels à la main. Arbitrage de l'auteur : **des lectures et la connexion, aucune écriture
depuis la page**.

## Ce qui a ete fait

- **Le détail de chaque sonde** : un bouton « Détails », replié par défaut, affiche le statut,
  les en-têtes utiles (`Content-Type`, `Cache-Control`, `Content-Security-Policy`...) et le corps
  de la réponse, JSON indenté.
- **Une console d'appels en lecture** : n'importe quelle route `GET` de la carte, un champ par
  paramètre du chemin, appelée avec la session de la page. Le « lecture seule » est tenu par le
  code (la construction de la requête refuse toute autre méthode), pas seulement par le
  sélecteur. Chaque paramètre est encodé : on ne peut pas saisir un chemin libre.
- **Une connexion de démonstration** sur `POST /admin/api/auth/login`, envoyée avec
  `credentials: 'omit'` : la réponse (et son `csrf_token`) s'affiche, mais le cookie de session de
  qui regarde la page n'est pas remplacé. La page explique pourquoi le cookie de session n'est
  pas lisible (`HttpOnly`) et donne la séquence `curl` avec un fichier de cookies pour rejouer
  la connexion hors du navigateur. Le mot de passe saisi n'est ni conservé ni réaffiché.

## Les garde-fous, et comment ils sont vérifiés

- **Aucun contenu de réponse n'est interprété comme du HTML.** Une réponse d'API peut contenir
  du balisage ; tout passe par `textContent`. Trois tests injectent `<img src=x onerror=...>`
  dans une réponse simulée et vérifient qu'aucun élément n'est créé.
- **La session de la page survit à la connexion de démonstration.** Vérifié en vrai navigateur :
  `tests/e2e/admin-health-console.spec.js` se connecte en administrateur, lance la connexion de
  démonstration avec le compte responsable, puis appelle `/admin/api/auth/me` depuis la console :
  la réponse porte encore `role_code: admin`.

## Un defaut trouve par le test navigateur

Les panneaux de réponse portaient l'attribut `hidden`, mais leur règle CSS (`display: grid` ou
`flex`) l'emportait : ils s'affichaient, vides, avant tout appel. Les tests JavaScript, joués sur
un faux DOM sans feuille de style, ne pouvaient pas le voir. Corrigé par une règle ciblée sur ces
seuls panneaux ; le test navigateur vérifie désormais qu'ils sont fermés au chargement.

## Mesures

- **Tests JavaScript : 428, 0 échec** (399 avant ce lot).
- **Test navigateur de la page : 5 scénarios sur 5** sur une pile jetable.
- **PHPStan et PHPUnit inchangés** (aucun fichier PHP modifié).
- **Audit d'accessibilité mesuré : 19 écrans, 946 mesures de contraste, 85 combinaisons, 0
  violation**. Seule la page Santé change : 65 mesures (62 avant), 28 règles conformes (26).
