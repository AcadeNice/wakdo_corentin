/*
 * Tests du controle de saisie en temps reel des formulaires du back-office
 * (Cr 2.b.1, node:test + jsdom).
 *
 * Couvre : les messages en francais par type d'ecart (obligatoire, e-mail, bornes,
 * entier, motif, longueur, confirmation), l'affichage pendant la frappe relie au
 * champ (aria-invalid, aria-describedby), le blocage de l'envoi d'un formulaire
 * invalide avant tout autre script, et la cohabitation avec le modal PIN (champs
 * masques ignores). Le serveur reste juge final : ce fichier ne fait qu anticiper.
 */
import { test, mock } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';

// form-validation.js est du CommonJS (admin = racine CommonJS) ; import par defaut.
import formValidation from '../../src/public/admin/assets/js/form-validation.js';

function setup(formHtml, options = { delay: 0, hideDelay: 0 }) {
    const dom = new JSDOM('<!DOCTYPE html><html><body>' + formHtml + '</body></html>');
    const doc = dom.window.document;
    formValidation.init(doc, options);
    return { dom, doc };
}

/** Simule l'etat d'un champ nombre dont le navigateur a rejete la saisie brute
 *  (« 33e ») : value vaut '' et validity.badInput vaut true. jsdom ne produit pas
 *  cet etat tout seul, d'ou la surcharge de validity sur l'element. */
function simulateBadInput(field) {
    Object.defineProperty(field, 'validity', { configurable: true, value: { badInput: true, valid: false } });
    field.value = '';
}

function type(dom, field, value) {
    field.value = value;
    field.dispatchEvent(new dom.window.Event('input', { bubbles: true }));
}

function leave(dom, field) {
    // Comme un navigateur : blur sur le champ, puis focusout qui remonte au formulaire.
    field.dispatchEvent(new dom.window.Event('blur'));
    field.dispatchEvent(new dom.window.Event('focusout', { bubbles: true }));
}

function press(dom, doc, type) {
    doc.dispatchEvent(new dom.window.Event(type, { bubbles: true }));
}

function submit(dom, form) {
    const event = new dom.window.Event('submit', { bubbles: true, cancelable: true });
    form.dispatchEvent(event);
    return event;
}

function errorOf(doc, field) {
    const id = (field.getAttribute('aria-describedby') || '').split(/\s+/).find((token) => token.endsWith('-live-error'));
    return id ? doc.getElementById(id) : null;
}

const FORM = '<form id="f" method="post" action="/admin/products">' +
    '<div class="form-group"><label for="name">Nom</label>' +
    '  <input id="name" name="name" type="text" maxlength="10" required></div>' +
    '<div class="form-group"><label for="email">E-mail</label>' +
    '  <input id="email" name="email" type="email"></div>' +
    '<div class="form-group"><label for="price">Prix</label>' +
    '  <input id="price" name="price" type="number" min="1" max="500" step="1"></div>' +
    '<div class="form-group"><label for="slug">Slug</label>' +
    '  <input id="slug" name="slug" type="text" pattern="[a-z0-9]+(?:-[a-z0-9]+)*"' +
    '         data-pattern-message="Minuscules, chiffres et tirets uniquement."></div>' +
    '<div class="form-group"><label for="pwd">Mot de passe</label>' +
    '  <input id="pwd" name="pwd" type="password" minlength="8"></div>' +
    '<div class="form-group"><label for="pwd2">Confirmation</label>' +
    '  <input id="pwd2" name="pwd2" type="password" data-match="pwd"></div>' +
    '<button type="submit">Enregistrer</button></form>';

test('messageFor donne un message en francais pour chaque type d ecart', () => {
    const { doc } = setup(FORM);
    const field = (id) => doc.getElementById(id);

    field('name').value = '';
    assert.equal(formValidation.messageFor(field('name')), 'Ce champ est obligatoire.');
    field('name').value = 'Big Tasty';
    assert.equal(formValidation.messageFor(field('name')), '');

    field('email').value = 'corentin';
    assert.match(formValidation.messageFor(field('email')), /^Adresse e-mail invalide/);
    field('email').value = 'corentin@acadenice.fr';
    assert.equal(formValidation.messageFor(field('email')), '');

    field('price').value = '0';
    assert.equal(formValidation.messageFor(field('price')), 'La valeur doit être supérieure ou égale à 1.');
    field('price').value = '501';
    assert.equal(formValidation.messageFor(field('price')), 'La valeur doit être inférieure ou égale à 500.');
    field('price').value = '12.5';
    assert.equal(formValidation.messageFor(field('price')), 'Un nombre entier est attendu.');

    field('slug').value = 'Menu Maxi';
    assert.equal(formValidation.messageFor(field('slug')), 'Minuscules, chiffres et tirets uniquement.');

    // minlength est controle par le fichier lui-meme : le navigateur ne le signale
    // qu'apres une frappe reelle, jamais sur une valeur posee par script.
    field('pwd').value = 'abc';
    assert.equal(formValidation.messageFor(field('pwd')), '8 caractères minimum (3 saisis).');

    field('pwd').value = 'motdepasse';
    field('pwd2').value = 'motdepass';
    assert.equal(formValidation.messageFor(field('pwd2')), 'Les deux saisies ne correspondent pas.');
});

