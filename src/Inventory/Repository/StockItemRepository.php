<?php

namespace App\Inventory\Repository;

use App\Inventory\Entity\StockItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class StockItemRepository extends ServiceEntityRepository
{
  public function __construct(ManagerRegistry $registry)
  {
    parent::__construct($registry, StockItem::class);
  }

  public function findByProductId(int $productId): ?StockItem
  {
    return $this->findOneBy(['productId' => $productId]);
  }
}