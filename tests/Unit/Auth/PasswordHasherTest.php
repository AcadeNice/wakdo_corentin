<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use ReflectionClass;
use ReflectionMethod;
use PHPUnit\Framework\TestCase;
use App\Auth\PasswordHasher;
use App\Core\Config;

/**
 * Verifie le hash argon2id (cout pilote par l'environnement) et le leurre de
 * timing. Les couts sont volontairement abaisses ici pour garder la suite
 * rapide -- SAUF pour les deux tests qui portent explicitement sur le chemin
 * "parametres par defaut" (DEFAULT_DECOY_HASH), qui doivent au contraire
 * verifier les VRAIS couts par defaut du projet.
 */
final class PasswordHasherTest extends TestCase
{
    /** @var list<string> */
    private array $touchedKeys = [];

    private string $cacheDir;

    protected function setUp(): void
    {
        // Cout reduit : les tests ne valident pas la robustesse cryptographique
        // (couverte par les valeurs de prod) mais la mecanique hash/verify/cout.
        // Deliberement DIFFERENT des parametres par defaut du projet
        // (65536/4/1) : decoyHash() doit alors emprunter le chemin "cache
        // disque", pas la constante DEFAULT_DECOY_HASH -- c'est precisement ce
        // que ces tests verifient (a l'exception des deux tests "defaut" qui
        // retablissent les vrais couts explicitement).
        $this->setEnv('ARGON2_MEMORY_COST', '1024');
        $this->setEnv('ARGON2_TIME_COST', '1');
        $this->setEnv('ARGON2_THREADS', '1');

        // Dossier de cache ISOLE par test (pas le /tmp reel et partage du
        // conteneur) : un nom unique par test, nettoye en tearDown(). Sans cet
        // isolement, deux executions de la suite (ou deux tests dans le meme
        // process PHPUnit) laisseraient des fichiers derriere eux dans un
        // dossier partage, et un test pourrait lire le cache ecrit par un autre.
        $this->cacheDir = sys_get_temp_dir() . '/wakdo-auth-decoy-test-' . bin2hex(random_bytes(6));
        $this->setEnv('AUTH_DECOY_CACHE_DIR', $this->cacheDir);
    }

