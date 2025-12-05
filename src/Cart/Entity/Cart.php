<?php

namespace App\Cart\Entity;

use App\Cart\Repository\CartRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CartRepository::class)]
#[ORM\Table(name: 'cart_cart')]
class Cart
{
  #[ORM\Id]
  #[ORM\GeneratedValue]
  #[ORM\Column]
  private ?int $id = null;

  #[ORM\Column(length: 255)]
  private string $sessionId;

  #[ORM\Column]
  private DateTimeImmutable $createdAt;

  #[ORM\OneToMany(targetEntity: CartItem::class, mappedBy: 'cart', cascade: ['persist', 'remove'], orphanRemoval: true)]
  private Collection $items;

  public function __construct()
  {
    $this->items = new ArrayCollection();
    $this->createdAt = new DateTimeImmutable();
  }

  // Gettery i settery dla id, sessionId, createdAt...

  public function getItems(): Collection
  {
    return $this->items;
  }

  public function addItem(CartItem $item): self
  {
    if (!$this->items->contains($item)) {
      $this->items->add($item);
      $item->setCart($this);
    }
    return $this;
  }

  public function removeItem(CartItem $item): self
  {
    if ($this->items->removeElement($item)) {
      if ($item->getCart() === $this) {
        $item->setCart(null);
      }
    }
    return $this;
  }

  public function getId(): ?int
  {
    return $this->id;
  }

  public function setId(?int $id): void
  {
    $this->id = $id;
  }

  public function getSessionId(): string
  {
    return $this->sessionId;
  }

  public function setSessionId(string $sessionId): void
  {
    $this->sessionId = $sessionId;
  }

  public function getCreatedAt(): DateTimeImmutable
  {
    return $this->createdAt;
  }

  public function setCreatedAt(DateTimeImmutable $createdAt): void
  {
    $this->createdAt = $createdAt;
  }
}