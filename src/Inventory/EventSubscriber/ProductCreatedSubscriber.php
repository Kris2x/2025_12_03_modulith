<?php

namespace App\Inventory\EventSubscriber;

use App\Catalog\Event\ProductCreatedEvent;
use App\Inventory\Service\StockService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ProductCreatedSubscriber implements EventSubscriberInterface
{
  public function __construct(
    private StockService $stockService,
  ) {}

  public static function getSubscribedEvents(): array
  {
    return [
      ProductCreatedEvent::class => 'onProductCreated',
    ];
  }

  public function onProductCreated(ProductCreatedEvent $event): void
  {
    $this->stockService->createStockItem($event->productId);
  }
}