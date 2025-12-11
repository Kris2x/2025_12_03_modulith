# Moduł Inventory

## Przegląd

Moduł **Inventory** odpowiada za zarządzanie stanami magazynowymi produktów. Śledzi ilość dostępnych sztuk każdego produktu i automatycznie tworzy/usuwa wpisy magazynowe w reakcji na eventy z modułu Catalog.

## Struktura

```
src/Inventory/
├── Controller/
│   └── StockController.php               # Zarządzanie stanami magazynowymi
├── Entity/
│   └── StockItem.php                     # Encja stanu magazynowego
├── EventHandler/                         # Handlery eventów (Symfony Messenger)
│   ├── ProductCreatedHandler.php         # Reaguje na ProductCreatedEvent
│   └── ProductDeletedHandler.php         # Reaguje na ProductDeletedEvent
├── Form/
│   └── StockItemType.php                 # Formularz edycji stanu
├── QueryHandler/
│   ├── GetStockQuantityHandler.php       # Handler Query Bus - ilość na stanie
│   └── CheckStockAvailabilityHandler.php # Handler Query Bus - sprawdzenie dostępności
├── Repository/
│   └── StockItemRepository.php           # Repozytorium stanów
└── Service/
    └── StockService.php                  # Logika biznesowa magazynu
```

---

## Encje

### StockItem

**Tabela:** `inventory_stock_item`

| Pole | Typ | Opis |
|------|-----|------|
| `id` | int | Klucz główny (auto-increment) |
| `productId` | int | **ID produktu (NIE relacja!)** |
| `quantity` | int | Ilość na stanie (domyślnie 0) |

**Ważne:** `productId` to zwykły `int`, NIE relacja Doctrine. Moduł Inventory przechowuje tylko referencję do produktu, nie tworzy bezpośredniej relacji bazodanowej.

```php
#[ORM\Entity(repositoryClass: StockItemRepository::class)]
#[ORM\Table(name: 'inventory_stock_item')]
class StockItem
{
    #[ORM\Column]
    private int $productId;  // ID produktu z Catalog, NIE relacja!

    #[ORM\Column]
    private int $quantity = 0;
}
```

---

## Serwisy

### StockService

Główny serwis do zarządzania stanami magazynowymi.

**Zależności:**
- `EntityManagerInterface` - operacje na bazie
- `StockItemRepository` - dostęp do stanów magazynowych

**Metody:**

| Metoda | Opis |
|--------|------|
| `createStockItem(int $productId, int $quantity = 0)` | Tworzy nowy wpis magazynowy |
| `getStockForProduct(int $productId)` | Pobiera stan dla produktu |
| `isAvailable(int $productId, int $quantity)` | Sprawdza dostępność |
| `save(StockItem)` | Zapisuje zmiany w stanie |
| `removeByProductId(int $productId)` | Usuwa wpis magazynowy |

**Tworzenie wpisu magazynowego:**

```php
public function createStockItem(int $productId, int $quantity = 0): StockItem
{
    $stockItem = new StockItem();
    $stockItem->setProductId($productId);
    $stockItem->setQuantity($quantity);

    $this->em->persist($stockItem);
    $this->em->flush();

    return $stockItem;
}
```

**Sprawdzanie dostępności:**

```php
public function isAvailable(int $productId, int $quantity): bool
{
    $stock = $this->getStockForProduct($productId);

    return $stock && $stock->getQuantity() >= $quantity;
}
```

---

## Event Handlers

Handlery eventów są zaimplementowane jako Symfony Messenger handlers, co pozwala na:
- Unifikację z Query Bus (ten sam mechanizm)
- Łatwe przejście na async (zmiana w `messenger.yaml`)
- Przygotowanie pod Outbox Pattern

### ProductCreatedHandler

Reaguje na utworzenie produktu w module Catalog.

