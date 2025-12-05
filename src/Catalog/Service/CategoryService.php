<?php

namespace App\Catalog\Service;

use App\Catalog\Entity\Category;
use App\Catalog\Repository\CategoryRepository;
use App\Catalog\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;

class CategoryService
{
  public function __construct(
    private EntityManagerInterface $em,
    private CategoryRepository $categoryRepository,
    private ProductRepository $productRepository,
  ) {}

  public function createCategory(Category $category): void
  {
    $this->em->persist($category);
    $this->em->flush();
  }

  public function getCategory(int $id): ?Category
  {
    return $this->categoryRepository->find($id);
  }

  public function getAllCategories(): array
  {
    return $this->categoryRepository->findAll();
  }

  public function updateCategory(Category $category): void
  {
    $this->em->flush();
  }

  public function deleteCategory(Category $category): void
  {
    $this->em->remove($category);
    $this->em->flush();
  }

  public function countProductsInCategory(Category $category): int
  {
    return $this->productRepository->countByCategory($category);
  }
}
