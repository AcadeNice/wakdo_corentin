/**
 * Depot d'image par glisser-deposer, en complement du champ fichier.
 *
 * Le champ <input type="file"> reste VISIBLE et utilisable au clavier : la zone
 * de depot est une commodite posee autour de lui, jamais un remplacement. C'est
 * ce qui garde le formulaire utilisable sans souris (Cr 1.c.4) et sans
 * JavaScript du tout - sans ce fichier, le champ fichier fonctionne seul.
 *
 * L'apercu est produit en data: (FileReader) et non en blob: : la politique de
 * securite du back-office autorise "img-src 'self' data:", un blob: serait
 * refuse par le navigateur.
 *
 * Aucune librairie externe : glisser-deposer et FileReader sont natifs.
 *
 * CSP 'self' : script externe, aucun handler inline. Style CommonJS testable +
 * browser-safe (meme pattern que stock-thresholds.js/pin-modal.js/menu-form.js).
 */
(function () {
    'use strict';

    var MAX_MB = 5;

    function lisible(octets) {
        var mo = octets / (1024 * 1024);
        return mo < 0.1 ? Math.round(octets / 1024) + ' Ko' : mo.toFixed(1) + ' Mo';
    }

    function initialiser(zone) {
        var champ = zone.querySelector('input[type="file"]');
        var message = zone.querySelector('[data-image-drop-hint]');
        var apercu = zone.querySelector('[data-image-drop-preview]');

        if (!champ || !message) {
            return;
        }

        var messageInitial = message.innerHTML;

        function decrire(fichier) {
            if (!fichier) {
                message.innerHTML = messageInitial;
                if (apercu) {
                    apercu.removeAttribute('src');
                    apercu.hidden = true;
                }
                return;
            }

            var alerte = fichier.size > MAX_MB * 1024 * 1024
                ? ' — trop lourd, le serveur refusera ce fichier'
                : '';

            message.textContent = fichier.name + ' (' + lisible(fichier.size) + ')' + alerte;

            if (apercu && fichier.type.indexOf('image/') === 0) {
                var lecteur = new FileReader();
                lecteur.onload = function () {
                    apercu.src = String(lecteur.result);
                    apercu.hidden = false;
                };
                lecteur.readAsDataURL(fichier);
            }
        }

        champ.addEventListener('change', function () {
            decrire(champ.files && champ.files[0] ? champ.files[0] : null);
        });

        ['dragenter', 'dragover'].forEach(function (evenement) {
            zone.addEventListener(evenement, function (e) {
                e.preventDefault();
                zone.classList.add('is-dragover');
            });
        });

        ['dragleave', 'dragend'].forEach(function (evenement) {
            zone.addEventListener(evenement, function () {
                zone.classList.remove('is-dragover');
            });
        });

        zone.addEventListener('drop', function (e) {
            e.preventDefault();
            zone.classList.remove('is-dragover');

            var fichiers = e.dataTransfer && e.dataTransfer.files;
            if (!fichiers || fichiers.length === 0) {
                return;
            }

            // Le fichier depose doit atterrir DANS le champ pour partir avec le
            // formulaire : DataTransfer est le seul moyen normalise d'alimenter
            // input.files. Si le navigateur le refuse, on le dit plutot que de
            // laisser croire que l'image est prise en compte.
            try {
                var transfert = new DataTransfer();
                transfert.items.add(fichiers[0]);
                champ.files = transfert.files;
            } catch (erreur) {
                message.textContent = 'Votre navigateur ne gere pas le depot : utilisez le bouton de choix de fichier.';
                return;
            }

            decrire(fichiers[0]);
        });
    }

    function init(doc) {
        Array.prototype.forEach.call(
            doc.querySelectorAll('[data-image-drop]'),
            initialiser
        );
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { init: init, lisible: lisible };
    }
    if (typeof document !== 'undefined' && document.addEventListener) {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
        });
    }
}());
