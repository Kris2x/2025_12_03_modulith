<?php

namespace App\Catalog\Adapter;

use App\Cart\Port\CartProductProviderInterface;
use App\Catalog\Repository\ProductRepository;
use App\Inventory\Port\ProductCatalogInterface;

class CartProductCatalogProvider implements ProductCatalogInterface, CartProductProviderInterface
{
  public function __construct(
    private readonly ProductRepository $productRepository,
  ) {}

  public function getProductName(int $productId): string
  {
    $product = $this->productRepository->find($productId);

    if (!$product) {
      throw new \InvalidArgumentException("Product $productId not found");
    }

    return $product->getName();
  }

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

  public function getPrice(int $productId): string
  {
    $product = $this->productRepository->find($productId);

    if (!$product) {
      throw new \InvalidArgumentException("Product $productId not found");
    }

    return $product->getPrice();
  }

  public function productExists(int $productId): bool
  {
    return $this->productRepository->find($productId) !== null;
  }
}