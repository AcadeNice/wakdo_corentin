/*
 * Garde-fou anti-fuite des collections Postman/Bruno commises dans le depot.
 *
 * MODELE : LISTE BLANCHE, pas liste noire. Toute cle d'un environnement commis
 * doit etre VIDE, a deux exceptions pres :
 *   - `baseUrl` : une URL locale de demo, jamais un secret ;
 *   - `pin_email` : doit valoir EXACTEMENT la reference `{{email}}` (reutilise
 *     l'email de connexion pose par le jury, pas une valeur libre).
 * Toute autre cle non vide committee est un rejet -- que son nom evoque un
 * secret evident (`csrf`, `password*`) ou non (`categoryId`, `run`,
 * `created_*`...). Une liste blanche ne depend pas d'avoir pense a lister
 * d'avance chaque prefixe dangereux : c'est ce qui a change ici par rapport a
 * la premiere version de ce garde-fou (qui ne verifiait que les cles
 * correspondant a un motif connu a l'avance).
 *
 * Sept surfaces verifiees (les deux dernieres ajoutees au contre-audit du
 * 29/09, constat 8) :
 *   1. `docs/api/bruno/environments/*.bru` (bloc(s) `vars { ... }` -- TOUS les
 *      blocs de ce nom dans le fichier, pas seulement le premier) ;
 *   2. `docs/api/wakdo.postman_environment.json` (`values[]`) ;
 *   3. Les variables de COLLECTION Postman (`variable[]`), a la racine et sur
 *      tout dossier/requete -- distinctes du fichier d'environnement ; un
 *      hand-edit peut en ajouter n'importe ou dans l'arbre `item` ;
 *   4. Le corps de chaque requete `.bru` (hors environments/), a N'IMPORTE
 *      QUELLE profondeur d'imbrication (un sous-objet JSON compte) : aucun
 *      champ `password*`/`pin*` ne doit y porter un LITTERAL FIXE (une chaine
 *      sans aucune reference `{{...}}` dedans) -- une collection rejouee sur
 *      une pile jetable ne doit jamais dependre d'une constante qui ressemble
 *      a un identifiant reel ;
 *   5. Les blocs declaratifs `vars:pre-request { ... }` / `vars:post-response
 *      { ... }`, dans N'IMPORTE QUEL fichier `.bru` (environnement ou
 *      requete) -- une syntaxe Bruno alternative a un script JS pour poser une
 *      variable, invisible du bloc `vars { ... }` ordinaire si elle existe ;
 *   6. `baseUrl`, dans les DEUX fichiers d'environnement commis, pointe vers
 *      une adresse LOCALE (`localhost`, `*.localhost` ou `*.test`) -- jamais un
 *      hote de production ou un domaine tiers. `baseUrl` est la seule cle de la
 *      liste blanche autorisee a porter une valeur non vide (point 1 plus haut) ;
 *      cette regle verifie que cette valeur reste elle-meme sans danger, pas
 *      seulement qu'elle est presente ;
 *   7. Le corps de chaque requete `wakdo-admin.postman_collection.json` (JSON
 *      DEJA parse, mode `raw`), a n'importe quelle profondeur d'imbrication :
 *      meme regle que le point 4 mais cote Postman -- aucun champ
 *      `password*`/`pin*` en litteral fixe.
 *
 * Rappel structurel (pourquoi ce garde-fou existe) : `bru run` PERSISTE sur
 * disque toute variable posee par un script via `bru.setEnvVar()` -- meme une
 * cle jamais declaree au depart -- dans le fichier `.bru` lui-meme
 * ("Bruno automatically... persists the change to disk",
 * docs.usebruno.com/testing/script/javascript-reference). Lancer `bru run`
 * directement sur le fichier suivi par git (plutot que sur une COPIE, cf.
 * docs/api/demo-api.md) a deja, une fois, committe de vrais jetons CSRF et des
 * ids de ressources creees dans ce fichier suivi par git : ce garde-fou est le
 * filet qui le detecterait si ca se reproduisait.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import url from 'node:url';

const ROOT = path.resolve(path.dirname(url.fileURLToPath(import.meta.url)), '..', '..');

const ANY_TEMPLATE_REF_RE = /\{\{[a-zA-Z0-9_]+\}\}/;

/**
 * Liste blanche : vrai seulement pour les deux exceptions documentees en tete
 * de fichier. Toute autre cle doit etre vide.
 */
