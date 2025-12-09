<?php

namespace App\Inventory\Adapter;

use App\Catalog\Port\StockInfoInterface;
use App\Inventory\Service\StockService;

class StockInfoAdapter implements StockInfoInterface
{
    public function __construct(
        private StockService $stockService,
    ) {}

    public function getQuantity(int $productId): int
    {
        $stockItem = $this->stockService->getStockForProduct($productId);
        return $stockItem?->getQuantity() ?? 0;
    }

    public function isInStock(int $productId): bool
    {
        return $this->stockService->isAvailable($productId, 1);
    }
}
