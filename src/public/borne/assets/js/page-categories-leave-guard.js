/*
 * page-categories-leave-guard.js — Point d'entree de abandon-guard.js pour
 * categories.html. Fichier EXTERNE et non un script en ligne : la CSP de la
 * borne (script-src 'self') refuse toute execution inline, un <script
 * type="module"> pose directement dans la page ne s'executerait jamais (les
 * imports depuis un tel bloc echouent silencieusement, cf. console : "Refused
 * to execute inline script...").
 *
 * Cable sur 'pageshow' (pas seulement DOMContentLoaded) : un retour en arriere
 * du navigateur peut restaurer categories.html depuis le bfcache (page statique,
 * aucun handler unload) sans rejouer les scripts -- 'pageshow' se declenche dans
 * les deux cas (chargement normal ET restauration bfcache).
 */
import { installLeaveGuard } from './abandon-guard.js';

window.addEventListener('pageshow', () => installLeaveGuard('#back-to-welcome'));
