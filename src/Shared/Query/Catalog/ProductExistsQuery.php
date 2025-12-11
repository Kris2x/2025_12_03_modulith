<?php

declare(strict_types=1);

namespace App\Shared\Query\Catalog;

/**
 * Query: Sprawdź czy produkt istnieje.
 *
 * @example
 * $exists = $queryBus->query(new ProductExistsQuery(productId: 123));
 * // Returns: bool
 */
final readonly class ProductExistsQuery
{
    public function __construct(
        public int $productId,
    ) {}
}
