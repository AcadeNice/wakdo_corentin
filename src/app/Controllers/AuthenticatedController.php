<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Authorizer;
use App\Auth\SessionGuard;
use App\Auth\SessionManager;
use App\Core\Controller;
use App\Core\DatabaseInterface;

/**
 * Base des controleurs proteges : fournit la session, la garde de session
 * (RG-6 + RG-T02) et le service d'autorisation (RG-T03), cables depuis la Config
 * et la Database injectees. Vit dans App\Controllers (au-dessus de Core et Auth)
 * pour ne pas inverser la dependance du Core vers Auth.
 *
 * Les hooks sont proteges et surchargeables en test pour injecter des doubles.
 */
abstract class AuthenticatedController extends Controller
{
    protected function sessionManager(): SessionManager
    {
        return new SessionManager($this->config);
    }

    /**
     * Acces aux donnees via l'interface. Centralise le seam pour que toutes les
     * dependances DB (garde, autorisation, repositories, transactions, audit)
     * passent par un point unique surchargeable en test.
     */
    protected function db(): DatabaseInterface
    {
        return $this->database;
    }

    protected function sessionGuard(): SessionGuard
    {
        return new SessionGuard($this->sessionManager(), $this->db(), $this->config);
    }

    protected function authorizer(): Authorizer
    {
        return new Authorizer($this->db());
    }
}
