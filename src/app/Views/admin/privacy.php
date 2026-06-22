<?php

declare(strict_types=1);

/**
 * Mention d'information RGPD (Cr 3.d.2), injectee dans admin/layout.php. Page
 * statique : informe le personnel des donnees traitees par l'application, de leur
 * usage, de leur conservation, de leur (non-)partage et des droits associes. Le
 * contenu est litteral (aucune donnee dynamique a echapper).
 */
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Traitement des donnees personnelles</h1>
        <p class="page-subtitle">Information sur les donnees que cette application stocke, utilise et conserve, et sur vos droits (RGPD).</p>
    </div>
</div>

<section class="card" aria-labelledby="privacy-scope">
    <h2 id="privacy-scope">Qui est concerne</h2>
    <p>
        Cette mention concerne les <strong>comptes du personnel</strong> (administration,
        manager, cuisine, comptoir, drive). La borne client est <strong>anonyme</strong> :
        une commande passee en borne ne collecte aucune donnee personnelle (pas de nom,
        ni e-mail, ni telephone) ; seul un numero de table facultatif y est saisi.
    </p>
</section>

<section class="card" aria-labelledby="privacy-controller">
    <h2 id="privacy-controller">Responsable du traitement</h2>
    <p>
        Le responsable du traitement est <strong>l'exploitant du restaurant Wakdo</strong>.
        Pour toute question ou pour exercer vos droits, le contact est
        l'administrateur du systeme : <strong>contact@wakdo.local</strong>, ou
        l'administration sur place.
    </p>
</section>

<section class="card" aria-labelledby="privacy-data">
    <h2 id="privacy-data">Donnees traitees</h2>
    <div class="table-container">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th scope="col">Donnee</th>
                        <th scope="col">Finalite</th>
                        <th scope="col">Base legale</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>E-mail, prenom, nom</td>
                        <td>Identifier le compte et la personne qui se connecte</td>
                        <td>Execution de la relation d'emploi</td>
                    </tr>
                    <tr>
                        <td>Mot de passe et PIN (stockes uniquement haches, argon2id)</td>
                        <td>Authentifier la connexion et valider les actions sensibles ; hors journaux et hors affichage</td>
                        <td>Execution de la relation d'emploi (securite des acces)</td>
                    </tr>
                    <tr>
                        <td>Role, statut actif, date de derniere connexion</td>
                        <td>Determiner les actions autorisees (RBAC) et l'etat du compte</td>
                        <td>Interet legitime (gestion des acces)</td>
                    </tr>
                    <tr>
                        <td>Journal d'audit des actions sensibles (auteur, action, horodatage)</td>
                        <td>Tracer qui a effectue une action sensible (annulation, changement de prix, gestion des comptes)</td>
                        <td>Interet legitime (tracabilite, prevention de la fraude interne)</td>
                    </tr>
                    <tr>
                        <td>Compteurs de tentatives de connexion et adresse IP de connexion</td>
                        <td>Limiter les attaques par force brute sur l'authentification</td>
                        <td>Interet legitime (securite du systeme)</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="card" aria-labelledby="privacy-share">
    <h2 id="privacy-share">Conservation et partage</h2>
    <ul>
        <li><strong>Donnees de compte</strong> (identite, role, statut) : conservees tant que le compte est actif, puis anonymisees a l'effacement.</li>
        <li><strong>Journal d'audit</strong> : conserve environ <strong>12 mois</strong> (interet legitime, tracabilite fiscale), puis purge par une tache planifiee, independamment du cycle de vie du compte.</li>
        <li><strong>Compteurs de connexion</strong> : reinitialises a la connexion reussie ; non conserves au-dela de leur usage de securite.</li>
    </ul>
    <p>
        Les donnees sont hebergees sur l'infrastructure du restaurant et ne sont
        <strong>partagees avec aucun tiers</strong>. Aucune donnee n'est utilisee a des
        fins publicitaires ni cedee a des fins commerciales.
    </p>
</section>

<section class="card" aria-labelledby="privacy-rights">
    <h2 id="privacy-rights">Vos droits</h2>
    <p>Vous disposez d'un droit d'acces, de rectification et d'effacement de vos donnees personnelles :</p>
    <ul>
        <li><strong>Acces et rectification</strong> : un administrateur peut consulter et corriger les informations de votre compte (rubrique Utilisateurs).</li>
        <li><strong>Effacement</strong> : a la demande, vos donnees personnelles sont anonymisees ; le compte est conserve sous une forme non identifiante pour preserver l'integrite des historiques, et vos identifiants sont invalides.</li>
    </ul>
    <p>
        Pour exercer ces droits, adressez-vous a l'administration du restaurant, qui
        traite la demande depuis le back-office.
    </p>
</section>
