<?php

namespace App\Catalog\Controller;

use App\Catalog\Entity\Product;
use App\Catalog\Form\ProductType;
use App\Catalog\Service\ProductService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/catalog/product', name: 'catalog_product_')]
class ProductController extends AbstractController
{
  public function __construct(
    private readonly ProductService $productService,
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

  #[Route('/new', name: 'create', methods: ['GET', 'POST'])]
  public function create(Request $request): Response
  {
    $product = new Product();
    $form = $this->createForm(ProductType::class, $product);

    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      $this->productService->createProduct($product);

      return $this->redirectToRoute('catalog_product_show', [
        'id' => $product->getId(),
      ]);
    }

    return $this->render('catalog/product/create.html.twig', [
      'form' => $form,
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