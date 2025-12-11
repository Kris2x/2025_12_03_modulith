<?php

namespace App\Catalog\Controller;

use App\Catalog\Entity\Product;
use App\Catalog\Form\ProductType;
use App\Catalog\Service\ProductService;
use App\Shared\Bus\QueryBusInterface;
use App\Shared\Query\Cart\GetCartQuantityQuery;
use App\Shared\Query\Inventory\GetStockQuantityQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/catalog/product', name: 'catalog_product_')]
class ProductController extends AbstractController
{
  public function __construct(
    private readonly ProductService $productService,
    private readonly QueryBusInterface $queryBus,
  ) {}

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
  public function show(int $id, Request $request): Response
  {
    $product = $this->productService->getProduct($id);

    if (!$product) {
      throw $this->createNotFoundException();
    }

    $stockQuantity = $this->queryBus->query(new GetStockQuantityQuery($id));
    $cartId = $request->getSession()->get('cart_id', '');
    $quantityInCart = $this->queryBus->query(new GetCartQuantityQuery($cartId, $id));
    $availableQuantity = max(0, $stockQuantity - $quantityInCart);

    return $this->render('catalog/product/show.html.twig', [
      'product' => $product,
      'stockQuantity' => $stockQuantity,
      'quantityInCart' => $quantityInCart,
      'availableQuantity' => $availableQuantity,
      'isInStock' => $availableQuantity > 0,
    ]);
  }

  #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
  public function edit(int $id, Request $request): Response
  {
    $product = $this->productService->getProduct($id);

    if (!$product) {
      throw $this->createNotFoundException();
    }

    $form = $this->createForm(ProductType::class, $product);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      $this->productService->updateProduct($product);
      $this->addFlash('success', 'Produkt zaktualizowany');

      return $this->redirectToRoute('catalog_product_show', ['id' => $product->getId()]);
    }

    return $this->render('catalog/product/edit.html.twig', [
      'product' => $product,
      'form' => $form,
    ]);
  }

  #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
  public function delete(int $id, Request $request): Response
  {
    $product = $this->productService->getProduct($id);

    if (!$product) {
      throw $this->createNotFoundException();
    }

    $this->productService->deleteProduct($product);
    $this->addFlash('success', 'Produkt usunięty');

    return $this->redirectToRoute('catalog_product_index');
  }
}
