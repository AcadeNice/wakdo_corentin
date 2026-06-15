<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Jeton CSRF synchroniseur stocke en session (RG-T01). Choisi plutot que le
 * double-submit (plus faible derriere un domaine parent partage) ou un HMAC
 * stateless (inutile puisqu'on a deja un etat serveur en session).
 *
 * Comparaison en temps constant (hash_equals) ; le jeton est re-genere apres
 * session_regenerate_id pour qu'un jeton plante avant l'authentification ne
 * puisse pas etre rejoue.
 */
final class Csrf
{
    private const KEY = '_csrf';

    /**
     * Jeton stable de la session : genere une fois (32 octets CSPRNG en hex)
     * puis reutilise tant que la session vit.
     */
    public static function token(SessionManager $session): string
    {
        $existing = $session->get(self::KEY);
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        return self::rotate($session);
    }

    /**
     * Vrai uniquement si un jeton existe en session et egale (temps constant) le
     * jeton soumis. Toute absence (pas de jeton, soumission vide) renvoie false.
     */
    public static function validate(SessionManager $session, ?string $submitted): bool
    {
        $stored = $session->get(self::KEY);

        if (!is_string($stored) || $stored === '' || $submitted === null || $submitted === '') {
            return false;
        }

        return hash_equals($stored, $submitted);
    }

    /**
     * Re-genere le jeton (apres regeneration d'ID de session sur login reussi) :
     * invalide tout jeton anterieur a l'authentification.
     */
    public static function rotate(SessionManager $session): string
    {
        $token = bin2hex(random_bytes(32));
        $session->set(self::KEY, $token);

        return $token;
    }
}
