<?php

namespace App\Cart\Port;

interface CartProductProviderInterface
{
  public function getPrice(int $productId): string;

  public function productExists(int $productId): bool;

  public function getProductName(int $productId): string;

  /**
   * @param int[] $productIds
   * @return array<int, string> productId => name
   */
  public function getProductNames(array $productIds): array;
}
