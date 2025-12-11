<?php

declare(strict_types=1);

namespace App\Cart\QueryHandler;

use App\Shared\Query\Cart\GetCartQuantityQuery;
use App\Cart\Repository\CartRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final class GetCartQuantityHandler
{
    public function __construct(
        private CartRepository $cartRepository,
    ) {}

    public function __invoke(GetCartQuantityQuery $query): int
    {
        $cart = $this->cartRepository->findBySessionId($query->sessionId);

        if ($cart === null) {
            return 0;
        }

        foreach ($cart->getItems() as $item) {
            if ($item->getProductId() === $query->productId) {
                return $item->getQuantity();
            }
        }

        return 0;
    }
}
