<?php

namespace App\Shared\Event;

readonly class ProductDeletedEvent
{
    public function __construct(
        public int $productId,
    ) {}
}
