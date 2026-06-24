# Donnees de la borne

La borne consomme l'API REST en lecture : `/api/categories`, `/api/products`,
`/api/menus` et `/api/allergens` (cf. `docs/api/conventions.md` section 5.2). La
couche `assets/js/data.js` deballe l'enveloppe `{ data }` et traduit la forme
canonique vers la forme attendue par les pages.

Les anciens fichiers JSON statiques (`categories.json`, `produits.json`,
`allergens.json`) qui servaient de repli avant l'API ont ete retires : la borne
reflete la base via l'API.
