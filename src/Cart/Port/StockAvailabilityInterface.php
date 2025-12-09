<?php

namespace App\Cart\Port;

interface StockAvailabilityInterface
{
  /**
   * Sprawdza czy podana ilość produktu jest dostępna na stanie.
   */
  public function isAvailable(int $productId, int $quantity): bool;
}