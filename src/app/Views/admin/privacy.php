<?php

declare(strict_types=1);

/**
 * Mention d'information RGPD (Cr 3.d.2), injectee dans admin/layout.php. Page
 * statique : informe le personnel des donnees traitees par l'application, de leur
 * usage, de leur conservation, de leur (non-)partage et des droits associes. Le
 * contenu est litteral (aucune donnee dynamique a echapper).
 *
 * Chaque section enveloppe son contenu dans .card-body : .card seul ne porte AUCUN
 * padding (il est concu pour un .card-header + .card-body internes), donc un texte
 * pose directement dedans touche le bord du cadre (F40, mise en page : "textes
 * colles au bord des cadres" sur cette page).
 */
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Traitement des données personnelles</h1>
        <p class="page-subtitle">Information sur les données que cette application stocke, utilise et conserve, et sur vos droits (RGPD).</p>
    </div>
</div>

<section class="card" aria-labelledby="privacy-scope">
    <div class="card-body">
        <h2 id="privacy-scope">Qui est concerné</h2>
        <p>
            Cette mention concerne les <strong>comptes du personnel</strong> (administration,
            manager, cuisine, comptoir, drive). La borne client est <strong>anonyme</strong> :
            une commande passée en borne ne collecte aucune donnée personnelle (pas de nom,
            ni e-mail, ni téléphone) ; seul un numéro de table facultatif y est saisi.
        </p>
    </div>
</section>

<section class="card" aria-labelledby="privacy-controller">
    <div class="card-body">
        <h2 id="privacy-controller">Responsable du traitement</h2>
        <p>
            Le responsable du traitement est <strong>l'exploitant du restaurant Wakdo</strong>.
            Pour toute question ou pour exercer vos droits, le contact est
            l'administrateur du système : <strong>contact@wakdo.local</strong>, ou
            l'administration sur place.
        </p>
    </div>
</section>

<section class="card" aria-labelledby="privacy-data">
    <div class="card-body">
        <h2 id="privacy-data">Données traitées</h2>
        <div class="table-container">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Donnée</th>
                            <th scope="col">Finalité</th>
                            <th scope="col">Base légale</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>E-mail, prénom, nom</td>
                            <td>Identifier le compte et la personne qui se connecte</td>
                            <td>Exécution de la relation d'emploi</td>
                        </tr>
                        <tr>
                            <td>Mot de passe et PIN (stockés uniquement hachés, argon2id)</td>
                            <td>Authentifier la connexion et valider les actions sensibles ; hors journaux et hors affichage</td>
                            <td>Exécution de la relation d'emploi (sécurité des accès)</td>
                        </tr>
                        <tr>
                            <td>Rôle, statut actif, date de dernière connexion</td>
                            <td>Déterminer les actions autorisées (RBAC) et l'état du compte</td>
                            <td>Intérêt légitime (gestion des accès)</td>
                        </tr>
                        <tr>
                            <td>Journal d'audit des actions sensibles (auteur, action, horodatage)</td>
                            <td>Tracer qui a effectué une action sensible (annulation, changement de prix, gestion des comptes)</td>
                            <td>Intérêt légitime (traçabilité, prévention de la fraude interne)</td>
                        </tr>
                        <tr>
                            <td>Compteurs de tentatives de connexion et adresse IP de connexion</td>
                            <td>Limiter les attaques par force brute sur l'authentification</td>
                            <td>Intérêt légitime (sécurité du système)</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

<section class="card" aria-labelledby="privacy-share">
    <div class="card-body">
        <h2 id="privacy-share">Conservation et partage</h2>
        <ul>
            <li><strong>Données de compte</strong> (identité, rôle, statut) : conservées tant que le compte est actif, puis anonymisées à l'effacement.</li>
            <li><strong>Journal d'audit</strong> : conservé environ <strong>12 mois</strong> (intérêt légitime, traçabilité fiscale), puis purgé par une tâche planifiée, indépendamment du cycle de vie du compte.</li>
            <li><strong>Compteurs de connexion</strong> : réinitialisés à la connexion réussie ; non conservés au-delà de leur usage de sécurité.</li>
        </ul>
        <p>
            Les données sont hébergées sur l'infrastructure du restaurant et ne sont
            <strong>partagées avec aucun tiers</strong>. Aucune donnée n'est utilisée à des
            fins publicitaires ni cédée à des fins commerciales.
        </p>
    </div>
</section>

<section class="card" aria-labelledby="privacy-rights">
    <div class="card-body">
        <h2 id="privacy-rights">Vos droits</h2>
        <p>Vous disposez d'un droit d'accès, de rectification et d'effacement de vos données personnelles :</p>
        <ul>
            <li><strong>Accès et rectification</strong> : un administrateur peut consulter et corriger les informations de votre compte (rubrique Utilisateurs).</li>
            <li><strong>Effacement</strong> : à la demande, vos données personnelles sont anonymisées ; le compte est conservé sous une forme non identifiante pour préserver l'intégrité des historiques, et vos identifiants sont invalides.</li>
        </ul>
        <p>
            Pour exercer ces droits, adressez-vous à l'administration du restaurant, qui
            traite la demande depuis le back-office.
        </p>
    </div>
</section>
