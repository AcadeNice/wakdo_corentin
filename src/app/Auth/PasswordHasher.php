<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Config;

/**
 * Enveloppe argon2id de password_hash / password_verify avec les couts lus dans
 * l'environnement (.env / docker-compose). Porte aussi le leurre de timing
 * utilise quand l'email est inconnu (anti-enumeration, mlt.md 12.1 RG-2).
 */
final class PasswordHasher
{
    // Cache a l'echelle du process (worker PHP-FPM) : le PasswordHasher est
    // instancie a chaque requete, mais le leurre doit etre calcule une seule fois
    // par worker (voir decoyHash()).
    private static ?string $decoy = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function hash(string $plain): string
    {
        // argon2id en dur : choix security-by-design non configurable (pas de
        // bascule runtime vers un algo plus faible). Seuls les couts sont lus de
        // l'environnement (options()) ; il n'existe donc pas de var PASSWORD_ALGO.
        return password_hash($plain, PASSWORD_ARGON2ID, $this->options());
    }

    public function verify(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    /**
     * Verifie le mot de passe soumis contre un leurre argon2id de meme cout, et
     * jette le resultat. But : egaliser le temps CPU du chemin "email inconnu"
     * avec celui du chemin "mauvais mot de passe", pour ne pas reveler par le
     * timing si un compte existe (RG-2). Le leurre est calcule une fois par
     * process sur un secret jetable ; il ne correspond a aucun mot de passe reel.
     */
    public function verifyDecoy(string $plain): void
    {
        password_verify($plain, $this->decoyHash());
    }

    /**
     * @return array{memory_cost: int, time_cost: int, threads: int}
     */
    private function options(): array
    {
        // Defauts alignes sur .env.example / OWASP (64 MiB, 4 iterations, 1 thread).
        return [
            'memory_cost' => $this->config->int('ARGON2_MEMORY_COST', 65536),
            'time_cost'   => $this->config->int('ARGON2_TIME_COST', 4),
            'threads'     => $this->config->int('ARGON2_THREADS', 1),
        ];
    }

    private function decoyHash(): string
    {
        // Cache statique par process : le hash argon2id du leurre est couteux et
        // n'est calcule qu'une fois par worker, puis reutilise. Sans ce cache,
        // comme le PasswordHasher est instancie a chaque requete, chaque tentative
        // sur email inconnu paierait un password_hash supplementaire absent du
        // chemin email connu -> ecart de timing reintroduisant l'oracle d'enumeration.
        if (self::$decoy === null) {
            self::$decoy = password_hash(bin2hex(random_bytes(16)), PASSWORD_ARGON2ID, $this->options());
        }

        return self::$decoy;
    }
}
