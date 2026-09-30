<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Reponse d'erreur au format de la surface appelee. L'API (/api, /admin/api, et
 * /admin/me, seule route JSON du back-office) garde l'enveloppe {data, error} qu'un
 * client HTTP doit pouvoir lire. Le back-office recoit une page HTML lisible par un
 * equipier : avant, une adresse inconnue ou une exception lui affichaient du JSON brut.
 *
 * Utilisee par le routeur (404, 405) et par le controleur frontal (500). La page ne
 * reprend jamais l'adresse demandee, et ne montre le message d'une exception qu'en mode
 * debogage, echappe : rien de la mecanique interne ne fuit en production.
 */
final class ErrorResponse
{
    public static function wantsJson(string $path): bool
    {
        return preg_match('#^/(api|admin/api)(/|$)#', $path) === 1 || $path === '/admin/me';
    }

    public static function notFound(string $path): Response
    {
        if (self::wantsJson($path)) {
            return (new Response())->json(['data' => null, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Resource not found']], 404);
        }

        return self::page(404, 'Page introuvable', 'Cette adresse ne correspond à aucune page du back-office. Le lien est peut-être ancien, ou l\'adresse a été mal saisie.');
    }

    public static function methodNotAllowed(string $path, string $method = 'POST'): Response
    {
        if (self::wantsJson($path)) {
            return (new Response())->json(['data' => null, 'error' => ['code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed']], 405);
        }
        if (strtoupper($method) === 'GET') {
            // L'adresse n'existe que pour un envoi de formulaire : pour qui l'a tapee ou
            // suivie, c'est une page qui n'existe pas. Le code HTTP reste exact (405).
            return self::page(405, 'Page introuvable', 'Cette adresse ne correspond à aucune page du back-office. Le lien est peut-être ancien, ou l\'adresse a été mal saisie.');
        }

        return self::page(405, 'Action impossible', 'Cette page existe, mais pas pour cette action. Reviens en arrière et recommence depuis le bouton prévu.');
    }

    public static function internal(string $path, bool $debug, string $message): Response
    {
        if (self::wantsJson($path)) {
            return (new Response())->json(['data' => null, 'error' => ['code' => 'INTERNAL_ERROR', 'message' => $debug ? $message : 'Internal server error']], 500);
        }

        // D-7 (2e revue adverse, contre-audit 30/09, mineur) : une erreur FATALE
        // (contrairement a un 404/405 ordinaire, qui n'a rien a voir avec une
        // route precise) peut survenir en PLEIN TRAITEMENT d'une route qui
        // porte un secret dans son URL -- ex. GET /reset_password?token=...
        // si `showConfirm()` levait une exception non attrapee. La page charge
        // sa propre feuille de style (meme origine) : sans "no-referrer", la
        // politique par defaut laisserait cette requete de ressource porter le
        // jeton en Referer. Limite a CETTE page (500), pas a `page()` en
        // general : un 404/405 ordinaire garde la politique par defaut
        // (`security-headers.spec.js` l'attend sur `/admin/adresse-inconnue`).
        return self::page(500, 'Une erreur est survenue', 'La demande n\'a pas pu aboutir. Rien n\'a été enregistré si l\'action était en cours. Réessaie dans un instant ; si cela recommence, préviens un responsable.', $debug ? $message : null)
            ->setHeader('Referrer-Policy', 'no-referrer');
    }

    private static function page(int $status, string $title, string $text, ?string $detail = null): Response
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $css = $e(Asset::forAdmin()->url('/assets/css/admin.css'));
        $detailHtml = $detail !== null ? '<pre class="error-page__detail">' . $e($detail) . '</pre>' : '';
        $html = '<!DOCTYPE html>' . "\n"
            . '<html lang="fr"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<title>' . $e($title) . ' - Wakdo Admin</title>'
            . '<link rel="stylesheet" href="' . $css . '"></head><body>'
            . '<main class="login-page"><div class="login-card error-page">'
            . '<p class="error-page__code">Erreur ' . $status . '</p>'
            . '<h1 class="error-page__title">' . $e($title) . '</h1>'
            . '<p>' . $e($text) . '</p>' . $detailHtml
            . '<p class="error-page__links"><a class="btn btn-primary" href="/admin/dashboard">Retour au tableau de bord</a> '
            . '<a class="btn btn-secondary" href="/login">Page de connexion</a></p>'
            . '</div></main></body></html>';

        return (new Response())->html($html, $status);
    }
}
