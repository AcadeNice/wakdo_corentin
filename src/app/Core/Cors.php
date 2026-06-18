<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Middleware CORS de l'API publique kiosk (docs/api/conventions.md section 10).
 * La borne (kiosk.localhost) appelle l'API (admin.localhost) en CROSS-ORIGIN ;
 * sans en-tete Access-Control-Allow-Origin, le navigateur bloque la lecture de la
 * reponse.
 *
 * Politique stricte :
 *  - origine UNIQUE et EXACTE (CORS_ALLOWED_ORIGIN, jamais de joker), egale a
 *    l'origine du kiosk ;
 *  - scope aux chemins /api/ uniquement (les pages back-office sont same-origin) ;
 *  - methodes GET/POST/OPTIONS (lecture catalogue + creation/paiement de commande) ;
 *  - en-tete de requete Content-Type (corps JSON) ;
 *  - PAS de credentials : l'API kiosk est anonyme (aucun cookie cross-origin), donc
 *    Access-Control-Allow-Credentials est volontairement absent (et l'origine reste
 *    une valeur exacte, ce qui serait incompatible avec un joker de toute facon).
 *
 * Fail-closed : si aucune origine n'est configuree, ou si l'Origin de la requete ne
 * correspond pas EXACTEMENT, aucun en-tete CORS n'est pose -> le navigateur bloque.
 *
 * Decouple de Config (recoit l'origine en chaine) -> testable sans environnement ;
 * le front controller lit CORS_ALLOWED_ORIGIN et l'injecte.
 */
final class Cors
{
    private const ALLOW_METHODS = 'GET, POST, OPTIONS';
    private const ALLOW_HEADERS = 'Content-Type';
    private const MAX_AGE = '600';

    public function __construct(private readonly string $allowedOrigin)
    {
    }

    /**
     * Repond a une requete preliminaire (preflight) : OPTIONS sur /api/ depuis
     * l'origine autorisee -> 204 avec les en-tetes CORS, court-circuitant le routeur
     * (qui n'a pas de route OPTIONS). Renvoie null si ce n'est pas un preflight a
     * traiter ici : le flux normal de dispatch continue.
     */
    public function preflightResponse(Request $request): ?Response
    {
        if ($request->method() !== 'OPTIONS' || !$this->isAllowed($request)) {
            return null;
        }

        $response = (new Response())->setStatus(204);
        $this->putHeaders($response, true);

        return $response;
    }

    /**
     * Pose les en-tetes CORS sur une reponse effective (GET/POST), y compris une
     * reponse d'erreur (le navigateur a besoin de l'en-tete pour lire le corps d'une
     * 4xx), si la requete vient de l'origine autorisee vers /api/. No-op sinon.
     */
    public function applyTo(Request $request, Response $response): void
    {
        if (!$this->isAllowed($request)) {
            return;
        }

        $this->putHeaders($response, false);
    }

    /**
     * Origine exacte configuree ET requete /api/ ET Origin de la requete identique.
     * Comparaison stricte par egalite (pas de prefixe, pas de joker).
     */
    private function isAllowed(Request $request): bool
    {
        if ($this->allowedOrigin === '') {
            return false;
        }

        if (!str_starts_with($request->path(), '/api/')) {
            return false;
        }

        return $request->header('origin') === $this->allowedOrigin;
    }

    private function putHeaders(Response $response, bool $preflight): void
    {
        $response->setHeader('Access-Control-Allow-Origin', $this->allowedOrigin);
        $response->setHeader('Vary', 'Origin');

        if ($preflight) {
            $response->setHeader('Access-Control-Allow-Methods', self::ALLOW_METHODS);
            $response->setHeader('Access-Control-Allow-Headers', self::ALLOW_HEADERS);
            $response->setHeader('Access-Control-Max-Age', self::MAX_AGE);
        }
    }
}
