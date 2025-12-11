# Moduł Catalog

## Przegląd

Moduł **Catalog** odpowiada za zarządzanie produktami i kategoriami w systemie e-commerce. Jest to główny moduł dostarczający dane o produktach dla pozostałych modułów przez Query Bus.

## Struktura

```
src/Catalog/
├── Controller/
│   ├── CategoryController.php            # CRUD kategorii
│   └── ProductController.php             # CRUD produktów
├── Entity/
│   ├── Category.php                      # Encja kategorii
│   └── Product.php                       # Encja produktu
├── Form/
│   ├── CategoryType.php                  # Formularz kategorii
│   └── ProductType.php                   # Formularz produktu
├── QueryHandler/
│   ├── GetProductNamesHandler.php        # Handler - nazwy produktów
│   ├── GetProductPriceHandler.php        # Handler - cena produktu
│   └── ProductExistsHandler.php          # Handler - sprawdzenie istnienia
├── Repository/
│   ├── CategoryRepository.php            # Repozytorium kategorii
│   └── ProductRepository.php             # Repozytorium produktów
└── Service/
    ├── CategoryService.php               # Logika biznesowa kategorii
    └── ProductService.php                # Logika biznesowa produktów
```

**Uwaga:** Eventy `ProductCreatedEvent` i `ProductDeletedEvent` są w `Shared/Event/` (SharedKernel pattern).

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

**Handlery:**
- `Inventory\EventHandler\ProductCreatedHandler` - tworzy `StockItem` dla nowego produktu

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

**Handlery:**
- `Inventory\EventHandler\ProductDeletedHandler` - usuwa `StockItem`
- `Cart\EventHandler\ProductDeletedHandler` - usuwa pozycje koszyka z tym produktem

**Dlaczego eventy w Shared?**
- Uniknięcie cyklicznych zależności między modułami
- Każdy moduł importuje eventy z Shared, nie z innych modułów biznesowych
- Zmiana sygnatury eventu w jednym miejscu

---

## Query Handlers

Moduł Catalog udostępnia dane innym modułom przez Query Bus:

### GetProductPriceHandler

```php
#[AsMessageHandler(bus: 'query.bus')]
final class GetProductPriceHandler
{
    public function __invoke(GetProductPriceQuery $query): ?string
    {
        $product = $this->productRepository->find($query->productId);
        return $product?->getPrice();
    }
}
```

### GetProductNamesHandler

```php
#[AsMessageHandler(bus: 'query.bus')]
final class GetProductNamesHandler
{
    public function __invoke(GetProductNamesQuery $query): array
    {
        return $this->productRepository->getProductNames($query->productIds);
    }
}
```

### ProductExistsHandler

```php
#[AsMessageHandler(bus: 'query.bus')]
final class ProductExistsHandler
{
    public function __invoke(ProductExistsQuery $query): bool
    {
        return $this->productRepository->find($query->productId) !== null;
    }
}
```

**Użycie w innych modułach:**

```php
// Cart sprawdza czy produkt istnieje
$exists = $this->queryBus->query(new ProductExistsQuery($productId));

// Inventory pobiera nazwy produktów
$names = $this->queryBus->query(new GetProductNamesQuery([1, 2, 3]));
```

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

**Strona produktu z danymi z innych modułów:**

```php
public function show(Product $product, Request $request): Response
{
    $sessionId = $request->getSession()->getId();

    // Pobierz dane z innych modułów przez Query Bus
    $stockQuantity = $this->queryBus->query(
        new GetStockQuantityQuery($product->getId())
    );
    $cartQuantity = $this->queryBus->query(
        new GetCartQuantityQuery($product->getId(), $sessionId)
    );

    $availableQuantity = max(0, $stockQuantity - $cartQuantity);

    return $this->render('catalog/product/show.html.twig', [
        'product' => $product,
        'stockQuantity' => $stockQuantity,
        'cartQuantity' => $cartQuantity,
        'availableQuantity' => $availableQuantity,
    ]);
}
```

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

### Udostępnia dane przez Query Bus

```
Catalog ──[GetProductPriceQuery]────► Cart
        ──[GetProductNamesQuery]────► Cart, Inventory
        ──[ProductExistsQuery]──────► Cart
```

Query są zdefiniowane w `Shared/Query/Catalog/`, handlery w `Catalog/QueryHandler/`.

### Pobiera dane przez Query Bus

```
Catalog ──[GetStockQuantityQuery]───► Inventory
        ──[GetCartQuantityQuery]────► Cart
```

Dla wyświetlenia stanu magazynowego i ilości w koszyku na stronie produktu.

### Publikuje eventy

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
│  │       │            ▼                                │   │
│  │       │     EventBusInterface                       │   │
│  │       │                                             │   │
│  │       │ uses QueryBusInterface for:                 │   │
│  │       │   - GetStockQuantityQuery (Inventory)       │   │
│  │       │   - GetCartQuantityQuery (Cart)             │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │  QueryHandlers (udostępnia dane)                    │   │
│  │  - GetProductNamesHandler                           │   │
│  │  - GetProductPriceHandler                           │   │
│  │  - ProductExistsHandler                             │   │
│  └─────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
```

---

## Kluczowe decyzje architektoniczne

### 1. Query Bus zamiast Port/Adapter

Catalog udostępnia dane przez Query Bus:
- Wszystkie Query w `Shared/Query/Catalog/`
- Handlery w `Catalog/QueryHandler/`
- Jeden wzorzec dla całego projektu

### 2. Event Bus dla powiadomień

Zmiany w produktach są komunikowane przez Event Bus:
- Fire & forget (nie czeka na odpowiedź)
- Możliwość wielu handlerów na jeden event
- Łatwe przejście na async

### 3. SharedKernel dla kontraktów

Query i Eventy są w `Shared/`:
- Moduły nie importują się nawzajem
- Wszystkie zależą tylko od Shared
- Łatwe zarządzanie kontraktami
