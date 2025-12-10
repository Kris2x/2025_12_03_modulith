<?php

declare(strict_types=1);

namespace App\Catalog\QueryHandler;

use App\Shared\Query\Catalog\GetProductPriceQuery;
use App\Catalog\Repository\ProductRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler dla GetProductPriceQuery.
 *
 * Pobiera cenę produktu po ID.
 *
 * Porównanie z Port/Adapter:
 * - Port: Cart/Port/CartProductProviderInterface::getPrice()
 * - Adapter: Catalog/Adapter/CartProductAdapter::getPrice()
 * - Query Bus: ta klasa
 */
#[AsMessageHandler]
final class GetProductPriceHandler
{
    public function __construct(
        private ProductRepository $productRepository,
    ) {}

    public function __invoke(GetProductPriceQuery $query): ?string
    {
        $product = $this->productRepository->find($query->productId);

        return $product?->getPrice();
    }
}
