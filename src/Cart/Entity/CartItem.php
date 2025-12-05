<?php

namespace App\Cart\Entity;

namespace App\Cart\Entity;

use App\Cart\Repository\CartItemRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CartItemRepository::class)]
#[ORM\Table(name: 'cart_item')]
class CartItem
{
  #[ORM\Id]
  #[ORM\GeneratedValue]
  #[ORM\Column]
  private ?int $id = null;

  #[ORM\ManyToOne(targetEntity: Cart::class, inversedBy: 'items')]
  #[ORM\JoinColumn(nullable: false)]
  private ?Cart $cart = null;

  #[ORM\Column]
  private int $productId;

  #[ORM\Column]
  private int $quantity = 1;

  #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
  private string $priceAtAdd;

  public function getId(): ?int
  {
    return $this->id;
  }

  public function setId(?int $id): void
  {
    $this->id = $id;
  }

  public function getCart(): ?Cart
  {
    return $this->cart;
  }

  public function setCart(?Cart $cart): void
  {
    $this->cart = $cart;
  }

  public function getProductId(): int
  {
    return $this->productId;
  }

  public function setProductId(int $productId): void
  {
    $this->productId = $productId;
  }

  public function getQuantity(): int
  {
    return $this->quantity;
  }

  public function setQuantity(int $quantity): void
  {
    $this->quantity = $quantity;
  }

  public function getPriceAtAdd(): string
  {
    return $this->priceAtAdd;
  }

  public function setPriceAtAdd(string $priceAtAdd): void
  {
    $this->priceAtAdd = $priceAtAdd;
  }
}