test('une saisie numerique mal formee est signalee au lieu de partir vide', () => {
    // Regression evitee (relecture du 2026-09-23) : « 33e » donne value='' ; sans ce
    // controle, un champ facultatif (taille en cl) partait vide et le serveur
    // effacait la valeur sans message.
    const { dom, doc } = setup(
        '<form id="f" method="post" action="/admin/products/3/edit">' +
        '<input id="size_cl" name="size_cl" type="number" min="0" max="65535">' +
        '<button type="submit">Enregistrer</button></form>',
    );
    const size = doc.getElementById('size_cl');
    simulateBadInput(size);

    assert.equal(formValidation.messageFor(size), 'Saisissez un nombre.');
    size.dispatchEvent(new dom.window.Event('input', { bubbles: true }));
    assert.equal(size.getAttribute('aria-invalid'), 'true', 'signale pendant la frappe');
    assert.equal(submit(dom, doc.getElementById('f')).defaultPrevented, true, 'envoi bloque');
});

test('un entier ecrit en notation scientifique ou decimale est refuse, comme par le serveur', () => {
    // ctype_digit('1e3') est faux cote serveur ; le navigateur, lui, accepte « 1e3 »
    // comme nombre valide : le controle doit s'aligner sur le serveur.
    const { doc } = setup(
        '<form><input id="packs" name="packs" type="number" min="1" max="65535"></form>' +
        '<form><input id="delta" name="delta" type="number" step="1" min="-2147483647" max="2147483647"></form>',
    );
    const packs = doc.getElementById('packs');
    const delta = doc.getElementById('delta');

    packs.value = '1e3';
    assert.equal(formValidation.messageFor(packs), 'Un nombre entier est attendu.');
    packs.value = '1000';
    assert.equal(formValidation.messageFor(packs), '');
    delta.value = '-3';
    assert.equal(formValidation.messageFor(delta), '', 'le signe moins reste permis quand min est negatif');
});

test('les espaces en bord de saisie sont ignores, comme le trim du serveur', () => {
    const { doc } = setup(
        '<form><input id="slug" name="slug" type="text" required pattern="[a-z0-9]+(?:-[a-z0-9]+)*">' +
        '<input id="nom" name="nom" type="text" required maxlength="3"></form>',
    );
    const slug = doc.getElementById('slug');
    const nom = doc.getElementById('nom');

    slug.value = '  petites-faims ';
    assert.equal(formValidation.messageFor(slug), '', 'motif controle sur la valeur nettoyee');
    slug.value = '   ';
    assert.equal(formValidation.messageFor(slug), 'Ce champ est obligatoire.', 'que des espaces = vide');
    // Longueur comptee en caracteres, comme mb_strlen cote serveur (un emoji = 1).
    nom.value = ' ab\u{1F354} ';
    assert.equal(formValidation.messageFor(nom), '');
});

test('chaque champ sans id ni name a son propre message', () => {
    // Lignes de recette (product-recipe.js) : champs generes sans id ni name.
    const { dom, doc } = setup(
        '<form><input class="q" type="number" min="1"><input class="q" type="number" min="1"></form>',
    );
    const [first, second] = doc.querySelectorAll('input.q');

    type(dom, first, '0');
    type(dom, second, '2');

    assert.notEqual(errorOf(doc, first), errorOf(doc, second));
    assert.equal(errorOf(doc, first).textContent, 'La valeur doit être supérieure ou égale à 1.');
    assert.equal(errorOf(doc, first).hidden, false, 'le champ valide suivant n efface pas ce message');
});

test('le message d un champ corrige ne disparait pas pendant le clic qui suit', () => {
    // Le clic sur « Enregistrer » fait perdre le focus au champ : masquer son message
    // a cet instant remontait le bouton de 43 px sous le pointeur et le clic etait
    // perdu (mesure du 2026-09-23). Le masquage attend donc la fin du clic.
    mock.timers.enable({ apis: ['setTimeout'] });
    try {
        const { dom, doc } = setup(FORM, { delay: 0, hideDelay: 200 });
        const email = doc.getElementById('email');
        type(dom, email, 'corentin@');
        const error = errorOf(doc, email);
        assert.equal(error.hidden, false);

        email.value = 'corentin@acadenice.fr';
        leave(dom, email);
        assert.equal(error.hidden, false, 'toujours affiche pendant le clic');
        assert.equal(email.getAttribute('aria-invalid'), 'false', 'le champ est deja annonce valide');

        mock.timers.tick(200);
        assert.equal(error.hidden, true);
    } finally {
        mock.timers.reset();
    }
});

