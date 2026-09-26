<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Reception et enregistrement d'une image envoyee depuis le back-office.
 *
 * Trois principes, dans cet ordre de priorite :
 *
 * 1. Le type reel du fichier est lu DANS SON CONTENU (finfo, puis getimagesize).
 *    Ni l'extension du nom d'origine ni le type annonce par le navigateur ne
 *    sont consultes : ces deux valeurs viennent du client, donc d'un endroit
 *    que l'on ne controle pas (Cr 4.e.1).
 * 2. Le nom du fichier stocke est REGENERE cote serveur. Le nom d'origine n'est
 *    jamais reutilise, pas meme pour deviner l'extension. C'est ce qui ferme
 *    d'un coup la traversee de repertoire ("../../") et les noms a double
 *    extension du type "photo.php.png".
 * 3. La destination est public/uploads, qui est VOISINE des deux racines web
 *    (public/borne et public/admin) sans etre incluse dedans. Un fichier depose
 *    la n'est donc jamais servi ni execute directement par Apache : il n'est
 *    atteignable que par l'alias en lecture seule declare dans les vhosts.
 *
 * Les deux limites (taille, formats acceptes) viennent de l'environnement
 * (UPLOAD_MAX_SIZE_MB, UPLOAD_ALLOWED_MIME) et non du code : elles se reglent
 * par hote sans redeploiement.
 *
 * Non `final` : isUploadedFile()/moveUploadedFile() (plus bas) sont le seul
 * point de passage vers is_uploaded_file()/move_uploaded_file(), deux fonctions
 * dont le comportement depend d'un vrai envoi HTTP traite par PHP -- un test
 * unitaire n'en a jamais. Les tests sous-classent pour les retomber sur
 * l'equivalent filesystem le plus proche (is_file()/rename()), comme les
 * controleurs le font deja pour leurs propres seams.
 */
class ImageUploader
{
    /**
     * Correspondance type reel detecte -> extension ecrite sur le disque.
     *
     * C'est cette table qui decide de l'extension finale, jamais le nom envoye
     * par le client. Un type absent d'ici est refuse meme s'il figure dans
     * UPLOAD_ALLOWED_MIME : la table est le garde-fou de derniere ligne.
     */
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    /** Sous-dossiers autorises sous uploads/ : liste fermee, pas de valeur libre. */
    private const SUBDIRS = ['products', 'categories'];

    private const DEFAULT_MAX_MB = 5;
    private const DEFAULT_MIME = 'image/jpeg,image/png,image/webp';

    private readonly string $baseDir;

    /**
     * $baseDir est calcule depuis l'emplacement de ce fichier
     * (src/app/Core/ImageUploader.php -> src/public/uploads) plutot que code en
     * dur, pour rester identique sur l'hote et dans le conteneur. Le parametre
     * n'existe que pour les tests, qui pointent vers un dossier temporaire.
     */
    public function __construct(
        private readonly Config $config,
        ?string $baseDir = null,
    ) {
        $this->baseDir = $baseDir ?? dirname(__DIR__, 2) . '/public/uploads';
    }

