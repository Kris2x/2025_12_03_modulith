<?php

namespace App\Inventory\Port;

interface ProductCatalogInterface
{
  /**
   * @param int[] $productIds
   * @return array<int, string> Mapa productId => productName
   */
  public function getProductNames(array $productIds): array;
}