```php
#[AsMessageHandler(bus: 'event.bus')]
final class ProductCreatedHandler
{
    public function __construct(
        private StockService $stockService,
    ) {}

    public function __invoke(ProductCreatedEvent $event): void
    {
        // Automatycznie tworzy StockItem z quantity=0 dla nowego produktu
        $this->stockService->createStockItem($event->productId);
    }
}
```

### ProductDeletedHandler

Reaguje na usunięcie produktu w module Catalog.

```php
#[AsMessageHandler(bus: 'event.bus')]
final class ProductDeletedHandler
{
    public function __construct(
        private StockService $stockService,
    ) {}

    public function __invoke(ProductDeletedEvent $event): void
    {
        // Usuwa StockItem gdy produkt jest usuwany
        $this->stockService->removeByProductId($event->productId);
    }
}
```

**Przepływ eventów:**

```
┌─────────────┐                      ┌─────────────┐
│   CATALOG   │  ProductCreatedEvent │  INVENTORY  │
│             │ ────────────────────►│             │
│  createProduct()                   │  createStockItem()
│             │                      │  (quantity=0)
│             │                      │             │
│  deleteProduct()                   │             │
│             │  ProductDeletedEvent │             │
│             │ ────────────────────►│             │
│             │                      │  removeByProductId()
└─────────────┘                      └─────────────┘
```

---

## Query Handlers

Moduł Inventory udostępnia dane innym modułom przez Query Bus:

### GetStockQuantityHandler

Zwraca ilość produktu na stanie magazynowym.

```php
#[AsMessageHandler(bus: 'query.bus')]
final class GetStockQuantityHandler
{
    public function __construct(
        private StockService $stockService,
    ) {}

    public function __invoke(GetStockQuantityQuery $query): int
    {
        $stockItem = $this->stockService->getStockForProduct($query->productId);

        return $stockItem?->getQuantity() ?? 0;
    }
}
```

**Query (w Shared):**

```php
// src/Shared/Query/Inventory/GetStockQuantityQuery.php
readonly class GetStockQuantityQuery
{
    public function __construct(
        public int $productId,
    ) {}
}
```

### CheckStockAvailabilityHandler

Sprawdza czy żądana ilość produktu jest dostępna.

```php
#[AsMessageHandler(bus: 'query.bus')]
final class CheckStockAvailabilityHandler
{
    public function __construct(
        private StockService $stockService,
    ) {}

    public function __invoke(CheckStockAvailabilityQuery $query): bool
    {
        return $this->stockService->isAvailable($query->productId, $query->quantity);
    }
}
```

**Query (w Shared):**

```php
// src/Shared/Query/Inventory/CheckStockAvailabilityQuery.php
readonly class CheckStockAvailabilityQuery
{
    public function __construct(
        public int $productId,
        public int $quantity,
    ) {}
}
```

**Użycie w innych modułach:**

```php
// Cart sprawdza dostępność przed dodaniem do koszyka
$isAvailable = $this->queryBus->query(
    new CheckStockAvailabilityQuery($productId, $quantity)
);

// Catalog wyświetla stan magazynowy na stronie produktu
$stockQuantity = $this->queryBus->query(
    new GetStockQuantityQuery($productId)
);
```

---

## Kontroler i routing

### StockController

**Prefix:** `/inventory/stock`

| Route | Metoda | Akcja | Opis |
|-------|--------|-------|------|
| `/` | GET | `index` | Lista stanów magazynowych |
| `/{id}/edit` | GET/POST | `edit` | Edycja ilości |

**Widok listy z nazwami produktów (przez Query Bus):**

```php
public function index(
    StockItemRepository $stockItemRepository,
    QueryBusInterface $queryBus
): Response {
    $stockItems = $stockItemRepository->findAll();

    // Pobierz ID produktów
    $productIds = array_map(
        fn(StockItem $item) => $item->getProductId(),
        $stockItems
    );

    // Pobierz nazwy jednym zapytaniem przez Query Bus
    $productNames = $queryBus->query(new GetProductNamesQuery($productIds));

    return $this->render('inventory/stock/index.html.twig', [
        'stockItems' => $stockItems,
        'productNames' => $productNames,
    ]);
}
```

