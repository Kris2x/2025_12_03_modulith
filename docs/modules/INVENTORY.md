# Moduł Inventory

## Przegląd

Moduł **Inventory** odpowiada za zarządzanie stanami magazynowymi produktów. Śledzi ilość dostępnych sztuk każdego produktu i automatycznie tworzy/usuwa wpisy magazynowe w reakcji na eventy z modułu Catalog.

## Struktura

```
src/Inventory/
├── Adapter/
│   ├── StockAvailabilityAdapter.php      # Adapter dla Cart (sprawdzanie dostępności)
│   └── StockInfoAdapter.php              # Adapter dla Catalog (stan magazynowy)
├── Controller/
│   └── StockController.php               # Zarządzanie stanami magazynowymi
├── Entity/
│   └── StockItem.php                     # Encja stanu magazynowego
├── EventSubscriber/
│   └── ProductEventSubscriber.php        # Reaguje na eventy produktów
├── Form/
│   └── StockItemType.php                 # Formularz edycji stanu
├── Port/
│   └── ProductCatalogInterface.php       # Interfejs do pobierania nazw produktów
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

## Port (interfejs wejściowy)

### ProductCatalogInterface

Interfejs definiujący co moduł Inventory potrzebuje od modułu Catalog.

```php
namespace App\Inventory\Port;

interface ProductCatalogInterface
{
    /**
     * @param int[] $productIds
     * @return array<int, string> Mapa productId => productName
     */
    public function getProductNames(array $productIds): array;
}
```

**Implementacja:** `Catalog\Adapter\InventoryProductAdapter`

**Zastosowanie:**
- Wyświetlanie nazw produktów w widoku magazynu
- Jeden interfejs z jedną metodą (Interface Segregation Principle)

---

## Event Subscribers

### ProductEventSubscriber

Reaguje na eventy z modułu Catalog dotyczące produktów.

```php
class ProductEventSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            ProductCreatedEvent::class => 'onProductCreated',
            ProductDeletedEvent::class => 'onProductDeleted',
        ];
    }

    public function onProductCreated(ProductCreatedEvent $event): void
    {
        // Automatycznie tworzy StockItem z quantity=0 dla nowego produktu
        $this->stockService->createStockItem($event->productId);
    }

    public function onProductDeleted(ProductDeletedEvent $event): void
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

## Kontroler i routing

### StockController

**Prefix:** `/inventory/stock`

| Route | Metoda | Akcja | Opis |
|-------|--------|-------|------|
| `/` | GET | `index` | Lista stanów magazynowych |
| `/{id}/edit` | GET/POST | `edit` | Edycja ilości |

**Widok listy z nazwami produktów:**

```php
public function index(
    StockItemRepository $stockItemRepository,
    ProductCatalogInterface $productCatalog
): Response {
    $stockItems = $stockItemRepository->findAll();

    // Pobierz ID produktów
    $productIds = array_map(
        fn(StockItem $item) => $item->getProductId(),
        $stockItems
    );

    // Pobierz nazwy jednym zapytaniem (batch)
    $productNames = $productCatalog->getProductNames($productIds);

    return $this->render('inventory/stock/index.html.twig', [
        'stockItems' => $stockItems,
        'productNames' => $productNames,
    ]);
}
```

---

## Integracja z innymi modułami

### Pobiera dane z (konsumuje)

```
Inventory ──[ProductCatalogInterface]──► Catalog
```

Moduł Inventory używa interfejsu `ProductCatalogInterface` do pobierania nazw produktów w celu wyświetlenia ich w widoku.

### Udostępnia dane (eksportuje)

```
Cart ────[StockAvailabilityInterface]───► Inventory
Catalog ─[StockInfoInterface]───────────► Inventory
```

Moduł Inventory implementuje adaptery dla:
- **StockAvailabilityAdapter** (dla Cart) - walidacja dostępności przy dodawaniu do koszyka
- **StockInfoAdapter** (dla Catalog) - wyświetlanie stanu na stronie produktu

### Reaguje na eventy (nasłuchuje)

```
Shared ──[ProductCreatedEvent]──► Inventory (tworzy StockItem)
Shared ──[ProductDeletedEvent]──► Inventory (usuwa StockItem)
```

