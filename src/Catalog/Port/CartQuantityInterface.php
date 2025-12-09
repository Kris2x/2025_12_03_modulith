<?php

namespace App\Catalog\Port;

interface CartQuantityInterface
{
    /**
     * Zwraca ilość danego produktu w koszyku użytkownika.
     */
    public function getQuantityInCart(string $sessionId, int $productId): int;
}