function isAllowedEnvValue(key, value) {
    if (key === 'baseUrl') {
        return true;
    }
    if (key === 'pin_email') {
        return value === '{{email}}';
    }

    return value === '';
}

/**
 * `baseUrl` local uniquement (point 6 de l'en-tete) : `localhost`, un
 * sous-domaine de `localhost` (`admin.localhost`, cf. .env.example
 * APP_HOST_ADMIN), ou un domaine `*.test` (TLD reserve aux tests, RFC 2606) --
 * avec ou sans port explicite, en http ou https. Rejette toute autre valeur
 * (IP publique, domaine de production, domaine tiers) : cette collection est
 * une demo REJOUABLE qui cree/modifie/supprime des ressources reelles (voir la
 * section "Effets de bord" de docs/api/demo-api.md), jamais a pointer sur une
 * base qui n'est pas jetable.
 */
const LOCAL_BASE_URL_RE = /^https?:\/\/([a-z0-9-]+\.)*(localhost|test)(:\d+)?\/?$/i;

function isLocalBaseUrl(value) {
    return LOCAL_BASE_URL_RE.test(value);
}

function assertAllowListed(entries, sourceLabel) {
    for (const { key, value } of entries) {
        assert.ok(
            isAllowedEnvValue(key, value),
            `${sourceLabel} : la cle '${key}' porte une valeur non vide committee ('${value}') -- ` +
            "seules 'baseUrl' (URL locale) et 'pin_email' (doit valoir exactement '{{email}}') " +
            'peuvent etre non vides ; toute autre cle doit rester vide dans le fichier COMMIS ' +
            '(relancer scripts/gen_postman.py puis scripts/gen_bruno.py, et lancer Newman/`bru run` ' +
            'sur une COPIE de l\'environnement -- cf. docs/api/demo-api.md -- jamais sur le fichier suivi par git)',
        );
    }
}

/**
 * Parse une paire cle/valeur Bru (une ligne `cle: valeur`, valeur pouvant etre
 * vide). Cle : lettres/chiffres/underscore/tiret -- un tiret dans un nom de
 * variable est une syntaxe Bruno valide, meme si ce depot n'en genere aucun a
 * ce jour ; le motif le tolere pour ne pas laisser passer une cle pareille
 * sans verification si elle apparaissait un jour.
 */
function parseBruKeyValueLines(blockContent, out) {
    for (const line of blockContent.split('\n')) {
        const m = line.match(/^\s*([A-Za-z0-9_-]+):\s*(.*)$/);
        if (m) {
            out[m[1]] = m[2].trim();
        }
    }
}

/**
 * Parse TOUS les blocs `vars { ... }` d'un fichier .bru (format ecrit par
 * scripts/gen_bruno.py), pas seulement le premier : un fichier .bru autorise
 * plusieurs blocs de meme nom (Bruno les fusionne a l'usage), et un seul
 * `.match()` sans drapeau global ne verrait que le premier -- laissant un
 * second bloc `vars { ... }` entierement hors de la verification. Suffisant
 * pour ce garde-fou -- pas un parseur Bru complet.
 */
function parseBruVars(content) {
    const out = {};
    for (const match of content.matchAll(/vars\s*\{([\s\S]*?)\n\}/g)) {
        parseBruKeyValueLines(match[1], out);
    }

    return out;
}

/**
 * Parse tous les blocs DECLARATIFS `vars:pre-request { ... }` et
 * `vars:post-response { ... }` d'un fichier .bru : une syntaxe Bruno
 * alternative a un script JS (`bru.setEnvVar()`) pour poser une variable AVANT
 * l'envoi ou APRES la reponse -- donc invisible de parseBruVars() ci-dessus si
 * un fichier en contenait un. Aucun generateur de ce depot n'en emet a ce
 * jour ; verifie quand meme, dans N'IMPORTE QUEL fichier .bru (environnement
 * OU requete), pas seulement les environnements.
 */
function parseDeclarativeVarBlocks(content) {
    const out = {};
    for (const blockName of ['vars:pre-request', 'vars:post-response']) {
        const re = new RegExp(`${blockName.replace(':', '\\:')}\\s*\\{([\\s\\S]*?)\\n\\}`, 'g');
        for (const match of content.matchAll(re)) {
            parseBruKeyValueLines(match[1], out);
        }
    }

    return out;
}

