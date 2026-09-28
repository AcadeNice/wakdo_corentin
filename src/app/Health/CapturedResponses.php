<?php

declare(strict_types=1);

namespace App\Health;

/**
 * Reponses REELLES de chaque route, capturees sur une pile jetable par
 * tests/e2e/health-capture.spec.js (lance par tests/e2e/run-health-capture.sh) et
 * versionnees dans captured-responses.json, a cote de cette classe.
 *
 * Le trajet de la page Sante les affiche quand il ne peut pas faire l'appel lui-meme :
 * une ecriture (la page n'ecrit jamais en base), ou un refus qui demande un autre
 * compte. Le fichier est volontairement hors de la racine web (src/public) : il n'est
 * transmis qu'a la page Sante, reservee a l'administrateur, jamais servi en statique.
 */
final class CapturedResponses
{
    public static function path(): string
    {
        return __DIR__ . '/captured-responses.json';
    }

    /**
     * @return array<string, mixed>|null le document capture, ou null s'il manque ou
     *   n'est pas du JSON (la page le dit alors, sans inventer de reponse)
     */
    public static function load(?string $path = null): ?array
    {
        $file = $path ?? self::path();
        if (!is_file($file)) {
            return null;
        }
        $raw = file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $doc = json_decode($raw, true);

        return is_array($doc) && isset($doc['routes']) && is_array($doc['routes']) ? $doc : null;
    }
}
