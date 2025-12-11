# Komunikacja między modułami w Modularnym Monolicie

## Spis treści

1. [Wprowadzenie](#wprowadzenie)
2. [Problem do rozwiązania](#problem-do-rozwiązania)
3. [Nasze rozwiązanie - Query Bus + Event Bus](#nasze-rozwiązanie)
4. [Alternatywne podejścia (referencyjne)](#alternatywne-podejścia)
5. [Porównanie podejść](#porównanie-podejść)
6. [Rekomendacje](#rekomendacje)
7. [Podsumowanie](#podsumowanie)

---

## Wprowadzenie

Modularny monolit to architektura, która łączy zalety monolitu (prostota deploymentu, transakcje) z zaletami mikroserwisów (jasne granice między domenami, możliwość późniejszego podziału). Kluczowym wyzwaniem jest **komunikacja między modułami** - musi być na tyle luźna, by moduły mogły ewoluować niezależnie, ale na tyle wydajna, by nie wprowadzać nadmiernej złożoności.

### Stan projektu

Ten projekt używa **Query Bus + Event Bus** jako jedynego wzorca komunikacji między modułami. Jest to świadoma decyzja architektoniczna po ewaluacji różnych podejść.

---

## Problem do rozwiązania

System e-commerce z trzema modułami:

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│   Catalog   │     │  Inventory  │     │    Cart     │
│             │     │             │     │             │
│  - Product  │     │  - Stock    │     │  - Cart     │
│  - Category │     │    Item     │     │  - CartItem │
└─────────────┘     └─────────────┘     └─────────────┘
```

**Scenariusze wymagające komunikacji:**

1. **Cart potrzebuje ceny produktu** - przy dodawaniu do koszyka
2. **Inventory potrzebuje nazwy produktu** - przy wyświetlaniu stanu
3. **Catalog chce pokazać dostępność** - ilość na stanie
4. **Cart musi zwalidować dostępność** - przed dodaniem do koszyka

### Naiwne podejście (antypattern)

```php
// ❌ ŹLE: Cart bezpośrednio używa encji z Catalog
namespace App\Cart\Service;

use App\Catalog\Entity\Product;  // Import z innego modułu!
use App\Catalog\Repository\ProductRepository;  // Bezpośredni dostęp!

class CartService
{
    public function __construct(
        private ProductRepository $productRepository,  // Naruszenie granic!
    ) {}

    public function addItem(Cart $cart, int $productId, int $quantity): void
    {
        $product = $this->productRepository->find($productId);
        $item = new CartItem();
        $item->setPrice($product->getPrice());  // Coupling do encji Catalog
        // ...
    }
}
```

**Problemy tego podejścia:**

1. **Tight coupling** - Cart zna szczegóły implementacji Catalog
2. **Trudność testowania** - potrzebna pełna baza danych
3. **Ripple effect** - zmiana w Product wymusza zmiany w Cart
4. **Niemożliwy podział** - nie da się wydzielić mikroserwisu

---

## Nasze rozwiązanie

### Query Bus + Event Bus

Projekt używa **wyłącznie Query Bus i Event Bus** do komunikacji między modułami. Oba są zbudowane na Symfony Messenger.

```
┌──────────────────────────────────────────────────────────────────┐
│                         SHARED KERNEL                              │
│                                                                    │
│  ┌─────────────────────────────────────────────────────────────┐  │
│  │                       Bus Interfaces                         │  │
│  │  QueryBusInterface  |  EventBusInterface                     │  │
│  └─────────────────────────────────────────────────────────────┘  │
│                                                                    │
│  ┌──────────────────┐  ┌──────────────────────────────────────┐  │
│  │     Query/       │  │            Event/                     │  │
│  │  - Catalog/      │  │  - ProductCreatedEvent                │  │
│  │  - Inventory/    │  │  - ProductDeletedEvent                │  │
│  │  - Cart/         │  │                                       │  │
│  └──────────────────┘  └──────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────────┘
           │                              │
           │ query()                      │ dispatch()
           ▼                              ▼
┌─────────────────────┐      ┌─────────────────────┐
│     QUERY BUS       │      │     EVENT BUS       │
│  (synchroniczne)    │      │  (fire & forget)    │
└─────────────────────┘      └─────────────────────┘
```

### Implementacja Query Bus

**Query (kontrakt w Shared):**

```php
// src/Shared/Query/Inventory/GetStockQuantityQuery.php
namespace App\Shared\Query\Inventory;

readonly class GetStockQuantityQuery
{
    public function __construct(
        public int $productId,
    ) {}
}
```

**Handler (w module źródłowym):**

```php
// src/Inventory/QueryHandler/GetStockQuantityHandler.php
namespace App\Inventory\QueryHandler;

use App\Shared\Query\Inventory\GetStockQuantityQuery;
use App\Inventory\Service\StockService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

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

**Użycie:**

```php
// src/Catalog/Controller/ProductController.php
class ProductController extends AbstractController
{
    public function __construct(
        private QueryBusInterface $queryBus,
    ) {}

    public function show(int $id): Response
    {
        $product = $this->productService->getProduct($id);

        // Pobierz dane przez Query Bus
        $stockQuantity = $this->queryBus->query(
            new GetStockQuantityQuery($id)
        );

        return $this->render('catalog/product/show.html.twig', [
            'product' => $product,
            'stockQuantity' => $stockQuantity,
        ]);
    }
}
```

### Implementacja Event Bus

**Event (w Shared):**

```php
// src/Shared/Event/ProductCreatedEvent.php
namespace App\Shared\Event;

readonly class ProductCreatedEvent
{
    public function __construct(
        public int $productId,
        public string $productName,
    ) {}
}
```

**Handler (w module nasłuchującym):**

```php
// src/Inventory/EventHandler/ProductCreatedHandler.php
namespace App\Inventory\EventHandler;

use App\Shared\Event\ProductCreatedEvent;
use App\Inventory\Service\StockService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'event.bus')]
final class ProductCreatedHandler
{
    public function __construct(
        private StockService $stockService,
    ) {}

    public function __invoke(ProductCreatedEvent $event): void
    {
        $this->stockService->createStockItem($event->productId);
    }
}
```

### Zalety Query Bus + Event Bus

1. **Jeden wzorzec** - spójność w całym projekcie
2. **Centralizacja** - wszystkie kontrakty w `Shared/`
3. **Middleware** - cache, logging, metrics w jednym miejscu
4. **Mniej plików** - 2 pliki na operację (Query/Event + Handler)
5. **Async-ready** - łatwe przejście na asynchroniczne z Symfony Messenger

### Struktura plików

```
src/
├── Shared/
│   ├── Bus/
│   │   ├── QueryBusInterface.php
│   │   ├── QueryBus.php
│   │   ├── EventBusInterface.php
│   │   └── EventBus.php
│   ├── Query/
│   │   ├── Catalog/
│   │   │   ├── ProductExistsQuery.php
│   │   │   ├── GetProductPriceQuery.php
│   │   │   └── GetProductNamesQuery.php
│   │   ├── Inventory/
│   │   │   ├── GetStockQuantityQuery.php
│   │   │   └── CheckStockAvailabilityQuery.php
│   │   └── Cart/
│   │       └── GetCartQuantityQuery.php
│   └── Event/
│       ├── ProductCreatedEvent.php
│       └── ProductDeletedEvent.php
├── Catalog/
│   └── QueryHandler/
│       ├── ProductExistsHandler.php
│       ├── GetProductPriceHandler.php
│       └── GetProductNamesHandler.php
├── Inventory/
│   ├── QueryHandler/
│   │   ├── GetStockQuantityHandler.php
│   │   └── CheckStockAvailabilityHandler.php
│   └── EventHandler/
│       ├── ProductCreatedHandler.php
│       └── ProductDeletedHandler.php
└── Cart/
    ├── QueryHandler/
    │   └── GetCartQuantityHandler.php
    └── EventHandler/
        └── ProductDeletedHandler.php
```

---

## Alternatywne podejścia

Poniżej przedstawiamy alternatywne wzorce komunikacji między modułami jako materiał referencyjny. Projekt świadomie wybrał Query Bus + Event Bus, ale warto znać inne opcje.

### Port/Adapter (Hexagonal Architecture)

Wzorzec Port/Adapter wprowadza warstwę abstrakcji między modułami. Moduł konsumujący definiuje **port** (interfejs), a moduł dostarczający implementuje **adapter**.

```php
// Port w module konsumującym (Catalog)
interface StockInfoInterface
{
    public function getQuantity(int $productId): int;
}

// Adapter w module dostarczającym (Inventory)
class StockInfoAdapter implements StockInfoInterface
{
    public function getQuantity(int $productId): int
    {
        $stock = $this->stockService->getStockForProduct($productId);
        return $stock?->getQuantity() ?? 0;
    }
}
```

**Zalety:**
- Pełna izolacja - moduły nie znają swoich implementacji
- Łatwe testowanie - można mockować interfejsy
- Type safety - kompilator/IDE pilnuje zgodności

**Wady:**
- Boilerplate - jeden interfejs = jeden adapter = jeden alias w services.yaml
- Rozproszenie kodu - port w jednym miejscu, adapter w drugim
- Wiele plików - przy 10 operacjach: 10 interfejsów + 10 adapterów + 10 aliasów

### Facade Pattern

Facade to uproszczone API modułu - jedna klasa grupująca wszystkie operacje dostępne dla innych modułów.

```php
// Fasada w module Inventory
class InventoryFacade
{
    public function getQuantity(int $productId): int { ... }
    public function isAvailable(int $productId, int $quantity): bool { ... }
    public function reserveStock(int $productId, int $quantity): string { ... }
}
```

**Zalety:**
- Prostota - jeden import, jedna klasa
- Discoverability - wszystkie operacje w jednym miejscu

**Wady:**
- God Object - fasada może urosnąć do setek metod
- Coupling - moduły zależą od konkretnej klasy
- Naruszenie ISP - klient zna metody, których nie używa

### Shared DTOs

Shared DTOs to współdzielone obiekty transferu danych umieszczone w module Shared.

```php
// DTO w Shared
readonly class ProductInfo
{
    public function __construct(
        public int $id,
        public string $name,
        public string $price,
    ) {}
}

// Kontrakt w Shared
interface ProductCatalogContract
{
    public function getProduct(int $productId): ?ProductInfo;
}
```

**Zalety:**
- Spójność - ten sam DTO wszędzie
- Rich DTOs - mogą zawierać metody pomocnicze
- IDE friendly - pełne type-hints

**Wady:**
- Shared coupling - wszystkie moduły zależą od Shared
- Versioning - zmiana DTO wpływa na wszystkie moduły

---

## Porównanie podejść

### Tabela porównawcza

| Kryterium | Query Bus | Port/Adapter | Facade | Shared DTOs |
|-----------|-----------|--------------|--------|-------------|
| **Złożoność** | Średnia | Średnia | Niska | Niska |
| **Boilerplate** | Niski | Wysoki | Niski | Średni |
| **Type safety** | Ograniczona | Pełna | Pełna | Pełna |
| **Testowalność** | Bardzo dobra | Bardzo dobra | Dobra | Dobra |
| **Izolacja** | Pełna | Pełna | Częściowa | Częściowa |
| **Middleware** | Tak | Nie | Nie | Nie |
| **Async-ready** | Tak | Nie | Nie | Nie |

### Dlaczego Query Bus?

Projekt wybrał Query Bus z następujących powodów:

1. **Jeden wzorzec** - prostota i spójność
2. **Mniej boilerplate** - 2 pliki vs 4 pliki (Port/Adapter)
3. **Middleware** - łatwe dodanie cache, logging, metrics
4. **Async-ready** - naturalne przejście na kolejki
5. **Zunifikowane z Event Bus** - oba na Symfony Messenger

### Diagram decyzyjny

```
Czy planujesz mikroserwisy w przyszłości?
│
├── TAK
│   │
│   └── Czy zespół zna CQRS?
│       │
│       ├── TAK ──────▶ Query Bus + Event Bus (✓ nasz wybór)
│       │
│       └── NIE ──────▶ Port/Adapter
│
└── NIE
    │
    └── Jak duży jest projekt?
        │
        ├── Mały (<5 modułów) ──────▶ Facade
        │
        └── Średni/Duży ──────▶ Query Bus lub Port/Adapter
```

---

## Rekomendacje

### Dla tego projektu

**Używaj Query Bus dla:**
- Pobierania danych z innych modułów (synchroniczne)
- Walidacji wymagającej danych z innych modułów

**Używaj Event Bus dla:**
- Powiadomień o zmianach (fire & forget)
- Reakcji na zdarzenia domenowe

### Zasady

1. **Query NIE zmienia danych** - tylko odczyt
2. **Event opisuje PRZESZŁOŚĆ** - "ProductCreated" nie "CreateProduct"
3. **Jeden handler na Query** - jasna odpowiedzialność
4. **Wielu handlerów na Event** - możliwość rozszerzania

### Struktura nazewnictwa

```
Query:
├── GetProductPriceQuery     # "Get" + obiekt + "Query"
├── CheckStockAvailabilityQuery  # "Check" + warunek + "Query"
└── ProductExistsQuery       # obiekt + "Exists" + "Query"

Event:
├── ProductCreatedEvent      # obiekt + "Created" + "Event"
├── ProductDeletedEvent      # obiekt + "Deleted" + "Event"
└── OrderPlacedEvent         # obiekt + "Placed" + "Event"
```

---

## Podsumowanie

### Kluczowe wnioski

1. **Projekt używa Query Bus + Event Bus** jako jedynego wzorca komunikacji
2. **Query Bus** = synchroniczne pobieranie danych
3. **Event Bus** = asynchroniczne powiadomienia
4. **Kontrakty w Shared** - Query, Event, Bus interfaces
5. **Handlery w modułach** - QueryHandler, EventHandler

### Przepływ danych

```
┌─────────────────────────────────────────────────────────────────────┐
│                       KOMUNIKACJA MIĘDZY MODUŁAMI                   │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  ODCZYT (Query Bus):                                                │
│  ┌──────────┐   GetStockQuantityQuery   ┌──────────┐               │
│  │ CATALOG  │ ────────────────────────► │INVENTORY │               │
│  │          │ ◄──────────────────────── │          │               │
│  │          │        return 42          │          │               │
│  └──────────┘                           └──────────┘               │
│                                                                     │
│  POWIADOMIENIA (Event Bus):                                         │
│  ┌──────────┐   ProductCreatedEvent     ┌──────────┐               │
│  │ CATALOG  │ ────────────────────────► │INVENTORY │ (creates stock)│
│  │          │ ────────────────────────► │  CART    │ (no-op)       │
│  └──────────┘   (fire & forget)         └──────────┘               │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

### Checklist

- [x] Query Bus dla odczytu cross-module
- [x] Event Bus dla powiadomień
- [x] Kontrakty w SharedKernel
- [x] Handlery w modułach źródłowych
- [x] Moduły NIE importują encji z innych modułów
- [ ] Outbox Pattern dla eventów (przyszłość)
- [ ] Inbox Pattern dla idempotentności (przyszłość)

---

## Źródła i dalsze czytanie

- [Symfony Messenger](https://symfony.com/doc/current/messenger.html)
- [kgrzybek/modular-monolith-with-ddd](https://github.com/kgrzybek/modular-monolith-with-ddd)
- [Kamil Grzybek - Modular Monolith](http://www.kamilgrzybek.com/design/modular-monolith-domain-centric-design/)
- [Microsoft - CQRS Pattern](https://docs.microsoft.com/en-us/azure/architecture/patterns/cqrs)
