<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;

/**
 * Mention d'information sur le traitement des donnees personnelles (RGPD, Cr 3.d.2 :
 * l'application informe l'utilisateur du stockage, de l'utilisation et du cadre de
 * partage de ses donnees personnelles). GET /admin/privacy, accessible a tout
 * utilisateur authentifie.
 *
 * La page concerne les donnees du STAFF : la borne client est anonyme (aucune PII
 * client collectee, customer_order.acting_user_id = NULL cote kiosk, cf.
 * PROJECT_CONTEXT section 19). Elle informe sur les donnees stockees, leur usage et
 * leur (non-)partage, et rappelle les droits d'acces / rectification / effacement
 * (effacement materialise par l'anonymisation, mlt 10.5 ERASE_USER_PII).
 *
 * Page statique (pas d'acces BDD) rendue dans le shell admin. Non `final` : les
 * tests sous-classent pour injecter des doubles.
 */
class PrivacyController extends AdminController
{
    /**
     * @param array<string, string> $params
     */
    public function index(array $params = []): Response
    {
        $guard = $this->guard();
        if ($guard instanceof Response) {
            return $guard;
        }

        return $this->adminView('admin/privacy', [
            'title'     => 'Traitement des donnees personnelles - Wakdo Admin',
            'activeNav' => '',
        ], $guard);
    }
}
