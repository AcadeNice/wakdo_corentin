<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/**
 * Garde de non-regression sur les assets statiques admin issus de l'audit
 * d'accessibilite Bloc 1 (parite avec la borne + harmonisation du focus).
 *
 * Ce ne sont pas des tests de logique applicative : ils lisent les fichiers
 * REELLEMENT servis par le vhost admin (docker/apache/vhost.conf, DocumentRoot
 * public/admin) pour empecher une regression silencieuse — fichier supprime,
 * regle retiree, copie qui diverge de la borne au prochain edit de l'un des
 * deux fichiers.
 */
final class AdminAccessibilityAssetsTest extends TestCase
{
    private function projectRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private function readOrFail(string $relativePath): string
    {
        $content = file_get_contents($this->projectRoot() . '/' . $relativePath);
        self::assertNotFalse($content, sprintf('fichier illisible : %s', $relativePath));

        return $content;
    }

    public function testDyslexiaToggleScriptIsSharedWithBorneNotDuplicated(): void
    {
        // assets/js/a11y.js est partage physiquement avec la borne (meme fichier :
        // symlink cote admin, cf. la note dans admin/layout.php). Ce test verifie
        // le CONTENU plutot que le mecanisme : il resterait vert si le partage
        // etait remplace par une copie ordinaire, ce qu'il ne doit surtout pas
        // faire sans le savoir (drift silencieux entre borne et admin).
        $borneSource = $this->readOrFail('src/public/borne/assets/js/a11y.js');
        $adminCopy = $this->readOrFail('src/public/admin/assets/js/a11y.js');

        self::assertSame(
            $borneSource,
            $adminCopy,
            'le module a11y admin ne doit pas diverger de la borne (reutilisation, pas duplication)',
        );
    }

    public function testDyslexiaFontFilesAreReachableFromAdminOrigin(): void
    {
        // Le vhost admin sert son propre docroot (public/admin), distinct de celui
        // de la borne : les polices doivent y etre physiquement atteignables, sinon
        // le @font-face d'admin.css echoue silencieusement (repli navigateur muet,
        // aucune erreur visible sans ouvrir les devtools).
        $root = $this->projectRoot();

        self::assertFileExists($root . '/src/public/admin/assets/fonts/opendyslexic-latin-400-normal.woff2');
        self::assertFileExists($root . '/src/public/admin/assets/fonts/opendyslexic-latin-700-normal.woff2');
    }

    public function testFaviconIsReachableFromAdminOrigin(): void
    {
        self::assertFileExists($this->projectRoot() . '/src/public/admin/assets/images/favicon.svg');
    }

    public function testAdminStylesheetDeclaresDyslexiaToggleAndFontFace(): void
    {
        $css = $this->readOrFail('src/public/admin/assets/css/admin.css');

        self::assertStringContainsString('@font-face', $css);
        self::assertStringContainsString('OpenDyslexic', $css);
        self::assertStringContainsString('.a11y-toggle', $css);
        self::assertStringContainsString('html.dys-font', $css);
    }

    public function testAdminStylesheetHasNoBareFocusSelectorLeft(): void
    {
        // Harmonisation Cr 1.c.4 : plus aucun :focus nu, tout est sur :focus-visible
        // (convention deja utilisee par le reste du fichier avant ce lot). Regex
        // negative plutot qu'une liste des 4 selecteurs corriges : capture aussi
        // toute regression future qui reintroduirait un :focus nu ailleurs.
        $css = $this->readOrFail('src/public/admin/assets/css/admin.css');

        self::assertDoesNotMatchRegularExpression('/:focus(?!-visible)/', $css);
    }

    public function testAdminStylesheetHasSkipLinkStyle(): void
    {
        $css = $this->readOrFail('src/public/admin/assets/css/admin.css');

        self::assertStringContainsString('.skip-link', $css);
        self::assertStringContainsString('.skip-link:focus-visible', $css);
    }
}
