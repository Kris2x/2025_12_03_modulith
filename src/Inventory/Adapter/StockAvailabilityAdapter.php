<?php

namespace App\Inventory\Adapter;

use App\Cart\Port\StockAvailabilityInterface;
use App\Inventory\Service\StockService;

class StockAvailabilityAdapter implements StockAvailabilityInterface
{
  public function __construct(
    private StockService $stockService,
  )
  {
  }

  public function isAvailable(int $productId, int $quantity): bool
  {
    return $this->stockService->isAvailable($productId, $quantity);
  }
}