/**
 * Extrait et parse le bloc `body { ... }` d'un fichier .bru de REQUETE
 * (distinct de `vars { ... }` : un corps de requete est un blob JSON indente
 * une fois de plus que le reste du fichier .bru, jamais une liste cle/valeur
 * Bru). Renvoie null si la requete n'a pas de corps (GET...) ou si le corps
 * n'est pas du JSON (hors perimetre de ce garde-fou).
 *
 * Repose sur une invariante du generateur (scripts/gen_bruno.py) : le JSON du
 * corps est TOUJOURS indente au moins une fois (`json.dumps(..., indent=2)`
 * imbrique sous le bloc `body {`), donc l'accolade fermante DU JSON n'est
 * jamais seule en debut de ligne -- seule celle du bloc `body { }` Bru
 * lui-meme l'est. `\n}\n` (sans indentation) cible donc sans ambiguite la fin
 * du bloc Bru, pas une fin de sous-objet JSON imbrique.
 */
function parseBruBody(content) {
    const match = content.match(/\nbody\s*\{\n([\s\S]*?)\n\}\n/);
    if (!match) {
        return null;
    }

    try {
        const parsed = JSON.parse(match[1]);

        return typeof parsed === 'object' && parsed !== null ? parsed : null;
    } catch {
        return null;
    }
}

/**
 * Parcourt RECURSIVEMENT une valeur JSON deja parsee (objets et tableaux
 * imbriques a n'importe quelle profondeur), pas seulement ses cles de premier
 * niveau : un sous-objet (`{"user": {"password": "..."}}`) porterait sinon un
 * litteral fixe sans jamais etre vu par un simple `Object.entries(body)`.
 * Accumule dans `out` chaque champ `password*`/`pin*` trouve, avec son chemin
 * pointe (`a.b.c`) pour un message d'erreur exploitable.
 */
function collectSecretLikeJsonFields(value, fieldPath, out) {
    if (Array.isArray(value)) {
        value.forEach((item, index) => collectSecretLikeJsonFields(item, [...fieldPath, String(index)], out));

        return;
    }

    if (value === null || typeof value !== 'object') {
        return;
    }

    for (const [key, child] of Object.entries(value)) {
        const nextPath = [...fieldPath, key];
        if (/^(password.*|pin.*)$/i.test(key) && typeof child === 'string') {
            out.push({ fieldPath: nextPath.join('.'), value: child });
        }
        collectSecretLikeJsonFields(child, nextPath, out);
    }
}

function walk(dir) {
    const results = [];
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            results.push(...walk(full));
        } else {
            results.push(full);
        }
    }

    return results;
}

function findBruEnvironmentFiles(dir) {
    return walk(dir).filter(
        (f) => f.endsWith('.bru') && path.basename(path.dirname(f)) === 'environments',
    );
}

function findBruRequestFiles(dir) {
    return walk(dir).filter(
        (f) => f.endsWith('.bru')
            && path.basename(path.dirname(f)) !== 'environments'
            && path.basename(f) !== 'folder.bru',
    );
}

/**
 * Parcourt recursivement l'arbre `item` d'une collection Postman et rassemble
 * tout tableau `variable[]` rencontre -- a la racine de la collection, sur un
 * dossier, ou sur une requete individuelle. Postman autorise ce champ a
 * n'importe quel niveau de l'arbre ; un hand-edit peut en ajouter n'importe ou.
 */
function collectPostmanVariableArrays(node, pathLabel, out) {
    if (Array.isArray(node.variable) && node.variable.length > 0) {
        out.push({ label: pathLabel, entries: node.variable });
    }

    if (Array.isArray(node.item)) {
        for (const child of node.item) {
            collectPostmanVariableArrays(child, `${pathLabel} > ${child.name ?? '?'}`, out);
        }
    }
}

/**
 * Parcourt recursivement l'arbre `item` d'une collection Postman et rassemble
 * le corps JSON (mode `raw`) de chaque requete feuille, deja PARSE (le motif
 * de secret cote Postman, contrairement au `.bru` -- point 7 de l'en-tete).
 * `request.body.raw` porte des references `{{run}}` etc. que `JSON.parse` ne
 * comprend pas nativement, mais elles restent a l'INTERIEUR d'une chaine JSON
 * valide (`"Demo {{run}}"`) : le parse reussit tel quel, aucune substitution
 * n'est necessaire pour ce garde-fou (on cherche un litteral FIXE, une chaine
 * SANS aucune reference `{{...}}` dedans, cf. collectSecretLikeJsonFields).
 */
