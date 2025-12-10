<?php

declare(strict_types=1);

namespace App\Inventory\QueryHandler;

use App\Shared\Query\Inventory\CheckStockAvailabilityQuery;
use App\Inventory\Service\StockService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler dla CheckStockAvailabilityQuery.
 *
 * Sprawdza czy żądana ilość produktu jest dostępna na stanie.
 *
 * Porównanie z Port/Adapter:
 * - Port: Cart/Port/StockAvailabilityInterface
 * - Adapter: Inventory/Adapter/StockAvailabilityAdapter
 * - Query Bus: ta klasa (jeden plik zamiast dwóch)
 */
#[AsMessageHandler]
final class CheckStockAvailabilityHandler
{
    public function __construct(
        private StockService $stockService,
    ) {}

    public function __invoke(CheckStockAvailabilityQuery $query): bool
    {
        return $this->stockService->isAvailable(
            $query->productId,
            $query->quantity
        );
    }
}
