<?php

namespace App\Catalog\Service;

use App\Catalog\Entity\Product;
use App\Catalog\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Catalog\Event\ProductCreatedEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class ProductService
{
  public function __construct(
    private EntityManagerInterface $em,
    private ProductRepository $productRepository,
    private EventDispatcherInterface $dispatcher,
  )
  {
  }

  public function createProduct(Product $product): void
  {
    $this->em->persist($product);
    $this->em->flush();

    // Dispatch event po zapisaniu
    $this->dispatcher->dispatch(new ProductCreatedEvent(
      $product->getId(),
      $product->getName(),
    ));
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