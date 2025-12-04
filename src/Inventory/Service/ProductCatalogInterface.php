<?php

namespace App\Inventory\Service;

interface ProductCatalogInterface
{
  /**
   * @param int[] $productIds
   * @return array<int, string> Mapa productId => productName
   */
  public function getProductNames(array $productIds): array;
}