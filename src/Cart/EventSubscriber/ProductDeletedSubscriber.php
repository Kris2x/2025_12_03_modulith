<?php

namespace App\Cart\EventSubscriber;

use App\Catalog\Event\ProductDeletedEvent;
use App\Cart\Service\CartService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ProductDeletedSubscriber implements EventSubscriberInterface
{
  public function __construct(
    private CartService $cartService,
  ) {}

  public static function getSubscribedEvents(): array
  {
    return [
      ProductDeletedEvent::class => 'onProductDeleted',
    ];
  }

  public function onProductDeleted(ProductDeletedEvent $event): void
  {
    $this->cartService->removeItemsByProductId($event->productId);
  }
}
