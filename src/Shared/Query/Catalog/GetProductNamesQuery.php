<?php

declare(strict_types=1);

namespace App\Shared\Query\Catalog;

/**
 * Query: Pobierz nazwy produktów (batch).
 *
 * @example
 * $names = $queryBus->query(new GetProductNamesQuery(productIds: [1, 2, 3]));
 * // Returns: [1 => "iPhone", 2 => "MacBook", 3 => "AirPods"]
 */
final readonly class GetProductNamesQuery
{
    /**
     * @param int[] $productIds
     */
    public function __construct(
        public array $productIds,
    ) {}
}