**Uwaga:** Eventy są teraz w `Shared/Event/` (SharedKernel pattern).

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
│  │       │ uses                                         │   │
│  │       ▼                                              │   │
│  │  ProductCatalogInterface ◄─── PORT (from Catalog)    │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │           ProductEventSubscriber                     │   │
│  │  listens: ProductCreatedEvent (creates StockItem)    │   │
│  │           ProductDeletedEvent (removes StockItem)    │   │
│  │  (eventy z Shared/Event/)                            │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │  Adaptery (eksportowane)                              │   │
│  │  - StockAvailabilityAdapter (for Cart)                │   │
│  │  - StockInfoAdapter (for Catalog)                     │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │  QueryHandlers (dla Query Bus)                       │   │
│  │  - GetStockQuantityHandler                           │   │
│  │  - CheckStockAvailabilityHandler                     │   │
│  └─────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
                           ▲
                           │ implements ProductCatalogInterface
┌──────────────────────────│──────────────────────────────────┐
│                       CATALOG                               │
│              InventoryProductAdapter                        │
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
- **Rozszerzalność** - łatwo dodać kolejnych subskrybentów

### 3. Interface Segregation Principle

`ProductCatalogInterface` ma tylko jedną metodę (`getProductNames`), bo tylko tego Inventory potrzebuje. Cart ma szerszy interfejs (`CartProductProviderInterface`) z dodatkowymi metodami.

### 4. Batch loading

`getProductNames(array $productIds)` pobiera nazwy wszystkich produktów jednym zapytaniem SQL, eliminując problem N+1.

---

## Adaptery (Porty wyjściowe)

### StockAvailabilityAdapter

Adapter dla modułu Cart - walidacja dostępności.

**Implementuje:** `Cart\Port\StockAvailabilityInterface`

```php
namespace App\Inventory\Adapter;

use App\Cart\Port\StockAvailabilityInterface;

class StockAvailabilityAdapter implements StockAvailabilityInterface
{
    public function isAvailable(int $productId, int $quantity): bool
    {
        return $this->stockService->isAvailable($productId, $quantity);
    }

    public function getAvailableQuantity(int $productId): int
    {
        $stock = $this->stockService->getStockForProduct($productId);
        return $stock ? $stock->getQuantity() : 0;
    }
}
```

### StockInfoAdapter

Adapter dla modułu Catalog - wyświetlanie stanu magazynowego.

**Implementuje:** `Catalog\Port\StockInfoInterface`

```php
namespace App\Inventory\Adapter;

use App\Catalog\Port\StockInfoInterface;

class StockInfoAdapter implements StockInfoInterface
{
    public function getStockQuantity(int $productId): int
    {
        $stock = $this->stockService->getStockForProduct($productId);
        return $stock ? $stock->getQuantity() : 0;
    }
}
```

---

## Query Bus (alternatywa)

Moduł Inventory udostępnia handlery dla Query Bus:

### GetStockQuantityHandler

```php
namespace App\Inventory\QueryHandler;

use App\Shared\Query\Inventory\GetStockQuantityQuery;

class GetStockQuantityHandler
{
    public function __invoke(GetStockQuantityQuery $query): int
    {
        $stock = $this->stockService->getStockForProduct($query->productId);
        return $stock ? $stock->getQuantity() : 0;
    }
}
```

### CheckStockAvailabilityHandler

```php
namespace App\Inventory\QueryHandler;

use App\Shared\Query\Inventory\CheckStockAvailabilityQuery;

class CheckStockAvailabilityHandler
{
    public function __invoke(CheckStockAvailabilityQuery $query): bool
    {
        return $this->stockService->isAvailable($query->productId, $query->quantity);
    }
}
```

**Użycie:**
```php
$isAvailable = $this->queryBus->query(
    new CheckStockAvailabilityQuery($productId, $quantity)
);
```

Więcej o Query Bus w [docs/articles/QUERY_BUS_GUIDE.md](../articles/QUERY_BUS_GUIDE.md).

---

## Przyszłe rozszerzenia

Moduł jest przygotowany na rozszerzenia:

1. **Rezerwacja stock'u** przy dodaniu do koszyka
2. **Zmniejszanie stock'u** przy złożeniu zamówienia (nasłuchiwanie `OrderPlacedEvent`)
3. **Historia zmian** - tracking kto/kiedy zmienił ilość
4. **Powiadomienia** o niskim stanie magazynowym
5. **Multi-warehouse** - wiele magazynów z różnymi stanami
