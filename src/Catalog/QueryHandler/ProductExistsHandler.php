<?php

declare(strict_types=1);

namespace App\Catalog\QueryHandler;

use App\Shared\Query\Catalog\ProductExistsQuery;
use App\Catalog\Repository\ProductRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final class ProductExistsHandler
{
    public function __construct(
        private ProductRepository $productRepository,
    ) {}

    public function __invoke(ProductExistsQuery $query): bool
    {
        return $this->productRepository->find($query->productId) !== null;
    }
}
