# Moduł Catalog

## Przegląd

Moduł **Catalog** odpowiada za zarządzanie produktami i kategoriami w systemie e-commerce. Jest to główny moduł dostarczający dane o produktach dla pozostałych modułów.

## Struktura

```
src/Catalog/
├── Adapter/
│   ├── CartProductAdapter.php            # Adapter dla modułu Cart
│   └── InventoryProductAdapter.php       # Adapter dla modułu Inventory
├── Controller/
│   ├── CategoryController.php            # CRUD kategorii
│   └── ProductController.php             # CRUD produktów + demo Query Bus
├── Entity/
│   ├── Category.php                      # Encja kategorii
│   └── Product.php                       # Encja produktu
├── Form/
│   ├── CategoryType.php                  # Formularz kategorii
│   └── ProductType.php                   # Formularz produktu
├── Port/
│   ├── StockInfoInterface.php            # Port do pobierania stanu magazynowego
│   └── CartQuantityInterface.php         # Port do pobierania ilości w koszyku
├── QueryHandler/
│   ├── GetProductNamesHandler.php        # Handler Query Bus - nazwy produktów
│   └── GetProductPriceHandler.php        # Handler Query Bus - cena produktu
├── Repository/
│   ├── CategoryRepository.php            # Repozytorium kategorii
│   └── ProductRepository.php             # Repozytorium produktów
└── Service/
    ├── CategoryService.php               # Logika biznesowa kategorii
    └── ProductService.php                # Logika biznesowa produktów
```

**Uwaga:** Eventy `ProductCreatedEvent` i `ProductDeletedEvent` zostały przeniesione do `Shared/Event/` (SharedKernel pattern).

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
- `EventBusInterface` - emitowanie eventów (Symfony Messenger)

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

**Lokalizacja:** `src/Shared/Event/` (SharedKernel pattern)

### ProductCreatedEvent

Emitowany po utworzeniu nowego produktu.

```php
// src/Shared/Event/ProductCreatedEvent.php
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
// src/Shared/Event/ProductDeletedEvent.php
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

**Dlaczego eventy w Shared?**
- Uniknięcie cyklicznych zależności między modułami
- Każdy moduł importuje eventy z Shared, nie z innych modułów biznesowych
- Zmiana sygnatury eventu w jednym miejscu

---

## Adaptery (Porty wyjściowe)

Adaptery implementują interfejsy (porty) zdefiniowane przez inne moduły. Każdy adapter ma jedną odpowiedzialność (Interface Segregation Principle).

### CartProductAdapter

Adapter dla modułu Cart.

**Implementuje:** `Cart\Port\CartProductProviderInterface`

**Metody:**

| Metoda | Opis |
|--------|------|
| `getPrice(int)` | Pobiera cenę produktu |
| `productExists(int)` | Sprawdza czy produkt istnieje |
| `getProductName(int)` | Pobiera nazwę pojedynczego produktu |
| `getProductNames(array)` | Pobiera nazwy wielu produktów (batch) |

### InventoryProductAdapter

Adapter dla modułu Inventory.

**Implementuje:** `Inventory\Port\ProductCatalogInterface`

**Metody:**

| Metoda | Opis |
|--------|------|
| `getProductNames(array)` | Pobiera nazwy wielu produktów (batch) |

**Dlaczego batch?**
Metoda `getProductNames(array)` wykonuje jedno zapytanie SQL zamiast N zapytań, eliminując problem N+1.

**Dlaczego dwa adaptery?**
- Rozdzielenie odpowiedzialności (SRP)
- Zmiany dla Cart nie wpływają na Inventory i odwrotnie
- Łatwiejsze testowanie jednostkowe

---

## Porty (interfejsy wejściowe)

Porty definiują co moduł Catalog potrzebuje od innych modułów do wyświetlenia strony produktu.

### StockInfoInterface

Port do pobierania informacji o stanie magazynowym.

```php
namespace App\Catalog\Port;

interface StockInfoInterface
{
    /**
     * Zwraca ilość produktu na stanie magazynowym
     */
    public function getStockQuantity(int $productId): int;
}
```

**Implementacja:** `Inventory\Adapter\StockInfoAdapter`

### CartQuantityInterface

Port do pobierania ilości produktu w koszyku użytkownika.

```php
namespace App\Catalog\Port;

