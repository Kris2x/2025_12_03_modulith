<?php

namespace App\Catalog\Service;

use App\Catalog\Entity\Product;
use App\Catalog\Repository\ProductRepository;
use App\Shared\Bus\EventBusInterface;
use App\Shared\Event\ProductCreatedEvent;
use App\Shared\Event\ProductDeletedEvent;
use Doctrine\ORM\EntityManagerInterface;

class ProductService
{
    public function __construct(
        private EntityManagerInterface $em,
        private ProductRepository $productRepository,
        private EventBusInterface $eventBus,
    ) {}

    public function createProduct(Product $product): void
    {
        $this->em->persist($product);
        $this->em->flush();

        // Dispatch event po zapisaniu
        $this->eventBus->dispatch(new ProductCreatedEvent(
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

    public function updateProduct(Product $product): void
    {
        $this->em->flush();
    }

    public function deleteProduct(Product $product): void
    {
        $productId = $product->getId();

        $this->em->remove($product);
        $this->em->flush();

        // Dispatch event po usunięciu
        $this->eventBus->dispatch(new ProductDeletedEvent($productId));
    }
}