test('reinitialiser le formulaire efface les messages (fenetre des seuils rouverte)', () => {
    const { dom, doc } = setup(FORM);
    const price = doc.getElementById('price');
    type(dom, price, '0');
    assert.equal(price.getAttribute('aria-invalid'), 'true');

    doc.getElementById('f').reset();

    assert.equal(errorOf(doc, price).hidden, true);
    assert.equal(price.hasAttribute('aria-invalid'), false);
});

test('la zone de message existe des l initialisation, pour etre annoncee a la premiere erreur', () => {
    const { doc } = setup(FORM);
    const email = doc.getElementById('email');
    const error = errorOf(doc, email);

    assert.ok(error, 'reliee au champ avant toute saisie');
    assert.equal(error.hidden, true);
    assert.equal(error.getAttribute('aria-live'), 'polite');
});

test('un champ ajoute apres le chargement est controle pendant la saisie', () => {
    // Lignes de recette ajoutees par product-recipe.js apres l'initialisation.
    const { dom, doc } = setup('<form id="f"><input id="nom" name="nom" required></form>');
    const added = doc.createElement('input');
    added.type = 'number';
    added.min = '1';
    doc.getElementById('f').appendChild(added);

    type(dom, added, '0');

    assert.equal(added.getAttribute('aria-invalid'), 'true');
    assert.equal(errorOf(doc, added).textContent, 'La valeur doit être supérieure ou égale à 1.');
});

test('un appui long sur le bouton garde le message jusqu au relachement', () => {
    // Le masquage attend la fin de l'appui (pointerup), quelle que soit sa duree :
    // un delai fixe perdait encore le clic au-dela de 200 ms (relecture du 2026-09-23).
    mock.timers.enable({ apis: ['setTimeout'] });
    try {
        const { dom, doc } = setup(FORM, { delay: 0, hideDelay: 200 });
        const email = doc.getElementById('email');
        type(dom, email, 'corentin@');
        const error = errorOf(doc, email);

        email.value = 'corentin@acadenice.fr';
        press(dom, doc, 'pointerdown');
        leave(dom, email);
        mock.timers.tick(1000);
        assert.equal(error.hidden, false, 'toujours affiche tant que le bouton est enfonce');

        press(dom, doc, 'pointerup');
        mock.timers.tick(1);
        assert.equal(error.hidden, true, 'masque juste apres le relachement');
    } finally {
        mock.timers.reset();
    }
});

test('la zone de message sort du label qui englobe le champ', () => {
    // Un <p> dans un <label> est invalide et s'ajouterait au nom du champ.
    const { doc } = setup('<form><label>Quantite <input id="q" type="number" min="1"></label></form>');
    const field = doc.getElementById('q');
    const error = errorOf(doc, field);

    assert.equal(error.closest('label'), null);
    assert.equal(error.previousElementSibling, field.closest('label'));
});

test('le signe moins n est accepte que si le champ admet des valeurs negatives', () => {
    // ctype_digit('-0') est faux cote serveur : un champ a minimum positif ou nul
    // n'accepte que des chiffres.
    const { doc } = setup(
        '<form><input id="stock" type="number" min="0"><input id="delta" type="number" step="1" min="-5" max="5"></form>',
    );
    const stock = doc.getElementById('stock');
    const delta = doc.getElementById('delta');

    stock.value = '-0';
    assert.equal(formValidation.messageFor(stock), 'Un nombre entier est attendu.');
    delta.value = '-3';
    assert.equal(formValidation.messageFor(delta), '');
});

test('data-not-zero refuse 0 avec le message du champ (regle metier sans equivalent HTML)', () => {
    const { doc } = setup(
        '<form method="post" action="/admin/ingredients/3/adjust">' +
        '<input id="delta" name="delta" type="number" step="1" required' +
        ' data-not-zero="Un ajustement de 0 ne change rien : saisissez 5 pour ajouter, -3 pour retirer.">' +
        '</form>',
    );
    const delta = doc.getElementById('delta');

    delta.value = '0';
    assert.equal(formValidation.messageFor(delta), 'Un ajustement de 0 ne change rien : saisissez 5 pour ajouter, -3 pour retirer.');
    delta.value = '-3';
    assert.equal(formValidation.messageFor(delta), '');
});

