/*
 * allergens.js — Modale GENERALE d'information allergenes (front borne).
 *
 * Information reglementaire (UE INCO 1169/2011) presentee au client : la liste
 * des 14 allergenes a declaration obligatoire. C'est une info GENERALE (pas un
 * calcul par produit) ; le mapping ingredient_allergen par produit reste differe.
 *
 * CSP 'self' : aucun script inline, aucun handler inline. Le DOM est construit par
 * l'API (createElement/textContent) ; textContent neutralise toute injection.
 * Les donnees viennent de data.js (loadAllergens) : liste fixe en P5, /api/allergens
 * au swap P4. openAllergenModal prend la liste en parametre pour rester independant
 * de la couche de chargement (et testable sans fetch).
 */

const OVERLAY_CLASS = 'allergen-modal-overlay';

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
 * Ouvre la modale generale listant les allergenes fournis. Idempotent : une
 * eventuelle modale ouverte est d'abord fermee (pas de doublon empile).
 * @param {Array<{id:number, name:string, description?:string}>} allergens
 * @returns {HTMLElement} l'overlay cree
 */
export function openAllergenModal(allergens) {
    closeAllergenModal();

    const list = Array.isArray(allergens) ? allergens : [];

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
    title.textContent = 'Allergenes';
    modal.appendChild(title);

    const intro = document.createElement('p');
    intro.className = 'allergen-modal-intro';
    intro.textContent = 'Les 14 allergenes a declaration obligatoire (reglement UE INCO 1169/2011). Pour toute question, demandez en caisse.';
    modal.appendChild(intro);

    const ul = document.createElement('ul');
    ul.className = 'allergen-modal-list';
    for (const allergen of list) {
        const li = document.createElement('li');

        const name = document.createElement('span');
        name.className = 'allergen-name';
        name.textContent = String(allergen.name ?? '');
        li.appendChild(name);

        if (allergen.description) {
            const desc = document.createElement('span');
            desc.className = 'allergen-desc';
            desc.textContent = ' - ' + String(allergen.description);
            li.appendChild(desc);
        }

        ul.appendChild(li);
    }
    modal.appendChild(ul);

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
