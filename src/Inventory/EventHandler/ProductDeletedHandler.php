<?php

declare(strict_types=1);

namespace App\Inventory\EventHandler;

use App\Inventory\Service\StockService;
use App\Shared\Event\ProductDeletedEvent;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler reagujący na usunięcie produktu.
 *
 * Gdy Catalog usuwa produkt, Inventory automatycznie
 * usuwa powiązany rekord StockItem.
 */
#[AsMessageHandler(bus: 'event.bus')]
final class ProductDeletedHandler
{
    public function __construct(
        private StockService $stockService,
    ) {}

    public function __invoke(ProductDeletedEvent $event): void
    {
        $this->stockService->removeByProductId($event->productId);
    }
}