---

## Integracja z innymi modułami

### Pobiera dane przez Query Bus

```
Inventory ──[GetProductNamesQuery]──► Catalog
```

Moduł Inventory używa Query Bus do pobierania nazw produktów w celu wyświetlenia ich w widoku.

### Udostępnia dane przez Query Bus

```
Cart ────[CheckStockAvailabilityQuery]───► Inventory
Catalog ─[GetStockQuantityQuery]─────────► Inventory
```

Moduł Inventory udostępnia Query Handlery dla:
- **GetStockQuantityQuery** - zwraca ilość na stanie (dla Catalog)
- **CheckStockAvailabilityQuery** - sprawdza dostępność (dla Cart)

### Reaguje na eventy (nasłuchuje)

```
Shared ──[ProductCreatedEvent]──► Inventory (tworzy StockItem)
Shared ──[ProductDeletedEvent]──► Inventory (usuwa StockItem)
```

**Uwaga:** Eventy są w `Shared/Event/` (SharedKernel pattern).

**Automatyzacja:**
- Tworzenie produktu → automatyczny wpis magazynowy z `quantity=0`
- Usunięcie produktu → automatyczne usunięcie wpisu magazynowego

---

## Szablony Twig

```
templates/inventory/stock/
├── index.html.twig    # Lista stanów magazynowych
└── edit.html.twig     # Edycja ilości
```

**Przekazywane dane do index:**
- `stockItems` - lista obiektów StockItem
- `productNames` - mapa `productId => name`

---

## Diagram zależności

```
┌─────────────────────────────────────────────────────────────┐
│                       INVENTORY                             │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │  Controller ──► StockService ──► Repository         │   │
│  │       │                                              │   │
│  │       │ uses QueryBusInterface for:                  │   │
│  │       │   - GetProductNamesQuery (Catalog)           │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │  EventHandlers (nasłuchuje Shared/Event/)           │   │
│  │  - ProductCreatedHandler (creates StockItem)        │   │
│  │  - ProductDeletedHandler (removes StockItem)        │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │  QueryHandlers (udostępnia dane)                    │   │
│  │  - GetStockQuantityHandler                          │   │
│  │  - CheckStockAvailabilityHandler                    │   │
│  └─────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
```

---

## Kluczowe decyzje architektoniczne

### 1. Referencja przez ID, nie relację Doctrine

`StockItem.productId` to `int`, nie `#[ORM\ManyToOne]`. Dzięki temu:
- Moduły są niezależne
- Brak problemów z kaskadowym usuwaniem
- Łatwiejsza migracja do mikroserwisów

### 2. Event-driven synchronizacja

Zamiast ręcznie tworzyć `StockItem` przy tworzeniu produktu, używamy eventów:
- **Luźne powiązanie** - Catalog nie wie o Inventory
- **Automatyzacja** - nie można "zapomnieć" o utworzeniu wpisu
- **Rozszerzalność** - łatwo dodać kolejnych handlerów

### 3. Query Bus dla danych między modułami

Moduł udostępnia i pobiera dane przez Query Bus:
- Wszystkie Query w `Shared/Query/`
- Handlery w odpowiednich modułach
- Jeden wzorzec dla całego projektu

### 4. Batch loading

`GetProductNamesQuery` pobiera nazwy wszystkich produktów jednym zapytaniem SQL, eliminując problem N+1.

---

## Przyszłe rozszerzenia

Moduł jest przygotowany na rozszerzenia:

1. **Rezerwacja stock'u** przy dodaniu do koszyka
2. **Zmniejszanie stock'u** przy złożeniu zamówienia (nasłuchiwanie `OrderPlacedEvent`)
3. **Historia zmian** - tracking kto/kiedy zmienił ilość
4. **Powiadomienia** o niskim stanie magazynowym
5. **Multi-warehouse** - wiele magazynów z różnymi stanami
