<?php

declare(strict_types=1);

namespace App\Shared\Query\Inventory;

/**
 * Query: Sprawdź czy żądana ilość produktu jest dostępna.
 *
 * Odpowiednik Port/Adapter: StockAvailabilityInterface::isAvailable()
 *
 * @example
 * $isAvailable = $queryBus->query(
 *     new CheckStockAvailabilityQuery(productId: 123, quantity: 5)
 * );
 */
final readonly class CheckStockAvailabilityQuery
{
    public function __construct(
        public int $productId,
        public int $quantity,
    ) {}
}
