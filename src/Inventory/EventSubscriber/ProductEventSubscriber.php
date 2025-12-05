<?php

namespace App\Inventory\EventSubscriber;

use App\Catalog\Event\ProductCreatedEvent;
use App\Catalog\Event\ProductDeletedEvent;
use App\Inventory\Service\StockService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ProductEventSubscriber implements EventSubscriberInterface
{
  public function __construct(
    private StockService $stockService,
  ) {}

  public static function getSubscribedEvents(): array
  {
    return [
      ProductCreatedEvent::class => 'onProductCreated',
      ProductDeletedEvent::class => 'onProductDeleted',
    ];
  }

  public function onProductCreated(ProductCreatedEvent $event): void
  {
    $this->stockService->createStockItem($event->productId);
  }

  public function onProductDeleted(ProductDeletedEvent $event): void
  {
    $this->stockService->removeByProductId($event->productId);
  }
}