<?php

namespace App\Cart\Repository;
use App\Cart\Entity\Cart;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CartRepository extends ServiceEntityRepository
{
  public function __construct(ManagerRegistry $registry)
  {
    parent::__construct($registry, Cart::class);
  }

  public function findBySessionId(string $sessionId): ?Cart
  {
    return $this->findOneBy(['sessionId' => $sessionId]);
  }
}