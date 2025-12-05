<?php

namespace App\Catalog\Entity;

use App\Catalog\Repository\ProductRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Table(name: 'catalog_product')]
class Product
{
  #[ORM\Id]
  #[ORM\GeneratedValue]
  #[ORM\Column]
  private ?int $id = null;

  #[ORM\Column(length: 255)]
  private string $name;

  #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
  private string $price;

  #[ORM\Column(type: 'text', nullable: true)]
  private ?string $description = null;

  #[ORM\ManyToOne(targetEntity: Category::class)]
  #[ORM\JoinColumn(onDelete: 'SET NULL')]
  private ?Category $category = null;

  public function getId(): ?int
  {
    return $this->id;
  }

  public function setId(?int $id): void
  {
    $this->id = $id;
  }

  public function getName(): string
  {
    return $this->name;
  }

  public function setName(string $name): void
  {
    $this->name = $name;
  }

  public function getPrice(): string
  {
    return $this->price;
  }

  public function setPrice(string $price): void
  {
    $this->price = $price;
  }

  public function getDescription(): ?string
  {
    return $this->description;
  }

  public function setDescription(?string $description): void
  {
    $this->description = $description;
  }

  public function getCategory(): ?Category
  {
    return $this->category;
  }

  public function setCategory(?Category $category): void
  {
    $this->category = $category;
  }
}