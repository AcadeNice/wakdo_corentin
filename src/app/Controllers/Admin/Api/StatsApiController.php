<?php

declare(strict_types=1);

namespace App\Controllers\Admin\Api;

use App\Controllers\StatsController;
use App\Core\Response;

/**
 * Tableau de bord statistiques en JSON (`/admin/api/stats`), meme permission
 * (`stats.read`) et memes indicateurs que `StatsController` (HTML), reutilises par
 * heritage (`statsRepository()`, `orderQuery()`) : compteurs de catalogue, sante du
 * stock (RG-T21), KPIs de vente. Lecture seule, pas de PIN.
 *
 * Non `final` : les tests sous-classent pour injecter des doubles, meme convention
 * que le reste des controleurs admin.
 */
class StatsApiController extends StatsController
{
    use JsonApiTrait;

    /**
     * @param array<string, string> $params
     */
    public function apiIndex(array $params = []): Response
    {
        $guard = $this->guardApi('stats.read');
        if ($guard instanceof Response) {
            return $guard;
        }

        return $this->okResponse([
            'counts' => $this->statsRepository()->counts(),
            'stock'  => $this->statsRepository()->stockHealth(),
            'sales'  => $this->orderQuery()->salesKpis(),
        ]);
    }
}
