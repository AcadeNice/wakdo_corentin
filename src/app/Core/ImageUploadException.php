<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Envoi d'image refuse.
 *
 * Le message porte par cette exception est destine a etre reaffiche tel quel
 * dans le formulaire : il reste donc factuel et sans detail d'implementation
 * (pas de chemin serveur, pas de type MIME brut), pour ne rien apprendre a
 * quelqu'un qui sonderait le formulaire.
 */
final class ImageUploadException extends RuntimeException
{
}
