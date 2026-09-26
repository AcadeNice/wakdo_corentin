<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Resultat immuable d'une operation d'authentification (login ou confirmation
 * de reinitialisation). Le controleur mappe ce resultat vers une reponse HTTP
 * sans re-deriver les branches de securite.
 *
 * Le message d'echec par defaut est unique et generique (anti-enumeration) :
 * identifiants faux, compte inactif et throttle partagent le meme texte.
 */
final class AuthResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?int $userId,
        public readonly ?int $roleId,
        public readonly ?string $redirectTo,
        public readonly ?string $error,
        // Non-null UNIQUEMENT pour le verrou IP (jamais pour le verrou compte,
        // cf. throttled() ci-dessous) : c'est le seul canal par lequel un
        // consommateur JSON peut distinguer "trop de tentatives" (429) d'un
        // "identifiants faux" (401) sans reintroduire une enumeration de comptes.
        public readonly ?int $retryAfterSeconds = null,
    ) {
    }

    public static function success(int $userId, int $roleId, string $redirectTo): self
    {
        return new self(true, $userId, $roleId, $redirectTo, null);
    }

    public static function failure(string $error = 'Email ou mot de passe incorrect'): self
    {
        return new self(false, null, null, null, $error);
    }

    /**
     * Verrou IP (RG-8, dimension 'ip') : SEULE dimension de throttling sure a
     * distinguer d'un mot de passe faux par un code HTTP different (429 plutot
     * que 401). Le verrou par COMPTE reste, lui, replie sur failure() (401) :
     * un compte donne n'existe et ne se verrouille QUE si son email existe, donc
     * exposer un 429 different d'un 401 pour CE verrou REVELERAIT par le code
     * HTTP qu'un compte existe (anti-enumeration RG-2/ERR-3).
     *
     * Le verrou IP, lui, ne depend pas de l'email tente : l'exposer ne dit rien
     * sur un compte precis -- A CONDITION que AuthService::authenticate() fasse,
     * sur le chemin "compte verrouille", EXACTEMENT le meme travail que sur le
     * chemin "email inconnu" (meme appel a verifyDecoy(), meme increment du
     * compteur IP, meme absence d'increment du compteur compte). Sans cette
     * equivalence de travail, le compteur IP progresserait pour un email
     * inconnu mais pas pour un compte verrouille : l'IP n'atteindrait alors
     * jamais 429 sur un compte existant verrouille (compteur gele), ce qui
     * distinguerait les deux cas en un nombre fini de requetes (le seuil de
     * throttling IP configure) -- la meme fuite que ce docblock pretend eviter.
     * La garantie tient donc de ce COMPORTEMENT cote AuthService (verifie par
     * AuthServiceTest::testIpLockedTakesPriorityOverAccountLockForRetryAfter et
     * ses tests freres), pas d'une propriete intrinseque a cette classe.
     */
    public static function throttled(int $retryAfterSeconds, string $error = 'Email ou mot de passe incorrect'): self
    {
        return new self(false, null, null, null, $error, max(0, $retryAfterSeconds));
    }
}
