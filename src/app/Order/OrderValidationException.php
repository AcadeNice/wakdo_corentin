<?php

declare(strict_types=1);

namespace App\Order;

/**
 * Erreur de validation d'une commande (reference invalide, indisponible, selection
 * hors slot, modifier interdit...). Le code machine (`$this->getMessage()`) sert de
 * code d'erreur API ; le controleur le traduit en reponse HTTP 422.
 */
final class OrderValidationException extends \RuntimeException
{
}
