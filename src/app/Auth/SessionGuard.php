<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Config;
use App\Core\DatabaseInterface;

/**
 * Garde de session pour les requetes authentifiees (mlt.md 12.1 RG-6 + RG-T02).
 *
 * Cablee depuis P3 (AuthenticatedController::sessionGuard(), AdminController::
 * guard(), JsonApiTrait::guardApi()) : tout controleur protege appelle check()
 * en tete d'action et agit sur le GuardResult (rediriger vers /login si false).
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
     * borne absolue (RG-6), puis en UNE SEULE lecture (RG-T02) : is_active = 1,
     * role_id ACTUEL (jamais celui pose en session a la connexion -- un
     * changement de role doit s'appliquer des la requete suivante, pas
     * seulement a la prochaine connexion), et session_epoch (jamais invalide
     * par defaut ; une reinitialisation de mot de passe l'incremente en base,
     * ce qui ferme immediatement toute session ouverte avant elle, cf.
     * PasswordResetService::confirmReset()). Sur succes, rafraichit
     * last_activity (fenetre idle glissante).
     */
    public function check(?int $now = null): GuardResult
    {
        $now ??= time();

        $userId = $this->session->getInt('user_id');
        // Presence uniquement : prouve qu'une identite a bien ete posee a la
        // connexion (AuthService::authenticate()). Sa VALEUR n'est plus utilisee
        // pour l'autorisation -- celle-ci vient de la lecture base ci-dessous.
        $sessionHasIdentity = $this->session->getInt('role_id');
        $loggedInAt = $this->session->getInt('logged_in_at');
        $lastActivity = $this->session->getInt('last_activity');
        // Absent (session anterieure a ce lot, ou colonne fraichement ajoutee) =>
        // epoch 0, la valeur par defaut en base (migration 0020) : pas de rejet
        // d'une session valide qui n'a simplement jamais vu de reinitialisation.
        $sessionEpoch = $this->session->getInt('session_epoch') ?? 0;

        if ($userId === null || $sessionHasIdentity === null || $loggedInAt === null) {
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

        // RG-T02 : is_active, role_id et session_epoch relus EN BASE, dans la
        // MEME requete SQL, a chaque requete HTTP -- pas seulement is_active
        // comme avant ce lot. Les consommateurs de GuardResult->roleId (guard(),
        // guardApi(), visibleSources(), permissionsFor()...) recoivent ainsi
        // toujours le role COURANT, jamais celui fige en session a la connexion.
        $row = $this->db->fetch('SELECT is_active, role_id, session_epoch FROM user WHERE id = :id', ['id' => $userId]);

        if ($row === null || (int) ($row['is_active'] ?? 0) !== 1) {
            return new GuardResult(false, null, null, 'inactive');
        }

        $dbSessionEpoch = (int) ($row['session_epoch'] ?? 0);
        if ($dbSessionEpoch !== $sessionEpoch) {
            return new GuardResult(false, null, null, 'password_changed');
        }

        $roleId = (int) ($row['role_id'] ?? 0);

        $this->session->set('last_activity', $now);

        return new GuardResult(true, $userId, $roleId, null);
    }
}
