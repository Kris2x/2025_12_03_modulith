<?php

declare(strict_types=1);

namespace App\Inventory\QueryHandler;

use App\Shared\Query\Inventory\GetStockQuantityQuery;
use App\Inventory\Service\StockService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler dla GetStockQuantityQuery.
 *
 * Handlery są automatycznie rejestrowane przez Symfony Messenger
 * dzięki atrybutowi #[AsMessageHandler].
 *
 * Konwencja nazewnictwa:
 * - Query w Shared/Query/{Module}/
 * - Handler w {Module}/QueryHandler/
 *
 * Porównanie z Port/Adapter:
 * - Port/Adapter: StockInfoAdapter::getQuantity() w Inventory/Adapter/
 * - Query Bus: GetStockQuantityHandler w Inventory/QueryHandler/
 *
 * Handler jest w tym samym module co dane (Inventory),
 * ale Query jest w Shared - dzięki temu Cart/Catalog nie
 * importują niczego z Inventory.
 */
#[AsMessageHandler]
final class GetStockQuantityHandler
{
    public function __construct(
        private StockService $stockService,
    ) {}

    public function __invoke(GetStockQuantityQuery $query): int
    {
        $stockItem = $this->stockService->getStockForProduct($query->productId);

        return $stockItem?->getQuantity() ?? 0;
    }
}
