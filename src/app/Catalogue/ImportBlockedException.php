<?php

declare(strict_types=1);

namespace App\Catalogue;

use RuntimeException;

/**
 * Levee par ProductImportService::apply() quand le rapport rejoue AU MOMENT DE
 * L'ECRITURE porte encore au moins une erreur (fichier modifie entre l'aperçu et
 * la confirmation, ou etat de la base ayant change entre-temps). Aucune ecriture
 * n'a lieu : le controleur la traduit en re-affichage de l'apercu, jamais en 500.
 */
final class ImportBlockedException extends RuntimeException
{
}