interface CartQuantityInterface
{
    /**
     * Zwraca ilość produktu w koszyku dla danej sesji
     */
    public function getQuantityInCart(int $productId, string $sessionId): int;
}
```

**Implementacja:** `Cart\Adapter\CartQuantityAdapter`

**Zastosowanie na stronie produktu:**

```php
// ProductController::show()
$stockQuantity = $this->stockInfo->getStockQuantity($product->getId());
$cartQuantity = $this->cartQuantity->getQuantityInCart($product->getId(), $sessionId);
$availableQuantity = max(0, $stockQuantity - $cartQuantity);

return $this->render('catalog/product/show.html.twig', [
    'product' => $product,
    'stockQuantity' => $stockQuantity,
    'cartQuantity' => $cartQuantity,
    'availableQuantity' => $availableQuantity,
]);
```

**Widok produktu wyświetla:**
- Stan magazynowy (np. "Na stanie: 10 szt.")
- Ilość w koszyku (np. "W koszyku: 2 szt.")
- Dostępna ilość do dodania (np. "Możesz dodać: 8 szt.")
- Formularz blokowany gdy `availableQuantity = 0`

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
Catalog ──[CartProductAdapter]──────► Cart
        ──[InventoryProductAdapter]─► Inventory
```

Moduł Catalog **nie zna** swoich konsumentów. Implementuje interfejsy zdefiniowane przez inne moduły.

### Pobiera dane z (konsumuje)

```
Catalog ──[StockInfoInterface]────► Inventory
        ──[CartQuantityInterface]─► Cart
```

Moduł Catalog pobiera dane do wyświetlenia na stronie produktu:
- Stan magazynowy z Inventory
- Ilość w koszyku z Cart

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
│  │       │            │                                │   │
│  │       │ uses       ▼                                │   │
│  │       │     EventDispatcher                         │   │
│  │       ▼            │                                │   │
│  │  StockInfoInterface◄──── PORT (from Inventory)      │   │
│  │  CartQuantityInterface◄─ PORT (from Cart)           │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  ┌───────────────────────┐  ┌───────────────────────────┐  │
│  │  CartProductAdapter   │  │  InventoryProductAdapter  │  │
│  │  implements:          │  │  implements:              │  │
│  │  CartProductProvider  │  │  ProductCatalogInterface  │  │
│  │  Interface (for Cart) │  │  (for Inventory)          │  │
│  └───────────────────────┘  └───────────────────────────┘  │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │  QueryHandlers (dla Query Bus)                       │   │
│  │  - GetProductNamesHandler                            │   │
│  │  - GetProductPriceHandler                            │   │
│  └─────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
                           ▲
                           │ implements ports
┌──────────────────────────│──────────────────────────────────┐
│                      INVENTORY                              │
│                   StockInfoAdapter                          │
└─────────────────────────────────────────────────────────────┘
┌─────────────────────────────────────────────────────────────┐
│                        CART                                 │
│                  CartQuantityAdapter                        │
└─────────────────────────────────────────────────────────────┘
```

---

## Query Bus (alternatywa)

Moduł Catalog udostępnia handlery dla Query Bus:

### GetProductNamesHandler

```php
namespace App\Catalog\QueryHandler;

use App\Shared\Query\Catalog\GetProductNamesQuery;

class GetProductNamesHandler
{
    public function __invoke(GetProductNamesQuery $query): array
    {
        return $this->productRepository->getProductNames($query->productIds);
    }
}
```

### GetProductPriceHandler

```php
namespace App\Catalog\QueryHandler;

use App\Shared\Query\Catalog\GetProductPriceQuery;

class GetProductPriceHandler
{
    public function __invoke(GetProductPriceQuery $query): ?string
    {
        $product = $this->productRepository->find($query->productId);
        return $product?->getPrice();
    }
}
```

**Demo porównawcze:**
Akcja `ProductController::compareApproaches()` prezentuje różnicę między Port/Adapter a Query Bus na żywo.

Więcej o Query Bus w [docs/articles/QUERY_BUS_GUIDE.md](../articles/QUERY_BUS_GUIDE.md).
