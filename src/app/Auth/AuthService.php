<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Config;
use App\Core\DatabaseInterface;

/**
 * Authentification back-office : AUTHENTICATE_USER (mlt.md 12.1) et
 * LOGOUT_USER (12.2). Requetes preparees inline (pas de repository : jeu de
 * requetes fixe et borne, une seule famille d'operations). Le temps est injecte
 * (?int $now) pour des comparaisons de verrou deterministes en test.
 *
 * Fail-closed : toute exception PDO remonte ; aucune session n'est jamais
 * ouverte sur une erreur de base de donnees.
 */
final class AuthService
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly Config $config,
        private readonly SessionManager $session,
        private readonly PasswordHasherInterface $hasher,
    ) {
    }

    /**
     * Ordre strict (12.1) : RG-1 lookup (toujours, pour payer le cout SELECT sur
     * hit comme sur miss) -> PRE-3 gate IP puis compte -> RG-2 verify (leurre si
     * miss OU compte verrouille -- MEME traitement des deux, voir plus bas).
     * Succes : RG-3 regenerate + rotate CSRF, RG-4 session, RG-5/RG-9 reset+audit
     * (une transaction), RG-7 redirection dynamique. Echec : RG-8 backoff
     * degressif compte + upsert IP + audit (une transaction). Message d'echec
     * unique (ERR-1/3) ; SEUL le verrou IP porte un `retryAfterSeconds`
     * (consommateur JSON, cf. AuthResult::throttled()).
     *
     * REGLE : le verrou COMPTE doit rester indiscernable d'un mot de passe faux
     * -- meme code, et le meme travail observable sur les deux chemins (meme
     * appel a `verifyDecoy()`, meme increment du SEUL compteur IP, meme audit).
     * Sans cette regle, un compte verrouille se distingue d'un email inconnu :
     * soit par le compteur IP (s'il n'avance pas sur un compte deja verrouille,
     * il n'atteint jamais le seuil de 429 qu'un email inconnu, lui, atteint),
     * soit par le temps de reponse (si `verifyDecoy()` n'est pas appele, la
     * reponse est nettement plus rapide qu'une verification reelle). Voir le
     * bloc `if ($accountLocked)` ci-dessous. Le leurre est calibre sur un hash
     * STOCKE de reference (le compte cible s'il existe deja mais est
     * verrouille, sinon un compte quelconque via referenceHashForDecoy()), PAS
     * sur la configuration argon2id courante -- c'est le hash stocke qui dicte
     * le cout REEL d'un `password_verify()`, pas la configuration (voir
     * PasswordHasherInterface::verifyDecoy() pour le pourquoi complet, et
     * needsRehash()/recordSuccess() plus bas pour la reconvergence du parc
     * quand la configuration change).
     */
    public function authenticate(string $email, string $password, string $ip, ?int $now = null): AuthResult
    {
        $now ??= time();
        $accountPolicy = ThrottlePolicy::fromConfig($this->config, 'account');
        $ipPolicy = ThrottlePolicy::fromConfig($this->config, 'ip');

        // RG-1 : recherche systematique (hit ou miss) afin que le cout du SELECT
        // soit paye dans les deux cas (limite l'oracle de timing par enumeration).
        $user = $this->findActiveUserByEmail($email);

        // PRE-3 : porte de throttling AVANT toute verification de mot de passe.
        $accountLockedUntil = $user !== null ? $this->stringOrNull($user['lockout_until'] ?? null) : null;
        $accountLocked = $accountPolicy->isLockedUntil($accountLockedUntil, $now);
        $ipLockedUntil = $this->ipLockoutUntil($ip);
        $ipLocked = $ipPolicy->isLockedUntil($ipLockedUntil, $now);

        if ($ipLocked) {
            // ERR-3 : pas d'increment (le compteur tourne deja, le verrou est actif).
            // Contrairement au verrou compte ci-dessous, exposer CE verrou (retryAfterSeconds)
            // ne leak rien : il ne depend pas de l'email tente (cf. AuthResult::throttled()).
            $lockoutUntil = strtotime((string) $ipLockedUntil);
            $retryAfter = $lockoutUntil !== false ? $lockoutUntil - $now : 0;

            return AuthResult::throttled($retryAfter);
        }

        if ($accountLocked) {
            // REGLE : ce chemin fait EXACTEMENT le meme travail que "email
            // inconnu" ci-dessous -- meme appel a verifyDecoy() (qui ne paie
            // qu'UN password_verify(), cf. PasswordHasher::decoyHash()), meme
            // increment du compteur IP, meme audit. $userId=null, $roleId=null :
            // le compteur PAR COMPTE n'avance pas plus qu'il ne l'aurait fait
            // pour un email inconnu (deja verrouille, l'incrementer ne
            // changerait rien au verrou actif deja calcule) ; SEUL le compteur
            // IP progresse, exactement comme pour un email inconnu -- sans quoi
            // un compte verrouille se distingue d'un email inconnu, par le
            // compteur IP (gele ici, jamais de 429) et par le temps de reponse
            // (reponse immediate sans le travail de verifyDecoy()).
            //
            // Reference de calibrage : le hash STOCKE de CE compte precis (deja
            // en main, aucune requete de plus) -- pas options() -- puisque
            // c'est ce hash qui dicte le cout REEL d'une verification contre ce
            // meme compte une fois deverrouille (cf. PasswordHasherInterface::
            // verifyDecoy()).
            $this->hasher->verifyDecoy($password, $this->stringOrNull($user['password_hash'] ?? null));
            $this->recordFailure(null, null, 0, $ip, $accountPolicy, $ipPolicy, $now);

            return AuthResult::failure();
        }

        // RG-2 : email inconnu -> verify leurre (timing) puis echec generique.
        // Reference de calibrage : PAS ce compte (il n'existe pas) -- un hash
        // STOCKE quelconque d'un autre compte, pour le meme motif que ci-dessus.
        if ($user === null) {
            $this->hasher->verifyDecoy($password, $this->referenceHashForDecoy());
            $this->recordFailure(null, null, 0, $ip, $accountPolicy, $ipPolicy, $now);

            return AuthResult::failure();
        }

        $userId = (int) ($user['id'] ?? 0);
        $roleId = (int) ($user['role_id'] ?? 0);
        $storedHash = (string) ($user['password_hash'] ?? '');

        if (!$this->hasher->verify($password, $storedHash)) {
            $attempts = (int) ($user['failed_login_attempts'] ?? 0);
            $this->recordFailure($userId, $roleId, $attempts, $ip, $accountPolicy, $ipPolicy, $now);

            return AuthResult::failure();
        }

        // (b) Convergence du parc : si ce hash ne porte plus les options()
        // courantes (ex. ARGON2_MEMORY_COST augmente depuis sa creation), le
        // rehacher ICI -- seul moment ou le mot de passe clair, deja verifie,
        // est disponible. Sans ca, un changement de ARGON2_* laisse les hashes
        // existants a leur ancien cout indefiniment (cf. PasswordHasherInterface::
        // needsRehash()).
        $rehashedPassword = $this->hasher->needsRehash($storedHash) ? $this->hasher->hash($password) : null;

        // Succes : RG-3 (anti-fixation) d'abord (change l'ID, pas encore d'identite).
        $this->session->regenerate();

        // RG-5 + RG-9 : reset compteurs + clear IP + audit succes (+ rehash si
        // du), une transaction. Fait AVANT de poser l'identite en session : si
        // la base echoue, aucune session authentifiee ne subsiste (fail-closed, D9).
        $this->recordSuccess($userId, $roleId, $ip, $now, $rehashedPassword);

        // RG-4 : identite + horodatages pour les bornes idle/absolue (RG-6),
        // puis rotation du jeton CSRF anterieur a l'authentification.
        $this->session->set('user_id', $userId);
        $this->session->set('role_id', $roleId);
        $this->session->set('logged_in_at', $now);
        $this->session->set('last_activity', $now);
        Csrf::rotate($this->session);

        $routeRaw = $user['default_route'] ?? null;
        $defaultRoute = is_string($routeRaw) && $routeRaw !== '' ? $routeRaw : '/';

        return AuthResult::success($userId, $roleId, $defaultRoute);
    }

    /**
     * LOGOUT_USER (12.2) : efface puis detruit la session. Aucune I/O base.
     */
    public function logout(): void
    {
        $this->session->clear();
        $this->session->destroy();
    }

    /**
     * RG-1 : utilisateur actif par email, joint a son role pour la route de
     * redirection dynamique (RG-7). Requete preparee (RG-T06).
     *
     * @return array<string, mixed>|null
     */
    private function findActiveUserByEmail(string $email): ?array
    {
        return $this->db->fetch(
            'SELECT u.id, u.password_hash, u.role_id, u.failed_login_attempts, u.lockout_until, r.default_route '
            . 'FROM user u JOIN role r ON r.id = u.role_id '
            . 'WHERE u.email = :email AND u.is_active = 1 LIMIT 1',
            ['email' => $email],
        );
    }

    /**
     * Un hash STOCKE de reference quelconque, pour calibrer le leurre sur le
     * cout REEL de password_verify() plutot que sur options() (la
     * configuration) : c'est le hash stocke lui-meme qui dicte son propre cout
     * (les parametres argon2id sont encodes DEDANS), pas la configuration
     * courante -- un cout calibre sur la configuration diverge du cout reel
     * des qu'un deploiement change ARGON2_MEMORY_COST/TIME_COST/THREADS sans
     * rehacher l'existant (mesure relecture adverse : 256 ms reel contre 99 ms
     * leurre, cache par ailleurs parfaitement sain, aucune panne necessaire).
     * `LIMIT 1` sans ORDER BY : n'importe quel compte convient, on ne cherche
     * pas LE compte le plus representatif, seulement UN hash reellement
     * stocke. Renvoie null sur une base fraiche sans aucun utilisateur (borne
     * de demarrage, pas un cas operationnel normal -- ce depot seede toujours
     * un compte admin, db/seeds/0001) : PasswordHasher::verifyDecoy() se rabat
     * alors sur son propre mecanisme calibre sur options().
     */
    private function referenceHashForDecoy(): ?string
    {
        $row = $this->db->fetch('SELECT password_hash FROM user LIMIT 1');

        return $this->stringOrNull($row['password_hash'] ?? null);
    }

    private function ipLockoutUntil(string $ip): ?string
    {
        $row = $this->db->fetch(
            'SELECT lockout_until FROM login_throttle WHERE ip_address = :ip',
            ['ip' => $ip],
        );

        return $row === null ? null : $this->stringOrNull($row['lockout_until'] ?? null);
    }

    /**
     * RG-8 : enregistre l'echec sur les deux dimensions (compte si connu + IP)
     * et une ligne audit_log, le tout dans une seule transaction atomique (RG-T08).
     */
    private function recordFailure(
        ?int $userId,
        ?int $roleId,
        int $currentAttempts,
        string $ip,
        ThrottlePolicy $accountPolicy,
        ThrottlePolicy $ipPolicy,
        int $now,
    ): void {
        $nowDt = date('Y-m-d H:i:s', $now);
        $windowSeconds = $this->config->int('IP_THROTTLE_WINDOW_SECONDS', 900);

        $windowCutoff = date('Y-m-d H:i:s', $now - $windowSeconds);

        $this->db->transaction(function (DatabaseInterface $db) use (
            $userId,
            $roleId,
            $currentAttempts,
            $ip,
            $accountPolicy,
            $ipPolicy,
            $now,
            $nowDt,
            $windowCutoff,
        ): void {
            // Dimension compte. Pour ne pas reveler par le timing si l'email existe
            // (anti-enumeration, RG-2), on emet la MEME requete dans les deux cas :
            // sur email inconnu, un UPDATE sur id = 0 (aucune ligne touchee car les
            // PK user sont AUTO_INCREMENT >= 1), donc meme profil d'I/O, effet nul.
            if ($userId !== null) {
                $newAttempts = $currentAttempts + 1;
                $lockSeconds = $accountPolicy->lockoutSeconds($newAttempts);
                $lockUntil = $lockSeconds > 0 ? date('Y-m-d H:i:s', $now + $lockSeconds) : null;

                $db->execute(
                    'UPDATE user SET failed_login_attempts = :attempts, last_failed_login_at = :now, '
                    . 'lockout_until = :lock WHERE id = :id',
                    ['attempts' => $newAttempts, 'now' => $nowDt, 'lock' => $lockUntil, 'id' => $userId],
                );
            } else {
                $db->execute(
                    'UPDATE user SET failed_login_attempts = :attempts, last_failed_login_at = :now, '
                    . 'lockout_until = :lock WHERE id = :id',
                    ['attempts' => 0, 'now' => $nowDt, 'lock' => null, 'id' => 0],
                );
            }

            // Dimension IP : increment ATOMIQUE cote SQL (failed_attempts + 1) pour
            // eviter le lost-update sous concurrence ; la fenetre glissante est
            // reinitialisee en SQL si elle a expire. Le verrou de ligne pris par
            // l'upsert serialise les tentatives concurrentes sur la meme IP.
            // Placeholders distincts : en prepare reelle (EMULATE_PREPARES = false)
            // un meme nom ne peut pas etre lie plusieurs fois.
            $db->execute(
                'INSERT INTO login_throttle (ip_address, failed_attempts, window_started_at, last_attempt_at) '
                . 'VALUES (:ip, 1, :now_i, :now_li) '
                . 'ON DUPLICATE KEY UPDATE '
                . 'failed_attempts = IF(window_started_at < :cutoff, 1, failed_attempts + 1), '
                . 'window_started_at = IF(window_started_at < :cutoff2, :now_w, window_started_at), '
                . 'last_attempt_at = :now_lu',
                [
                    'ip' => $ip,
                    'now_i' => $nowDt,
                    'now_li' => $nowDt,
                    'cutoff' => $windowCutoff,
                    'cutoff2' => $windowCutoff,
                    'now_w' => $nowDt,
                    'now_lu' => $nowDt,
                ],
            );

            // Relit le compteur post-increment (valeur autoritaire ecrite ci-dessus,
            // ligne deja verrouillee par cette transaction) pour calculer le backoff
            // IP en PHP via ThrottlePolicy, puis pose le verrou.
            $row = $db->fetch('SELECT failed_attempts FROM login_throttle WHERE ip_address = :ip', ['ip' => $ip]);
            $ipAttempts = (int) ($row['failed_attempts'] ?? 1);
            $ipLockSeconds = $ipPolicy->lockoutSeconds($ipAttempts);
            $ipLockUntil = $ipLockSeconds > 0 ? date('Y-m-d H:i:s', $now + $ipLockSeconds) : null;

            $db->execute(
                'UPDATE login_throttle SET lockout_until = :lock WHERE ip_address = :ip',
                ['lock' => $ipLockUntil, 'ip' => $ip],
            );

            $this->writeAudit($db, 'auth.login_failed', $userId, $roleId, 'Échec de connexion');
        });
    }

    /**
     * RG-9 : remise a zero du compteur compte + clear du throttle IP + audit du
     * succes (+ rehash du mot de passe si $rehashedPassword est fourni, point
     * (b) de la convergence du parc -- cf. PasswordHasherInterface::needsRehash()),
     * une seule transaction (RG-T08).
     */
    private function recordSuccess(int $userId, int $roleId, string $ip, int $now, ?string $rehashedPassword = null): void
    {
        $nowDt = date('Y-m-d H:i:s', $now);

        $this->db->transaction(function (DatabaseInterface $db) use ($userId, $roleId, $ip, $nowDt, $rehashedPassword): void {
            if ($rehashedPassword !== null) {
                $db->execute(
                    'UPDATE user SET password_hash = :hash WHERE id = :id',
                    ['hash' => $rehashedPassword, 'id' => $userId],
                );
            }

            $db->execute(
                'UPDATE user SET failed_login_attempts = 0, lockout_until = NULL, last_login_at = :now WHERE id = :id',
                ['now' => $nowDt, 'id' => $userId],
            );

            // Clear de la ligne IP : 0 ligne affectee si aucune n'existait (benin).
            // Placeholders distincts (cf. recordFailure : prepare reelle, un nom
            // ne peut etre lie qu'une fois).
            $db->execute(
                'UPDATE login_throttle SET failed_attempts = 0, lockout_until = NULL, '
                . 'window_started_at = :now_w, last_attempt_at = :now_l WHERE ip_address = :ip',
                ['now_w' => $nowDt, 'now_l' => $nowDt, 'ip' => $ip],
            );

            $this->writeAudit($db, 'auth.login_success', $userId, $roleId, 'Connexion réussie');
        });
    }

    /**
     * RG-T14 : audit_log strictement en INSERT (jamais d'UPDATE/DELETE). summary
     * non personnel ; details laisse NULL pour un evenement d'auth (aucune PII).
     */
    private function writeAudit(
        DatabaseInterface $db,
        string $actionCode,
        ?int $userId,
        ?int $roleId,
        string $summary,
    ): void {
        $db->execute(
            'INSERT INTO audit_log (actor_user_id, actor_role_id, action_code, entity_type, entity_id, summary) '
            . 'VALUES (:uid, :rid, :code, :etype, :eid, :summary)',
            [
                'uid' => $userId,
                'rid' => $roleId,
                'code' => $actionCode,
                'etype' => $userId !== null ? 'user' : null,
                'eid' => $userId,
                'summary' => $summary,
            ],
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