function collectPostmanRequestBodies(node, pathLabel, out) {
    if (node.request && node.request.body && node.request.body.mode === 'raw' && typeof node.request.body.raw === 'string') {
        try {
            const parsed = JSON.parse(node.request.body.raw);
            if (typeof parsed === 'object' && parsed !== null) {
                out.push({ label: pathLabel, body: parsed });
            }
        } catch {
            // Corps non-JSON (aucun a ce jour dans cette collection) : hors perimetre.
        }
    }

    if (Array.isArray(node.item)) {
        for (const child of node.item) {
            collectPostmanRequestBodies(child, `${pathLabel} > ${child.name ?? '?'}`, out);
        }
    }
}

test("aucun fichier environments/*.bru de la collection Bruno ne committe une valeur hors liste blanche", () => {
    const bruDir = path.join(ROOT, 'docs', 'api', 'bruno');
    const files = findBruEnvironmentFiles(bruDir);
    assert.ok(files.length > 0, `aucun fichier environments/*.bru trouve sous ${bruDir} -- ce garde-fou ne verifie plus rien, corriger le chemin`);

    for (const file of files) {
        const vars = parseBruVars(fs.readFileSync(file, 'utf8'));
        assert.ok(Object.keys(vars).length > 0, `${path.relative(ROOT, file)} : aucune variable trouvee -- le parseur ou le format a change, corriger ce garde-fou`);
        assertAllowListed(
            Object.entries(vars).map(([key, value]) => ({ key, value })),
            path.relative(ROOT, file),
        );
    }
});

test("l'environnement Postman ne committe pas de valeur hors liste blanche", () => {
    const file = path.join(ROOT, 'docs', 'api', 'wakdo.postman_environment.json');
    const env = JSON.parse(fs.readFileSync(file, 'utf8'));
    assert.ok(Array.isArray(env.values) && env.values.length > 0, `${path.relative(ROOT, file)} : structure inattendue (pas de 'values')`);

    assertAllowListed(
        env.values.map((entry) => ({ key: entry.key, value: String(entry.value ?? '') })),
        path.relative(ROOT, file),
    );
});

test('aucune variable de COLLECTION Postman (racine, dossier ou requete) ne committe une valeur hors liste blanche', () => {
    const file = path.join(ROOT, 'docs', 'api', 'wakdo-admin.postman_collection.json');
    const collection = JSON.parse(fs.readFileSync(file, 'utf8'));

    const found = [];
    collectPostmanVariableArrays(collection, path.relative(ROOT, file), found);

    // Un resultat VIDE est legitime ici (cette collection ne definit
    // aujourd'hui aucune variable de collection, seulement un fichier
    // d'environnement separe) : ce test protege contre une regression future
    // (un hand-edit qui en ajouterait une avec une valeur committee), il
    // n'exige pas que le tableau existe deja -- contrairement aux deux tests
    // precedents, ou l'ABSENCE de variables serait elle-meme une anomalie.
    for (const { label, entries } of found) {
        assertAllowListed(
            entries.map((entry) => ({ key: entry.key, value: String(entry.value ?? '') })),
            label,
        );
    }
});

test('aucun corps de requete .bru (a quelque profondeur que ce soit) ne committe un mot de passe ou un PIN en litteral fixe', () => {
    const bruDir = path.join(ROOT, 'docs', 'api', 'bruno');
    const files = findBruRequestFiles(bruDir);
    assert.ok(files.length > 0, `aucun fichier de requete .bru trouve sous ${bruDir} -- ce garde-fou ne verifie plus rien, corriger le chemin`);

    let checked = 0;
    for (const file of files) {
        const body = parseBruBody(fs.readFileSync(file, 'utf8'));
        if (body === null) {
            continue;
        }

        const found = [];
        collectSecretLikeJsonFields(body, [], found);

        for (const { fieldPath, value } of found) {
            checked += 1;
            const str = String(value ?? '');
            const safe = str === '' || ANY_TEMPLATE_REF_RE.test(str);
            assert.ok(
                safe,
                `${path.relative(ROOT, file)} : le champ '${fieldPath}' du corps de requete porte le litteral fixe ` +
                `'${str}' -- aucune reference '{{...}}' dedans (ex. '{{run}}' pour varier a chaque execution) : ` +
                'remplacer par une valeur templatee, jamais une constante qui ressemble a un identifiant reel',
            );
        }
    }

    assert.ok(checked > 0, `aucun champ password*/pin* trouve dans un corps de requete sous ${bruDir} -- ce garde-fou ne verifie plus rien, corriger le motif ou le chemin`);
});

