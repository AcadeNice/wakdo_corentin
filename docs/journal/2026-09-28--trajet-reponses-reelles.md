# Page Santé : le trajet d'un appel affiche des réponses réelles, capturées ou envoyées

**Date** : 2026-09-28
**Branche** : `feat/health-real-responses`
**Duree estimee** : une journée de travail assisté

---

## La demande

L'auteur, en préparant ses appels pour l'oral : « J'ai pas les bons retours [...] sur tous ça
passe pas ». Le trajet de la page Santé affichait, en fin de parcours, un corps écrit à la main
(`{ "data": { … } }`) au lieu de la réponse du serveur. Comparées au serveur, plusieurs réponses
de refus étaient même fausses : un produit inexistant répond `Produit introuvable.`, la page
annonçait `Resource not found`.

## Ce qui a ete fait

- **Un programme de capture** (`tests/e2e/health-capture.spec.js`, lancé par
  `tests/e2e/run-health-capture.sh`) monte une pile Docker jetable et appelle pour de vrai
  chacune des 158 routes de la carte : en succès, puis sur chacun des refus que le trajet
  propose (sans session, sans permission, sans jeton, corps mal typé, saisie invalide, code
  personnel faux, adresse inconnue, élément inexistant, autre site). Les écritures JSON rejouent
  la collection Postman livrée ; les formulaires du back-office sont remplis sur les vraies
  pages ; la base est arrêtée pour obtenir la vraie réponse d'une exception ; le lien de
  réinitialisation du mot de passe est lu dans le journal de la pile. Résultat : **158 succès
  sur 158, 670 refus obtenus sur 689 tentés**, dans `src/app/Health/captured-responses.json`.
- **Le trajet** fait le vrai appel quand c'est sans danger : une lecture, avec la session de la
  page ; le refus « sans session » d'une route JSON, sans le cookie ; l'adresse inconnue d'une
  lecture. Une lecture qui attend un identifiant le lit d'abord dans la liste correspondante.
  Rien ne part au chargement de la page, seulement sur un clic. Pour une écriture, le trajet
  affiche la réponse capturée, avec sa date, son commit et la façon dont elle a été obtenue :
  **aucune écriture ne part de la page**, garantie tenue par le code (`buildTrajetRequest`) et
  vérifiée par un test navigateur. Sous chaque réponse, la requête envoyée est affichée.
- **Un refus que la capture n'a pas pu provoquer n'est plus proposé.** Par exemple, une route
  sans corps (activer, marquer prête) n'a pas de contrôle de format à refuser : l'étape affiche
  « refus non observé sur cette route : la même demande a répondu 200 », au lieu d'un 415
  inventé.
- **Plus aucun corps écrit à la main** : les fonctions qui les fabriquaient ont été retirées. Si
  le fichier capturé manque, la page le dit et n'affiche rien.

## La carte des routes, rangée par action

L'auteur, en lisant les 29 routes des ingrédients : « il y a pas de doublon tu vas me dire ».
Il n'y en avait pas, mais la carte alignait les routes à plat, et trois routes d'une même action
se lisaient comme des répétitions. La carte range désormais les routes **par action**, avec une
colonne pour la page affichée (GET), une pour l'envoi du formulaire, une pour l'API JSON, et une
pour la borne dans les groupes qui en ont. Les ingrédients passent de 29 lignes à 13 actions ;
l'ensemble, de 158 routes à 80 actions. Chaque route garde son bouton (trajet, console). Le texte
de la section explique les trois raisons d'avoir jusqu'à trois routes par action. Deux
corrections de nommage au passage : le `DELETE` d'un compte et celui d'une catégorie
**désactivent** (`is_active = 0`), ils sont rangés avec « Désactiver », pas avec « Supprimer ».

Le balayage du back-office a refusé la première version : à 1024 px, les groupes à cinq colonnes
se chevauchaient, et la grille, nommée `health-actions`, était lue comme une barre de boutons
(dans ce projet, une classe en `*actions` désigne une barre de boutons alignés). La grille
s'appelle `health-amap`, et les groupes à cinq colonnes passent en fiches sous 1280 px.

## L'historique des mouvements dans l'API, et la collection complétée

La revue des cartes a montré deux actions présentes dans le back-office mais absentes de l'API.
Décision de l'auteur : ajouter la lecture de l'historique, laisser la recherche nutritionnelle au
back-office et l'écrire.

- **`GET /admin/api/ingredients/{id}/movements`** (`stock.read`) : la route 158. Même règle que la
  page du back-office (RG-4) : l'auteur d'un mouvement n'est renvoyé qu'aux détenteurs de
  `stock.manage`, et le champ `actor` est alors absent, pas seulement vide. La fonction qui teste
  la permission (`may`) est partagée avec le contrôleur du back-office, pas recopiée.
