<?php

namespace App\Catalog\Service;

use App\Catalog\Repository\ProductRepository;
use App\Inventory\Service\ProductCatalogInterface;

class ProductCatalogProvider implements ProductCatalogInterface
{
  public function __construct(
    private ProductRepository $productRepository,
  ) {}

  public function getProductNames(array $productIds): array
  {
    if (empty($productIds)) {
      return [];
    }

    $products = $this->productRepository->findBy(['id' => $productIds]);

    $names = [];
    foreach ($products as $product) {
      $names[$product->getId()] = $product->getName();
    }

    return $names;
  }
}