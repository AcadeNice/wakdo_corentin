# Provenance des librairies vendues (assets/vendor/)

Ce dossier contient la seule dependance front externe du projet, embarquee en
local et non chargee depuis un service distant. Raison : le vhost de la borne
impose une politique de securite de contenu stricte, meme origine
(`docker/apache/vhost.conf`, bloc `<VirtualHost>` `ServerName ${APP_HOST_KIOSK}` :
`Header always set Content-Security-Policy "default-src 'self'; ... script-src
'self'; ..."`) — aucune source de script tierce n'est autorisee, un `<script
src="https://cdn...">` serait bloque par le navigateur.

## a11y-dialog

| Champ | Valeur |
|---|---|
| Paquet | `a11y-dialog` |
| Version | `8.1.5` (epinglee, pas de plage semver) |
| Registre | npm (`https://registry.npmjs.org/a11y-dialog`) |
| Licence | MIT — Copyright (c) 2025 Kitty Giraudel (voir `a11y-dialog/LICENSE`) |
| Documentation | https://a11y-dialog.netlify.app/ |
| Depot source | https://github.com/KittyGiraudel/a11y-dialog |
| Fichier vendu | `a11y-dialog/a11y-dialog.esm.min.js` |
| Poids du fichier vendu | 4562 octets (mesure : `wc -c`) |
| SHA-256 du fichier vendu | `d2857eb9f71d008c0bb839b8140dc6a03f66ed9444877c3c13ea4f89867ca79c` |
| Integrite npm (tarball complet) | `sha512-SlFk3QSqeuvmN/anaIteUkB6ipBHoG1jq5gfQZU2kqvbkDW3Iab7SNufj4io4e8StvuIshD+loJnsQgTEvq6dA==` |

Obtenu via `npm pack a11y-dialog@8.1.5` (registre npm officiel), sans passer par
un CDN a aucun moment, y compris pour le recuperer sur la machine de
developpement. Le fichier vendu est copie OCTET POUR OCTET depuis
`dist/a11y-dialog.esm.min.js` du paquet publie (verifie par `cmp` au moment de
la copie) : aucune ligne n'a ete ajoutee ou modifiee dans le fichier lui-meme,
y compris pour y noter cette provenance — l'ajout d'un en-tete aurait change le
SHA-256 et rendu la comparaison avec le paquet publie moins directe. C'est pour
cette raison que la provenance est documentee ici, dans un fichier voisin,
plutot que dans le fichier vendu.

## focusable-selectors — une dependance deja PRESENTE dans le fichier ci-dessus

| Champ | Valeur |
|---|---|
| Paquet | `focusable-selectors` |
| Version declaree par a11y-dialog 8.1.5 | `^0.8.0` (resolue et embarquee : `0.8.4`) |
| Licence | MIT — Copyright (c) 2021 Kitty Giraudel (voir `focusable-selectors/LICENSE`) |
| Depot source | https://github.com/KittyGiraudel/focusable-selectors |

Point verifie et important a ne pas presenter de travers : **`focusable-selectors`
n'est PAS charge comme un fichier separe par le navigateur.** a11y-dialog le
declare comme dependance de production dans son `package.json` (`"dependencies":
{"focusable-selectors": "^0.8.0"}`), mais son build (rollup) l'INLINE dans
chaque fichier `dist/*` qu'il publie. Verification faite en inspectant le
fichier vendu lui-meme : la liste des selecteurs CSS de focusable-selectors
0.8.4 (`a[href]`, `area[href]`, `:not([inert])`, etc.) est presente telle
quelle dans `a11y-dialog.esm.min.js` (`grep -o "area\[href\]"` la trouve). Il
n'existe donc aucun second fichier `.js` a servir pour cette dependance — son
LICENSE est tout de meme conserve ici pour l'attribution complete, puisque son
CODE est physiquement present dans ce que le navigateur execute.

## Fichiers de ce dossier

```
assets/vendor/
  package.json                        Scope ESM pour Node (voir contenu du fichier)
  NOTICE.md                           Ce fichier
  a11y-dialog/
    a11y-dialog.esm.min.js            Le fichier importe par confirm-modal.js
    LICENSE                           Licence MIT d'a11y-dialog, verbatim
  focusable-selectors/
    LICENSE                           Licence MIT de focusable-selectors, verbatim (pas de .js : voir ci-dessus)
```