- **La recherche nutritionnelle reste au back-office**, écrit dans le contrat de l'API : elle
  interroge Open Food Facts et écrit directement ce qu'il renvoie ; l'exposer permettrait de la
  déclencher en boucle vers un tiers, sans que personne voie ce qui est écrit.
- **La collection Postman et Bruno** gagne les quatre requêtes qui manquaient : l'historique des
  mouvements, le modèle d'import, l'aperçu d'import sans écriture (`dry_run=1`) et l'état de
  santé détaillé. La capture a rejoué la collection régénérée : l'aperçu d'import répond 200.

## Pourquoi — decisions et alternatives

- **Capturer plutôt qu'appeler la production pour les écritures.** L'auteur avait tranché la
  veille : des lectures et la connexion, aucune écriture depuis la page. Une réponse capturée
  sur une copie jetable est une vraie réponse du même code, sans toucher aux données de la
  démonstration.
- **Le fichier hors de la racine web.** Il est rangé dans `src/app/Health/` et transmis par la
  page Santé, déjà réservée à `role.manage`. Le servir sous `/assets/` l'aurait rendu lisible
  sans compte. Aucune route n'est ajoutée.
- **Une seule mise en forme.** La capture et la page utilisent la même fonction
  (`summarizeBody` de `health.js`) : une réponse capturée et une réponse en direct se lisent de
  la même façon.

## Defauts trouves en chemin

- **Une clé d'idempotence de plus de 36 caractères faisait échouer la commande en 500** (la
  colonne est un `VARCHAR(36)`). Corrigé par #186, déployé par #187 : 422
  `INVALID_IDEMPOTENCY_KEY`, vérifié sur la production.
- **Une adresse inconnue du back-office, et une exception, répondaient en JSON brut** à
  l'équipier. Corrigé dans la foulée : `App\Core\ErrorResponse` choisit le format selon la
  surface. L'API (`/api`, `/admin/api`, `/admin/me`) garde son enveloppe JSON ; le back-office
  affiche une page lisible (« Page introuvable », « Une erreur est survenue »), sans détail
  interne hors mode débogage, et sans reprendre l'adresse demandée. Un `GET` vers une adresse
  qui n'existe qu'en envoi de formulaire affiche aussi « Page introuvable » (le code reste 405).
  Test navigateur : `tests/e2e/admin-error-pages.spec.js`, accessibilité comprise (axe-core,
  0 violation).
- **Le chiffre d'accessibilité d'hier comptait un bug.** L'audit rejoué donne 935 mesures au
  lieu de 946 : l'aperçu d'import du 27/09 affichait le tableau d'erreurs du défaut des accents,
  corrigé depuis par #178. Détail dans `docs/soutenance/preuves/06-audit-accessibilite-mesure.md`.
- **Ma propre capture a d'abord masqué trop peu** : un mot de passe de test inventé figurait en
  clair dans une requête capturée. Le test `CapturedResponsesTest` l'a trouvé ; le masquage vise
  maintenant tout champ dont le nom contient `password`, `pin` ou `csrf`.

## Garde-fous

- `tests/Unit/Health/CapturedResponsesTest.php`, joué en CI : chaque route du routeur a sa
  réponse capturée (ou une raison écrite), aucune capture ne vise une route disparue, et le
  fichier ne contient ni jeton de 64 caractères, ni mot de passe de démonstration, ni cookie.
- `tests/js/health.test.js` : aucune écriture ne part avec la session de la page ; le succès
  d'une écriture n'est pas envoyé.
- `tests/e2e/admin-health-trajet.spec.js`, en vrai navigateur : aucune requête d'écriture vers
  `/api/orders` quand on affiche « passer commande ».

## Mesures

- Tests PHP : 2386 tests, 7886 assertions, 0 échec ; PHPStan niveau 6 propre.
- Tests JavaScript : 446, 0 échec.
- Tests navigateur sur pile jetable : console, trajet et balayage, 22 scénarios sur 22 ;
  balayage du back-office : 4807 vérifications, 0 échec.
- Audit d'accessibilité : 19 écrans, 935 mesures, 85 combinaisons, 0 violation (rejoué après la
  carte par action : inchangé).

## Questions anticipees du jury

- **Q** : « Vos réponses capturées ne risquent-elles pas de vieillir ? »
  **R** : Si une route change sans nouvelle capture, le test `CapturedResponsesTest` échoue en
  CI en la nommant. La date et le commit de la capture sont affichés sous chaque réponse.
- **Q** : « La page peut-elle écrire en production ? »
  **R** : Non : `buildTrajetRequest` ne construit aucune écriture avec la session. Le seul appel
  d'écriture possible part sans cookie, et le serveur le refuse avant tout contrôleur (401). Un
  test navigateur vérifie qu'aucune requête d'écriture ne part.
