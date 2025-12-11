<?php

declare(strict_types=1);

namespace App\Shared\Query\Inventory;

/**
 * Query: Pobierz ilość produktu na stanie.
 *
 * @example
 * $quantity = $queryBus->query(new GetStockQuantityQuery(productId: 123));
 */
final readonly class GetStockQuantityQuery
{
    public function __construct(
        public int $productId,
    ) {}
}
