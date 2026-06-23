<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Config;
use App\Core\DatabaseInterface;

/**
 * Garde de session pour les requetes authentifiees (mlt.md 12.1 RG-6 + RG-T02).
 *
 * NOTE DE PERIMETRE : concu et teste en P2, mais CABLE en P3. Quand les pages
 * admin deviendront dynamiques, chaque controleur protege appellera check() en
 * tete d'action et agira sur le GuardResult (rediriger vers /login si false).
 */
final class SessionGuard
{
    public function __construct(
        private readonly SessionManager $session,
        private readonly DatabaseInterface $db,
        private readonly Config $config,
    ) {
    }

    /**
     * Verifie la session : presence d'identite, borne d'inactivite (idle) et
     * borne absolue (RG-6), puis re-verification is_active = 1 en base (RG-T02).
     * Sur succes, rafraichit last_activity (fenetre idle glissante).
     */
    public function check(?int $now = null): GuardResult
    {
        $now ??= time();

        $userId = $this->session->getInt('user_id');
        $roleId = $this->session->getInt('role_id');
        $loggedInAt = $this->session->getInt('logged_in_at');
        $lastActivity = $this->session->getInt('last_activity');

        if ($userId === null || $roleId === null || $loggedInAt === null) {
            return new GuardResult(false, null, null, 'no_session');
        }

        $idleLimit = $this->config->int('SESSION_LIFETIME_IDLE', 14400);
        $absoluteLimit = $this->config->int('SESSION_LIFETIME_ABSOLUTE', 36000);

        if ($lastActivity === null || ($now - $lastActivity) > $idleLimit) {
            return new GuardResult(false, null, null, 'idle_timeout');
        }

        if (($now - $loggedInAt) > $absoluteLimit) {
            return new GuardResult(false, null, null, 'absolute_timeout');
        }

        // RG-T02 : is_active re-verifie a chaque requete (un compte desactive en
        // cours de session perd l'acces des la requete suivante).
        $row = $this->db->fetch('SELECT is_active FROM user WHERE id = :id', ['id' => $userId]);

        if ($row === null || (int) ($row['is_active'] ?? 0) !== 1) {
            return new GuardResult(false, null, null, 'inactive');
        }

        $this->session->set('last_activity', $now);

        return new GuardResult(true, $userId, $roleId, null);
    }
}
