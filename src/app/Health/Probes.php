<?php

declare(strict_types=1);

namespace App\Health;

/**
 * Les sept appels reels lances DEPUIS la page `/admin/health` VERS l'API
 * (contrat section 4) : chacun exerce un refus reel de la pile (session, jeton
 * anti-rejeu, type de contenu, route, methode) et doit echouer AVANT toute
 * ecriture. Donnee PURE (aucun appel reseau ici) : `Probes::all()` decrit les
 * requetes que la page JavaScript (chantier B) execute et que
 * `tests/Integration/HealthProbesSafetyDbTest.php` rejoue contre la vraie pile
 * pour prouver leur innocuite.
 *
 * L'ordre est celui du contrat : une regression qui echangerait deux sondes
 * (ex. `jeton` et `type`) resterait fonctionnellement correcte mais romprait la
 * narration pedagogique de la page (chaque sonde illustre UNE couche de
 * defense, dans l'ordre ou elle serait franchie par un appel reel) -- fige ici
 * pour que ce test la protege.
 */
final class Probes
{
    /**
     * @return list<array{
     *     id: string, label: string, method: string, url: string,
     *     credentials: string, sendCsrf: bool, contentType: ?string,
     *     body: ?string, expect: int, expectCode: ?string, why: string,
     * }>
     */
    public static function all(): array
    {
        return [
            [
                'id'          => 'sante',
                'label'       => "Santé de l'API (sonde de déploiement continu)",
                'method'      => 'GET',
                'url'         => '/api/health',
                'credentials' => 'include',
                'sendCsrf'    => false,
                'contentType' => null,
                'body'        => null,
                'expect'      => 200,
                'expectCode'  => null,
                'why'         => "Prouve que la chaîne complète (routeur, contrôleur, connexion à la base) répond, "
                    . 'sur la même sonde que celle utilisée par le déploiement continu pour valider chaque mise en production.',
            ],
            [
                'id'          => 'catalogue',
                'label'       => 'Lecture du catalogue public',
                'method'      => 'GET',
                'url'         => '/api/products',
                'credentials' => 'include',
                'sendCsrf'    => false,
                'contentType' => null,
                'body'        => null,
                'expect'      => 200,
                'expectCode'  => null,
                'why'         => "Prouve que l'API publique consultée par la borne (lecture seule, sans authentification) "
                    . 'reste accessible et renvoie une réponse exploitable.',
            ],
            [
                'id'          => 'session',
                'label'       => "Appel d'administration sans session",
                'method'      => 'GET',
                'url'         => '/admin/api/products',
                'credentials' => 'omit',
                'sendCsrf'    => false,
                'contentType' => null,
                'body'        => null,
                'expect'      => 401,
                'expectCode'  => 'AUTH_REQUIRED',
                'why'         => "Prouve qu'un appel sans session sur une route d'administration est refusé avant tout "
                    . "accès aux données, avec le code d'erreur attendu.",
            ],
            [
                'id'          => 'jeton',
                'label'       => 'Écriture sans jeton anti-rejeu',
                'method'      => 'PUT',
                'url'         => '/admin/api/roles/0',
                'credentials' => 'include',
                'sendCsrf'    => false,
                'contentType' => 'application/json',
                'body'        => '{}',
                'expect'      => 403,
                'expectCode'  => 'CSRF_INVALID',
                'why'         => "Prouve qu'une écriture sans jeton anti-rejeu est bloquée avant toute modification, "
                    . 'même pour un compte qui détient la permission requise.',
            ],
            [
                'id'          => 'type',
                'label'       => 'Mauvais type de contenu',
                'method'      => 'PUT',
                'url'         => '/admin/api/roles/0',
                'credentials' => 'include',
                'sendCsrf'    => true,
                'contentType' => 'text/plain',
                'body'        => '{}',
                'expect'      => 415,
                'expectCode'  => 'UNSUPPORTED_MEDIA_TYPE',
                'why'         => "Prouve qu'un corps annoncé dans un format inattendu est rejeté avant d'être interprété, "
                    . 'même une fois le jeton anti-rejeu validé.',
            ],
            [
                'id'          => 'introuvable',
                'label'       => 'Route inexistante',
                'method'      => 'GET',
                'url'         => '/api/nexiste-pas',
                'credentials' => 'include',
                'sendCsrf'    => false,
                'contentType' => null,
                'body'        => null,
                'expect'      => 404,
                'expectCode'  => 'NOT_FOUND',
                'why'         => "Prouve que le routeur distingue une route inexistante d'une erreur serveur, avec un "
                    . 'code prévisible plutôt qu\'un plantage.',
            ],
            [
                'id'          => 'methode',
                'label'       => 'Méthode non autorisée',
                'method'      => 'DELETE',
                'url'         => '/api/products',
                'credentials' => 'include',
                'sendCsrf'    => false,
                'contentType' => null,
                'body'        => null,
                'expect'      => 405,
                'expectCode'  => 'METHOD_NOT_ALLOWED',
                'why'         => "Prouve que le routeur distingue un chemin connu appelé avec une méthode imprévue "
                    . "d'une route totalement inconnue.",
            ],
        ];
    }
}
