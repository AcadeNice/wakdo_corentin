<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Csrf;
use App\Core\Response;
use App\Health\CapturedResponses;
use App\Health\HealthReport;
use App\Health\Probes;
use App\Health\RouteMap;

/**
 * Page "Santé de l'API" (`GET /admin/health`, permission `role.manage` -- la
 * meme que la gestion RBAC, aucune permission nouvelle ajoutee au catalogue).
 * Quatre blocs (contrat section "Ce que fait la page") : l'etat en direct
 * (`App\Health\HealthReport`, rendu ici puis relu par polling via
 * `HealthApiController`), les appels reels lances depuis la page
 * (`App\Health\Probes`), le trajet anime d'un appel (porte par la vue, chantier
 * B) et la carte des routes lue EN DIRECT dans le routeur
 * (`App\Health\RouteMap`, chantier A -- une seule table de verite partagee
 * avec les tests de matrice de roles).
 *
 * Non `final` : les tests sous-classent pour injecter des doubles, meme
 * convention que le reste des controleurs admin (PrivacyController,
 * StatsController).
 */
class HealthPageController extends AdminController
{
    /**
     * @param array<string, string> $params
     */
    public function index(array $params = []): Response
    {
        $guard = $this->guard('role.manage');
        if ($guard instanceof Response) {
            return $guard;
        }

        return $this->adminView('admin/health/index', [
            'title'      => 'Santé de l\'API - Wakdo Admin',
            'activeNav'  => 'health',
            'report'     => $this->healthReport()->build(),
            'routes'     => RouteMap::rows(),
            'probes'     => Probes::all(),
            'responses'  => CapturedResponses::load(),
            'csrfToken'  => Csrf::token($this->sessionManager()),
        ], $guard);
    }

    protected function healthReport(): HealthReport
    {
        return new HealthReport($this->db(), $this->config);
    }
}
