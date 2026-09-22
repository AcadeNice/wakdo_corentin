/*
 * allergens.js — Modale allergenes PAR PRODUIT (front borne).
 *
 * Information reglementaire (UE INCO 1169/2011) presentee au client. Jusqu'a F11b
 * cette modale listait les 14 categories, les memes pour tous les produits : une
 * information vraie mais inutile, puisqu'elle ne disait rien du produit consulte.
 * Elle affiche desormais les allergenes REELLEMENT portes par la recette, calcules
 * cote serveur (product_ingredient -> ingredient_allergen -> allergen).
 *
 * Trois etats, jamais confondus — c'est le coeur du module :
 *   1. revu, avec allergenes -> on les nomme ;
 *   2. revu, sans allergene  -> on AFFIRME l'absence ;
 *   3. non revu              -> on n'affirme rien, on renvoie vers l'equipe.
 * Traiter 3 comme 2 reviendrait a annoncer "sans gluten" a quelqu'un a qui personne
 * n'a rien verifie. Le drapeau `allergenesComplets` porte cette distinction ; il
 * vient de allergens_complete cote API, lui-meme derive de
 * ingredient.allergens_reviewed_at.
 *
 * L'avertissement de traces est present dans les TROIS etats : une cuisine de
 * restauration rapide manipule tous ces allergenes sur le meme plan de travail, et
 * une absence a la recette n'est pas une absence dans l'assiette.
 *
 * CSP 'self' : aucun script inline, aucun handler inline. Le DOM est construit par
 * l'API (createElement/textContent) ; textContent neutralise toute injection. La
 * modale recoit ses donnees en parametres pour rester independante de la couche de
 * chargement (et testable sans fetch).
 */

const OVERLAY_CLASS = 'allergen-modal-overlay';

const TRACES_NOTICE = 'Nos plats sont prepares dans une cuisine ou les 14 allergenes '
    + 'sont manipules : une presence accidentelle par traces n est pas exclue.';

const INCOMPLETE_NOTICE = 'Nous n avons pas encore verifie tous les ingredients de ce '
    + 'produit. La liste ci-dessus peut etre incomplete : demandez a l equipe avant de '
    + 'commander.';

const UNKNOWN_NOTICE = 'Information non disponible pour ce produit : demandez a l equipe '
    + 'avant de commander.';

const NONE_NOTICE = 'Verifie : ce produit ne contient aucun des 14 allergenes a '
    + 'declaration obligatoire.';

/* Reference stable du handler clavier pour pouvoir le retirer a la fermeture. */
function onKeydown(event) {
    if (event.key === 'Escape') {
        closeAllergenModal();
    }
}

/**
 * Construit le bouton "i" qui ouvre la modale. `onOpen` est appele au clic ;
 * la propagation est stoppee pour ne pas declencher le clic de la carte produit
 * (sur la carte, le bouton est superpose a une zone cliquable).
 * @param {() => void} onOpen
 * @returns {HTMLButtonElement}
 */
export function buildAllergenInfoButton(onOpen) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'allergen-info-btn';
    btn.setAttribute('aria-label', 'Informations allergenes');
    btn.title = 'Informations allergenes';
    btn.textContent = 'i';
    btn.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        if (typeof onOpen === 'function') {
            onOpen();
        }
    });
    return btn;
}

/**
 * Index id -> description depuis la reference INCO. La description explique un
 * allergene ("Ble, seigle, orge...") ; elle n'est PAS repetee sur chaque ligne de
 * l'API produit, qui ne porte que id/code/name.
 * @param {Array<{id:number, description?:string}>} reference
 * @returns {Object<number, string>}
 */
function describeById(reference) {
    const byId = {};
    for (const entry of Array.isArray(reference) ? reference : []) {
        if (entry && entry.description) byId[entry.id] = String(entry.description);
    }
    return byId;
}

