<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Config;

/**
 * Enveloppe argon2id de password_hash / password_verify avec les couts lus dans
 * l'environnement (.env / docker-compose). Porte aussi le leurre de timing
 * utilise quand l'email est inconnu (anti-enumeration, mlt.md 12.1 RG-2).
 */
final class PasswordHasher implements PasswordHasherInterface
{
    /**
     * Parametres argon2id PAR DEFAUT du projet (.env.example : 64 MiB, 4
     * iterations, 1 thread) -- le deploiement qui ne personnalise PAS
     * ARGON2_MEMORY_COST/TIME_COST/THREADS, donc le cas le plus courant.
     *
     * @var array{memory_cost: int, time_cost: int, threads: int}
     */
    private const DEFAULT_OPTIONS = ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1];

    /**
     * Hash argon2id CONSTANT du leurre, genere UNE FOIS avec DEFAULT_OPTIONS et
     * ECRIT EN DUR dans le code : ni calcul, ni disque, ni base de donnees --
     * le cout est alors STRICTEMENT constant, sur tout le parc, des le tout
     * premier appel. Le leurre n'est PAS un secret (il ne protege rien par
     * lui-meme, il ne fait que payer le meme cout CPU qu'une verification
     * reelle ; un attaquant qui le connaitrait n'apprendrait rien sur un vrai
     * mot de passe, et peut de toute facon mesurer les parametres argon2id au
     * chronometre) : l'ecrire en dur n'est donc pas une fuite.
     *
     * Regenerer si DEFAULT_OPTIONS change :
     *   php -r 'echo password_hash(bin2hex(random_bytes(16)), PASSWORD_ARGON2ID, ["memory_cost" => 65536, "time_cost" => 4, "threads" => 1]);'
     *
     * PasswordHasherTest::testDefaultDecoyHashMatchesDefaultArgon2Parameters()
     * echoue (avec ce meme rappel dans son message) si cette constante derive
     * des parametres par defaut ci-dessus.
     */
    private const DEFAULT_DECOY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$UmVYQXZTdFluMlNGLmdtcQ$DFVLlBq/dmsrNuEMReQx4L4BBB5eryhOGbWZpTNZpTI';

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
     * Verifie le mot de passe soumis, sans jamais authentifier -- voir
     * PasswordHasherInterface::verifyDecoy() pour le contrat complet (pourquoi
     * $referenceHash, quand fourni, prime TOUJOURS sur decoyHash()).
     */
    public function verifyDecoy(string $plain, ?string $referenceHash = null): void
    {
        if (is_string($referenceHash) && $this->looksLikeArgon2idHash($referenceHash)) {
            password_verify($plain, $referenceHash);

            return;
        }

        password_verify($plain, $this->decoyHash());
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, $this->options());
    }

    /**
     * Vrai seulement si $hash EST verifiable comme un hash argon2id (n'importe
     * quel jeu de parametres -- on ne le compare pas a options() ici, puisque
     * c'est precisement le point : on choisit de faire confiance au cout QUE
     * PORTE ce hash, pas a celui de la configuration courante). Un hash vide,
     * tronque ou mal forme est rejete : password_verify() dessus renverrait
     * false en temps quasi nul, ce qui rouvrirait l'oracle exactement comme un
     * hash de reference absent -- jamais suppose valide du seul fait qu'il a
     * ete fourni par l'appelant.
     */
    private function looksLikeArgon2idHash(string $hash): bool
    {
        if ($hash === '') {
            return false;
        }

        return (password_get_info($hash)['algoName'] ?? null) === 'argon2id';
    }

    /**
     * @return array{memory_cost: int, time_cost: int, threads: int}
     */
    private function options(): array
    {
        // Defauts alignes sur .env.example / OWASP (64 MiB, 4 iterations, 1 thread)
        // -- IDENTIQUES a DEFAULT_OPTIONS ci-dessus (pas une coincidence : c'est ce
        // qui permet a decoyHash() de detecter "parametres par defaut" par simple
        // egalite de tableau).
        return [
            'memory_cost' => $this->config->int('ARGON2_MEMORY_COST', self::DEFAULT_OPTIONS['memory_cost']),
            'time_cost'   => $this->config->int('ARGON2_TIME_COST', self::DEFAULT_OPTIONS['time_cost']),
            'threads'     => $this->config->int('ARGON2_THREADS', self::DEFAULT_OPTIONS['threads']),
        ];
    }

    /**
     * Repertoire du cache disque du leurre -- utilise UNIQUEMENT quand options()
     * differe de DEFAULT_OPTIONS (cf. decoyHash()). Override possible via la
     * variable d'environnement AUTH_DECOY_CACHE_DIR (utilise par
     * PasswordHasherTest pour un dossier isole par test, nettoye en tearDown()
     * -- jamais le /tmp reel et partage du conteneur). Sans override :
     * `sys_get_temp_dir()` (par defaut `/tmp` du conteneur PHP-FPM, ecrit par
     * l'utilisateur du pool).
     */
    private function cacheDir(): string
    {
        return $this->config->get('AUTH_DECOY_CACHE_DIR') ?? (sys_get_temp_dir() . '/wakdo-auth-decoy');
    }

    /**
     * Empreinte du jeu de parametres argon2id courant (options()) : sert de cle
     * au cache disque, pour qu'un changement de
     * ARGON2_MEMORY_COST/TIME_COST/THREADS (env) n'aille JAMAIS servir un leurre
     * au mauvais cout (ce qui rouvrirait l'oracle de timing que ce fichier ferme).
     */
    private function fingerprint(): string
    {
        return hash('sha256', (string) json_encode($this->options()));
    }

    private function cachePath(string $fingerprint): string
    {
        return $this->cacheDir() . '/' . $fingerprint . '.hash';
    }

    /**
     * Hash argon2id CONSTANT du leurre, calibre sur la CONFIGURATION
     * (options()) plutot que sur un hash reellement stocke -- repli de DERNIER
     * RECOURS, utilise UNIQUEMENT quand l'appelant ne peut fournir AUCUN hash
     * de reference a verifyDecoy() (ex. base fraiche sans aucun utilisateur).
     * Le chemin normal, prefere partout ou un compte existe (cf.
     * AuthService::authenticate()), calibre directement sur un hash STOCKE
     * (verifyDecoy($plain, $referenceHash)) : c'est le SEUL calibrage qui
     * ferme vraiment l'oracle, puisque le cout reel d'un `password_verify()`
     * est dicte par les parametres encodes DANS le hash stocke, pas par
     * options() (relecture adverse : un deploiement qui change ARGON2_* sans
     * rehacher l'existant voit le cout REEL des comptes stagner a l'ancien
     * cout, alors que ce repli-ci suivrait le NOUVEAU -- d'ou l'ecart mesure,
     * 256 ms contre 99 ms, cache par ailleurs sain). Deux chemins, pas plus
     * (Rasoir d'Ockham : le leurre n'est pas un secret, une table BDD dediee
     * pour une valeur derivee de trois entiers publics serait une
     * sur-conception -- une requete de plus sur le chemin d'authentification,
     * une migration a deployer, pour un gain nul sur le cas le plus courant) :
     *
     * 1. Parametres PAR DEFAUT (options() === DEFAULT_OPTIONS, le deploiement
     *    qui ne personnalise pas ARGON2_*) : DEFAULT_DECOY_HASH, ecrit en dur.
     *    Aucun calcul, aucun disque, cout strictement constant des le tout
     *    premier appel -- le cas le plus courant est donc immunise sans aucune
     *    dependance externe.
     * 2. Parametres PERSONNALISES (un deploiement qui change
     *    ARGON2_MEMORY_COST/TIME_COST/THREADS) : DEFAULT_DECOY_HASH ne convient
     *    plus (son cout ne correspond plus aux couts REELS de ce deploiement,
     *    ce qui rouvrirait l'oracle). Repli sur le cache disque
     *    (cacheDir()/<empreinte>.hash), VALIDE avant d'etre fait confiance --
     *    un cache lisible mais au contenu invalide (tronque, mauvais algo,
     *    mauvais cout) ne doit jamais etre utilise tel quel (isValidDecoyHash()),
     *    et le dossier doit etre sain (dirIsSafeToUse() : ni lien symbolique,
     *    ni proprietaire etranger, ni droits differents de 0700 -- un dossier
     *    laisse par un `docker exec` de maintenance en root, par exemple, est
     *    refuse plutot qu'utilise en l'etat). Ce chemin NE SERT QU'AUX
     *    DEPLOIEMENTS A PARAMETRES PERSONNALISES ; si le disque est EN PLUS
     *    indisponible pour l'un d'eux, le repli residuel est un calcul complet
     *    (password_hash() frais, immediatement ecrit sur disque pour la
     *    prochaine fois) -- plus cher qu'une verification seule, mais un
     *    residu assume et journalise UNE FOIS (logCacheFailureOnce()), pas
     *    degrade en silence : fermer ce dernier residu demanderait exactement
     *    la sur-conception (BDD, ou etat partage inter-workers) que ce fichier
     *    evite deliberement pour le cas par defaut.
     */
    private function decoyHash(): string
    {
        $options = $this->options();

        if ($options === self::DEFAULT_OPTIONS) {
            return self::DEFAULT_DECOY_HASH;
        }

        $fingerprint = $this->fingerprint();
        $dir = $this->cacheDir();

        if ($this->dirIsSafeToUse($dir)) {
            $cached = @file_get_contents($this->cachePath($fingerprint));
            if (is_string($cached) && $cached !== '' && $this->isValidDecoyHash($cached, $options)) {
                return $cached;
            }
        }

        $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_ARGON2ID, $options);
        $this->tryPersist($dir, $fingerprint, $hash);

        return $hash;
    }

    /**
     * Vrai seulement si $hash est un hash argon2id REELLEMENT verifiable et
     * portant EXACTEMENT $options -- jamais suppose du seul fait qu'il a pu etre
     * lu. `password_get_info()` ne leve jamais d'exception, meme sur un contenu
     * absurde (elle renvoie alors algoName = 'unknown') : aucune gestion
     * d'exception n'est donc necessaire ici.
     *
     * @param array{memory_cost: int, time_cost: int, threads: int} $options
     */
    private function isValidDecoyHash(string $hash, array $options): bool
    {
        $info = password_get_info($hash);
        if (($info['algoName'] ?? null) !== 'argon2id') {
            return false;
        }

        /** @var array<string, mixed> $actual */
        $actual = $info['options'] ?? [];

        return ((int) ($actual['memory_cost'] ?? -1)) === $options['memory_cost']
            && ((int) ($actual['time_cost'] ?? -1)) === $options['time_cost']
            && ((int) ($actual['threads'] ?? -1)) === $options['threads'];
    }

    /**
     * Vrai seulement si $dir peut etre utilise en confiance pour le cache
     * disque : ni lien symbolique (is_dir() suit un lien symbolique -- un
     * dossier atteint par un lien pourrait rediriger vers un chemin hors du
     * controle de l'application), ni possede par un autre utilisateur (un
     * dossier laisse par un `docker exec` de maintenance en root, par exemple,
     * porterait un contenu qu'on ne peut ni verifier avoir ecrit nous-memes ni
     * modifier -- mesure sur ce depot : 516-530 ms contre 260 ms pour le chemin
     * sain, un ecart qui rouvre l'oracle sans le moindre attaquant), ni a des
     * droits differents de 0700. L'extension posix (disponible sur cette image)
     * est necessaire pour lire l'uid du processus courant ; si elle manquait, ce
     * dossier est refuse PAR PRUDENCE (echec ferme : voir decoyHash() pour le
     * repli residuel).
     */
    private function dirIsSafeToUse(string $dir): bool
    {
        if (!is_dir($dir) || is_link($dir)) {
            return false;
        }

        if (!function_exists('posix_getuid')) {
            $this->logCacheFailureOnce('extension posix absente, impossible de verifier le proprietaire du dossier de cache');

            return false;
        }

        $owner = @fileowner($dir);
        if ($owner === false || $owner !== posix_getuid()) {
            $this->logCacheFailureOnce(sprintf(
                'dossier de cache "%s" appartient a un autre utilisateur (uid %s), refuse',
                $dir,
                is_int($owner) ? (string) $owner : '?',
            ));

            return false;
        }

        $perms = @fileperms($dir);
        if ($perms === false || ($perms & 0777) !== 0700) {
            $this->logCacheFailureOnce(sprintf('dossier de cache "%s" a des droits inattendus (0700 attendu), refuse', $dir));

            return false;
        }

        return true;
    }

    /**
     * Cree $dir si absent (mkdir 0700), sans jamais suivre un lien symbolique
     * existant a sa place. N'ecrit PAS le fichier de cache lui-meme : seul
     * l'appelant (tryPersist()) le fait, apres avoir re-verifie dirIsSafeToUse().
     */
    private function ensureDirCreated(string $dir): bool
    {
        if (is_link($dir)) {
            return false;
        }

        if (is_dir($dir)) {
            return true;
        }

        if (@mkdir($dir, 0700, true)) {
            return true;
        }

        // Course benigne : un autre worker a pu le creer entre le is_dir() et le
        // mkdir() ci-dessus. Retester plutot que d'echouer a tort.
        clearstatcache(true, $dir);

        return is_dir($dir);
    }

    /**
     * Tentative d'ecriture ATOMIQUE (fichier temporaire puis rename(), garanti
     * atomique par POSIX sur un meme systeme de fichiers) du leurre sur disque,
     * en BEST EFFORT (ce chemin ne sert qu'aux deploiements a parametres
     * personnalises, cf. decoyHash()) : un echec ici ne fait jamais echouer
     * verifyDecoy(), mais n'est plus degrade EN SILENCE -- consigne une seule
     * fois dans le journal d'erreurs PHP (logCacheFailureOnce()), et nettoie
     * tout fichier temporaire qui n'aurait pas atteint rename().
     */
    private function tryPersist(string $dir, string $fingerprint, string $hash): void
    {
        if (!$this->ensureDirCreated($dir) || !$this->dirIsSafeToUse($dir)) {
            $this->logCacheFailureOnce(sprintf(
                'dossier de cache "%s" indisponible ou non conforme : cache disque desactive (repli sur un calcul complet a chaque appel, cf. decoyHash())',
                $dir,
            ));

            return;
        }

        $path = $this->cachePath($fingerprint);
        $tmp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';

        if (@file_put_contents($tmp, $hash) === false) {
            $this->logCacheFailureOnce(sprintf('ecriture impossible dans le fichier temporaire "%s"', $tmp));

            return;
        }

        if (@rename($tmp, $path) === false) {
            $this->logCacheFailureOnce(sprintf('rename() a echoue de "%s" vers "%s"', $tmp, $path));
            @unlink($tmp);
        }
    }

    /**
     * Chemin d'un marqueur sur DISQUE (pas une propriete `static` PHP -- une
     * requete distincte remet TOUJOURS `static` a sa valeur initiale sous
     * PHP-FPM classique, verifie sur ce depot ; un premier correctif de ce
     * fichier promettait "une seule fois par processus" avec un simple booleen
     * `static`, promesse fausse en pratique : la relecture a mesure 45 lignes
     * de journal pour 75 requetes, une quasi a chaque appel, ce qui permet a
     * un visiteur non authentifie de faire grossir le journal d'erreurs sans
     * limite en repetant une tentative de connexion). Le nom du marqueur
     * incorpore une empreinte de cacheDir() : deux configurations differentes
     * (ex. deux tests unitaires avec des AUTH_DECOY_CACHE_DIR distincts) ne se
     * marquent pas l'une l'autre comme "deja journalise".
     */
    private function failureMarkerPath(): string
    {
        return sys_get_temp_dir() . '/wakdo-auth-decoy-failure-' . hash('sha256', $this->cacheDir()) . '.marker';
    }

    /**
     * Consigne UNE SEULE fois (pas a chaque requete, cf. failureMarkerPath())
     * qu'un incident de cache a ete rencontre, via un fichier marqueur sur
     * disque plutot qu'un booleen `static` (voir failureMarkerPath()). Le
     * marqueur vit directement dans sys_get_temp_dir() (typiquement `/tmp`,
     * monde-inscriptible avec bit collant) -- PAS dans cacheDir() elle-meme,
     * puisque c'est precisement CE dossier qui vient d'etre juge inutilisable ;
     * ecrire le marqueur a cote plutot que dedans evite de dependre du
     * dossier en panne pour signaler qu'il est en panne. Best-effort
     * (`@file_exists`/`@touch`) : un echec d'ecriture du marqueur lui-meme
     * degrade au pire vers l'ancien comportement (un journal plus bavard),
     * jamais vers un echec d'authentification. Sans effet sur le comportement
     * d'authentification (jamais lu par verifyDecoy()) : uniquement de
     * l'observabilite pour l'operateur.
     */
    private function logCacheFailureOnce(string $detail): void
    {
        $marker = $this->failureMarkerPath();

        if (@file_exists($marker)) {
            return;
        }

        error_log('PasswordHasher: ' . $detail . '.');
        @touch($marker);
    }
}
