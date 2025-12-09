<?php

namespace App\Catalog\Port;

interface StockInfoInterface
{
    /**
     * Zwraca ilość produktu na stanie.
     */
    public function getQuantity(int $productId): int;

    /**
     * Sprawdza czy produkt jest dostępny (ilość > 0).
     */
    public function isInStock(int $productId): bool;
}