test('une saisie invalide est signalee pendant la frappe puis effacee une fois corrigee', () => {
    const { dom, doc } = setup(FORM);
    const email = doc.getElementById('email');

    type(dom, email, 'corentin@');
    const error = errorOf(doc, email);
    assert.ok(error, 'message relie au champ par aria-describedby');
    assert.equal(error.hidden, false);
    assert.match(error.textContent, /^Adresse e-mail invalide/);
    assert.equal(email.getAttribute('aria-invalid'), 'true');

    type(dom, email, 'corentin@acadenice.fr');
    assert.equal(error.hidden, true);
    assert.equal(email.getAttribute('aria-invalid'), 'false');
});

test('un champ obligatoire vide n est signale qu en quittant le champ', () => {
    const { dom, doc } = setup(FORM);
    const name = doc.getElementById('name');

    type(dom, name, '');
    assert.notEqual(name.getAttribute('aria-invalid'), 'true', 'pas de reproche pendant la frappe');

    leave(dom, name);
    assert.equal(name.getAttribute('aria-invalid'), 'true');
    assert.equal(errorOf(doc, name).textContent, 'Ce champ est obligatoire.');
});

test('la confirmation est recontrolee quand le champ d origine change', () => {
    const { dom, doc } = setup(FORM);
    const pwd = doc.getElementById('pwd');
    const pwd2 = doc.getElementById('pwd2');

    type(dom, pwd, 'motdepasse');
    type(dom, pwd2, 'motdepasse');
    assert.equal(pwd2.getAttribute('aria-invalid'), 'false');

    type(dom, pwd, 'autre-mot-de-passe');
    assert.equal(pwd2.getAttribute('aria-invalid'), 'true');
    assert.equal(errorOf(doc, pwd2).textContent, 'Les deux saisies ne correspondent pas.');
});

test('l envoi d un formulaire invalide est bloque avant tout autre script, focus sur le premier ecart', () => {
    const { dom, doc } = setup(FORM);
    const form = doc.getElementById('f');
    let otherHandlerRan = false;
    form.addEventListener('submit', () => { otherHandlerRan = true; });

    doc.getElementById('price').value = '0';
    const event = submit(dom, form);

    assert.equal(event.defaultPrevented, true);
    assert.equal(otherHandlerRan, false, 'le modal PIN ne doit pas s ouvrir sur un formulaire invalide');
    assert.equal(doc.activeElement, doc.getElementById('name'), 'focus sur le premier champ en erreur');
    assert.equal(doc.getElementById('price').getAttribute('aria-invalid'), 'true');
});

test('un formulaire valide part normalement et laisse les autres scripts agir', () => {
    const { dom, doc } = setup(FORM);
    const form = doc.getElementById('f');
    let otherHandlerRan = false;
    form.addEventListener('submit', () => { otherHandlerRan = true; });

    doc.getElementById('name').value = 'Big Tasty';
    const event = submit(dom, form);

    assert.equal(event.defaultPrevented, false);
    assert.equal(otherHandlerRan, true);
});

test('les champs masques (confirmation PIN geree par le modal) ne bloquent pas l envoi', () => {
    const { dom, doc } = setup(
        '<form id="f" method="post" action="/admin/ingredients/3/adjust">' +
        '<input id="delta" name="delta" type="number" step="1" required>' +
        '<fieldset hidden><input id="pin_email" name="pin_email" type="email" required>' +
        '<input id="pin" name="pin" type="password" required></fieldset>' +
        '<button type="submit">Enregistrer</button></form>',
    );
    doc.getElementById('delta').value = '5';

    assert.equal(submit(dom, doc.getElementById('f')).defaultPrevented, false);
});

test('le message serveur d un champ disparait des que la saisie change', () => {
    const { dom, doc } = setup(
        '<form method="post" action="/admin/categories"><div class="form-group">' +
        '<input id="name" name="name" type="text" required value="Burgers">' +
        '<p class="form-error" id="server-error">Ce nom est deja utilise.</p>' +
        '</div></form>',
    );

    type(dom, doc.getElementById('name'), 'Burgers maison');

    assert.equal(doc.getElementById('server-error').hidden, true);
});

test('init ne gere que les formulaires sans validation propre et remplace les bulles du navigateur', () => {
    const { doc } = setup(
        FORM +
        '<form id="own" novalidate><input id="own-field" required></form>' +
        '<form id="off" data-live-validate="off"><input id="off-field" required></form>',
    );

    assert.equal(doc.getElementById('f').noValidate, true, 'messages du module a la place des bulles natives');
    assert.equal(doc.getElementById('f').dataset.liveValidate, 'on');
    assert.equal(doc.getElementById('own').dataset.liveValidate, undefined);
    assert.equal(doc.getElementById('off').dataset.liveValidate, 'off');
});
