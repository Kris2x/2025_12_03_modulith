<?php

namespace App\Cart\Repository;

use App\Cart\Entity\CartItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CartItemRepository extends ServiceEntityRepository
{
  public function __construct(ManagerRegistry $registry)
  {
    parent::__construct($registry, CartItem::class);
  }

  public function removeByProductId(int $productId): int
  {
    return $this->createQueryBuilder('ci')
      ->delete()
      ->where('ci.productId = :productId')
      ->setParameter('productId', $productId)
      ->getQuery()
      ->execute();
  }
}
