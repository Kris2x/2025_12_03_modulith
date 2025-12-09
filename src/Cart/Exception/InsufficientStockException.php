<?php

namespace App\Cart\Exception;

use Exception;

class InsufficientStockException extends Exception
{
    public function __construct(
        public readonly int $productId,
        public readonly int $requestedQuantity,
    ) {
        parent::__construct("Niewystarczająca ilość produktu na stanie");
    }
}