    /**
     * Un fichier a-t-il reellement ete choisi par l'utilisateur ?
     *
     * Un champ fichier laisse vide arrive quand meme dans $_FILES, avec le code
     * UPLOAD_ERR_NO_FILE. Sans cette distinction, modifier un produit sans
     * toucher a sa photo serait traite comme un envoi rate.
     *
     * @param array<string, mixed>|null $file
     */
    public function isSubmitted(?array $file): bool
    {
        if ($file === null) {
            return false;
        }

        return (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    }

    /**
     * Passe le fichier au crible SANS rien ecrire sur le disque, et renvoie le
     * type reel confirme.
     *
     * Verifier et ecrire sont volontairement separes : un formulaire peut repartir
     * en erreur apres coup (mot de passe a usage unique refuse, validation d'un
     * autre champ), et un fichier deja deplace serait alors un orphelin que plus
     * personne ne reference. L'appelant valide tot, ecrit tard.
     *
     * @param array<string, mixed> $file une entree de $_FILES
     *
     * @throws ImageUploadException si le fichier est refuse, a n'importe quelle etape
     */
    public function validate(array $file, string $subdir): string
    {
        if (!in_array($subdir, self::SUBDIRS, true)) {
            throw new ImageUploadException('Destination d\'image inconnue.');
        }

        $this->assertTransferSucceeded((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE));

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !$this->isUploadedFile($tmp)) {
            throw new ImageUploadException('Le fichier reçu n\'est pas un envoi valide.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            throw new ImageUploadException('Le fichier envoyé est vide.');
        }

        $maxMb = $this->maxMegabytes();
        if ($size > $maxMb * 1024 * 1024) {
            throw new ImageUploadException(sprintf('L\'image dépasse la taille maximale de %d Mo.', $maxMb));
        }

        $mime = $this->detectMime($tmp);
        if (!isset(self::EXTENSIONS[$mime]) || !in_array($mime, $this->allowedMimes(), true)) {
            throw new ImageUploadException('Format d\'image non accepté. Formats possibles : JPEG, PNG, WebP.');
        }

        // Deuxieme lecture du contenu, volontairement redondante : finfo se
        // prononce sur les premiers octets, donc un fichier qui commence par une
        // signature PNG et continue en autre chose lui echappe. getimagesize,
        // lui, refuse ce qu'il ne sait pas decoder entierement.
        if (@getimagesize($tmp) === false) {
            throw new ImageUploadException('Le fichier n\'est pas une image exploitable.');
        }

        return $mime;
    }

    /**
     * Valide puis ecrit l'image, et renvoie le chemin relatif a stocker en base
     * et a servir aux deux vhosts (ex : uploads/products/ab12...ef.webp).
     *
     * A appeler au plus tard, juste avant l'ecriture en base : a partir d'ici le
     * fichier existe sur le disque.
     *
     * @param array<string, mixed> $file une entree de $_FILES
     *
     * @throws ImageUploadException si le fichier est refuse ou l'ecriture impossible
     */
    public function store(array $file, string $subdir): string
    {
        $mime = $this->validate($file, $subdir);
        $tmp = (string) ($file['tmp_name'] ?? '');

        $directory = $this->prepareDirectory($subdir);
        $name = bin2hex(random_bytes(16)) . '.' . self::EXTENSIONS[$mime];

        if (!$this->moveUploadedFile($tmp, $directory . '/' . $name)) {
            throw new ImageUploadException('Enregistrement de l\'image impossible.');
        }

        // Lecture seule pour le serveur web : rien de ce qui est depose ici n'a
        // vocation a etre execute ni reecrit.
        @chmod($directory . '/' . $name, 0644);

        return 'uploads/' . $subdir . '/' . $name;
    }

    /**
     * Supprime une image precedemment deposee, quand elle est remplacee ou que
     * son produit disparait.
     *
     * Ne touche QUE les chemins de la forme uploads/<sous-dossier>/<nom>, ce qui
     * exclut par construction les images livrees avec le projet
     * (assets/images/...) : un remplacement de photo ne doit pas pouvoir effacer
     * le catalogue d'origine.
     */
    public function remove(?string $relativePath): void
    {
        if ($relativePath === null || $relativePath === '') {
            return;
        }

        if (!preg_match('#^uploads/([a-z]+)/([a-f0-9]{32}\.[a-z]{3,4})$#', $relativePath, $matches)) {
            return;
        }

        if (!in_array($matches[1], self::SUBDIRS, true)) {
            return;
        }

        $path = $this->baseDir . '/' . $matches[1] . '/' . $matches[2];
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Traduit les codes d'erreur de PHP en messages affichables.
     *
     * UPLOAD_ERR_INI_SIZE merite son propre message : il se declenche AVANT que
     * le code ne voie le fichier (plafond php.ini), et un message generique
     * laisserait croire a un bug alors que la photo est simplement trop lourde.
     */
    private function assertTransferSucceeded(int $error): void
    {
        $message = match ($error) {
            UPLOAD_ERR_OK        => null,
            UPLOAD_ERR_NO_FILE   => 'Aucun fichier n\'a été envoyé.',
            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE => sprintf('L\'image dépasse la taille maximale de %d Mo.', $this->maxMegabytes()),
            UPLOAD_ERR_PARTIAL   => 'L\'envoi a été interrompu, merci de réessayer.',
            default              => 'L\'envoi de l\'image a échoué.',
        };

        if ($message !== null) {
            throw new ImageUploadException($message);
        }
    }

    private function maxMegabytes(): int
    {
        $value = $this->config->int('UPLOAD_MAX_SIZE_MB', self::DEFAULT_MAX_MB);

        return $value > 0 ? $value : self::DEFAULT_MAX_MB;
    }

    /**
     * @return list<string>
     */
    private function allowedMimes(): array
    {
        $raw = $this->config->get('UPLOAD_ALLOWED_MIME', self::DEFAULT_MIME) ?? self::DEFAULT_MIME;

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    private function detectMime(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            throw new ImageUploadException('Verification du type de fichier indisponible.');
        }

        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);

        return $mime === false ? '' : $mime;
    }

    private function prepareDirectory(string $subdir): string
    {
        $directory = $this->baseDir . '/' . $subdir;

        // mkdir() suppime (@) comme chmod()/getimagesize() plus haut : l'echec est
        // deja traduit en ImageUploadException juste apres, un warning PHP brut en
        // plus n'apporterait rien et polluerait les journaux de production.
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new ImageUploadException('Dossier de destination indisponible.');
        }

        return $directory;
    }

    /**
     * Point d extension pour les tests : en production il faut la verification
     * native (le fichier vient-il vraiment d un envoi HTTP ?), mais un test
     * unitaire n a pas d envoi HTTP a presenter.
     */
    protected function isUploadedFile(string $path): bool
    {
        return is_uploaded_file($path);
    }

    /** Meme raison que isUploadedFile : deplacement reel en production, simule en test. */
    protected function moveUploadedFile(string $from, string $to): bool
    {
        return move_uploaded_file($from, $to);
    }
}
