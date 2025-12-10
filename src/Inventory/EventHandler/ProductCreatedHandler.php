<?php

declare(strict_types=1);

namespace App\Inventory\EventHandler;

use App\Inventory\Service\StockService;
use App\Shared\Event\ProductCreatedEvent;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler reagujący na utworzenie produktu.
 *
 * Gdy Catalog tworzy nowy produkt, Inventory automatycznie
 * tworzy dla niego rekord StockItem z początkową ilością 0.
 */
#[AsMessageHandler(bus: 'event.bus')]
final class ProductCreatedHandler
{
    public function __construct(
        private StockService $stockService,
    ) {}

    public function __invoke(ProductCreatedEvent $event): void
    {
        $this->stockService->createStockItem($event->productId);
    }
}
