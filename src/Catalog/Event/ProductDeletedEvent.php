<?php

namespace App\Catalog\Event;

readonly class ProductDeletedEvent
{
  public function __construct(
    public int $productId,
  ) {}
}
