<?php

namespace App\Catalog\Event;

readonly class ProductCreatedEvent
{
  public function __construct(
    public int $productId,
    public string $productName,
  ) {}
}