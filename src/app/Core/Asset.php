<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Fabrique l'adresse d'un fichier statique du back-office, avec un marqueur de
 * version (?v=...).
 *
 * POURQUOI. Les adresses etaient fixes (/assets/js/product-recipe.js). Apres un
 * deploiement, un navigateur qui gardait l'ancien fichier en reserve executait du
 * JavaScript qui ne correspondait plus au HTML servi : les boutons ne faisaient
 * rien, sans erreur visible. Faire changer l'adresse a chaque version rend le
 * fichier garde inutilisable : le navigateur va chercher le nouveau de lui-meme.
 *
 * Un seul point d'entree : les vues appellent $asset('/assets/...'), injecte par
 * App\Core\Controller. Aucun suffixe recopie a la main dans une vue (une garde de
 * test refuse toute adresse /assets/... ecrite en dur dans un attribut).
 *
 * D'OU VIENT LE MARQUEUR, dans cet ordre :
 *   1. src/VERSION, ecrit par scripts/deploy.sh au format "<sha court> <date>" et
 *      deja lu par HealthController. Un deploiement = un marqueur, tous les
 *      fichiers basculent ensemble. C'est le cas de la production.
 *   2. a defaut, la date de modification du fichier lui-meme. src/VERSION est
 *      ignore par git : il n'existe ni en installation locale, ni sur une pile de
 *      test. Le repli suit alors le contenu fichier par fichier.
 *   3. a defaut (fichier statique introuvable), aucun marqueur : on rend l'adresse
 *      telle quelle plutot qu'une adresse portant un marqueur vide ou invente.
 *
 * Le marqueur est filtre sur [A-Za-z0-9._-] : un src/VERSION corrompu ne peut pas
 * injecter d'espace, de guillemet ni de separateur de parametre dans l'attribut rendu.
 */
final class Asset
{
    /** Jeu de caracteres sur : ni guillemet, ni espace, ni separateur d'URL. */
    private const SAFE_MARKER = '/[^A-Za-z0-9._-]+/';

    private static ?self $admin = null;

    private ?string $deployMarker = null;

    private bool $deployMarkerLoaded = false;

    /**
     * @param string $documentRoot Racine web servant les /assets (sans barre finale).
     * @param string $versionFile  Marqueur de version ecrit au deploiement.
     */
    public function __construct(
        private readonly string $documentRoot,
        private readonly string $versionFile,
    ) {
    }

    /**
     * Instance du vhost admin : la seule racine web dont les adresses sont
     * produites par PHP (la borne est du HTML statique, cf. docker/apache/cache.conf).
     * Memorisee pour ne lire src/VERSION qu'une fois par requete.
     */
    public static function forAdmin(): self
    {
        if (self::$admin === null) {
            // src/app/Core/Asset.php -> dirname(__DIR__, 2) = src/ (meme calcul
            // que HealthController pour retrouver src/VERSION).
            $src = dirname(__DIR__, 2);
            self::$admin = new self($src . '/public/admin', $src . '/VERSION');
        }

        return self::$admin;
    }

    public function url(string $path): string
    {
        $marker = $this->marker($path);

        if ($marker === null) {
            return $path;
        }

        return $path . (str_contains($path, '?') ? '&' : '?') . 'v=' . $marker;
    }

    private function marker(string $path): ?string
    {
        return $this->deployMarker() ?? $this->fileMarker($path);
    }

    /**
     * Version deployee, lue une seule fois. Absente, illisible ou vide apres
     * filtrage : on laisse le repli par fichier repondre.
     */
    private function deployMarker(): ?string
    {
        if ($this->deployMarkerLoaded) {
            return $this->deployMarker;
        }

        $this->deployMarkerLoaded = true;

        if (!is_file($this->versionFile) || !is_readable($this->versionFile)) {
            return null;
        }

        $line = trim((string) @file_get_contents($this->versionFile));
        if ($line === '') {
            return null;
        }

        // Format "<sha court> <date>" : seul le sha identifie la version.
        $sha = explode(' ', $line, 2)[0];
        $safe = preg_replace(self::SAFE_MARKER, '', $sha) ?? '';

        return $this->deployMarker = ($safe !== '' ? $safe : null);
    }

    /**
     * Repli hors deploiement : la date de modification du fichier servi. Change
     * quand le contenu change, reste stable sinon.
     */
    private function fileMarker(string $path): ?string
    {
        $relative = explode('?', $path, 2)[0];

        // Garde de chemin : on ne mesure que ce qui vit sous la racine web, et on
        // ne suit jamais un chemin remontant (l'appelant est une vue du depot, la
        // garde est une ceinture de securite, pas une validation d'entree).
        if (!str_starts_with($relative, '/') || str_contains($relative, '..')) {
            return null;
        }

        $file = $this->documentRoot . $relative;
        if (!is_file($file)) {
            return null;
        }

        $mtime = @filemtime($file);

        return $mtime === false ? null : (string) $mtime;
    }
}
