# Design - maquette borne Wakdo

## Fichiers

- `maquette-borne.pdf` : maquette ecrans complete fournie avec le brief ecole
- `screens/` : les 10 ecrans de la maquette exportes en PNG (un par ecran)
- `maquette-vs-build.md` : decomposition ecran par ecran + tracabilite maquette vs kiosk construit (ecarts structurants)

## Source en ligne

Prototype Figma public :

```
https://www.figma.com/design/0qnd0pH4qryZqjzXcB4qjN/borne?node-id=97-775
```

Le PDF est un export fige de cette maquette. Pour les modifications eventuelles ou pour
cliquer dans le prototype interactif, referencer le Figma comme source de verite.

## Utilisation prevue

Cette maquette guide :

1. **L'integration front en phase P5** : composants UI a reproduire en HTML/CSS/JS vanilla
   dans `src/public/borne/`.
2. **Les decisions UX au CDCF** : flows utilisateur (parcours commande, choix sur place / a
   emporter, options de paiement), nombre d'ecrans, transitions.
3. **Le mapping criteres RNCP Bloc 1** : tracabilite entre maquette et code livre, point
   d'appui pour les questions oral type *"comment vous etes passe de la maquette au code ?"*.

## Assets visuels associes

Tous les assets utilises par la maquette (logo, illustrations, vignettes, icones) ont ete
copies et normalises (kebab-case minuscule) dans `src/public/borne/assets/images/` :

```
src/public/borne/assets/images/
  produits/{burgers,wraps,encas,boissons,sauces,desserts,frites,salades}/
  categories/
  ui/
```

Voir `docs/merise/_sources/source-school.md` pour la note sur la normalisation.
