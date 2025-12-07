<?php

namespace App\Catalog\Adapter;

use App\Catalog\Repository\ProductRepository;
use App\Inventory\Port\ProductCatalogInterface;

class InventoryProductAdapter implements ProductCatalogInterface
{
    public function __construct(
        private readonly ProductRepository $productRepository,
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
