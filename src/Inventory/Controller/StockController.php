<?php

namespace App\Inventory\Controller;

use App\Inventory\Entity\StockItem;
use App\Inventory\Form\StockItemType;
use App\Inventory\Repository\StockItemRepository;
use App\Inventory\Service\StockService;
use App\Shared\Bus\QueryBusInterface;
use App\Shared\Query\Catalog\GetProductNamesQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/inventory/stock', name: 'inventory_stock_')]
class StockController extends AbstractController
{
  public function __construct(
    private StockService $stockService,
    private StockItemRepository $stockItemRepository,
    private QueryBusInterface $queryBus,
  ) {}

  #[Route('', name: 'index', methods: ['GET'])]
  public function index(): Response
  {
    $stockItems = $this->stockItemRepository->findAll();

    $productIds = array_map(
      fn($item) => $item->getProductId(),
      $stockItems
    );

    $productNames = $this->queryBus->query(new GetProductNamesQuery($productIds));

    return $this->render('inventory/stock/index.html.twig', [
      'stockItems' => $stockItems,
      'productNames' => $productNames,
    ]);
  }


  #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
  public function edit(StockItem $stockItem, Request $request): Response
  {
    $form = $this->createForm(StockItemType::class, $stockItem);

    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      $this->stockService->save($stockItem);

      return $this->redirectToRoute('inventory_stock_index');
    }

    $productNames = $this->queryBus->query(
      new GetProductNamesQuery([$stockItem->getProductId()])
    );
    $productName = $productNames[$stockItem->getProductId()] ?? 'Nieznany';

    return $this->render('inventory/stock/edit.html.twig', [
      'stockItem' => $stockItem,
      'productName' => $productName,
      'form' => $form,
    ]);
  }
}
