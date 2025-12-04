<?php

namespace App\Inventory\Entity;

use App\Inventory\Repository\StockItemRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StockItemRepository::class)]
#[ORM\Table(name: 'inventory_stock_item')]
class StockItem
{
  #[ORM\Id]
  #[ORM\GeneratedValue]
  #[ORM\Column]
  private ?int $id = null;

  #[ORM\Column]
  private int $productId;

  #[ORM\Column]
  private int $quantity = 0;

  public function getId(): ?int
  {
    return $this->id;
  }

  public function getProductId(): int
  {
    return $this->productId;
  }

  public function setProductId(int $productId): static
  {
    $this->productId = $productId;
    return $this;
  }

  public function getQuantity(): int
  {
    return $this->quantity;
  }

  public function setQuantity(int $quantity): static
  {
    $this->quantity = $quantity;
    return $this;
  }
}
