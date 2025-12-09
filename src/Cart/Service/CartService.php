<?php

namespace App\Cart\Service;

use App\Cart\Entity\Cart;
use App\Cart\Entity\CartItem;
use App\Cart\Repository\CartRepository;
use App\Cart\Repository\CartItemRepository;
use App\Cart\Exception\InsufficientStockException;
use App\Cart\Port\CartProductProviderInterface;
use App\Cart\Port\StockAvailabilityInterface;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

class CartService
{
  public function __construct(
    private CartRepository $cartRepository,
    private CartItemRepository $cartItemRepository,
    private EntityManagerInterface $em,
    private CartProductProviderInterface $priceProvider,
    private StockAvailabilityInterface $stockChecker,
  ) {}


  public function findCart(string $sessionId): ?Cart
  {
    return $this->cartRepository->findBySessionId($sessionId);
  }

  public function createCart(string $sessionId): Cart
  {
    $cart = new Cart();
    $cart->setSessionId($sessionId);
    $this->em->persist($cart);
    $this->em->flush();

    return $cart;
  }

  public function addItem(Cart $cart, int $productId, int $quantity = 1): void
  {
    if (!$this->priceProvider->productExists($productId)) {
      throw new InvalidArgumentException("Product $productId not found");
    }

    // Sprawdź czy produkt już jest w koszyku
    foreach ($cart->getItems() as $item) {
      if ($item->getProductId() === $productId) {
        $newQuantity = $item->getQuantity() + $quantity;
        if (!$this->stockChecker->isAvailable($productId, $newQuantity)) {
          throw new InsufficientStockException($productId, $newQuantity);
        }
        $item->setQuantity($newQuantity);
        $this->em->flush();
        return;
      }
    }

    // Nowa pozycja - sprawdź dostępność
    if (!$this->stockChecker->isAvailable($productId, $quantity)) {
      throw new InsufficientStockException($productId, $quantity);
    }

    // Nowa pozycja
    $item = new CartItem();
    $item->setProductId($productId);
    $item->setQuantity($quantity);
    $item->setPriceAtAdd($this->priceProvider->getPrice($productId));

    $cart->addItem($item);
    $this->em->flush();
  }

  public function removeItem(Cart $cart, int $productId): void
  {
    foreach ($cart->getItems() as $item) {
      if ($item->getProductId() === $productId) {
        $cart->removeItem($item);
        $this->em->flush();
        return;
      }
    }
  }

  public function clear(Cart $cart): void
  {
    foreach ($cart->getItems() as $item) {
      $cart->removeItem($item);
    }
    $this->em->flush();
  }

  public function getTotal(Cart $cart): string
  {
    $total = '0.00';
    foreach ($cart->getItems() as $item) {
      $itemTotal = bcmul($item->getPriceAtAdd(), (string)$item->getQuantity(), 2);
      $total = bcadd($total, $itemTotal, 2);
    }
    return $total;
  }

  /**
   * @return array<int, string> productId => name
   */
  public function getProductNames(Cart $cart): array
  {
    $productIds = [];
    foreach ($cart->getItems() as $item) {
      $productIds[] = $item->getProductId();
    }

    return $this->priceProvider->getProductNames($productIds);
  }

  public function updateItemQuantity(Cart $cart, int $productId, int $quantity): void
  {
    foreach ($cart->getItems() as $item) {
      if ($item->getProductId() === $productId) {
        if ($quantity <= 0) {
          $cart->removeItem($item);
        } else {
          if (!$this->stockChecker->isAvailable($productId, $quantity)) {
            throw new InsufficientStockException($productId, $quantity);
          }
          $item->setQuantity($quantity);
        }
        $this->em->flush();
        return;
      }
    }
  }

  public function removeItemsByProductId(int $productId): int
  {
    return $this->cartItemRepository->removeByProductId($productId);
  }
}