<?php

declare(strict_types=1);

namespace App\Shared\Query\Cart;

/**
 * Query: Pobierz ilość produktu w koszyku użytkownika.
 *
 * @example
 * $qty = $queryBus->query(new GetCartQuantityQuery(
 *     sessionId: 'abc123',
 *     productId: 456
 * ));
 */
final readonly class GetCartQuantityQuery
{
    public function __construct(
        public string $sessionId,
        public int $productId,
    ) {}
}
