<?php

namespace App\Catalog\Service;

use App\Catalog\Entity\Product;
use App\Catalog\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;

class ProductService
{
  public function __construct(
    private EntityManagerInterface $em,
    private ProductRepository $productRepository,
  )
  {
  }

  public function createProduct(Product $product): void
  {
    $this->em->persist($product);
    $this->em->flush();
  }

  public function getProduct(int $id): ?Product
  {
    return $this->productRepository->find($id);
  }

  public function getAllProducts(): array
  {
    return $this->productRepository->findAll();
  }
}