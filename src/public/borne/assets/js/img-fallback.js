/*
 * img-fallback.js — Repli d'image sans handler inline (CSP-safe).
 *
 * Remplace les attributs onerror="..." inline (bloques par une CSP script-src 'self'
 * sans 'unsafe-inline') par un unique listener delegue. Chaque <img> declare sa
 * strategie de repli via data-fallback :
 *   - "logo" : remplace la source par le logo (garde-fou anti-boucle) ; data-fallback-alt
 *     pose un texte alternatif de repli s'il est present.
 *   - "hide" : masque l'image (via une classe, pas de style inline -> CSP-safe).
 * L'evenement 'error' des ressources ne bouillonne pas : on ecoute en phase de
 * CAPTURE au niveau document pour l'intercepter, y compris sur les <img> injectees
 * plus tard (cartes produit, modales).
 */
const LOGO = 'assets/images/ui/logo.png';
const HIDDEN_CLASS = 'img-fallback-hidden';

/**
 * Applique le repli a une <img> en echec de chargement selon son data-fallback.
 * Pur et sans effet de bord global (testable) : la delegation est branchee par
 * initImageFallback.
 * @param {EventTarget|null} img
 */
export function handleImageError(img) {
    if (!img || img.tagName !== 'IMG') return;
    const mode = img.dataset.fallback;
    if (mode === 'hide') {
        img.classList.add(HIDDEN_CLASS);
        return;
    }
    // "logo" : evite une boucle si le logo lui-meme echoue (compare l'attribut brut,
    // pas la propriete .src qui est resolue en URL absolue).
    if (mode === 'logo' && img.getAttribute('src') !== LOGO) {
        img.src = LOGO;
        if (img.dataset.fallbackAlt) img.alt = img.dataset.fallbackAlt;
    }
}

/**
 * Branche le listener delegue (idempotent). Capture car 'error' ne bouillonne pas.
 * @param {Document|null} doc
 */
export function initImageFallback(doc = (typeof document !== 'undefined' ? document : null)) {
    if (!doc || doc.__wakdoImgFallback) return;
    doc.__wakdoImgFallback = true;
    doc.addEventListener('error', (e) => handleImageError(e.target), true);
}

initImageFallback();
