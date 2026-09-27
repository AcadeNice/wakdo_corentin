<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use App\Core\Asset;

/**
 * Marqueur de version des fichiers statiques du back-office.
 *
 * Le defaut couvert : apres un deploiement, un navigateur reexecutait l'ANCIEN
 * JavaScript parce que l'adresse du fichier ne changeait jamais. Les tests
 * verrouillent les trois proprietes attendues du marqueur : il est present, il
 * change quand la version change, et son absence ne produit jamais une adresse
 * cassee (installation locale ou pile de test, ou src/VERSION n'existe pas --
 * ce fichier est ignore par git et ecrit par scripts/deploy.sh).
 */
final class AssetTest extends TestCase
{
    private string $baseDir = '';

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/wakdo_asset_' . bin2hex(random_bytes(6));
        mkdir($this->baseDir . '/docroot/assets/js', 0o777, true);
        file_put_contents($this->baseDir . '/docroot/assets/js/app.js', "// v1\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->baseDir);
    }

    private function asset(?string $versionLine): Asset
    {
        $versionFile = $this->baseDir . '/VERSION';

        if ($versionLine !== null) {
            file_put_contents($versionFile, $versionLine);
        }

        return new Asset($this->baseDir . '/docroot', $versionFile);
    }

    // === Cas nominal : le marqueur de deploiement ===

    public function testAppendsTheDeployMarkerToTheAddress(): void
    {
        $asset = $this->asset("deb8942 2026-09-26T16:40:32+00:00\n");

        self::assertSame('/assets/js/app.js?v=deb8942', $asset->url('/assets/js/app.js'));
    }

    public function testUsesTheSameMarkerForEveryFileOfOneDeployment(): void
    {
        // Un deploiement = une version : tous les fichiers basculent ensemble.
        $asset = $this->asset("deb8942 2026-09-26T16:40:32+00:00\n");

        self::assertSame('/assets/css/admin.css?v=deb8942', $asset->url('/assets/css/admin.css'));
        self::assertSame('/assets/js/app.js?v=deb8942', $asset->url('/assets/js/app.js'));
    }

    public function testTheMarkerIsStableAcrossCallsForTheSameVersion(): void
    {
        $asset = $this->asset("deb8942 2026-09-26T16:40:32+00:00\n");

        self::assertSame($asset->url('/assets/js/app.js'), $asset->url('/assets/js/app.js'));
    }

    public function testTheMarkerChangesWhenTheDeployedVersionChanges(): void
    {
        $before = $this->asset("deb8942 2026-09-26T16:40:32+00:00\n")->url('/assets/js/app.js');
        $after = $this->asset("af94de4 2026-09-27T09:10:00+00:00\n")->url('/assets/js/app.js');

        self::assertNotSame($before, $after);
        self::assertSame('/assets/js/app.js?v=af94de4', $after);
    }

    public function testKeepsAnAlreadyPresentQueryStringIntact(): void
    {
        $asset = $this->asset("deb8942 2026-09-26T16:40:32+00:00\n");

        self::assertSame(
            '/assets/js/app.js?debug=1&v=deb8942',
            $asset->url('/assets/js/app.js?debug=1'),
        );
    }

    // === Robustesse du contenu de src/VERSION ===

    public function testRejectsCharactersThatWouldBreakTheAddress(): void
    {
        // Une ligne corrompue ne doit jamais injecter d'espace, de guillemet ni
        // de separateur de parametre dans l'attribut src/href rendu.
        $asset = $this->asset("de b\"89&42 2026-09-26T16:40:32+00:00\n");
        $url = $asset->url('/assets/js/app.js');

        self::assertMatchesRegularExpression('#^/assets/js/app\.js\?v=[A-Za-z0-9._-]+$#', $url);
    }

    public function testFallsBackWhenTheVersionLineIsOnlyWhitespace(): void
    {
        $asset = $this->asset("   \n");
        $url = $asset->url('/assets/js/app.js');

        // Pas de marqueur vide : soit un repli exploitable, soit rien du tout.
        self::assertStringStartsWith('/assets/js/app.js', $url);
        self::assertStringNotContainsString('v=&', $url);
        self::assertDoesNotMatchRegularExpression('/[?&]v=$/', $url);
    }

    public function testFallsBackWhenTheVersionLineHasNoUsableCharacter(): void
    {
        $asset = $this->asset("@@@ 2026-09-26T16:40:32+00:00\n");
        $url = $asset->url('/assets/js/app.js');

        self::assertDoesNotMatchRegularExpression('/[?&]v=$/', $url);
        self::assertStringNotContainsString('@', $url);
    }

    // === Absence du fichier de version (dev local, pile de test) ===

    public function testStillProducesAValidAddressWithoutTheVersionFile(): void
    {
        $asset = $this->asset(null);
        $url = $asset->url('/assets/js/app.js');

        self::assertMatchesRegularExpression('#^/assets/js/app\.js\?v=[A-Za-z0-9._-]+$#', $url);
    }

    public function testFallsBackToTheFileItselfAndChangesWithItsContent(): void
    {
        $asset = $this->asset(null);
        $before = $asset->url('/assets/js/app.js');

        // Repli par date de modification : une edition locale doit se voir.
        touch($this->baseDir . '/docroot/assets/js/app.js', time() + 60);
        clearstatcache();

        self::assertNotSame($before, $this->asset(null)->url('/assets/js/app.js'));
    }

    public function testReturnsThePathUnchangedWhenNothingCanBeMeasured(): void
    {
        // Ni fichier de version, ni fichier statique : on rend l'adresse telle
        // quelle plutot qu'une adresse portant un marqueur vide ou invente.
        $asset = $this->asset(null);

        self::assertSame('/assets/js/absent.js', $asset->url('/assets/js/absent.js'));
    }

    public function testNeverEmitsAnEmptyMarker(): void
    {
        $asset = $this->asset(null);

        foreach (['/assets/js/app.js', '/assets/js/absent.js', '/assets/css/absent.css'] as $path) {
            self::assertDoesNotMatchRegularExpression('/[?&]v=(&|$)/', $asset->url($path));
        }
    }

    // === Garde de chemin ===

    public function testDoesNotFollowATraversingPathToMeasureAFile(): void
    {
        $asset = $this->asset(null);

        self::assertSame('/assets/js/../../../VERSION', $asset->url('/assets/js/../../../VERSION'));
    }

    // === Garde d'usage : une seule fonction d'aide, appelee partout ===

    public function testNoViewReferencesAStaticFileWithoutTheHelper(): void
    {
        // Un suffixe recopie a la main dans une vue serait oublie a la suivante :
        // toute adresse /assets/... ecrite en dur dans un attribut est refusee ici,
        // pour que le marqueur reste porte par une fonction unique ($asset()).
        $offenders = [];

        foreach ($this->viewFiles() as $file) {
            $content = (string) file_get_contents($file);

            if (preg_match_all('/(?:src|href)="(\/assets\/[^"]*)"/', $content, $matches) > 0) {
                foreach ($matches[1] as $address) {
                    $offenders[] = basename(dirname($file)) . '/' . basename($file) . ' -> ' . $address;
                }
            }
        }

        self::assertSame([], $offenders);
    }

    /**
     * @return list<string>
     */
    private function viewFiles(): array
    {
        $root = dirname(__DIR__, 3) . '/src/app/Views';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        $files = [];

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        self::assertNotSame([], $files, 'Aucune vue trouvee sous src/app/Views.');
        sort($files);

        return $files;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
