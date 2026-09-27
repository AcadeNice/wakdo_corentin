<?php

declare(strict_types=1);

namespace App\Controllers\Admin\Api;

use App\Controllers\HealthPageController;
use App\Core\Response;

/**
 * Rapport de sante en JSON (`GET /admin/api/health`), meme permission
 * (`role.manage`) et meme rapport (`App\Health\HealthReport`, reutilise par
 * heritage de `HealthPageController::healthReport()`) que la page HTML : c'est
 * la route relue toutes les 15 secondes par le bloc "Etat en direct" de
 * `/admin/health` (polling), meme convention que `StatsApiController` vis-a-vis
 * de `StatsController`.
 *
 * A NE PAS CONFONDRE avec `GET /api/health` (`App\Controllers\HealthController`) :
 * cette derniere est la sonde de deploiement continu (`.forgejo/workflows/deploy.yml`),
 * anonyme, au format fige, et ne change pas avec ce chantier.
 *
 * Non `final` : meme convention que le reste des controleurs `*ApiController`.
 */
class HealthApiController extends HealthPageController
{
    use JsonApiTrait;

    /**
     * @param array<string, string> $params
     */
    public function apiIndex(array $params = []): Response
    {
        $guard = $this->guardApi('role.manage');
        if ($guard instanceof Response) {
            return $guard;
        }

        return $this->okResponse($this->healthReport()->build());
    }
}
