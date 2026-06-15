<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Config;

/**
 * Math pure du throttling anti brute-force (mlt.md 12.1 RG-8). Sans I/O ni
 * superglobale : c'est le calcul de securite le plus delicat (backoff degressif
 * + evaluation du verrou), donc isole ici pour etre entierement testable.
 *
 * La meme courbe sert aux deux dimensions : par compte (user.lockout_until,
 * seuil ACCOUNT_LOCKOUT_THRESHOLD) et par IP source (login_throttle.lockout_until,
 * seuil IP_THROTTLE_MAX_ATTEMPTS), instanciees via fromConfig().
 */
final class ThrottlePolicy
{
    public function __construct(
        private readonly int $threshold,
        private readonly int $baseSeconds,
        private readonly int $maxSeconds,
    ) {
    }

    /**
     * Backoff degressif : 0 sous le seuil ; au seuil = base ; puis doublement
     * base * 2^(tentatives - seuil), plafonne a maxSeconds. Ce n'est pas un
     * verrou definitif : il ralentit la force brute sans priver de service un
     * compte legitime victime de fautes de frappe (RG-8).
     */
    public function lockoutSeconds(int $attempts): int
    {
        if ($attempts < $this->threshold) {
            return 0;
        }

        $exponent = $attempts - $this->threshold;

        // Garde anti-debordement : au-dela d'un exposant raisonnable, 2^exposant
        // depasserait PHP_INT_MAX. Comme le resultat est de toute facon plafonne,
        // on court-circuite des que la valeur ne peut que depasser le plafond.
        if ($exponent >= 31) {
            return $this->maxSeconds;
        }

        $seconds = $this->baseSeconds * (2 ** $exponent);

        return (int) min($seconds, $this->maxSeconds);
    }

    /**
     * Vrai si le verrou ($lockoutUntil, datetime 'Y-m-d H:i:s' ou null) est
     * strictement dans le futur a l'instant $now (timestamp Unix injecte pour
     * des comparaisons deterministes en test). null/vide/illisible => pas de verrou.
     */
    public function isLockedUntil(?string $lockoutUntil, int $now): bool
    {
        if ($lockoutUntil === null || $lockoutUntil === '') {
            return false;
        }

        $until = strtotime($lockoutUntil);

        return $until !== false && $until > $now;
    }

    /**
     * Construit la politique pour la dimension 'account' (par compte) ou 'ip'
     * (par IP source). RG-8 precise "le meme backoff degressif" pour l'IP, donc
     * la dimension IP reutilise base/max et prend IP_THROTTLE_MAX_ATTEMPTS comme seuil.
     */
    public static function fromConfig(Config $config, string $dimension): self
    {
        $base = $config->int('ACCOUNT_LOCKOUT_BASE_SECONDS', 60);
        $max  = $config->int('ACCOUNT_LOCKOUT_MAX_SECONDS', 900);

        if ($dimension === 'ip') {
            return new self($config->int('IP_THROTTLE_MAX_ATTEMPTS', 20), $base, $max);
        }

        return new self($config->int('ACCOUNT_LOCKOUT_THRESHOLD', 5), $base, $max);
    }
}
