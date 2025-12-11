<?php

declare(strict_types=1);

namespace App\Shared\Query\Catalog;

/**
 * Query: Pobierz cenę produktu.
 *
 * @example
 * $price = $queryBus->query(new GetProductPriceQuery(productId: 123));
 * // Returns: "199.99" lub null jeśli produkt nie istnieje
 */
final readonly class GetProductPriceQuery
{
    public function __construct(
        public int $productId,
    ) {}
}
