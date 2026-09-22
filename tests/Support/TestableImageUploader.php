<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Core\ImageUploader;

/**
 * Sous-classe de test d'ImageUploader (cf. docblock de la classe : non `final`
 * pour cette seule raison). is_uploaded_file() ne repond vrai qu'a l'issue d'un
 * envoi HTTP reellement traite par PHP -- jamais le cas en test unitaire, meme
 * avec un fichier temporaire bien reel sur le disque. On retombe donc sur
 * is_file()/rename(), l'equivalent filesystem le plus proche, exactement comme
 * les controleurs le font deja pour leurs propres points d'extension (db(),
 * sessionManager()...).
 *
 * Non `final` : ImageUploaderTest specialise encore moveUploadedFile() dans une
 * classe anonyme pour simuler un echec d'ecriture (testStoreThrowsWhenTheMoveFails).
 */
class TestableImageUploader extends ImageUploader
{
    protected function isUploadedFile(string $path): bool
    {
        return is_file($path);
    }

    protected function moveUploadedFile(string $from, string $to): bool
    {
        return rename($from, $to);
    }
}