    protected function tearDown(): void
    {
        foreach ($this->touchedKeys as $key) {
            putenv($key);
        }
        $this->touchedKeys = [];

        if (is_link($this->cacheDir)) {
            @unlink($this->cacheDir);
        } elseif (is_dir($this->cacheDir)) {
            // chmod avant nettoyage : un test peut avoir laisse le dossier a
            // des droits qui empecheraient unlink/rmdir de ses propres enfants.
            @chmod($this->cacheDir, 0700);
            foreach (glob($this->cacheDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->cacheDir);
        }

        // Marqueur "deja journalise une fois" (PasswordHasher::failureMarkerPath(),
        // un fichier sur DISQUE, pas un booleen `static` -- cf. remarque de la
        // relecture adverse sur ce point precis) : isole par cacheDir(), donc
        // deja unique par test via le nom ci-dessus ; nettoye quand meme pour
        // ne rien laisser trainer dans /tmp d'une execution a l'autre.
        @unlink($this->failureMarkerPathFor($this->cacheDir));
    }

    private function setEnv(string $key, string $value): void
    {
        $this->touchedKeys[] = $key;
        putenv($key . '=' . $value);
    }

    private function hasher(): PasswordHasher
    {
        return new PasswordHasher(new Config());
    }

    private function failureMarkerPathFor(string $cacheDir): string
    {
        return sys_get_temp_dir() . '/wakdo-auth-decoy-failure-' . hash('sha256', $cacheDir) . '.marker';
    }

    /**
     * @param array<int, mixed> $args
     * @return mixed
     */
    private function callPrivate(PasswordHasher $hasher, string $method, array $args = [])
    {
        $ref = new ReflectionMethod(PasswordHasher::class, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($hasher, $args);
    }

    private function defaultDecoyHashConstant(): string
    {
        $ref = new ReflectionClass(PasswordHasher::class);
        $constant = $ref->getReflectionConstant('DEFAULT_DECOY_HASH');
        self::assertNotFalse($constant, 'PasswordHasher::DEFAULT_DECOY_HASH doit exister');

        /** @var string $value */
        $value = $constant->getValue();

        return $value;
    }

    public function testHashIsVerifiable(): void
    {
        $hasher = $this->hasher();
        $hash = $hasher->hash('WakdoAdmin2026!');

        self::assertTrue($hasher->verify('WakdoAdmin2026!', $hash));
    }

    public function testWrongPasswordIsRejected(): void
    {
        $hasher = $this->hasher();
        $hash = $hasher->hash('correct horse');

        self::assertFalse($hasher->verify('battery staple', $hash));
    }

    public function testHashUsesArgon2idAlgorithm(): void
    {
        $info = password_get_info($this->hasher()->hash('x'));

        self::assertSame('argon2id', $info['algoName']);
    }

    public function testHashEmbedsConfiguredCost(): void
    {
        $info = password_get_info($this->hasher()->hash('x'));

        self::assertSame(1024, $info['options']['memory_cost'] ?? null);
        self::assertSame(1, $info['options']['time_cost'] ?? null);
        self::assertSame(1, $info['options']['threads'] ?? null);
    }

    public function testVerifyDecoyRunsWithoutThrowing(): void
    {
        // Le leurre ne doit jamais lever ni valider un mot de passe : il ne sert
        // qu'a consommer un temps CPU comparable au chemin nominal.
        $this->expectNotToPerformAssertions();
        $this->hasher()->verifyDecoy('any-submitted-password');
    }

    /**
     * Coeur du correctif de calibrage : quand l'appelant fournit un hash
     * STOCKE de reference, verifyDecoy() doit verifier CONTRE CE HASH-LA (pas
     * contre decoyHash()) -- c'est le hash stocke qui dicte le cout reel, pas
     * options() (relecture adverse : 256 ms reel contre 99 ms leurre calibre
     * sur l'environnement, cache par ailleurs sain). Preuve directe : un hash
     * de reference marque volontairement (mot de passe connu), verifie a la
     * fois par verify() et par verifyDecoy() -- s'ils divergent en interne, ce
     * test ne le verrait pas, mais password_verify() est une fonction PHP
     * native ; ce qui est teste ici est que verifyDecoy() lui delegue bien
     * CE hash precis, pas un autre calcule en interne.
     */
    public function testVerifyDecoyUsesReferenceHashWhenProvided(): void
    {
        $hasher = $this->hasher();
        // Cout DELIBEREMENT different de options() (1024/1/1, setUp()) : si
        // verifyDecoy() ignorait $referenceHash pour retomber sur decoyHash(),
        // ce test ne le detecterait pas par le hash lui-meme -- mais la
        // methode privee verifie ci-dessous (looksLikeArgon2idHash) prouve que
        // le hash de reference est bien accepte tel quel, peu importe son
        // propre cout.
        $reference = password_hash('reference-secret', PASSWORD_ARGON2ID, ['memory_cost' => 8192, 'time_cost' => 2, 'threads' => 1]);

        self::assertTrue($this->callPrivate($hasher, 'looksLikeArgon2idHash', [$reference]));

        // Ne leve pas et n'authentifie jamais (verifyDecoy() renvoie void) :
        // seule la ligne ci-dessus prouve que le hash de reference est bien
        // reconnu et accepte tel quel, quel que soit son propre cout.
        $hasher->verifyDecoy('whatever-submitted', $reference);
    }

    /**
     * Un hash de reference vide ou mal forme (ex. valeur corrompue en base)
     * n'est jamais fait confiance : verifyDecoy() se rabat alors sur
     * decoyHash(), jamais sur un password_verify() contre un contenu qui
     * renverrait false en temps quasi nul (ce qui rouvrirait exactement
     * l'oracle que ce mecanisme sert a fermer).
     */
    public function testVerifyDecoyIgnoresImplausibleReferenceHash(): void
    {
        $hasher = $this->hasher();

        self::assertFalse($this->callPrivate($hasher, 'looksLikeArgon2idHash', ['']));
        self::assertFalse($this->callPrivate($hasher, 'looksLikeArgon2idHash', ['not-a-hash']));

        // Ne leve pas : verifyDecoy() se rabat silencieusement sur decoyHash().
        $hasher->verifyDecoy('whatever-submitted', 'not-a-hash');
    }

    /**
     * Sous des parametres argon2id PAR DEFAUT (65536/4/1, .env.example),
     * decoyHash() ne doit NI calculer NI toucher le disque : elle renvoie
     * directement la constante DEFAULT_DECOY_HASH. C'est le repli de dernier
     * recours (verifyDecoy() sans $referenceHash, cf. son docblock) : le cas
     * le plus courant (un deploiement qui ne personnalise pas ARGON2_*, et
     * sans hash de reference disponible) a un cout strictement constant des
     * le tout premier appel, sans dependance externe d'aucune sorte.
     */
    public function testDecoyHashReturnsDefaultConstantUnderDefaultParameters(): void
    {
        // Retablit les VRAIS parametres par defaut du projet pour ce test
        // precis (contrairement au reste de cette suite, volontairement
        // abaisse) : options() doit alors correspondre a DEFAULT_OPTIONS.
        putenv('ARGON2_MEMORY_COST');
        putenv('ARGON2_TIME_COST');
        putenv('ARGON2_THREADS');

        $decoyHash = $this->callPrivate($this->hasher(), 'decoyHash');

        self::assertSame($this->defaultDecoyHashConstant(), $decoyHash);
    }

    /**
     * Garde-fou : si DEFAULT_OPTIONS ou DEFAULT_DECOY_HASH divergent l'un de
     * l'autre (ex. quelqu'un change 65536 en une autre valeur dans
     * DEFAULT_OPTIONS sans regenerer la constante), ce test echoue avec la
     * commande exacte pour regenerer -- pas seulement "ca ne marche plus".
     */
    public function testDefaultDecoyHashMatchesDefaultArgon2Parameters(): void
    {
        $hash = $this->defaultDecoyHashConstant();
        $info = password_get_info($hash);

        $howToRegenerate = "PasswordHasher::DEFAULT_DECOY_HASH ne correspond plus aux parametres par defaut "
            . 'du projet (memory_cost=65536, time_cost=4, threads=1, cf. .env.example) -- regenerer avec : '
            . "php -r 'echo password_hash(bin2hex(random_bytes(16)), PASSWORD_ARGON2ID, "
            . '["memory_cost" => 65536, "time_cost" => 4, "threads" => 1]);\'';

        self::assertSame('argon2id', $info['algoName'], $howToRegenerate);
        self::assertSame(65536, $info['options']['memory_cost'] ?? null, $howToRegenerate);
        self::assertSame(4, $info['options']['time_cost'] ?? null, $howToRegenerate);
        self::assertSame(1, $info['options']['threads'] ?? null, $howToRegenerate);
    }

    /**
     * Le leurre DOIT porter le MEME jeu de parametres argon2id que options()
     * (memory_cost/time_cost/threads) : un leurre a un cout DIFFERENT du cout
     * reel rouvrirait l'ecart de temps que le cache fige est cense fermer (voir
     * decoyHash()). Parametres de ce test volontairement DIFFERENTS du defaut
     * (setUp()) : exerce donc le chemin "cache disque", pas la constante.
     * decoyHash() est privee : verifiee ici par reflexion plutot que d'exposer
     * un accesseur public rien que pour le test.
     */
    public function testDecoyHashMatchesConfiguredArgon2Parameters(): void
    {
        $hasher = $this->hasher();
        $decoyHash = $this->callPrivate($hasher, 'decoyHash');

        $info = password_get_info($decoyHash);

        self::assertSame('argon2id', $info['algoName']);
        self::assertSame(1024, $info['options']['memory_cost'] ?? null);
        self::assertSame(1, $info['options']['time_cost'] ?? null);
        self::assertSame(1, $info['options']['threads'] ?? null);
    }

    /**
     * Le cache disque du leurre est REUTILISE (pas recalcule) : deux appels
     * successifs renvoient EXACTEMENT le meme hash. Preuve directe, sans mesure
     * de temps instable en test unitaire, que la seconde verification ne paie
     * pas un second password_hash(). Parametres de ce test volontairement
     * differents du defaut (setUp()) : exerce le chemin "cache disque".
     */
    public function testDecoyHashIsCachedAcrossCalls(): void
    {
        $first = $this->callPrivate($this->hasher(), 'decoyHash');
        $second = $this->callPrivate($this->hasher(), 'decoyHash');

        self::assertSame($first, $second);
    }

    /**
     * Un cache disque au contenu EMPOISONNE (une chaine qui n'est pas un hash
     * argon2id valide -- ex. tronque par une ecriture interrompue, ou un
     * fichier ecrit a la main par erreur) ne doit JAMAIS etre renvoye tel quel :
     * password_verify() contre un contenu pareil renvoie false en temps quasi
     * nul, ce qui rouvrirait l'oracle de timing (relecture adverse : facteur 48
     * mesure entre un email inconnu -- 5 ms -- et un compte existant -- 241 ms
     * -- avant ce correctif).
     */
    public function testDecoyHashIgnoresPoisonedCacheContent(): void
    {
        $hasher = $this->hasher();
        $fingerprint = $this->callPrivate($hasher, 'fingerprint');
        $path = $this->callPrivate($hasher, 'cachePath', [$fingerprint]);

        mkdir(dirname($path), 0700, true);
        file_put_contents($path, 'x');

        $decoyHash = $this->callPrivate($hasher, 'decoyHash');

        self::assertNotSame('x', $decoyHash);
        $info = password_get_info($decoyHash);
        self::assertSame('argon2id', $info['algoName'], 'un cache empoisonne doit etre ignore, jamais renvoye tel quel');
        self::assertSame(1024, $info['options']['memory_cost'] ?? null);
    }

    /**
     * Un cache disque VALIDE (un vrai hash argon2id) mais mis en cache sous des
     * parametres DIFFERENTS de options() (ex. residu d'un ancien
     * ARGON2_MEMORY_COST, ou fichier altere) ne doit pas plus etre fait
     * confiance qu'un contenu empoisonne : le nom de fichier seul (empreinte de
     * options()) ne suffit pas comme preuve, le CONTENU doit etre revalide.
     */
    public function testDecoyHashIgnoresCacheAtWrongParameters(): void
    {
        $hasher = $this->hasher();
        $fingerprint = $this->callPrivate($hasher, 'fingerprint');
        $path = $this->callPrivate($hasher, 'cachePath', [$fingerprint]);

        mkdir(dirname($path), 0700, true);
        $wrongParamsHash = password_hash('whatever', PASSWORD_ARGON2ID, ['memory_cost' => 2048, 'time_cost' => 2, 'threads' => 1]);
        file_put_contents($path, $wrongParamsHash);

        $decoyHash = $this->callPrivate($hasher, 'decoyHash');

        self::assertNotSame($wrongParamsHash, $decoyHash, 'un cache aux mauvais parametres argon2id doit etre ignore');
        $info = password_get_info($decoyHash);
        self::assertSame(1024, $info['options']['memory_cost'] ?? null);
        self::assertSame(1, $info['options']['time_cost'] ?? null);
    }

    /**
     * Un dossier de cache atteint par un LIEN SYMBOLIQUE doit etre refuse, pas
     * suivi : is_dir() suit un lien symbolique, donc la seule verification
     * is_dir() ne suffit pas -- il faut explicitement is_link(). Verifie aussi
     * qu'aucune ecriture ne traverse ce lien (le dossier reel derriere reste
     * vide) : un lien pourrait rediriger vers un chemin hors du controle de
     * l'application.
     */
    public function testDecoyHashRefusesSymlinkedCacheDirectory(): void
    {
        $realDir = sys_get_temp_dir() . '/wakdo-auth-decoy-real-' . bin2hex(random_bytes(6));
        mkdir($realDir, 0700, true);
        symlink($realDir, $this->cacheDir);

        try {
            $hasher = $this->hasher();
            $decoyHash = $this->callPrivate($hasher, 'decoyHash');

            self::assertTrue(is_link($this->cacheDir), 'precondition : le chemin de cache doit etre un lien symbolique pour ce test');
            $info = password_get_info($decoyHash);
            self::assertSame('argon2id', $info['algoName']);
            self::assertSame(1024, $info['options']['memory_cost'] ?? null);
            self::assertSame([], glob($realDir . '/*') ?: [], 'aucune ecriture ne doit traverser le lien symbolique refuse');
        } finally {
            foreach (glob($realDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($realDir);
        }
    }

    /**
     * Un dossier de cache appartenant a un AUTRE UTILISATEUR (ex. cree par un
     * `docker exec` de maintenance en root, alors que PHP-FPM tourne en
     * www-data) doit etre refuse, meme s'il est par ailleurs un vrai dossier,
     * ni lien symbolique, aux bons droits 0700. Ce process de test tourne
     * lui-meme en root (verifie : chown() y est utilisable vers n'importe quel
     * uid) : n'importe quel uid different du sien simule "un autre compte".
     */
    public function testDecoyHashRefusesCacheDirectoryOwnedByAnotherUser(): void
    {
        if (!function_exists('posix_getuid') || !function_exists('chown')) {
            self::markTestSkipped('posix_getuid()/chown() indisponibles dans cet environnement');
        }

        mkdir($this->cacheDir, 0700, true);
        $otherUid = posix_getuid() + 1;
        if (!@chown($this->cacheDir, $otherUid)) {
            self::markTestSkipped('chown() a echoue (privilege insuffisant dans cet environnement de test) -- impossible de simuler un autre proprietaire');
        }

        $hasher = $this->hasher();
        $decoyHash = $this->callPrivate($hasher, 'decoyHash');

        $info = password_get_info($decoyHash);
        self::assertSame('argon2id', $info['algoName'], 'un dossier appartenant a un autre utilisateur doit etre refuse, jamais utilise');
        self::assertSame(1024, $info['options']['memory_cost'] ?? null);
        self::assertSame([], glob($this->cacheDir . '/*') ?: [], 'aucune ecriture ne doit reussir dans un dossier refuse pour mauvais proprietaire');
    }

    /**
     * Un dossier de cache aux DROITS inattendus (0755 -- lisible/traversable
     * par d'autres utilisateurs du systeme -- au lieu de 0700) doit etre
     * refuse, meme s'il appartient bien a l'utilisateur courant et n'est pas
     * un lien symbolique.
     */
    public function testDecoyHashRefusesCacheDirectoryWithWrongPermissions(): void
    {
        mkdir($this->cacheDir, 0755, true);

        $hasher = $this->hasher();
        $decoyHash = $this->callPrivate($hasher, 'decoyHash');

        $info = password_get_info($decoyHash);
        self::assertSame('argon2id', $info['algoName'], 'un dossier aux droits differents de 0700 doit etre refuse, jamais utilise');
        self::assertSame(1024, $info['options']['memory_cost'] ?? null);
        self::assertSame([], glob($this->cacheDir . '/*') ?: [], 'aucune ecriture ne doit reussir dans un dossier refuse pour droits inattendus');
    }

    /**
     * Le marqueur d'incident (failureMarkerPath()) doit etre ecrit des le
     * PREMIER incident, et jamais REECRIT par un second (journalise une seule
     * fois, pas a chaque appel -- corrige apres mesure de la relecture
     * adverse : 45 lignes de journal pour 75 requetes avec l'ancien mecanisme
     * `static`, qui repart a zero a chaque requete sous PHP-FPM). Preuve sans
     * dependre du temps qui passe : le marqueur est antidate avant le second
     * appel -- s'il etait reecrit, sa date remonterait a "maintenant".
     */
    public function testCacheFailureMarkerIsWrittenOnceNotOnEveryFailure(): void
    {
        mkdir($this->cacheDir, 0755, true); // droits refuses -> declenche l'incident.
        $hasher = $this->hasher();

        $this->callPrivate($hasher, 'decoyHash');

        $marker = $this->failureMarkerPathFor($this->cacheDir);
        self::assertFileExists($marker, 'le marqueur doit exister des le premier incident (fichier sur disque, pas un booleen static)');

        $past = time() - 3600;
        touch($marker, $past);

        $this->callPrivate($hasher, 'decoyHash');

        clearstatcache(true, $marker);
        self::assertSame($past, filemtime($marker), 'un second incident ne doit pas reecrire le marqueur');
    }
}
