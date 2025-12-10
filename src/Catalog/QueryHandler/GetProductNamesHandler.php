<?php

declare(strict_types=1);

namespace App\Catalog\QueryHandler;

use App\Shared\Query\Catalog\GetProductNamesQuery;
use App\Catalog\Repository\ProductRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler dla GetProductNamesQuery.
 *
 * Pobiera nazwy produktów (batch operation).
 *
 * Przykład optymalizacji w Query Bus - jedno zapytanie
 * do bazy dla wielu produktów zamiast N zapytań.
 */
#[AsMessageHandler]
final class GetProductNamesHandler
{
    public function __construct(
        private ProductRepository $productRepository,
    ) {}

    /**
     * @return array<int, string> productId => name
     */
    public function __invoke(GetProductNamesQuery $query): array
    {
        if (empty($query->productIds)) {
            return [];
        }

        $products = $this->productRepository->findBy([
            'id' => $query->productIds,
        ]);

        $names = [];
        foreach ($products as $product) {
            $names[$product->getId()] = $product->getName();
        }

        return $names;
    }
}
