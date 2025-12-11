<?php

declare(strict_types=1);

namespace App\Inventory\QueryHandler;

use App\Shared\Query\Inventory\CheckStockAvailabilityQuery;
use App\Inventory\Service\StockService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
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
