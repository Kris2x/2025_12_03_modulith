<?php

namespace App\Catalog\Controller;

use App\Catalog\Entity\Category;
use App\Catalog\Form\CategoryType;
use App\Catalog\Service\CategoryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/catalog/category', name: 'catalog_category_')]
class CategoryController extends AbstractController
{
  public function __construct(
    private readonly CategoryService $categoryService,
  ) {}

  #[Route('', name: 'index', methods: ['GET'])]
  public function index(): Response
  {
    $categories = $this->categoryService->getAllCategories();

    return $this->render('catalog/category/index.html.twig', [
      'categories' => $categories,
    ]);
  }

  #[Route('/new', name: 'create', methods: ['GET', 'POST'])]
  public function create(Request $request): Response
  {
    $category = new Category();
    $form = $this->createForm(CategoryType::class, $category);

    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      $this->categoryService->createCategory($category);
      $this->addFlash('success', 'Kategoria utworzona');

      return $this->redirectToRoute('catalog_category_index');
    }

    return $this->render('catalog/category/create.html.twig', [
      'form' => $form,
    ]);
  }

  #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
  public function edit(int $id, Request $request): Response
  {
    $category = $this->categoryService->getCategory($id);

    if (!$category) {
      throw $this->createNotFoundException();
    }

    $form = $this->createForm(CategoryType::class, $category);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      $this->categoryService->updateCategory($category);
      $this->addFlash('success', 'Kategoria zaktualizowana');

      return $this->redirectToRoute('catalog_category_index');
    }

    return $this->render('catalog/category/edit.html.twig', [
      'category' => $category,
      'form' => $form,
    ]);
  }

  #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
  public function delete(int $id): Response
  {
    $category = $this->categoryService->getCategory($id);

    if (!$category) {
      throw $this->createNotFoundException();
    }

    $this->categoryService->deleteCategory($category);
    $this->addFlash('success', 'Kategoria usunięta');

    return $this->redirectToRoute('catalog_category_index');
  }
}
