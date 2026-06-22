/*
 * a11y.js — Bascule de police adaptee aux personnes dyslexiques (front borne).
 *
 * Accessibilite RGAA Cr 1.c.2 : une police specifique pour les personnes
 * dyslexiques est prevue ET integree. La police OpenDyslexic (OFL 1.1) est
 * auto-hebergee (assets/fonts, @font-face dans style.css). Ce module ajoute un
 * bouton fixe present sur chaque ecran : au clic, il pose la classe .dys-font sur
 * <html> (qui redefinit --font-family-base, applique a tout le texte) et persiste
 * le choix dans localStorage pour le conserver d'un ecran a l'autre.
 *
 * CSP 'self' : aucun script inline, aucun handler inline. Le DOM est construit par
 * l'API (createElement/textContent). Les fonctions sont exportees pour etre testees
 * sans navigateur (jsdom) ; l'auto-init au chargement est gardee pour ne pas
 * s'executer a l'import en environnement de test (document absent a ce moment-la).
 */

export const STORAGE_KEY = 'wakdo_dyslexia_font';
export const ROOT_CLASS = 'dys-font';
const TOGGLE_SELECTOR = '[data-a11y-dys-toggle]';

/**
 * Lit la preference persistee. Tolere l'absence de storage (mode prive, quota) :
 * toute erreur d'acces renvoie false (police de base par defaut).
 * @param {Storage|null} storage
 * @returns {boolean}
 */
export function isDyslexiaEnabled(storage) {
    try {
        return storage != null && storage.getItem(STORAGE_KEY) === '1';
    } catch {
        return false;
    }
}

/**
 * Applique (ou retire) la classe .dys-font sur l'element racine fourni.
 * @param {boolean} enabled
 * @param {HTMLElement} root  typiquement document.documentElement
 */
export function applyDyslexiaPreference(enabled, root) {
    if (root && root.classList) {
        root.classList.toggle(ROOT_CLASS, Boolean(enabled));
    }
}

/**
 * Persiste la preference. Silencieux si le storage est indisponible.
 * @param {boolean} enabled
 * @param {Storage|null} storage
 */
export function persistDyslexiaPreference(enabled, storage) {
    try {
        if (storage != null) {
            storage.setItem(STORAGE_KEY, enabled ? '1' : '0');
        }
    } catch {
        /* storage indisponible : la preference reste valable pour la session en cours */
    }
}

/**
 * Construit le bouton de bascule (aria-pressed reflete l'etat). `onToggle` est
 * appele au clic avec le nouvel etat booleen ; l'appelant persiste et applique.
 * @param {boolean} initialEnabled
 * @param {(next: boolean) => void} onToggle
 * @returns {HTMLButtonElement}
 */
export function buildDyslexiaToggle(initialEnabled, onToggle) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'a11y-toggle';
    btn.setAttribute('data-a11y-dys-toggle', '');
    btn.setAttribute('aria-pressed', initialEnabled ? 'true' : 'false');
    // Libelle neutre (decrit le controle, pas l'action) : reste correct dans les
    // deux etats ; l'etat actif/inactif est porte par aria-pressed.
    btn.setAttribute('aria-label', 'Police adaptee aux personnes dyslexiques');

    const icon = document.createElement('span');
    icon.className = 'a11y-toggle__icon';
    icon.setAttribute('aria-hidden', 'true');
    icon.textContent = 'Aa';
    btn.appendChild(icon);

    const label = document.createElement('span');
    label.className = 'a11y-toggle__label';
    label.textContent = 'Police adaptee';
    btn.appendChild(label);

    btn.addEventListener('click', () => {
        const next = btn.getAttribute('aria-pressed') !== 'true';
        btn.setAttribute('aria-pressed', next ? 'true' : 'false');
        if (typeof onToggle === 'function') {
            onToggle(next);
        }
    });

    return btn;
}

/**
 * Initialise la bascule : applique la preference persistee, puis injecte le bouton
 * dans le conteneur (idempotent : ne reinjecte pas si un bouton existe deja).
 * @param {{storage?: Storage|null, root?: HTMLElement, container?: HTMLElement}} [options]
 * @returns {HTMLButtonElement|null} le bouton injecte, ou null si deja present
 */
export function initDyslexiaToggle(options = {}) {
    const storage = options.storage ?? (typeof window !== 'undefined' ? window.localStorage : null);
    const root = options.root ?? (typeof document !== 'undefined' ? document.documentElement : null);
    const container = options.container ?? (typeof document !== 'undefined' ? document.body : null);

    const enabled = isDyslexiaEnabled(storage);
    applyDyslexiaPreference(enabled, root);

    if (!container || container.querySelector(TOGGLE_SELECTOR)) {
        return null;
    }

    const btn = buildDyslexiaToggle(enabled, (next) => {
        applyDyslexiaPreference(next, root);
        persistDyslexiaPreference(next, storage);
    });

    container.appendChild(btn);
    return btn;
}

/* Auto-init au chargement. Gardee : a l'import en test (document absent), ne
   s'enregistre pas ; en navigateur, s'execute une fois le DOM pret. */
if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', () => initDyslexiaToggle());
}
