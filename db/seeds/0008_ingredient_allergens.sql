-- =============================================================================
-- Wakdo — Seed 0008 : allergenes des ingredients (ingredient_allergen + revue)
-- =============================================================================
-- Peuple la table de liaison restee vide depuis la migration 0001, et pose l'etat
-- de revue introduit par la migration 0011. A partir de ce seed, la borne calcule
-- les allergenes de chaque produit par la chaine product_ingredient ->
-- ingredient_allergen -> allergen (dictionary.md 3.8, note 15).
--
-- -----------------------------------------------------------------------------
-- METHODE (2026-07-31)
-- -----------------------------------------------------------------------------
-- Les lignes ne sont PAS deduites du nom de l'ingredient. Chacune vient d'une
-- recherche sur sources publiques ouvertes ce jour-la : 108 pages consultees,
-- reparties en 9 familles d'ingredients traitees independamment, puis deux relectures
-- adversariales croisees (coherence entre familles, et chasse a la sur-declaration).
--
-- Les deux relectures ont modifie les donnees. Corrections retenues, tracees ici
-- parce qu'elles sont le coeur de la valeur de ce fichier :
--   * Sauce barbecue : celeri AJOUTE. Le document McDonald's Suisse ne declarait que
--     la moutarde, mais la fiche du marche francais du meme produit porte "epices
--     (dont moutarde, celeri)". Un client allergique au celeri lisait "moutarde
--     uniquement" et se servait.
--   * Donut : oeuf RETIRE. Le livret allergenes officiel classe l'oeuf en TRACES sur
--     les deux variantes, pas en ingredient. Une trace promue en ingredient est une
--     sur-declaration, et le projet porte deja un avertissement general sur les traces.
--   * Dosette Barbecue : soja RETIRE. Un enregistrement du meme produit l'omet, et
--     celui qui le porte dit "huile de soja en proportion variable".
--   * Jambon, Salade, Tomate, Oignon, Roquette, Dose Eau : niveau de preuve ABAISSE
--     pour refleter la source qui porte reellement l'affirmation.
--
-- -----------------------------------------------------------------------------
-- LA REGLE DE DECISION (unique, appliquee a toutes les lignes)
-- -----------------------------------------------------------------------------
-- La premiere relecture a releve que la regle changeait d'une ligne a l'autre :
-- protectrice pour la galette de pomme de terre, non protectrice pour la frite.
-- Une seule regle s'applique donc desormais :
--
--   Chaque ligne epingle une REFERENCE PRODUIT NOMMEE dans son commentaire (la ligne
--   devient reproductible) et declare les allergenes de cette reference. Quand le nom
--   du catalogue couvre des recettes qui DIVERGENT sur un allergene et qu'aucune
--   reference ne peut etre epinglee, la ligne reste NON REVUE.
--
-- Cinq ingredients restent donc non revus a dessein (voir section 3). Ce n'est pas un
-- travail inacheve : c'est le chemin honnete du dispositif. La borne affiche pour eux
-- "information non disponible, demandez a l'equipe" au lieu d'unir deux recettes
-- incompatibles ou d'affirmer une absence que personne n'a verifiee.
--
-- -----------------------------------------------------------------------------
-- PORTEE ET RESERVE
-- -----------------------------------------------------------------------------
-- Donnees de DEMONSTRATION datees, issues de sources publiques. Ce n'est pas un
-- substitut aux fiches techniques du fournisseur reel d'un etablissement : une mise
-- en service reelle exige de relire chaque ligne sur la fiche du produit achete. Le
-- back-office permet cette correction (fiche ingredient -> "Allergenes de cet
-- ingredient"), qui reecrit la source et la date.
--
-- Niveaux de preuve utilises : L1 texte reglementaire · L2 document produit officiel
-- (table allergenes de chaine, fiche fabricant) · L4 base communautaire (Open Food
-- Facts, fiche revendeur).
--
-- Idempotence : INSERT IGNORE (PK composite) + UPDATE garde par
-- `allergens_reviewed_at IS NULL`. Rejouer le seed ne reecrit donc PAS une revue
-- posterieure faite depuis le back-office -- une correction humaine survit au replay.
-- Horodatage FIXE (date du seed) plutot que NOW() : le replay reste deterministe.
-- FK resolus par sous-requete sur le nom / le code (convention seed 0003).
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 1. ingredient_allergen — les liaisons
-- -----------------------------------------------------------------------------

INSERT IGNORE INTO ingredient_allergen (ingredient_id, allergen_id) VALUES
  -- PAINS ET BASES ------------------------------------------------------------
  -- Pain burger : reference = pain burger nature (type Harrys le moment). Le gluten
  -- est le seul allergene que toutes les sources ouvertes soutiennent. Le pain de
  -- certaines chaines ajoute farine de soja et graines de sesame : a revalider sur la
  -- fiche du pain reellement achete.
  ((SELECT id FROM ingredient WHERE name='Pain burger'), (SELECT id FROM allergen WHERE code='gluten')),
  -- Pain sesame : graines de sesame en surface (2,8% sur l'etiquette lue). Le sesame
  -- de surface se transfere par contact au grille-pain commun : risque de
  -- contamination croisee reel vers les autres pains.
  ((SELECT id FROM ingredient WHERE name='Pain sesame'), (SELECT id FROM allergen WHERE code='gluten')),
  ((SELECT id FROM ingredient WHERE name='Pain sesame'), (SELECT id FROM allergen WHERE code='sesame')),
  -- Pain signature : reference = pain burger BRIOCHE au beurre et poudre de lait
  -- (type Carrefour / La Boulangere). Gluten et oeufs sur les 5 etiquettes lues ; lait
  -- sur 4 des 5. Une brioche sans lait existe (Harrys nature, lait en traces) : c'est
  -- la reference epinglee qui tranche, et elle en contient.
  ((SELECT id FROM ingredient WHERE name='Pain signature'), (SELECT id FROM allergen WHERE code='gluten')),
  ((SELECT id FROM ingredient WHERE name='Pain signature'), (SELECT id FROM allergen WHERE code='eggs')),
  ((SELECT id FROM ingredient WHERE name='Pain signature'), (SELECT id FROM allergen WHERE code='milk')),
  -- Tortilla : reference = tortilla de BLE (fiche Santa Maria, produit etiquete vegan
  -- donc ni lait ni oeuf). Trois sources convergent sur le gluten seul.
  ((SELECT id FROM ingredient WHERE name='Tortilla'), (SELECT id FROM allergen WHERE code='gluten')),

  -- PROTEINES -----------------------------------------------------------------
  -- Steak hache : AUCUNE liaison. Reference = steak hache PUR BOEUF ("viande de boeuf,
  -- sel iode, poivre" au document officiel, aucune colonne allergene cochee). Une
  -- preparation facon burger (chapelure, oignon, moutarde, oeuf) porterait, elle, des
  -- allergenes : la revue est a refaire si le fournisseur passe a une viande assaisonnee.
  -- Filet de poulet pane : gluten STRUCTUREL (panure de ble). Le celeri n'est PAS
  -- ajoute : le document officiel le declare sur le nugget et pas sur ce filet. Une
  -- fiche de filet MARINE (type Tenders) declare en plus celeri, lait, soja et
  -- sulfites -- a revalider si le fournisseur retenu est un pane assaisonne.
  ((SELECT id FROM ingredient WHERE name='Filet de poulet pane'), (SELECT id FROM allergen WHERE code='gluten')),
  -- Galette de poisson : colin d'Alaska + panure de ble. Le code INCO reste "fish"
  -- quelle que soit l'espece, mais l'espece merite d'etre dite en salle : un client
  -- peut etre sensibilise a une espece precise. Des versions panees sans gluten
  -- existent (panure de riz).
  ((SELECT id FROM ingredient WHERE name='Galette de poisson'), (SELECT id FROM allergen WHERE code='fish')),
  ((SELECT id FROM ingredient WHERE name='Galette de poisson'), (SELECT id FROM allergen WHERE code='gluten')),
  -- Tranche de bacon : AUCUNE liaison. Quatre sources concordantes, aucune colonne
  -- allergene cochee (viande de porc, saumure, epices, dextrose, antioxydants).
  -- Nugget de poulet : le celeri est declare VERBATIM comme ingredient dans la liste
  -- officielle des nuggets de chaine, pas deduit. Resultat contre-intuitif conserve
  -- apres les deux relectures.
  ((SELECT id FROM ingredient WHERE name='Nugget de poulet'), (SELECT id FROM allergen WHERE code='gluten')),
  ((SELECT id FROM ingredient WHERE name='Nugget de poulet'), (SELECT id FROM allergen WHERE code='celery')),
  -- Jambon : reference = jambon cuit au bouillon CONTENANT du celeri (type Fleury
  -- Michon). Resultat non intuitif et c'est le point de la recherche : le bouillon de
  -- cuisson du jambon francais en apporte tres souvent. Un jambon dont le bouillon n'en
  -- contient pas existe (Herta) : preuve L4, a revalider sur le fournisseur reel.
  ((SELECT id FROM ingredient WHERE name='Jambon'), (SELECT id FROM allergen WHERE code='celery')),

  -- FROMAGES ------------------------------------------------------------------
  -- Les quatre : lait de vache ou de chevre pasteurise, sel, ferments, coagulant
  -- microbien. Le lait de chevre reste sous la categorie INCO "lait". Fiches
  -- fabricant concordantes, coagulant microbien (donc pas de presure animale a
  -- signaler), aucune autre categorie declaree.
  ((SELECT id FROM ingredient WHERE name='Cheddar'), (SELECT id FROM allergen WHERE code='milk')),
  ((SELECT id FROM ingredient WHERE name='Fromage de chevre'), (SELECT id FROM allergen WHERE code='milk')),
  ((SELECT id FROM ingredient WHERE name='Mozzarella'), (SELECT id FROM allergen WHERE code='milk')),
  ((SELECT id FROM ingredient WHERE name='Emmental'), (SELECT id FROM allergen WHERE code='milk')),

  -- LEGUMES FRAIS -------------------------------------------------------------
  -- Salade, Tomate, Oignon, Roquette : AUCUNE liaison. Aucun legume frais ne figure
  -- parmi les 14 de l'annexe II, et la table allergenes produit consultee laisse ces
  -- quatre lignes vides. Reserve : un melange de 4e gamme peut apporter des feuilles
  -- de la famille du celeri, un legume marine ou en sauce peut apporter des sulfites.
  -- Cornichon : reference = cornichon en saumure format restauration (fiche Hugo
  -- Reitzel / SEPAL, qui cite l'annexe II mot pour mot). Moutarde solide (3 fiches sur
  -- 4). Sulfites sur un partage 2 contre 2 -- retenus parce que la reference epinglee
  -- les declare (disulfite de potassium, moins de 100 ppm) ; un cornichon sans sulfites
  -- existe (Kuhne).
  ((SELECT id FROM ingredient WHERE name='Cornichon'), (SELECT id FROM allergen WHERE code='mustard')),
  ((SELECT id FROM ingredient WHERE name='Cornichon'), (SELECT id FROM allergen WHERE code='sulphites')),

  -- SAUCES DE RECETTE ---------------------------------------------------------
  -- Sauce Big Mac : base mayonnaise (jaune d'oeuf) + moutarde, d'apres la liste
  -- d'ingredients du document officiel. Ligne CONFIRMEE par les deux relectures : la
  -- fiche du marche francais donne le meme couple, huile de colza, ni soja ni celeri.
  ((SELECT id FROM ingredient WHERE name='Sauce Big Mac'), (SELECT id FROM allergen WHERE code='eggs')),
  ((SELECT id FROM ingredient WHERE name='Sauce Big Mac'), (SELECT id FROM allergen WHERE code='mustard')),
  -- Sauce ranch : trois sources independantes convergent sur exactement ce trio
  -- (babeurre / creme -> lait, jaune d'oeuf -> oeufs, moutarde). Ligne confirmee.
  ((SELECT id FROM ingredient WHERE name='Sauce ranch'), (SELECT id FROM allergen WHERE code='milk')),
  ((SELECT id FROM ingredient WHERE name='Sauce ranch'), (SELECT id FROM allergen WHERE code='eggs')),
  ((SELECT id FROM ingredient WHERE name='Sauce ranch'), (SELECT id FROM allergen WHERE code='mustard')),
  -- Sauce barbecue : CELERI AJOUTE apres relecture. La fiche du marche francais dit
  -- "epices (dont moutarde, celeri)". Le document suisse ne declarait que la moutarde :
  -- pour un projet francais, c'est la recette francaise qui fait foi. Preuve L4 (fiche
  -- communautaire) assumee plutot qu'un L2 qui omettrait le celeri. Le soja de la
  -- meme fiche ("huile de soja en proportion variable") n'est PAS retenu -- meme
  -- traitement que sur Dosette Barbecue, pour ne pas contredire la table elle-meme.
  ((SELECT id FROM ingredient WHERE name='Sauce barbecue'), (SELECT id FROM allergen WHERE code='celery')),
  ((SELECT id FROM ingredient WHERE name='Sauce barbecue'), (SELECT id FROM allergen WHERE code='mustard')),
  -- Sauce deluxe : AUCUNE liaison, ingredient laisse NON REVU (section 3).

  -- POMMES DE TERRE -----------------------------------------------------------
  -- Pomme de terre frite : AUCUNE liaison. Reference = frite NATURE non enrobee
  -- (pomme de terre + huile). Divergence reelle entre chaines confirmee sur 4 fiches :
  -- une frite ENROBEE (type Steakhouse) porte du gluten. La reference epinglee ici est
  -- la frite nature.
  -- Galette de pomme de terre : reference = rosti au BEURRE et a l'EMMENTAL (type
  -- Picard), qui declare le lait comme ingredient de recette. Une galette pomme de
  -- terre nature n'en contient pas (Findus, lait en trace eventuelle).
  ((SELECT id FROM ingredient WHERE name='Galette de pomme de terre'), (SELECT id FROM allergen WHERE code='milk')),

  -- BOISSONS ------------------------------------------------------------------
  -- Les huit sirops et jus : AUCUNE liaison. Fiches fabricant consultees une par une ;
  -- pour les jus, les 14 categories sont explicitement cochees "ne contient pas",
  -- ligne sulfites incluse. Reserve : un sirop de fontaine peut changer de recette, et
  -- un jus peut porter des sulfites selon le conditionnement.
  -- Gobelet : AUCUNE liaison, et la source dit pourquoi (section 2).

  -- DESSERTS ------------------------------------------------------------------
  -- Brownie, Cookie, Muffin : lignes CONFIRMEES par la relecture anti-sur-declaration,
  -- qui a extrait les marques "Contains" du livret allergenes officiel et les a
  -- trouvees identiques (le livret distingue "Contains" de "May contain traces" ; seul
  -- le premier est retenu ici).
  ((SELECT id FROM ingredient WHERE name='Brownie'), (SELECT id FROM allergen WHERE code='gluten')),
  ((SELECT id FROM ingredient WHERE name='Brownie'), (SELECT id FROM allergen WHERE code='eggs')),
  ((SELECT id FROM ingredient WHERE name='Brownie'), (SELECT id FROM allergen WHERE code='milk')),
  ((SELECT id FROM ingredient WHERE name='Brownie'), (SELECT id FROM allergen WHERE code='soybeans')),
  ((SELECT id FROM ingredient WHERE name='Cookie'), (SELECT id FROM allergen WHERE code='gluten')),
  ((SELECT id FROM ingredient WHERE name='Cookie'), (SELECT id FROM allergen WHERE code='eggs')),
  ((SELECT id FROM ingredient WHERE name='Cookie'), (SELECT id FROM allergen WHERE code='milk')),
  ((SELECT id FROM ingredient WHERE name='Cookie'), (SELECT id FROM allergen WHERE code='soybeans')),
  ((SELECT id FROM ingredient WHERE name='Muffin'), (SELECT id FROM allergen WHERE code='gluten')),
  ((SELECT id FROM ingredient WHERE name='Muffin'), (SELECT id FROM allergen WHERE code='eggs')),
  ((SELECT id FROM ingredient WHERE name='Muffin'), (SELECT id FROM allergen WHERE code='milk')),
  -- Cheesecake : biscuit (gluten), fromage frais et creme (lait), oeuf. Les 8 fiches
  -- detaillees ouvertes donnent le meme trio, chaque allergene rattache a un ingredient
  -- identifie. Preuve L4 : aucune fiche fabricant officielle ouverte.
  ((SELECT id FROM ingredient WHERE name='Cheesecake'), (SELECT id FROM allergen WHERE code='gluten')),
  ((SELECT id FROM ingredient WHERE name='Cheesecake'), (SELECT id FROM allergen WHERE code='eggs')),
  ((SELECT id FROM ingredient WHERE name='Cheesecake'), (SELECT id FROM allergen WHERE code='milk')),
  -- Donut : OEUF RETIRE apres relecture. Reference = donut GARNI (type Toffee Apple du
  -- livret officiel) : "Contains" gluten, lait, soja ; l'oeuf y est en TRACES sur les
  -- deux variantes du document. Un donut sucre nature ne porte que le gluten.
  ((SELECT id FROM ingredient WHERE name='Donut'), (SELECT id FROM allergen WHERE code='gluten')),
  ((SELECT id FROM ingredient WHERE name='Donut'), (SELECT id FROM allergen WHERE code='milk')),
  ((SELECT id FROM ingredient WHERE name='Donut'), (SELECT id FROM allergen WHERE code='soybeans')),
  -- Macaron : AUCUNE liaison, ingredient laisse NON REVU (section 3).
  -- Glace McFleury : AUCUNE liaison, ingredient laisse NON REVU (section 3).
  -- Glace sundae : glace molle NUE, qui ne porte que le lait. Deux documents officiels
  -- independants le disent sur le produit lui-meme.
  ((SELECT id FROM ingredient WHERE name='Glace sundae'), (SELECT id FROM allergen WHERE code='milk')),
  -- Topping chocolat : lecithine de SOJA (emulsifiant) + lait, etabli de deux facons
  -- concordantes sur la composition du topping chaud de chaine.
  ((SELECT id FROM ingredient WHERE name='Topping chocolat'), (SELECT id FROM allergen WHERE code='milk')),
  ((SELECT id FROM ingredient WHERE name='Topping chocolat'), (SELECT id FROM allergen WHERE code='soybeans')),

  -- DOSETTES DE SAUCE ---------------------------------------------------------
  -- Dosette Barbecue : SOJA RETIRE apres relecture (un enregistrement du meme produit
  -- l'omet, l'autre dit "en proportion variable"). Celeri et moutarde sont declares par
  -- les deux : "epices (dont moutarde, celeri)".
  ((SELECT id FROM ingredient WHERE name='Dosette Barbecue'), (SELECT id FROM allergen WHERE code='celery')),
  ((SELECT id FROM ingredient WHERE name='Dosette Barbecue'), (SELECT id FROM allergen WHERE code='mustard')),
  -- Dosette Moutarde : graines de moutarde comme ingredient de base (26% sur la dosette
  -- lue). Sulfites apportes par le conservateur E224 (disulfite de potassium).
  ((SELECT id FROM ingredient WHERE name='Dosette Moutarde'), (SELECT id FROM allergen WHERE code='mustard')),
  ((SELECT id FROM ingredient WHERE name='Dosette Moutarde'), (SELECT id FROM allergen WHERE code='sulphites')),
  -- Dosette Deluxe : allergenes declares mot pour mot "oeufs, moutarde, soja", coherent
  -- avec la liste lue (huiles de colza et de soja, moutarde a l'ancienne, jaune d'oeuf).
  ((SELECT id FROM ingredient WHERE name='Dosette Deluxe'), (SELECT id FROM allergen WHERE code='eggs')),
  ((SELECT id FROM ingredient WHERE name='Dosette Deluxe'), (SELECT id FROM allergen WHERE code='mustard')),
  ((SELECT id FROM ingredient WHERE name='Dosette Deluxe'), (SELECT id FROM allergen WHERE code='soybeans')),
  -- Dosette Ketchup : AUCUNE liaison, ingredient laisse NON REVU (section 3).
  -- Dosette Chinoise : sauce aigre-douce type chinoise. La source la plus nette de la
  -- famille : liste lue mot pour mot avec sauce de soja (soja + ble -> gluten) et
  -- epices contenant du celeri.
  ((SELECT id FROM ingredient WHERE name='Dosette Chinoise'), (SELECT id FROM allergen WHERE code='celery')),
  ((SELECT id FROM ingredient WHERE name='Dosette Chinoise'), (SELECT id FROM allergen WHERE code='gluten')),
  ((SELECT id FROM ingredient WHERE name='Dosette Chinoise'), (SELECT id FROM allergen WHERE code='soybeans')),
  -- Dosette Curry : liste lue mot pour mot, "2% curry (dont moutarde)".
  ((SELECT id FROM ingredient WHERE name='Dosette Curry'), (SELECT id FROM allergen WHERE code='mustard'));
  -- Dosette Pommes Frites : AUCUNE liaison, ingredient laisse NON REVU (section 3).

-- -----------------------------------------------------------------------------
-- 2. ingredient — marqueur de revue + provenance (45 des 50 ingredients)
-- -----------------------------------------------------------------------------
-- Poser allergens_reviewed_at est ce qui rend la liste AFFIRMABLE cote borne. Un
-- ingredient sans liaison ET avec cette date declare "verifie, aucun des 14".
-- La garde `allergens_reviewed_at IS NULL` protege une revue humaine posterieure.

-- Pains et bases
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Liste ingredients KFC (dec. 2023) + etiquette Harrys burger nature'
  WHERE name = 'Pain burger' AND allergens_reviewed_at IS NULL;
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Liste ingredients KFC (dec. 2023) + etiquette Harrys burger sesame'
  WHERE name = 'Pain sesame' AND allergens_reviewed_at IS NULL;
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Etiquettes pains burger brioches (Carrefour, La Boulangere) - L4'
  WHERE name = 'Pain signature' AND allergens_reviewed_at IS NULL;
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Fiche technique Santa Maria Tortilla Wraps ble + tableau Quesada'
  WHERE name = 'Tortilla' AND allergens_reviewed_at IS NULL;

-- Proteines
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Liste ingredients et allergenes McDonald''s (PDF officiel) - steak pur boeuf'
  WHERE name = 'Steak hache' AND allergens_reviewed_at IS NULL;
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'PDF officiels McDonald''s + KFC - filet pane non marine'
  WHERE name = 'Filet de poulet pane' AND allergens_reviewed_at IS NULL;
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'PDF officiel McDonald''s + fiche Findus colin d''Alaska pane'
  WHERE name = 'Galette de poisson' AND allergens_reviewed_at IS NULL;
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'PDF officiels McDonald''s + KFC - bacon de porc fume'
  WHERE name = 'Tranche de bacon' AND allergens_reviewed_at IS NULL;
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'PDF officiel McDonald''s - celeri declare comme ingredient des nuggets'
  WHERE name = 'Nugget de poulet' AND allergens_reviewed_at IS NULL;
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Fiche Fleury Michon jambon blanc (bouillon au celeri) - L4'
  WHERE name = 'Jambon' AND allergens_reviewed_at IS NULL;

-- Fromages
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Fiches techniques cheddar bloc et tranches (Boni Selection)'
  WHERE name = 'Cheddar' AND allergens_reviewed_at IS NULL;
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Fiche technique Chavroux fromage de chevre tranches'
  WHERE name = 'Fromage de chevre' AND allergens_reviewed_at IS NULL;
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Fiches techniques Galbani et Bonta di Lilli mozzarella'
  WHERE name = 'Mozzarella' AND allergens_reviewed_at IS NULL;
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Fiche technique Domalait emmental + liste ingredients McDonald''s'
  WHERE name = 'Emmental' AND allergens_reviewed_at IS NULL;

-- Legumes frais
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Tableau allergenes produit (ligne laitue iceberg) + annexe II 1169/2011'
  WHERE name = 'Salade' AND allergens_reviewed_at IS NULL;
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Tableau allergenes produit (ligne tomates) + annexe II 1169/2011'
  WHERE name = 'Tomate' AND allergens_reviewed_at IS NULL;
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Tableau allergenes produit (ligne oignons) + annexe II 1169/2011'
  WHERE name = 'Oignon' AND allergens_reviewed_at IS NULL;
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Tableau allergenes produit (ligne roquette) + annexe II 1169/2011'
  WHERE name = 'Roquette' AND allergens_reviewed_at IS NULL;
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Fiche technique Hugo Reitzel / SEPAL format restauration'
  WHERE name = 'Cornichon' AND allergens_reviewed_at IS NULL;

-- Sauces de recette
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Liste ingredients et allergenes McDonald''s (PDF officiel)'
  WHERE name = 'Sauce Big Mac' AND allergens_reviewed_at IS NULL;
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Fiche produit Burger King France sauce Creamy Ranch + tableau KFC'
  WHERE name = 'Sauce ranch' AND allergens_reviewed_at IS NULL;
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Fiche marche francais sauce barbecue (celeri declare) - L4'
  WHERE name = 'Sauce barbecue' AND allergens_reviewed_at IS NULL;

-- Pommes de terre
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Fiches Findus et McCain - frite nature non enrobee'
  WHERE name = 'Pomme de terre frite' AND allergens_reviewed_at IS NULL;
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Fiche Picard rostis au beurre et emmental'
  WHERE name = 'Galette de pomme de terre' AND allergens_reviewed_at IS NULL;

-- Boissons
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Coca-Cola France, FAQ allergenes (aucun allergene annexe II)'
  WHERE name = 'Dose Coca' AND allergens_reviewed_at IS NULL;
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Coca-Cola France, fiche Coca-Cola sans sucres'
  WHERE name = 'Dose Coca Zero' AND allergens_reviewed_at IS NULL;
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Annexe II 1169/2011 : l''eau ne figure pas dans la liste'
  WHERE name = 'Dose Eau' AND allergens_reviewed_at IS NULL;
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Coca-Cola France, fiche Fanta Orange'
  WHERE name = 'Dose Fanta' AND allergens_reviewed_at IS NULL;
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Lipton Ice Tea France, fiche saveur peche'
  WHERE name = 'Dose Ice Tea Peche' AND allergens_reviewed_at IS NULL;
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Lipton Ice Tea France, fiche saveur citron'
  WHERE name = 'Dose Ice Tea Citron' AND allergens_reviewed_at IS NULL;
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Fiche technique Minute Maid orange (14 categories negatives)'
  WHERE name = 'Dose Jus d Orange' AND allergens_reviewed_at IS NULL;
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Fiche technique Minute Maid pomme (14 categories negatives)'
  WHERE name = 'Dose Jus de Pomme' AND allergens_reviewed_at IS NULL;
-- Gobelet : la SOURCE porte la raison. Un gobelet n'est pas un aliment mais un
-- materiau au contact des denrees (reglement 1935/2004), hors du champ de l'annexe II.
-- Il est marque revu pour ne pas rendre les 12 boissons "information non disponible" a
-- cause de leur emballage -- ce qui serait faux dans l'autre sens. Limite de
-- modelisation connue (emballage porte comme ingredient de stock) : voir ADR-0015.
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Non alimentaire : materiau au contact (reglement 1935/2004), hors annexe II'
  WHERE name = 'Gobelet' AND allergens_reviewed_at IS NULL;

-- Desserts
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Livret allergenes McDonald''s UK 2024 (marques Contains)'
  WHERE name = 'Brownie' AND allergens_reviewed_at IS NULL;
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Etiquettes cheesecakes (Gu, Marie Morin, Picard) - L4'
  WHERE name = 'Cheesecake' AND allergens_reviewed_at IS NULL;
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Tableau allergenes KFC (juil. 2026) + livret McDonald''s UK 2024'
  WHERE name = 'Cookie' AND allergens_reviewed_at IS NULL;
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Livret McDonald''s UK 2024, donut garni (oeuf en traces, non retenu)'
  WHERE name = 'Donut' AND allergens_reviewed_at IS NULL;
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Livret allergenes McDonald''s UK 2024 (marques Contains)'
  WHERE name = 'Muffin' AND allergens_reviewed_at IS NULL;
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Carte desserts McDonald''s + tableau KFC - glace molle nue'
  WHERE name = 'Glace sundae' AND allergens_reviewed_at IS NULL;
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Carte desserts McDonald''s + tableau KFC - topping chaud au chocolat'
  WHERE name = 'Topping chocolat' AND allergens_reviewed_at IS NULL;

-- Dosettes de sauce
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Fiches sauce Classic Barbecue (2 enregistrements) - L4'
  WHERE name = 'Dosette Barbecue' AND allergens_reviewed_at IS NULL;
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Fiche stick moutarde Amora 5 ml - L4'
  WHERE name = 'Dosette Moutarde' AND allergens_reviewed_at IS NULL;
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Fiche sauce Mc Deluxe (oeufs, moutarde, soja declares) - L4'
  WHERE name = 'Dosette Deluxe' AND allergens_reviewed_at IS NULL;
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Fiche sauce Classic Chinoise (sauce de soja, epices au celeri) - L4'
  WHERE name = 'Dosette Chinoise' AND allergens_reviewed_at IS NULL;
UPDATE ingredient SET allergens_reviewed_at = '2026-07-31 09:00:00', allergens_source = 'Fiche sauce Classic Curry (curry dont moutarde) - L4'
  WHERE name = 'Dosette Curry' AND allergens_reviewed_at IS NULL;

-- -----------------------------------------------------------------------------
-- 3. Les cinq ingredients laisses NON REVUS, et pourquoi
-- -----------------------------------------------------------------------------
-- Aucun UPDATE ci-dessous : allergens_reviewed_at reste NULL a DESSEIN. La borne
-- affiche "information non disponible, demandez a l'equipe" sur les 8 produits qui les
-- utilisent. C'est le resultat correct : ces cinq noms de catalogue couvrent des
-- recettes qui divergent sur au moins un allergene, et aucune reference ne peut etre
-- epinglee sans decider a la place de l'exploitant quel produit il achete.
--
--   * Sauce deluxe (4 produits) — deux recettes qui s'excluent. Version cremeuse :
--     "oeufs, lait", sans moutarde. Version moutarde a l'ancienne : "oeufs, moutarde,
--     soja", sans aucun ingredient laitier. Affirmer les trois serait l'union de deux
--     recettes incompatibles ; quelle que soit celle servie, une des affirmations
--     serait fausse.
--   * Macaron (1 produit) — la coque (sucre glace, poudre d'amande, blanc d'oeuf) ne
--     porte que oeufs et fruits a coque. Le gluten, le lait et le soja viennent des
--     INCLUSIONS d'un assortiment precis (brisures de speculoos, malt d'orge, lecithine
--     de soja). Affirmer 5 codes sur-declarerait un macaron nature ; n'en affirmer que
--     2 sous-declarerait un macaron garni. Les deux sens sont faux.
--   * Glace McFleury (1 produit) — le nom couvre plusieurs parfums. Les inclusions
--     biscuit apportent gluten et soja, celles a base de noisette ou d'amande
--     apportent des FRUITS A COQUE. Une ligne unique tairait les fruits a coque pour
--     une partie des parfums : c'est le sens dangereux.
--   * Dosette Ketchup (1 produit) — la dosette de chaine relue ne declare aucun
--     allergene, le ketchup de detail en bouteille declare le celeri. L'absence de
--     declaration sur une fiche communautaire n'est pas une preuve d'absence.
--   * Dosette Pommes Frites (1 produit) — deux recettes portent ce nom : l'une au lait
--     ecreme, l'autre a base de jaune d'OEUF. Le seul invariant est la moutarde ;
--     choisir le lait tairait l'oeuf.
--
-- Pour lever chacune : ouvrir la fiche du produit reellement achete et enregistrer la
-- revue depuis le back-office (fiche ingredient -> "Allergenes de cet ingredient").
-- La garde IS NULL de la section 2 fait que rejouer ce seed ne l'ecrasera pas.
