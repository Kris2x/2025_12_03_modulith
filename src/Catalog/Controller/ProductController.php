<?php

namespace App\Catalog\Controller;

use App\Catalog\Service\ProductService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/catalog/product', name: 'catalog_product_')]
class ProductController extends AbstractController
{
  public function __construct(
    private ProductService $productService,
  )
  {
  }

  #[Route('', name: 'index', methods: ['GET'])]
  public function index(): Response
  {
    $products = $this->productService->getAllProducts();

    return $this->render('catalog/product/index.html.twig', [
      'products' => $products,
    ]);
  }

  #[Route('/{id}', name: 'show', methods: ['GET'])]
  public function show(int $id): Response
  {
    $product = $this->productService->getProduct($id);

    if (!$product) {
      throw $this->createNotFoundException();
    }

    return $this->render('catalog/product/show.html.twig', [
      'product' => $product,
    ]);
  }
}