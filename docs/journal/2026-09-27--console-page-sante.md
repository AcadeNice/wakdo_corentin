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

## Un second defaut, trouve le lendemain par le balayage du back-office

Le 2026-09-28, le balayage de mise en page (`tests/e2e/backoffice-sweep.spec.js`) a ete rejoue
sur la version deployee. La page Sante n'avait pas encore ete balayee : le dernier chiffre publie
(4630 verifications) lui etait anterieur. Trois echecs, tous sur cette page :

- **Une connexion de demonstration vide partait au serveur.** Le formulaire portait
  `novalidate` sans faire lui-meme le controle : un clic sur « Se connecter » champs vides
  envoyait la requete, recevait un 422 et comptait un essai dans la limitation des connexions
  par adresse (deux echecs du balayage : la console et le reseau signalent ce 422). Corrige :
  le formulaire rejoint le controle de saisie commun du back-office (`form-validation.js`,
  messages sous les champs), et `health.js` refuse d'envoyer un formulaire incomplet, quel que
  soit l'ordre des scripts. Quatre tests JS ecrits avant la correction, rouges puis verts, et
  un scenario navigateur qui verifie qu'aucune requete ne part.
- **La regle « texte technique » signalait `/api/health`.** Cette regle protege les equipiers
  d'un code ou d'un chemin affiche par erreur ; sur la page Sante, montrer les chemins de l'API
  est la fonction de la page, et le trajet d'un appel y nomme `routes.php`. Plutot que de
  masquer le releve, le balayage admet, pour cette seule page, les regles liees a l'API
  (chemin, code de permission, identifiant, nom de fichier `.php`), et ecrit le releve admis
  dans son tableau, prefixe. Une trace PHP ou un `undefined` y restent des echecs.

## Mesures

- **Tests JavaScript : 428, 0 échec** (399 avant ce lot) ; 432 après la correction du 28/09.
- **Test navigateur de la page : 5 scénarios sur 5** sur une pile jetable.
- **PHPStan et PHPUnit inchangés** (aucun fichier PHP modifié).
- **Audit d'accessibilité mesuré : 19 écrans, 946 mesures de contraste, 85 combinaisons, 0
  violation**. Seule la page Santé change : 65 mesures (62 avant), 28 règles conformes (26).
- **Audit d'accessibilite rejoue le 28/09**, apres le trajet aux reponses reelles : 935 mesures
  (et non plus 946). L'apercu d'import ne montre plus le tableau d'erreurs du defaut des accents,
  corrige par #178 ; le detail est dans la fiche 06.
- **Balayage du back-office, le 28/09** : 3 échecs à la première passe sur la page Santé, puis
  **4807 vérifications, 0 échec** après correction (5 rôles connectés plus l'état non connecté,
  111 pages-rôles, 4 largeurs). Test navigateur de la page : 6 scénarios sur 6.