test('aucun bloc declaratif vars:pre-request / vars:post-response, dans un fichier .bru quelconque, ne committe une valeur hors liste blanche', () => {
    const bruDir = path.join(ROOT, 'docs', 'api', 'bruno');
    const files = walk(bruDir).filter((f) => f.endsWith('.bru'));
    assert.ok(files.length > 0, `aucun fichier .bru trouve sous ${bruDir} -- ce garde-fou ne verifie plus rien, corriger le chemin`);

    for (const file of files) {
        const declared = parseDeclarativeVarBlocks(fs.readFileSync(file, 'utf8'));
        if (Object.keys(declared).length === 0) {
            continue;
        }

        assertAllowListed(
            Object.entries(declared).map(([key, value]) => ({ key, value })),
            path.relative(ROOT, file),
        );
    }
});

test("baseUrl de l'environnement Postman pointe vers une adresse locale (localhost / *.localhost / *.test)", () => {
    const file = path.join(ROOT, 'docs', 'api', 'wakdo.postman_environment.json');
    const env = JSON.parse(fs.readFileSync(file, 'utf8'));
    const entry = (env.values ?? []).find((v) => v.key === 'baseUrl');
    assert.ok(entry, `${path.relative(ROOT, file)} : aucune cle 'baseUrl' trouvee`);
    assert.ok(
        isLocalBaseUrl(String(entry.value ?? '')),
        `${path.relative(ROOT, file)} : baseUrl='${entry.value}' ne pointe pas vers une adresse locale ` +
        "(localhost, *.localhost ou *.test) -- cette collection cree/modifie/supprime des ressources " +
        'reelles (section "Effets de bord" de docs/api/demo-api.md), jamais a pointer sur une base non jetable',
    );
});

test("baseUrl de l'environnement Bruno pointe vers une adresse locale (localhost / *.localhost / *.test)", () => {
    const bruDir = path.join(ROOT, 'docs', 'api', 'bruno');
    const files = findBruEnvironmentFiles(bruDir);
    assert.ok(files.length > 0, `aucun fichier environments/*.bru trouve sous ${bruDir} -- ce garde-fou ne verifie plus rien, corriger le chemin`);

    for (const file of files) {
        const vars = parseBruVars(fs.readFileSync(file, 'utf8'));
        assert.ok(
            Object.prototype.hasOwnProperty.call(vars, 'baseUrl'),
            `${path.relative(ROOT, file)} : aucune cle 'baseUrl' trouvee`,
        );
        assert.ok(
            isLocalBaseUrl(vars.baseUrl),
            `${path.relative(ROOT, file)} : baseUrl='${vars.baseUrl}' ne pointe pas vers une adresse locale ` +
            '(localhost, *.localhost ou *.test) -- meme regle que pour docs/api/wakdo.postman_environment.json',
        );
    }
});

test('aucun corps de requete Postman (JSON brut, a quelque profondeur que ce soit) ne committe un mot de passe ou un PIN en litteral fixe', () => {
    const file = path.join(ROOT, 'docs', 'api', 'wakdo-admin.postman_collection.json');
    const collection = JSON.parse(fs.readFileSync(file, 'utf8'));

    const bodies = [];
    collectPostmanRequestBodies(collection, path.relative(ROOT, file), bodies);
    assert.ok(bodies.length > 0, `${path.relative(ROOT, file)} : aucun corps de requete JSON trouve -- ce garde-fou ne verifie plus rien, corriger le chemin`);

    let checked = 0;
    for (const { label, body } of bodies) {
        const found = [];
        collectSecretLikeJsonFields(body, [], found);

        for (const { fieldPath, value } of found) {
            checked += 1;
            const str = String(value ?? '');
            const safe = str === '' || ANY_TEMPLATE_REF_RE.test(str);
            assert.ok(
                safe,
                `${label} : le champ '${fieldPath}' du corps de requete porte le litteral fixe '${str}' -- ` +
                "aucune reference '{{...}}' dedans (ex. '{{run}}' pour varier a chaque execution) : remplacer " +
                'par une valeur templatee, jamais une constante qui ressemble a un identifiant reel',
            );
        }
    }

    assert.ok(checked > 0, `aucun champ password*/pin* trouve dans un corps de requete sous ${path.relative(ROOT, file)} -- ce garde-fou ne verifie plus rien, corriger le motif ou le chemin`);
});