/**
 * Ouvre la modale allergenes du produit consulte. Idempotent : une eventuelle
 * modale ouverte est d'abord fermee (pas de doublon empile).
 *
 * @param {{nom?:string, allergenes?:Array<{id:number, name:string}>, allergenesComplets?:boolean}} product
 *        produit a la forme borne. Un produit sans champ `allergenesComplets` est
 *        traite comme NON revu : par defaut on n'affirme rien.
 * @param {Array<{id:number, name:string, description?:string}>} reference
 *        les 14 categories INCO (descriptions). Peut etre vide : le nom de
 *        l'allergene vient de l'API produit, l'information vitale passe quand meme.
 * @returns {HTMLElement} l'overlay cree
 */
export function openProductAllergenModal(product, reference) {
    closeAllergenModal();

    const item = product && typeof product === 'object' ? product : {};
    const allergens = Array.isArray(item.allergenes) ? item.allergenes : [];
    // Defaut PRUDENT : l'absence du drapeau vaut "non revu", jamais "revu et vide".
    const complete = item.allergenesComplets === true;
    const descriptions = describeById(reference);

    const overlay = document.createElement('div');
    overlay.className = OVERLAY_CLASS;
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', 'Informations allergenes');

    const modal = document.createElement('div');
    modal.className = 'allergen-modal';

    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'allergen-modal-close';
    closeBtn.setAttribute('aria-label', 'Fermer');
    closeBtn.textContent = 'x';
    closeBtn.addEventListener('click', closeAllergenModal);
    modal.appendChild(closeBtn);

    const title = document.createElement('h2');
    title.className = 'allergen-modal-title';
    const name = String(item.nom ?? '').trim();
    title.textContent = name === '' ? 'Allergenes' : 'Allergenes - ' + name;
    modal.appendChild(title);

    if (allergens.length > 0) {
        const intro = document.createElement('p');
        intro.className = 'allergen-modal-intro';
        intro.textContent = 'Ce produit contient :';
        modal.appendChild(intro);

        const ul = document.createElement('ul');
        ul.className = 'allergen-modal-list';
        for (const allergen of allergens) {
            const li = document.createElement('li');

            const label = document.createElement('span');
            label.className = 'allergen-name';
            label.textContent = String(allergen?.name ?? '');
            li.appendChild(label);

            const description = descriptions[allergen?.id];
            if (description) {
                const desc = document.createElement('span');
                desc.className = 'allergen-desc';
                desc.textContent = ' - ' + description;
                li.appendChild(desc);
            }

            ul.appendChild(li);
        }
        modal.appendChild(ul);
    } else if (complete) {
        // Etat 2 : une absence VERIFIEE, donc affirmee. C'est le seul cas ou la borne
        // a le droit de dire "aucun".
        const none = document.createElement('p');
        none.className = 'allergen-modal-none';
        none.textContent = NONE_NOTICE;
        modal.appendChild(none);
    }

    if (!complete) {
        // Etat 3 : on n'affirme rien. role=alert pour que le lecteur d'ecran l'annonce
        // sans attendre que le client parcoure le panneau.
        const incomplete = document.createElement('p');
        incomplete.className = 'allergen-modal-incomplete';
        incomplete.setAttribute('role', 'alert');
        incomplete.textContent = allergens.length > 0 ? INCOMPLETE_NOTICE : UNKNOWN_NOTICE;
        modal.appendChild(incomplete);
    }

    const traces = document.createElement('p');
    traces.className = 'allergen-modal-traces';
    traces.textContent = TRACES_NOTICE;
    modal.appendChild(traces);

    overlay.appendChild(modal);

    // Clic sur le fond (hors du panneau) = fermeture ; clic dans le panneau, non.
    overlay.addEventListener('click', (event) => {
        if (event.target === overlay) {
            closeAllergenModal();
        }
    });

    document.addEventListener('keydown', onKeydown);
    document.body.appendChild(overlay);

    return overlay;
}

/**
 * Ferme la modale si elle est ouverte et retire le handler clavier. Sans effet
 * si aucune modale n'est ouverte (sur appel ou Echap repete).
 */
export function closeAllergenModal() {
    const existing = document.querySelector('.' + OVERLAY_CLASS);
    if (existing && existing.parentNode) {
        existing.parentNode.removeChild(existing);
    }
    document.removeEventListener('keydown', onKeydown);
}
