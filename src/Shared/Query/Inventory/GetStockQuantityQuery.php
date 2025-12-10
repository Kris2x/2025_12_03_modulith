<?php

declare(strict_types=1);

namespace App\Shared\Query\Inventory;

/**
 * Query: Pobierz ilość produktu na stanie.
 *
 * Odpowiednik Port/Adapter: StockInfoInterface::getQuantity()
 *
 * Query to prosty obiekt (DTO) reprezentujący pytanie do systemu.
 * Jest immutable (readonly) i zawiera wszystkie dane potrzebne
 * do udzielenia odpowiedzi.
 *
 * Dlaczego readonly class?
 * - Query nie powinno być modyfikowane po utworzeniu
 * - Promuje constructor injection wszystkich parametrów
 * - PHP 8.2+ feature dla lepszej czytelności
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
