<?php

namespace App\Inventory\Service;

use App\Inventory\Entity\StockItem;
use App\Inventory\Repository\StockItemRepository;
use Doctrine\ORM\EntityManagerInterface;

class StockService
{
  public function __construct(
    private EntityManagerInterface $em,
    private StockItemRepository $stockItemRepository,
  ) {}

  public function createStockItem(int $productId, int $quantity = 0): StockItem
  {
    $stockItem = new StockItem();
    $stockItem->setProductId($productId);
    $stockItem->setQuantity($quantity);

    $this->em->persist($stockItem);
    $this->em->flush();

    return $stockItem;
  }

  public function getStockForProduct(int $productId): ?StockItem
  {
    return $this->stockItemRepository->findByProductId($productId);
  }

  public function isAvailable(int $productId, int $quantity): bool
  {
    $stock = $this->getStockForProduct($productId);

    return $stock && $stock->getQuantity() >= $quantity;
  }

  public function save(StockItem $stockItem)
  {
    $this->em->flush();
  }
}