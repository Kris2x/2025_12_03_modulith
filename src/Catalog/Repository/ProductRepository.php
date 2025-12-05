<?php

namespace App\Catalog\Repository;

use App\Catalog\Entity\Product;
use App\Catalog\Entity\Category;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ProductRepository extends ServiceEntityRepository
{
  public function __construct(ManagerRegistry $registry)
  {
    parent::__construct($registry, Product::class);
  }

  public function countByCategory(Category $category): int
  {
    return $this->count(['category' => $category]);
  }
}
