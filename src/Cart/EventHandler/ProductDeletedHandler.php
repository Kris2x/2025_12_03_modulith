<?php

declare(strict_types=1);

namespace App\Cart\EventHandler;

use App\Cart\Service\CartService;
use App\Shared\Event\ProductDeletedEvent;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler reagujący na usunięcie produktu.
 *
 * Gdy Catalog usuwa produkt, Cart automatycznie
 * usuwa ten produkt ze wszystkich koszyków.
 */
#[AsMessageHandler(bus: 'event.bus')]
final class ProductDeletedHandler
{
    public function __construct(
        private CartService $cartService,
    ) {}

    public function __invoke(ProductDeletedEvent $event): void
    {
        $this->cartService->removeItemsByProductId($event->productId);
    }
}
