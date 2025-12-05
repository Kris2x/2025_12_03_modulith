# Moduł Catalog

## Przegląd

Moduł **Catalog** odpowiada za zarządzanie produktami i kategoriami w systemie e-commerce. Jest to główny moduł dostarczający dane o produktach dla pozostałych modułów.

## Struktura

```
src/Catalog/
├── Adapter/
│   └── CartProductCatalogProvider.php    # Implementacja portów dla innych modułów
├── Controller/
│   ├── CategoryController.php            # CRUD kategorii
│   └── ProductController.php             # CRUD produktów
├── Entity/
│   ├── Category.php                      # Encja kategorii
│   └── Product.php                       # Encja produktu
├── Event/
│   ├── ProductCreatedEvent.php           # Event tworzenia produktu
│   └── ProductDeletedEvent.php           # Event usunięcia produktu
├── Form/
│   ├── CategoryType.php                  # Formularz kategorii
│   └── ProductType.php                   # Formularz produktu
├── Repository/
│   ├── CategoryRepository.php            # Repozytorium kategorii
│   └── ProductRepository.php             # Repozytorium produktów
└── Service/
    ├── CategoryService.php               # Logika biznesowa kategorii
    └── ProductService.php                # Logika biznesowa produktów
```

---

## Encje

### Product

**Tabela:** `catalog_product`

| Pole | Typ | Opis |
|------|-----|------|
| `id` | int | Klucz główny (auto-increment) |
| `name` | string(255) | Nazwa produktu |
| `price` | decimal(10,2) | Cena produktu |
| `description` | text (nullable) | Opis produktu |
| `category_id` | int (nullable, FK) | Relacja do kategorii |

**Relacje:**
- `ManyToOne` do `Category` z opcją `ON DELETE SET NULL` - usunięcie kategorii ustawia `category_id` na NULL

```php
#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Table(name: 'catalog_product')]
class Product
{
    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Category $category = null;
}
```

### Category

**Tabela:** `catalog_category`

| Pole | Typ | Opis |
|------|-----|------|
| `id` | int | Klucz główny (auto-increment) |
| `name` | string(255) | Nazwa kategorii |

---

## Serwisy

### ProductService

Główny serwis do zarządzania produktami.

**Zależności:**
- `EntityManagerInterface` - operacje na bazie
- `ProductRepository` - dostęp do produktów
- `EventDispatcherInterface` - emitowanie eventów

**Metody:**

| Metoda | Opis | Eventy |
|--------|------|--------|
| `createProduct(Product)` | Tworzy nowy produkt | `ProductCreatedEvent` |
| `getProduct(int)` | Pobiera produkt po ID | - |
| `getAllProducts()` | Pobiera wszystkie produkty | - |
| `updateProduct(Product)` | Aktualizuje produkt | - |
| `deleteProduct(Product)` | Usuwa produkt | `ProductDeletedEvent` |

### CategoryService

Serwis do zarządzania kategoriami.

**Metody:**

| Metoda | Opis |
|--------|------|
| `createCategory(Category)` | Tworzy nową kategorię |
| `getCategory(int)` | Pobiera kategorię po ID |
| `getAllCategories()` | Pobiera wszystkie kategorie |
| `updateCategory(Category)` | Aktualizuje kategorię |
| `deleteCategory(Category)` | Usuwa kategorię |
| `countProductsInCategory(Category)` | Liczy produkty w kategorii |

---

## Eventy (zdarzenia domenowe)

### ProductCreatedEvent

Emitowany po utworzeniu nowego produktu.

```php
readonly class ProductCreatedEvent
{
    public function __construct(
        public int $productId,
        public string $productName,
    ) {}
}
```

**Subskrybenci:**
- `Inventory\ProductEventSubscriber` - tworzy `StockItem` dla nowego produktu

### ProductDeletedEvent

Emitowany po usunięciu produktu.

```php
readonly class ProductDeletedEvent
{
    public function __construct(
        public int $productId,
    ) {}
}
```

**Subskrybenci:**
- `Inventory\ProductEventSubscriber` - usuwa `StockItem`
- `Cart\ProductDeletedSubscriber` - usuwa pozycje koszyka z tym produktem

---

## Adaptery (Porty wyjściowe)

### CartProductCatalogProvider

Adapter implementujący interfejsy wymagane przez inne moduły.

**Implementuje:**
- `Cart\Port\CartProductProviderInterface`
- `Inventory\Port\ProductCatalogInterface`

**Metody:**

| Metoda | Interfejs | Opis |
|--------|-----------|------|
| `getPrice(int)` | Cart | Pobiera cenę produktu |
| `productExists(int)` | Cart | Sprawdza czy produkt istnieje |
| `getProductName(int)` | Cart | Pobiera nazwę pojedynczego produktu |
| `getProductNames(array)` | Cart, Inventory | Pobiera nazwy wielu produktów (batch) |

**Dlaczego batch?**
Metoda `getProductNames(array)` wykonuje jedno zapytanie SQL zamiast N zapytań, eliminując problem N+1.

---

## Kontrolery i routing

### ProductController

**Prefix:** `/catalog/product`

| Route | Metoda | Akcja | Opis |
|-------|--------|-------|------|
| `/` | GET | `index` | Lista produktów |
| `/{id}` | GET | `show` | Szczegóły produktu |
| `/create` | GET/POST | `create` | Formularz tworzenia |
| `/{id}/edit` | GET/POST | `edit` | Formularz edycji |
| `/{id}/delete` | POST | `delete` | Usunięcie produktu |

### CategoryController

**Prefix:** `/catalog/category`

| Route | Metoda | Akcja | Opis |
|-------|--------|-------|------|
| `/` | GET | `index` | Lista kategorii |
| `/create` | GET/POST | `create` | Formularz tworzenia |
| `/{id}/edit` | GET/POST | `edit` | Formularz edycji |
| `/{id}/delete` | POST | `delete` | Usunięcie kategorii |

---

## Integracja z innymi modułami

### Jako dostawca danych (eksportuje)

```
Catalog ──[CartProductCatalogProvider]──► Cart
                                        ► Inventory
```

Moduł Catalog **nie zna** swoich konsumentów. Implementuje interfejsy zdefiniowane przez inne moduły.

### Przez eventy (publikuje)

```
Catalog ──[ProductCreatedEvent]──► Inventory (tworzy StockItem)

Catalog ──[ProductDeletedEvent]──► Inventory (usuwa StockItem)
                                 ► Cart (usuwa CartItem)
```

---

## Szablony Twig

```
templates/catalog/
├── category/
│   ├── index.html.twig       # Lista kategorii
│   ├── create.html.twig      # Tworzenie kategorii
│   └── edit.html.twig        # Edycja kategorii
└── product/
    ├── index.html.twig       # Lista produktów (karty)
    ├── show.html.twig        # Szczegóły produktu + dodawanie do koszyka
    ├── create.html.twig      # Tworzenie produktu
    └── edit.html.twig        # Edycja produktu
```

---

## Diagram zależności

```
┌─────────────────────────────────────────────────────────────┐
│                         CATALOG                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │  Controller ──► Service ──► Repository ──► Entity   │   │
│  │                    │                                │   │
│  │                    ▼                                │   │
│  │            EventDispatcher                          │   │
│  │                    │                                │   │
│  │        ┌───────────┴───────────┐                   │   │
│  │        ▼                       ▼                   │   │
│  │  ProductCreatedEvent    ProductDeletedEvent        │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │              CartProductCatalogProvider              │   │
│  │  implements: CartProductProviderInterface            │   │
│  │              ProductCatalogInterface                 │   │
│  └─────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
```
