<?php

declare(strict_types=1);

namespace App\Catalogue;

use RuntimeException;

/**
 * Levee par MenuRepository::update() (reconcileSlots()) quand la configuration
 * soumise retire un menu_slot deja reference par order_item_selection (FK
 * ON DELETE RESTRICT, db/migrations/0001 ~l.391). Aucune ecriture de suppression
 * n'a lieu : la garde tourne AVANT toute ecriture (voir reconcileSlots()), et le
 * controleur traduit cette exception en 409 (conflit d'etat, ADR-0006) plutot que
 * de laisser remonter un SQLSTATE 23000 non intercepte (500).
 */
final class MenuSlotInUseException extends RuntimeException
{
}
