<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Contrat de PasswordHasher, extrait pour la seule raison suivante : les tests
 * de securite d'AuthService doivent pouvoir PROUVER que verifyDecoy() est
 * appele sur le chemin "compte verrouille" (pas seulement "email inconnu"),
 * sans dependre d'une mesure de temps instable en test unitaire. PasswordHasher
 * etant `final`, un espion ne peut pas le sous-classer ; ce contrat permet
 * d'injecter un double qui compte ses appels (App\Tests\Support\SpyPasswordHasher),
 * meme convention que DatabaseInterface/FakeDatabase.
 */
interface PasswordHasherInterface
{
    public function hash(string $plain): string;

    public function verify(string $plain, string $hash): bool;

    /**
     * Verifie le mot de passe soumis, sans jamais authentifier -- uniquement
     * pour payer le meme COUT CPU qu'une verification reelle contre un hash
     * deja stocke (RG-2 : egaliser le temps de reponse entre "email
     * inconnu"/"compte verrouille" et "mot de passe faux sur un compte connu et
     * actif").
     *
     * $referenceHash, quand il est fourni par l'appelant, DOIT etre un hash
     * argon2id REELLEMENT STOCKE (ex. `user.password_hash` d'un compte
     * quelconque) : c'est ce hash-la qui decide du cout, puisque les parametres
     * argon2id (memory_cost/time_cost/threads) sont encodes DANS le hash
     * lui-meme, pas lus depuis la configuration courante. Calibrer le leurre
     * sur `options()` (la configuration) plutot que sur un hash reellement
     * stocke ROUVRE l'ecart des qu'un deploiement change
     * ARGON2_MEMORY_COST/TIME_COST/THREADS sans rehacher l'existant : le cout
     * REEL d'un `password_verify()` contre un compte existant reste celui
     * fige au moment de la creation de SON hash, pas celui de la configuration
     * d'aujourd'hui (mesure relecture adverse : 256 ms reel contre 99 ms
     * leurre calibre sur l'environnement, cache par ailleurs parfaitement sain
     * -- aucune panne necessaire pour que l'ecart existe).
     *
     * $referenceHash absent (null) : aucun hash stocke n'est disponible pour
     * calibrer (ex. base fraiche sans aucun utilisateur) -- repli sur
     * PasswordHasher::decoyHash() (constante par defaut ou cache disque valide,
     * calibres sur options() faute de mieux).
     */
    public function verifyDecoy(string $plain, ?string $referenceHash = null): void;

    /**
     * Vrai si $hash ne porte plus les options() (memory_cost/time_cost/threads)
     * courantes -- enveloppe fine de `password_needs_rehash()`. Sert a
     * reconverger le parc de hashes stockes vers la configuration courante a
     * chaque connexion reussie (cf. AuthService::authenticate()) : sans ca, un
     * changement de ARGON2_* laisse les hashes existants a leur ancien cout
     * indefiniment, ce qui est precisement ce qui rouvre l'ecart decrit
     * ci-dessus pour verifyDecoy().
     */
    public function needsRehash(string $hash): bool;
}
