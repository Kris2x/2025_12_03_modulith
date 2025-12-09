<?php

namespace App\Cart\Adapter;

use App\Catalog\Port\CartQuantityInterface;
use App\Cart\Service\CartService;

class CartQuantityAdapter implements CartQuantityInterface
{
    public function __construct(
        private CartService $cartService,
    ) {}

    public function getQuantityInCart(string $sessionId, int $productId): int
    {
        $cart = $this->cartService->findCart($sessionId);

        if (!$cart) {
            return 0;
        }

        foreach ($cart->getItems() as $item) {
            if ($item->getProductId() === $productId) {
                return $item->getQuantity();
            }
        }

        return 0;
    }
}
