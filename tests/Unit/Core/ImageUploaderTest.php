<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use App\Core\Config;
use App\Core\ImageUploadException;
use App\Tests\Support\TestableImageUploader;

/**
 * ImageUploader est le seul gardien entre un envoi de fichier depuis le
 * back-office et le disque (Cr 4.e.1) : chaque protection documentee par la
 * classe est verrouillee ici par un test qui tente explicitement de la
 * violer (mauvais type, taille, nom malveillant, chemin hors uploads/...).
 *
 * Toutes les images de test sont de VRAIS fichiers valides (1x1, quelques
 * dizaines d'octets) : finfo/getimagesize lisent un contenu reel, jamais une
 * simulation qui contournerait la garde qu'on pretend tester.
 */
final class ImageUploaderTest extends TestCase
{
    /** PNG 1x1 valide et complet (signature + IHDR + IDAT + IEND), 68 octets. */
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    /** GIF 1x1 valide et complet : type reel reconnu par finfo, mais absent d'EXTENSIONS. */
    private const GIF_1X1 = 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';

    private string $baseDir = '';

    /** @var list<string> */
    private array $touchedKeys = [];

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/wakdo_uploads_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->baseDir);
        foreach ($this->touchedKeys as $key) {
            putenv($key);
        }
        $this->touchedKeys = [];
    }

    private function setEnv(string $key, string $value): void
    {
        $this->touchedKeys[] = $key;
        putenv($key . '=' . $value);
    }

    private function uploader(): TestableImageUploader
    {
        return new TestableImageUploader(new Config(), $this->baseDir);
    }

    /**
     * @return array<string, mixed>
     */
    private function fileFor(string $tmpPath, string $clientName = 'photo.png', ?int $size = null, int $error = UPLOAD_ERR_OK): array
    {
        return [
            'name'     => $clientName,
            'type'     => 'application/octet-stream', // jamais consulte par le code : verifie plus bas
            'tmp_name' => $tmpPath,
            'error'    => $error,
            'size'     => $size ?? (is_file($tmpPath) ? (int) filesize($tmpPath) : 0),
        ];
    }

    private function writeTemp(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wakdo_src_');
        self::assertNotFalse($path);
        file_put_contents($path, $bytes);

        return $path;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items === false ? [] : $items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    // --- isSubmitted() -------------------------------------------------------

    public function testIsSubmittedFalseOnNullField(): void
    {
        self::assertFalse($this->uploader()->isSubmitted(null));
    }

    public function testIsSubmittedFalseOnEmptyFileInput(): void
    {
        // Exactement ce que PHP place dans $_FILES pour un <input type="file">
        // laisse vide : le champ existe, mais rien n'a ete choisi.
        $empty = ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0];
        self::assertFalse($this->uploader()->isSubmitted($empty));
    }

    public function testIsSubmittedTrueWhenAFileWasSent(): void
    {
        $file = $this->fileFor($this->writeTemp(base64_decode(self::PNG_1X1)));
        self::assertTrue($this->uploader()->isSubmitted($file));
    }

    public function testIsSubmittedTrueEvenOnTransferError(): void
    {
        // Un envoi rate reste un envoi : isSubmitted() ne juge que "le champ a
        // ete rempli", pas "l'envoi a reussi" (deux questions distinctes, la
        // seconde est le role de validate()).
        $file = ['name' => 'x.png', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0];
        self::assertTrue($this->uploader()->isSubmitted($file));
    }

    // --- refus d'un envoi en erreur -------------------------------------------

    public function testValidateRejectsIniSizeError(): void
    {
        $file = $this->fileFor('', size: 0, error: UPLOAD_ERR_INI_SIZE);
        $this->expectException(ImageUploadException::class);
        $this->expectExceptionMessageMatches('/taille maximale/');
        $this->uploader()->validate($file, 'products');
    }

    public function testValidateRejectsPartialTransfer(): void
    {
        $file = $this->fileFor('', size: 0, error: UPLOAD_ERR_PARTIAL);
        $this->expectException(ImageUploadException::class);
        $this->expectExceptionMessageMatches('/interrompu/');
        $this->uploader()->validate($file, 'products');
    }

    public function testValidateRejectsNoFileError(): void
    {
        $file = $this->fileFor('', size: 0, error: UPLOAD_ERR_NO_FILE);
        $this->expectException(ImageUploadException::class);
        $this->uploader()->validate($file, 'products');
    }

    // --- destination inconnue -------------------------------------------------

    public function testValidateRejectsUnknownSubdir(): void
    {
        $file = $this->fileFor($this->writeTemp(base64_decode(self::PNG_1X1)));
        $this->expectException(ImageUploadException::class);
        $this->expectExceptionMessageMatches('/Destination/');
        $this->uploader()->validate($file, 'avatars');
    }

    // --- fichier qui n'est pas un envoi valide ---------------------------------

    public function testValidateRejectsEmptyTmpName(): void
    {
        $file = $this->fileFor('', size: 10);
        $this->expectException(ImageUploadException::class);
        $this->uploader()->validate($file, 'products');
    }

    public function testValidateRejectsTmpNamePointingNowhere(): void
    {
        // tmp_name ne correspondant a aucun fichier reel (usurpation) : refuse
        // avant toute lecture de contenu.
        $file = $this->fileFor('/nonexistent/path/wakdo-' . bin2hex(random_bytes(4)), size: 10);
        $this->expectException(ImageUploadException::class);
        $this->uploader()->validate($file, 'products');
    }

    // --- taille maximale --------------------------------------------------------

    public function testValidateRejectsFileOverConfiguredMaxSize(): void
    {
        $this->setEnv('UPLOAD_MAX_SIZE_MB', '1');
        $tmp = $this->writeTemp(base64_decode(self::PNG_1X1));
        $file = $this->fileFor($tmp, size: 2 * 1024 * 1024);

        $this->expectException(ImageUploadException::class);
        $this->expectExceptionMessageMatches('/taille maximale de 1 Mo/');
        $this->uploader()->validate($file, 'products');
    }

    public function testValidateRejectsEmptyFile(): void
    {
        $tmp = $this->writeTemp(base64_decode(self::PNG_1X1));
        $file = $this->fileFor($tmp, size: 0);

        $this->expectException(ImageUploadException::class);
        $this->expectExceptionMessageMatches('/vide/');
        $this->uploader()->validate($file, 'products');
    }

    // --- type non autorise / extension ne correspondant pas au contenu reel ----

    public function testValidateRejectsContentThatIsNotAnImageAtAll(): void
    {
        // Le nom pretend etre une image ; le contenu est un script. Le nom n'est
        // jamais consulte (Cr 4.e.1) : seul le contenu compte, et il est refuse.
        $tmp = $this->writeTemp('<?php echo "shell"; ?>');
        $file = $this->fileFor($tmp, clientName: 'photo.png');

        $this->expectException(ImageUploadException::class);
        $this->expectExceptionMessageMatches('/Format d image non accepte/');
        $this->uploader()->validate($file, 'products');
    }

    public function testValidateRejectsARealButDisallowedImageType(): void
    {
        // GIF : type reel correctement identifie par finfo, mais absent de la
        // table EXTENSIONS -> refuse, meme avec un nom .gif honnete.
        $tmp = $this->writeTemp(base64_decode(self::GIF_1X1));
        $file = $this->fileFor($tmp, clientName: 'anim.gif');

        $this->expectException(ImageUploadException::class);
        $this->uploader()->validate($file, 'products');
    }

    public function testHardcodedExtensionTableWinsOverAWideEnvAllowlist(): void
    {
        // Meme si l'environnement autorise (par erreur de configuration) le GIF,
        // la table EXTENSIONS reste le garde-fou de derniere ligne (docblock de
        // la classe) : un type absent d'EXTENSIONS est refuse quoi que dise
        // UPLOAD_ALLOWED_MIME.
        $this->setEnv('UPLOAD_ALLOWED_MIME', 'image/gif,image/jpeg,image/png,image/webp');
        $tmp = $this->writeTemp(base64_decode(self::GIF_1X1));
        $file = $this->fileFor($tmp, clientName: 'anim.gif');

        $this->expectException(ImageUploadException::class);
        $this->uploader()->validate($file, 'products');
    }

    public function testValidateRejectsAFileThatIsOnlyAPngSignatureStub(): void
    {
        // Seuls les 8 octets de signature PNG, sans le moindre chunk derriere :
        // qu'il soit attrape par finfo (type reel) ou par getimagesize (deuxieme
        // lecture, docblock de validate()), le resultat doit rester un refus.
        $tmp = $this->writeTemp("\x89PNG\r\n\x1a\n");
        $file = $this->fileFor($tmp, clientName: 'photo.png');

        $this->expectException(ImageUploadException::class);
        $this->uploader()->validate($file, 'products');
    }

    public function testValidateAcceptsAGenuinePngAndReturnsItsRealMime(): void
    {
        $tmp = $this->writeTemp(base64_decode(self::PNG_1X1));
        $file = $this->fileFor($tmp, clientName: 'ignored-name.txt');

        self::assertSame('image/png', $this->uploader()->validate($file, 'products'));
    }

    public function testValidateNeverWritesToDisk(): void
    {
        $tmp = $this->writeTemp(base64_decode(self::PNG_1X1));
        $file = $this->fileFor($tmp, clientName: 'photo.png');

        $this->uploader()->validate($file, 'products');

        self::assertDirectoryDoesNotExist($this->baseDir . '/products');
    }

    // --- traversee de chemin via le nom fourni par le client --------------------

    public function testStoreIgnoresPathTraversalAttemptInClientName(): void
    {
        $tmp = $this->writeTemp(base64_decode(self::PNG_1X1));
        $file = $this->fileFor($tmp, clientName: '../../../../etc/passwd');

        $relative = $this->uploader()->store($file, 'products');

        self::assertMatchesRegularExpression('#^uploads/products/[a-f0-9]{32}\.png$#', $relative);
        self::assertStringNotContainsString('..', $relative);
        self::assertStringNotContainsString('etc', $relative);
        self::assertStringNotContainsString('passwd', $relative);
        // Le fichier ecrit est bien confine sous products/, jamais remonte.
        self::assertFileExists($this->baseDir . '/' . substr($relative, strlen('uploads/')));
    }

    public function testStoreIgnoresANullByteInClientName(): void
    {
        // Poison null byte, classique de la traversee de chemin historique en C :
        // sans effet ici puisque le nom n'est jamais lu.
        $tmp = $this->writeTemp(base64_decode(self::PNG_1X1));
        $file = $this->fileFor($tmp, clientName: "photo.png\0.php");

        $relative = $this->uploader()->store($file, 'products');

        self::assertMatchesRegularExpression('#^uploads/products/[a-f0-9]{32}\.png$#', $relative);
    }

    // --- nom de fichier genere qui ne rejoue pas l'entree utilisateur -----------

    public function testStoreGeneratesARandomNameUnrelatedToClientInput(): void
    {
        $originalName = 'mon-super-produit-fetiche.png';
        $first = $this->uploader()->store(
            $this->fileFor($this->writeTemp(base64_decode(self::PNG_1X1)), clientName: $originalName),
            'products',
        );
        $second = $this->uploader()->store(
            $this->fileFor($this->writeTemp(base64_decode(self::PNG_1X1)), clientName: $originalName),
            'products',
        );

        self::assertNotSame($first, $second, 'deux envois doivent produire deux noms distincts (pas de rejeu deterministe)');
        self::assertStringNotContainsString('mon-super-produit-fetiche', $first);
        self::assertMatchesRegularExpression('#^uploads/products/[a-f0-9]{32}\.png$#', $first);
    }

    public function testStoreExtensionComesFromRealContentNeverFromClientName(): void
    {
        // Contenu PNG reel, nom pretendant etre un .php : l'extension ecrite vient
        // de EXTENSIONS (contenu reel), jamais du nom -- un .php ne peut pas
        // sortir de store().
        $tmp = $this->writeTemp(base64_decode(self::PNG_1X1));
        $file = $this->fileFor($tmp, clientName: 'evil.php');

        $relative = $this->uploader()->store($file, 'products');

        self::assertStringEndsWith('.png', $relative);
    }

    // --- ecriture reelle ----------------------------------------------------------

    public function testStoreCreatesTheDestinationDirectoryAndWritesTheFile(): void
    {
        self::assertDirectoryDoesNotExist($this->baseDir . '/categories');

        $tmp = $this->writeTemp(base64_decode(self::PNG_1X1));
        $relative = $this->uploader()->store($this->fileFor($tmp), 'categories');

        self::assertStringStartsWith('uploads/categories/', $relative);
        self::assertFileExists($this->baseDir . '/' . substr($relative, strlen('uploads/')));
    }

    public function testStoreThrowsWhenTheDestinationIsBlockedByAFile(): void
    {
        // Un fichier occupe deja l'emplacement attendu du dossier : mkdir()
        // echoue, et l'appelant doit recevoir une exception exploitable plutot
        // qu'un warning PHP brut (cf. le @ ajoute a prepareDirectory()).
        mkdir($this->baseDir, 0755, true);
        file_put_contents($this->baseDir . '/products', 'je bloque le dossier');

        $tmp = $this->writeTemp(base64_decode(self::PNG_1X1));
        $this->expectException(ImageUploadException::class);
        $this->expectExceptionMessageMatches('/indisponible/');
        $this->uploader()->store($this->fileFor($tmp), 'products');
    }

    public function testStoreThrowsWhenTheMoveFails(): void
    {
        $uploader = new class (new Config(), $this->baseDir) extends TestableImageUploader {
            protected function moveUploadedFile(string $from, string $to): bool
            {
                return false;
            }
        };

        $tmp = $this->writeTemp(base64_decode(self::PNG_1X1));
        $this->expectException(ImageUploadException::class);
        $this->expectExceptionMessageMatches('/Enregistrement/');
        $uploader->store($this->fileFor($tmp), 'products');
    }

    // --- remove() ----------------------------------------------------------------

    public function testRemoveIgnoresNullAndEmptyPath(): void
    {
        $uploader = $this->uploader();
        $uploader->remove(null);
        $uploader->remove('');

        self::assertDirectoryDoesNotExist($this->baseDir);
    }

    public function testRemoveDeletesAGenuineUploadedImage(): void
    {
        $tmp = $this->writeTemp(base64_decode(self::PNG_1X1));
        $relative = $this->uploader()->store($this->fileFor($tmp), 'products');
        $absolute = $this->baseDir . '/' . substr($relative, strlen('uploads/'));
        self::assertFileExists($absolute);

        $this->uploader()->remove($relative);

        self::assertFileDoesNotExist($absolute);
    }

    public function testRemoveNeverTouchesShippedCatalogueImages(): void
    {
        // Chemin du catalogue livre avec le projet (db/seeds/0002_catalogue.sql,
        // convention assets/images/...) : ne correspond pas au motif
        // uploads/<sous-dossier>/<nom-32-hex>.<ext> et ne doit donc jamais etre
        // touche par un remplacement/suppression d'image gere par ce depot.
        $catalogueRelative = 'assets/images/categories/menus.png';
        $catalogueAbsolute = $this->baseDir . '/' . $catalogueRelative;
        mkdir(dirname($catalogueAbsolute), 0755, true);
        file_put_contents($catalogueAbsolute, 'image du catalogue livre avec le projet');

        $this->uploader()->remove($catalogueRelative);

        self::assertFileExists($catalogueAbsolute);
    }

    public function testRemoveRejectsAnUnknownSubdirectoryEvenWithAValidLookingName(): void
    {
        // Le nom respecte le format genere (32 hex + extension courte) mais le
        // sous-dossier n'est pas dans l'allowlist SUBDIRS : refuse quand meme.
        $hex = str_repeat('a', 32);
        $sneaky = $this->baseDir . '/avatars/' . $hex . '.png';
        mkdir(dirname($sneaky), 0755, true);
        file_put_contents($sneaky, 'x');

        $this->uploader()->remove('uploads/avatars/' . $hex . '.png');

        self::assertFileExists($sneaky);
    }

    public function testRemoveIgnoresEveryMalformedVariantOfTheGeneratedNamePattern(): void
    {
        $uploader = $this->uploader();
        $candidates = [
            'uploads/products/../../etc/passwd',                  // traversee de chemin
            'uploads/products/' . str_repeat('a', 31) . '.png',    // hex trop court
            'uploads/products/' . str_repeat('A', 32) . '.png',    // hex majuscule refuse
            'uploads/products/' . str_repeat('a', 32) . '.p',      // extension trop courte
            'uploads/products/' . str_repeat('a', 32) . '.jpegxx', // extension trop longue
            '/etc/passwd',                                        // chemin absolu hors uploads/
            'uploads//' . str_repeat('a', 32) . '.png',            // sous-dossier vide
        ];

        foreach ($candidates as $candidate) {
            $uploader->remove($candidate);
        }

        // Aucun de ces motifs ne matche le regex ancre de remove() : pas la
        // moindre ecriture disque, le dossier racine des uploads n'existe meme pas.
        self::assertDirectoryDoesNotExist($this->baseDir);
    }
}
