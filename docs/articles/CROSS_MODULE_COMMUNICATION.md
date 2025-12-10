# Komunikacja między modułami w Modularnym Monolicie

## Spis treści

1. [Wprowadzenie](#wprowadzenie)
2. [Problem do rozwiązania](#problem-do-rozwiązania)
3. [Port/Adapter (Hexagonal Architecture)](#portadapter-hexagonal-architecture)
4. [Facade Pattern](#facade-pattern)
5. [Query Bus (CQRS-lite)](#query-bus-cqrs-lite)
6. [Shared DTOs](#shared-dtos)
7. [Event Sourcing](#event-sourcing)
8. [Porównanie podejść](#porównanie-podejść)
9. [Rekomendacje](#rekomendacje)
10. [Podsumowanie](#podsumowanie)

---

## Wprowadzenie

Modularny monolit to architektura, która łączy zalety monolitu (prostota deploymentu, transakcje) z zaletami mikroserwisów (jasne granice między domenami, możliwość późniejszego podziału). Kluczowym wyzwaniem jest **komunikacja między modułami** - musi być na tyle luźna, by moduły mogły ewoluować niezależnie, ale na tyle wydajna, by nie wprowadzać nadmiernej złożoności.

### Cel artykułu

Ten artykuł przedstawia pięć popularnych podejść do komunikacji między modułami:

1. **Port/Adapter** - wzorzec z architektury heksagonalnej
2. **Facade** - uproszczone API modułu
3. **Query Bus** - lekka wersja CQRS
4. **Shared DTOs** - współdzielone obiekty transferu danych
5. **Event Sourcing** - komunikacja przez zdarzenia

Każde podejście zostanie szczegółowo omówione z przykładami kodu, zaletami, wadami i przypadkami użycia.

---

## Problem do rozwiązania

Wyobraźmy sobie system e-commerce z trzema modułami:

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

## Port/Adapter (Hexagonal Architecture)

### Koncepcja

Wzorzec Port/Adapter (znany też jako Hexagonal Architecture lub Ports & Adapters) wprowadza **warstwę abstrakcji** między modułami. Moduł konsumujący definiuje **port** (interfejs) określający jakich danych potrzebuje, a moduł dostarczający implementuje **adapter**.

```
┌─────────────────────────────────────────────────────────────┐
│                         CATALOG                              │
│  ┌─────────────────┐     ┌────────────────────────────────┐ │
│  │ StockInfoAdapter│────▶│implements StockInfoInterface   │ │
│  └─────────────────┘     └────────────────────────────────┘ │
│           │                                                  │
│           ▼                                                  │
│  ┌─────────────────┐                                        │
│  │ ProductService  │                                        │
│  └─────────────────┘                                        │
└─────────────────────────────────────────────────────────────┘
                              │
                    implements│
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                        INVENTORY                             │
│  ┌─────────────────────────────────────┐                    │
│  │ Port: StockInfoInterface            │◀──────────────────┐│
│  │   - getQuantity(productId): int     │                   ││
│  │   - isInStock(productId): bool      │                   ││
│  └─────────────────────────────────────┘                   ││
│                                                             ││
│  ┌─────────────────┐     ┌─────────────────┐               ││
│  │ StockService    │────▶│ StockInfoAdapter│───────────────┘│
│  └─────────────────┘     └─────────────────┘                │
└─────────────────────────────────────────────────────────────┘
```

### Implementacja

#### Krok 1: Definiujemy Port (interfejs) w module konsumującym

```php
<?php
// src/Catalog/Port/StockInfoInterface.php

namespace App\Catalog\Port;

/**
 * Port definiowany przez Catalog - określa jakich danych
 * o stanie magazynowym potrzebuje moduł Catalog.
 *
 * Implementacja tego interfejsu znajduje się w module Inventory.
 */
interface StockInfoInterface
{
    /**
     * Zwraca ilość produktu na stanie.
     *
     * @param int $productId ID produktu z Catalog
     * @return int Ilość dostępna na stanie (0 jeśli brak)
     */
    public function getQuantity(int $productId): int;

    /**
     * Sprawdza czy produkt jest dostępny (ilość > 0).
     *
     * @param int $productId ID produktu z Catalog
     * @return bool True jeśli produkt dostępny
     */
    public function isInStock(int $productId): bool;
}
```

#### Krok 2: Implementujemy Adapter w module dostarczającym

```php
<?php
// src/Inventory/Adapter/StockInfoAdapter.php

namespace App\Inventory\Adapter;

use App\Catalog\Port\StockInfoInterface;  // Importujemy interfejs z Catalog
use App\Inventory\Service\StockService;   // Używamy własnego serwisu

/**
 * Adapter implementujący StockInfoInterface z Catalog.
 *
 * Ten adapter "tłumaczy" operacje z języka Catalog
 * na operacje w języku Inventory.
 */
class StockInfoAdapter implements StockInfoInterface
{
    public function __construct(
        private StockService $stockService,
    ) {}

    public function getQuantity(int $productId): int
    {
        $stockItem = $this->stockService->getStockForProduct($productId);

        // Adapter odpowiada za konwersję - jeśli nie ma StockItem,
        // zwracamy 0, a nie null czy wyjątek
        return $stockItem?->getQuantity() ?? 0;
    }

    public function isInStock(int $productId): bool
    {
        return $this->stockService->isAvailable($productId, 1);
    }
}
```

#### Krok 3: Konfigurujemy Dependency Injection

```yaml
# config/services.yaml

services:
    # Alias: gdy ktoś pyta o StockInfoInterface,
    # dostaje StockInfoAdapter
    App\Catalog\Port\StockInfoInterface:
        alias: App\Inventory\Adapter\StockInfoAdapter
```

#### Krok 4: Używamy portu w kontrolerze/serwisie

```php
<?php
// src/Catalog/Controller/ProductController.php

namespace App\Catalog\Controller;

use App\Catalog\Port\StockInfoInterface;
use App\Catalog\Repository\ProductRepository;

class ProductController extends AbstractController
{
    public function __construct(
        private ProductRepository $productRepository,
        private StockInfoInterface $stockInfo,  // Wstrzykujemy interfejs
    ) {}

    #[Route('/product/{id}', name: 'catalog_product_show')]
    public function show(int $id): Response
    {
        $product = $this->productRepository->find($id);

        // Używamy portu - nie wiemy skąd pochodzą dane
        $stockQuantity = $this->stockInfo->getQuantity($id);
        $isInStock = $this->stockInfo->isInStock($id);

        return $this->render('catalog/product/show.html.twig', [
            'product' => $product,
            'stockQuantity' => $stockQuantity,
            'isInStock' => $isInStock,
        ]);
    }
}
```

### Zalety Port/Adapter

1. **Pełna izolacja** - moduły nie znają swoich implementacji
2. **Łatwe testowanie** - można mockować interfejsy
3. **Dependency Inversion** - zależność od abstrakcji, nie implementacji
4. **Możliwość podziału** - interfejs staje się kontraktem API
5. **Type safety** - kompilator/IDE pilnuje zgodności

### Wady Port/Adapter

1. **Boilerplate** - jeden interfejs = jeden adapter = jeden alias
2. **Rozproszenie kodu** - port w jednym miejscu, adapter w drugim
3. **Wiele plików** - przy 50 operacjach: 50 interfejsów + 50 adapterów
4. **Trudność nawigacji** - trzeba "skakać" między modułami

### Kiedy używać?

- **Małe/średnie projekty** (do ~10 interfejsów między modułami)
- **Wysoka potrzeba izolacji** (przygotowanie do podziału na mikroserwisy)
- **Zespół lubi explicit contracts** (wszystko udokumentowane w interfejsach)

---

## Facade Pattern

### Koncepcja

Facade to **uproszczone API modułu** - jedna klasa, która grupuje wszystkie operacje dostępne dla innych modułów. Zamiast wielu interfejsów, mamy jeden punkt wejścia.

```
┌─────────────────────────────────────────────────────────────┐
│                        INVENTORY                             │
│                                                             │
│  ┌────────────────────────────────────────────────────────┐ │
│  │               InventoryFacade                          │ │
│  │                                                        │ │
│  │  + getQuantity(productId): int                         │ │
│  │  + isInStock(productId): bool                          │ │
│  │  + reserveStock(productId, qty): ReservationId         │ │
│  │  + releaseReservation(reservationId): void             │ │
│  │  + getStockHistory(productId): array                   │ │
│  └────────────────────────────────────────────────────────┘ │
│                           │                                  │
│                           ▼                                  │
│  ┌─────────────────┐  ┌─────────────────┐                   │
│  │  StockService   │  │ ReservationSvc  │                   │
│  └─────────────────┘  └─────────────────┘                   │
└─────────────────────────────────────────────────────────────┘
```

### Implementacja

#### Krok 1: Tworzymy Facade w module dostarczającym

```php
<?php
// src/Inventory/Facade/InventoryFacade.php

namespace App\Inventory\Facade;

use App\Inventory\Service\StockService;
use App\Inventory\Service\ReservationService;
use App\Inventory\Entity\StockItem;

/**
 * Fasada modułu Inventory - jedno API dla wszystkich operacji.
 *
 * To jest JEDYNY punkt wejścia do modułu Inventory z zewnątrz.
 * Inne moduły importują tylko tę klasę.
 */
class InventoryFacade
{
    public function __construct(
        private StockService $stockService,
        private ReservationService $reservationService,
    ) {}

    // ========================================
    // OPERACJE ODCZYTU (Query)
    // ========================================

    /**
     * Pobiera ilość produktu na stanie.
     */
    public function getQuantity(int $productId): int
    {
        $stockItem = $this->stockService->getStockForProduct($productId);
        return $stockItem?->getQuantity() ?? 0;
    }

    /**
     * Sprawdza czy żądana ilość jest dostępna.
     */
    public function isAvailable(int $productId, int $quantity = 1): bool
    {
        return $this->stockService->isAvailable($productId, $quantity);
    }

    /**
     * Pobiera dostępną ilość (stan minus rezerwacje).
     */
    public function getAvailableQuantity(int $productId): int
    {
        $stock = $this->getQuantity($productId);
        $reserved = $this->reservationService->getReservedQuantity($productId);
        return max(0, $stock - $reserved);
    }

    /**
     * Pobiera stan dla wielu produktów naraz (batch).
     *
     * @param int[] $productIds
     * @return array<int, int> productId => quantity
     */
    public function getQuantitiesForProducts(array $productIds): array
    {
        return $this->stockService->getQuantitiesForProducts($productIds);
    }

    // ========================================
    // OPERACJE ZAPISU (Command)
    // ========================================

    /**
     * Rezerwuje stock dla zamówienia.
     *
     * @throws InsufficientStockException
     */
    public function reserveStock(int $productId, int $quantity): string
    {
        return $this->reservationService->reserve($productId, $quantity);
    }

    /**
     * Anuluje rezerwację.
     */
    public function releaseReservation(string $reservationId): void
    {
        $this->reservationService->release($reservationId);
    }

    /**
     * Potwierdza rezerwację (zmniejsza faktyczny stan).
     */
    public function confirmReservation(string $reservationId): void
    {
        $this->reservationService->confirm($reservationId);
    }
}
```

#### Krok 2: Używamy Facade w innych modułach

```php
<?php
// src/Cart/Service/CartService.php

namespace App\Cart\Service;

use App\Inventory\Facade\InventoryFacade;  // Import tylko fasady

class CartService
{
    public function __construct(
        private CartRepository $cartRepository,
        private InventoryFacade $inventory,  // Jedna zależność
    ) {}

    public function addItem(Cart $cart, int $productId, int $quantity): void
    {
        // Używamy fasady do sprawdzenia dostępności
        if (!$this->inventory->isAvailable($productId, $quantity)) {
            throw new InsufficientStockException($productId, $quantity);
        }

        // Rezerwujemy stock
        $reservationId = $this->inventory->reserveStock($productId, $quantity);

        $item = new CartItem();
        $item->setProductId($productId);
        $item->setQuantity($quantity);
        $item->setReservationId($reservationId);

        $cart->addItem($item);
    }
}
```

```php
<?php
// src/Catalog/Controller/ProductController.php

namespace App\Catalog\Controller;

use App\Inventory\Facade\InventoryFacade;

class ProductController extends AbstractController
{
    public function __construct(
        private ProductRepository $productRepository,
        private InventoryFacade $inventory,  // Jedna zależność
    ) {}

    public function show(int $id): Response
    {
        $product = $this->productRepository->find($id);

        return $this->render('catalog/product/show.html.twig', [
            'product' => $product,
            'stockQuantity' => $this->inventory->getQuantity($id),
            'isInStock' => $this->inventory->isAvailable($id),
            'availableQuantity' => $this->inventory->getAvailableQuantity($id),
        ]);
    }
}
```

### Facade z interfejsem (hybrid)

Dla lepszej testowalności można dodać interfejs do fasady:

```php
<?php
// src/Inventory/Facade/InventoryFacadeInterface.php

namespace App\Inventory\Facade;

interface InventoryFacadeInterface
{
    public function getQuantity(int $productId): int;
    public function isAvailable(int $productId, int $quantity = 1): bool;
    public function reserveStock(int $productId, int $quantity): string;
    // ... pozostałe metody
}
```

```php
<?php
// src/Inventory/Facade/InventoryFacade.php

class InventoryFacade implements InventoryFacadeInterface
{
    // implementacja
}
```

### Zalety Facade

1. **Prostota** - jeden import, jedna klasa
2. **Discoverability** - wszystkie operacje w jednym miejscu
3. **Mniej plików** - nie trzeba tworzyć osobnych interfejsów
4. **Łatwa nawigacja** - IDE pokaże wszystkie metody
5. **API Documentation** - fasada jest dokumentacją modułu

### Wady Facade

1. **God Object** - fasada może urosnąć do setek metod
2. **Coupling kierunkowy** - Cart zna konkretną klasę z Inventory
3. **Trudniejsze mockowanie** - trzeba mockować całą fasadę
4. **Naruszenie ISP** - klient zna metody, których nie używa
5. **Mniejsza izolacja** - zależność od konkretnej klasy

### Kiedy używać?

- **Szybki prototyp** - mniej boilerplate'u
- **Stabilne API** - moduł nie zmienia się często
- **Mały zespół** - łatwiejsza nawigacja dla nowych osób
- **Monolityczny mindset** - nie planujesz dzielić na mikroserwisy

---

## Query Bus (CQRS-lite)

### Koncepcja

Query Bus to wzorzec, w którym zamiast bezpośrednio wywoływać metody innych modułów, wysyłamy **zapytania** (Query) na **szyny** (Bus). Każde zapytanie ma swój **handler**.

```
┌──────────────────────────────────────────────────────────────────┐
│                           QUERY BUS                               │
│  ┌─────────────────────────────────────────────────────────────┐ │
│  │                      QueryBus                               │ │
│  │                                                             │ │
│  │   dispatch(Query $query): mixed                             │ │
│  │                                                             │ │
│  └─────────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────────┘
           │                                    │
           │ GetStockQuantityQuery              │ GetProductPriceQuery
           ▼                                    ▼
┌─────────────────────┐              ┌─────────────────────┐
│     INVENTORY       │              │      CATALOG        │
│                     │              │                     │
│ GetStockQuantity    │              │ GetProductPrice     │
│    Handler          │              │    Handler          │
│                     │              │                     │
└─────────────────────┘              └─────────────────────┘
```

### Implementacja

#### Krok 1: Definiujemy Query w Shared

```php
<?php
// src/Shared/Query/Inventory/GetStockQuantityQuery.php

namespace App\Shared\Query\Inventory;

/**
 * Query do pobrania ilości produktu na stanie.
 *
 * Query to obiekt reprezentujący pytanie do systemu.
 * Jest immutable i zawiera wszystkie dane potrzebne do odpowiedzi.
 */
final readonly class GetStockQuantityQuery
{
    public function __construct(
        public int $productId,
    ) {}
}
```

```php
<?php
// src/Shared/Query/Inventory/GetStockQuantitiesQuery.php

namespace App\Shared\Query\Inventory;

/**
 * Query do pobrania stanów dla wielu produktów (batch).
 */
final readonly class GetStockQuantitiesQuery
{
    /**
     * @param int[] $productIds
     */
    public function __construct(
        public array $productIds,
    ) {}
}
```

```php
<?php
// src/Shared/Query/Inventory/CheckStockAvailabilityQuery.php

namespace App\Shared\Query\Inventory;

/**
 * Query do sprawdzenia czy żądana ilość jest dostępna.
 */
final readonly class CheckStockAvailabilityQuery
{
    public function __construct(
        public int $productId,
        public int $quantity,
    ) {}
}
```

#### Krok 2: Implementujemy Handler w module dostarczającym

```php
<?php
// src/Inventory/Query/GetStockQuantityHandler.php

namespace App\Inventory\Query;

use App\Shared\Query\Inventory\GetStockQuantityQuery;
use App\Inventory\Service\StockService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler dla GetStockQuantityQuery.
 *
 * Handlery są automatycznie rejestrowane przez Symfony Messenger
 * dzięki atrybutowi AsMessageHandler.
 */
#[AsMessageHandler]
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

```php
<?php
// src/Inventory/Query/CheckStockAvailabilityHandler.php

namespace App\Inventory\Query;

use App\Shared\Query\Inventory\CheckStockAvailabilityQuery;
use App\Inventory\Service\StockService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class CheckStockAvailabilityHandler
{
    public function __construct(
        private StockService $stockService,
    ) {}

    public function __invoke(CheckStockAvailabilityQuery $query): bool
    {
        return $this->stockService->isAvailable(
            $query->productId,
            $query->quantity
        );
    }
}
```

#### Krok 3: Konfigurujemy Symfony Messenger

```yaml
# config/packages/messenger.yaml

framework:
    messenger:
        # Query bus - synchroniczny
        buses:
            query.bus:
                middleware:
                    - validation

        routing:
            # Nie routujemy query do transportu - są synchroniczne
            'App\Shared\Query\*': sync
```

#### Krok 4: Tworzymy wrapper dla Query Bus

```php
<?php
// src/Shared/Bus/QueryBus.php

namespace App\Shared\Bus;

use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Wrapper dla Symfony Messenger zapewniający type-safe query handling.
 */
final class QueryBus
{
    public function __construct(
        private MessageBusInterface $queryBus,
    ) {}

    /**
     * Wysyła query i zwraca wynik.
     *
     * @template T
     * @param object $query
     * @return T
     */
    public function query(object $query): mixed
    {
        $envelope = $this->queryBus->dispatch($query);

        /** @var HandledStamp|null $handled */
        $handled = $envelope->last(HandledStamp::class);

        if ($handled === null) {
            throw new \RuntimeException(
                sprintf('Query %s was not handled', get_class($query))
            );
        }

        return $handled->getResult();
    }
}
```

#### Krok 5: Używamy Query Bus w innych modułach

```php
<?php
// src/Cart/Service/CartService.php

namespace App\Cart\Service;

use App\Shared\Bus\QueryBus;
use App\Shared\Query\Inventory\CheckStockAvailabilityQuery;
use App\Shared\Query\Catalog\GetProductPriceQuery;

class CartService
{
    public function __construct(
        private CartRepository $cartRepository,
        private QueryBus $queryBus,  // Jeden bus dla wszystkich query
    ) {}

    public function addItem(Cart $cart, int $productId, int $quantity): void
    {
        // Sprawdzamy dostępność przez Query Bus
        $isAvailable = $this->queryBus->query(
            new CheckStockAvailabilityQuery($productId, $quantity)
        );

        if (!$isAvailable) {
            throw new InsufficientStockException($productId, $quantity);
        }

        // Pobieramy cenę przez Query Bus
        $price = $this->queryBus->query(
            new GetProductPriceQuery($productId)
        );

        $item = new CartItem();
        $item->setProductId($productId);
        $item->setQuantity($quantity);
        $item->setPriceAtAdd($price);

        $cart->addItem($item);
    }
}
```

### Zalety Query Bus

1. **Pełne odkuplowanie** - moduły nie znają się nawzajem
2. **Scentralizowane Query** - wszystkie w jednym miejscu (Shared)
3. **Middleware** - można dodać logging, caching, validation
4. **Łatwe testowanie** - mockujesz tylko QueryBus
5. **Async-ready** - łatwo zmienić na asynchroniczne

### Wady Query Bus

1. **Większa złożoność** - bus, handlery, konfiguracja
2. **Brak type-hints** - IDE nie podpowie typu zwracanego
3. **Indirection** - trudniej śledzić przepływ kodu
4. **Learning curve** - wymaga znajomości wzorca
5. **Overkill dla małych projektów**

### Kiedy używać?

- **Duże projekty** - wiele modułów, wiele operacji
- **Zespół zna CQRS** - mniejszy opór przy wdrażaniu
- **Potrzeba middleware** - caching, logging, metrics
- **Przygotowanie do async** - późniejsza migracja na message queue

---

## Shared DTOs

### Koncepcja

Shared DTOs to podejście, w którym moduły komunikują się przez **współdzielone obiekty transferu danych** umieszczone w module Shared. Każdy moduł może tworzyć i konsumować te obiekty.

```
┌─────────────────────────────────────────────────────────────┐
│                         SHARED                               │
│                                                             │
│  ┌─────────────────────────────────────────────────────────┐│
│  │                      DTOs                               ││
│  │                                                         ││
│  │  ProductInfo { id, name, price }                        ││
│  │  StockInfo { productId, quantity, available }           ││
│  │  CartSummary { items[], total }                         ││
│  │  ProductWithStock { product, stock }                    ││
│  │                                                         ││
│  └─────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────┘
         ▲              ▲              ▲
         │              │              │
    creates/uses   creates/uses   creates/uses
         │              │              │
┌────────┴───┐  ┌───────┴───┐  ┌───────┴────┐
│  CATALOG   │  │ INVENTORY │  │    CART    │
└────────────┘  └───────────┘  └────────────┘
```

### Implementacja

#### Krok 1: Definiujemy DTOs w Shared

```php
<?php
// src/Shared/DTO/ProductInfo.php

namespace App\Shared\DTO;

/**
 * DTO reprezentujący podstawowe informacje o produkcie.
 *
 * DTO jest immutable (readonly) i nie zawiera żadnej logiki biznesowej.
 */
final readonly class ProductInfo
{
    public function __construct(
        public int $id,
        public string $name,
        public string $price,
        public ?string $description = null,
        public ?int $categoryId = null,
    ) {}

    /**
     * Factory method do tworzenia z encji Product.
     * Uwaga: ta metoda powinna być w Catalog, nie tutaj!
     */
    // public static function fromProduct(Product $product): self { ... }
}
```

```php
<?php
// src/Shared/DTO/StockInfo.php

namespace App\Shared\DTO;

/**
 * DTO reprezentujący informacje o stanie magazynowym.
 */
final readonly class StockInfo
{
    public function __construct(
        public int $productId,
        public int $quantity,
        public int $reservedQuantity = 0,
        public ?int $lowStockThreshold = null,
    ) {}

    /**
     * Oblicza dostępną ilość (stan minus rezerwacje).
     */
    public function getAvailableQuantity(): int
    {
        return max(0, $this->quantity - $this->reservedQuantity);
    }

    public function isInStock(): bool
    {
        return $this->getAvailableQuantity() > 0;
    }

    public function isLowStock(): bool
    {
        if ($this->lowStockThreshold === null) {
            return false;
        }
        return $this->getAvailableQuantity() <= $this->lowStockThreshold;
    }
}
```

```php
<?php
// src/Shared/DTO/ProductWithStock.php

namespace App\Shared\DTO;

/**
 * Composite DTO łączący informacje o produkcie i stanie.
 */
final readonly class ProductWithStock
{
    public function __construct(
        public ProductInfo $product,
        public StockInfo $stock,
    ) {}

    public function isAvailable(): bool
    {
        return $this->stock->isInStock();
    }

    public function getAvailableQuantity(): int
    {
        return $this->stock->getAvailableQuantity();
    }
}
```

#### Krok 2: Moduły tworzą DTOs ze swoich encji

```php
<?php
// src/Catalog/Service/ProductDtoFactory.php

namespace App\Catalog\Service;

use App\Catalog\Entity\Product;
use App\Shared\DTO\ProductInfo;

/**
 * Factory do tworzenia ProductInfo z encji Product.
 *
 * Umieszczamy w Catalog, bo tylko Catalog zna strukturę Product.
 */
class ProductDtoFactory
{
    public function createFromEntity(Product $product): ProductInfo
    {
        return new ProductInfo(
            id: $product->getId(),
            name: $product->getName(),
            price: $product->getPrice(),
            description: $product->getDescription(),
            categoryId: $product->getCategory()?->getId(),
        );
    }

    /**
     * @param Product[] $products
     * @return ProductInfo[]
     */
    public function createFromEntities(array $products): array
    {
        return array_map(
            fn(Product $p) => $this->createFromEntity($p),
            $products
        );
    }
}
```

```php
<?php
// src/Inventory/Service/StockDtoFactory.php

namespace App\Inventory\Service;

use App\Inventory\Entity\StockItem;
use App\Shared\DTO\StockInfo;

class StockDtoFactory
{
    public function createFromEntity(StockItem $item): StockInfo
    {
        return new StockInfo(
            productId: $item->getProductId(),
            quantity: $item->getQuantity(),
            reservedQuantity: $item->getReservedQuantity(),
            lowStockThreshold: $item->getLowStockThreshold(),
        );
    }
}
```

#### Krok 3: Definiujemy interfejsy używające DTOs

```php
<?php
// src/Shared/Contract/ProductCatalogContract.php

namespace App\Shared\Contract;

use App\Shared\DTO\ProductInfo;

/**
 * Kontrakt dla operacji na katalogu produktów.
 *
 * Umieszczamy w Shared, bo jest używany przez wiele modułów.
 */
interface ProductCatalogContract
{
    public function getProduct(int $productId): ?ProductInfo;

    /**
     * @param int[] $productIds
     * @return array<int, ProductInfo> productId => ProductInfo
     */
    public function getProducts(array $productIds): array;

    public function productExists(int $productId): bool;
}
```

```php
<?php
// src/Shared/Contract/StockQueryContract.php

namespace App\Shared\Contract;

use App\Shared\DTO\StockInfo;

interface StockQueryContract
{
    public function getStockInfo(int $productId): ?StockInfo;

    /**
     * @param int[] $productIds
     * @return array<int, StockInfo>
     */
    public function getStockInfoBatch(array $productIds): array;

    public function isAvailable(int $productId, int $quantity): bool;
}
```

#### Krok 4: Implementujemy kontrakty

```php
<?php
// src/Catalog/Adapter/ProductCatalogAdapter.php

namespace App\Catalog\Adapter;

use App\Shared\Contract\ProductCatalogContract;
use App\Shared\DTO\ProductInfo;
use App\Catalog\Repository\ProductRepository;
use App\Catalog\Service\ProductDtoFactory;

class ProductCatalogAdapter implements ProductCatalogContract
{
    public function __construct(
        private ProductRepository $productRepository,
        private ProductDtoFactory $dtoFactory,
    ) {}

    public function getProduct(int $productId): ?ProductInfo
    {
        $product = $this->productRepository->find($productId);

        if ($product === null) {
            return null;
        }

        return $this->dtoFactory->createFromEntity($product);
    }

    public function getProducts(array $productIds): array
    {
        $products = $this->productRepository->findBy(['id' => $productIds]);

        $result = [];
        foreach ($products as $product) {
            $result[$product->getId()] = $this->dtoFactory->createFromEntity($product);
        }

        return $result;
    }

    public function productExists(int $productId): bool
    {
        return $this->productRepository->find($productId) !== null;
    }
}
```

#### Krok 5: Używamy DTOs w innych modułach

```php
<?php
// src/Cart/Service/CartService.php

namespace App\Cart\Service;

use App\Shared\Contract\ProductCatalogContract;
use App\Shared\Contract\StockQueryContract;
use App\Shared\DTO\ProductInfo;

class CartService
{
    public function __construct(
        private CartRepository $cartRepository,
        private ProductCatalogContract $productCatalog,
        private StockQueryContract $stockQuery,
    ) {}

    public function addItem(Cart $cart, int $productId, int $quantity): void
    {
        // Pobieramy pełne DTO produktu
        $productInfo = $this->productCatalog->getProduct($productId);

        if ($productInfo === null) {
            throw new ProductNotFoundException($productId);
        }

        // Sprawdzamy dostępność przez DTO
        if (!$this->stockQuery->isAvailable($productId, $quantity)) {
            throw new InsufficientStockException($productId, $quantity);
        }

        $item = new CartItem();
        $item->setProductId($productInfo->id);
        $item->setQuantity($quantity);
        $item->setPriceAtAdd($productInfo->price);

        $cart->addItem($item);
    }

    /**
     * Pobiera koszyk z pełnymi informacjami o produktach.
     */
    public function getCartWithProducts(Cart $cart): array
    {
        $productIds = array_map(
            fn(CartItem $item) => $item->getProductId(),
            $cart->getItems()->toArray()
        );

        // Batch fetch - jedno zapytanie
        $products = $this->productCatalog->getProducts($productIds);
        $stocks = $this->stockQuery->getStockInfoBatch($productIds);

        $result = [];
        foreach ($cart->getItems() as $item) {
            $productId = $item->getProductId();
            $result[] = [
                'item' => $item,
                'product' => $products[$productId] ?? null,
                'stock' => $stocks[$productId] ?? null,
            ];
        }

        return $result;
    }
}
```

### Zalety Shared DTOs

1. **Spójność** - ten sam DTO wszędzie
2. **Batch operations** - łatwe pobieranie wielu obiektów
3. **Rich DTOs** - mogą zawierać metody pomocnicze
4. **Mniej transformacji** - nie trzeba mapować między formatami
5. **IDE friendly** - pełne type-hints

### Wady Shared DTOs

1. **Shared coupling** - wszystkie moduły zależą od Shared
2. **God module** - Shared rośnie z każdym nowym DTO
3. **Versioning** - zmiana DTO wpływa na wszystkie moduły
4. **Overkill** - czasem potrzebujemy tylko jednego pola
5. **Trudność podziału** - DTOs trzeba zduplikować przy podziale

### Kiedy używać?

- **Wiele danych między modułami** - nie tylko pojedyncze wartości
- **Batch operations** - częste pobieranie list
- **Stabilne struktury** - DTOs nie zmieniają się często
- **Monolityczny mindset** - nie planujesz dzielić na mikroserwisy

---

## Event Sourcing

### Koncepcja

Event Sourcing to paradygmat, w którym **stan aplikacji jest pochodną sekwencji zdarzeń**. Moduły komunikują się publikując zdarzenia, a inne moduły budują swoje widoki danych na podstawie tych zdarzeń.

```
┌─────────────────────────────────────────────────────────────────┐
│                        EVENT STORE                               │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │  ProductCreated { id: 1, name: "Phone", price: "999.00" }   ││
│  │  ProductCreated { id: 2, name: "Laptop", price: "1999.00" } ││
│  │  StockInitialized { productId: 1, quantity: 100 }           ││
│  │  StockInitialized { productId: 2, quantity: 50 }            ││
│  │  ProductAddedToCart { cartId: "abc", productId: 1, qty: 2 } ││
│  │  StockDecremented { productId: 1, quantity: 2 }             ││
│  │  ...                                                        ││
│  └─────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
         │                    │                    │
    subscribes           subscribes           subscribes
         │                    │                    │
         ▼                    ▼                    ▼
┌────────────────┐  ┌────────────────┐  ┌────────────────┐
│    CATALOG     │  │   INVENTORY    │  │      CART      │
│                │  │                │  │                │
│ ProductView    │  │ StockView      │  │ CartView       │
│ (read model)   │  │ (read model)   │  │ (read model)   │
└────────────────┘  └────────────────┘  └────────────────┘
```

### Implementacja (uproszczona)

#### Krok 1: Definiujemy eventy domenowe

```php
<?php
// src/Shared/Event/ProductCreatedEvent.php

namespace App\Shared\Event;

/**
 * Event reprezentujący utworzenie produktu.
 *
 * W Event Sourcing eventy są NIEZMIENNE i stanowią
 * źródło prawdy o tym, co się wydarzyło.
 */
final readonly class ProductCreatedEvent
{
    public function __construct(
        public int $productId,
        public string $name,
        public string $price,
        public ?string $description,
        public \DateTimeImmutable $occurredAt,
    ) {}
}
```

```php
<?php
// src/Shared/Event/ProductPriceChangedEvent.php

namespace App\Shared\Event;

final readonly class ProductPriceChangedEvent
{
    public function __construct(
        public int $productId,
        public string $oldPrice,
        public string $newPrice,
        public \DateTimeImmutable $occurredAt,
    ) {}
}
```

```php
<?php
// src/Shared/Event/StockReplenishedEvent.php

namespace App\Shared\Event;

final readonly class StockReplenishedEvent
{
    public function __construct(
        public int $productId,
        public int $quantityAdded,
        public int $newTotalQuantity,
        public \DateTimeImmutable $occurredAt,
    ) {}
}
```

#### Krok 2: Moduły publikują eventy

```php
<?php
// src/Catalog/Service/ProductService.php

namespace App\Catalog\Service;

use App\Shared\Event\ProductCreatedEvent;
use App\Shared\Event\ProductPriceChangedEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class ProductService
{
    public function __construct(
        private ProductRepository $productRepository,
        private EntityManagerInterface $em,
        private EventDispatcherInterface $dispatcher,
    ) {}

    public function createProduct(string $name, string $price, ?string $description): Product
    {
        $product = new Product();
        $product->setName($name);
        $product->setPrice($price);
        $product->setDescription($description);

        $this->em->persist($product);
        $this->em->flush();

        // Publikujemy event z pełnymi danymi
        $this->dispatcher->dispatch(new ProductCreatedEvent(
            productId: $product->getId(),
            name: $product->getName(),
            price: $product->getPrice(),
            description: $product->getDescription(),
            occurredAt: new \DateTimeImmutable(),
        ));

        return $product;
    }

    public function updatePrice(int $productId, string $newPrice): void
    {
        $product = $this->productRepository->find($productId);
        $oldPrice = $product->getPrice();

        $product->setPrice($newPrice);
        $this->em->flush();

        $this->dispatcher->dispatch(new ProductPriceChangedEvent(
            productId: $productId,
            oldPrice: $oldPrice,
            newPrice: $newPrice,
            occurredAt: new \DateTimeImmutable(),
        ));
    }
}
```

#### Krok 3: Moduły budują projekcje z eventów

```php
<?php
// src/Cart/Projection/ProductCatalogProjection.php

namespace App\Cart\Projection;

use App\Shared\Event\ProductCreatedEvent;
use App\Shared\Event\ProductPriceChangedEvent;
use App\Shared\Event\ProductDeletedEvent;

/**
 * Projekcja danych produktów w module Cart.
 *
 * Zamiast pytać Catalog o dane produktów, Cart buduje
 * własną kopię danych na podstawie eventów.
 */
class ProductCatalogProjection
{
    private array $products = [];

    public function onProductCreated(ProductCreatedEvent $event): void
    {
        $this->products[$event->productId] = [
            'id' => $event->productId,
            'name' => $event->name,
            'price' => $event->price,
            'exists' => true,
        ];
    }

    public function onProductPriceChanged(ProductPriceChangedEvent $event): void
    {
        if (isset($this->products[$event->productId])) {
            $this->products[$event->productId]['price'] = $event->newPrice;
        }
    }

    public function onProductDeleted(ProductDeletedEvent $event): void
    {
        if (isset($this->products[$event->productId])) {
            $this->products[$event->productId]['exists'] = false;
        }
    }

    // Query methods
    public function getProduct(int $productId): ?array
    {
        return $this->products[$productId] ?? null;
    }

    public function getPrice(int $productId): ?string
    {
        return $this->products[$productId]['price'] ?? null;
    }

    public function productExists(int $productId): bool
    {
        return ($this->products[$productId]['exists'] ?? false) === true;
    }
}
```

#### Krok 4: Persystencja projekcji w bazie

```php
<?php
// src/Cart/Entity/ProductCatalogView.php

namespace App\Cart\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Read model produktów w module Cart.
 *
 * To jest "zdenormalizowana" kopia danych z Catalog,
 * zoptymalizowana pod potrzeby Cart.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cart_product_catalog_view')]
class ProductCatalogView
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $productId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $price;

    #[ORM\Column(type: 'boolean')]
    private bool $exists = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $lastUpdated;

    // getters & setters...
}
```

```php
<?php
// src/Cart/EventSubscriber/ProductCatalogProjector.php

namespace App\Cart\EventSubscriber;

use App\Shared\Event\ProductCreatedEvent;
use App\Shared\Event\ProductPriceChangedEvent;
use App\Shared\Event\ProductDeletedEvent;
use App\Cart\Repository\ProductCatalogViewRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Projector aktualizujący read model ProductCatalogView.
 */
class ProductCatalogProjector implements EventSubscriberInterface
{
    public function __construct(
        private ProductCatalogViewRepository $viewRepository,
        private EntityManagerInterface $em,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ProductCreatedEvent::class => 'onProductCreated',
            ProductPriceChangedEvent::class => 'onProductPriceChanged',
            ProductDeletedEvent::class => 'onProductDeleted',
        ];
    }

    public function onProductCreated(ProductCreatedEvent $event): void
    {
        $view = new ProductCatalogView();
        $view->setProductId($event->productId);
        $view->setName($event->name);
        $view->setPrice($event->price);
        $view->setExists(true);
        $view->setLastUpdated($event->occurredAt);

        $this->em->persist($view);
        $this->em->flush();
    }

    public function onProductPriceChanged(ProductPriceChangedEvent $event): void
    {
        $view = $this->viewRepository->find($event->productId);

        if ($view !== null) {
            $view->setPrice($event->newPrice);
            $view->setLastUpdated($event->occurredAt);
            $this->em->flush();
        }
    }

    public function onProductDeleted(ProductDeletedEvent $event): void
    {
        $view = $this->viewRepository->find($event->productId);

        if ($view !== null) {
            $view->setExists(false);
            $view->setLastUpdated($event->occurredAt);
            $this->em->flush();
        }
    }
}
```

#### Krok 5: Używamy read modelu zamiast pytać inny moduł

```php
<?php
// src/Cart/Service/CartService.php

namespace App\Cart\Service;

use App\Cart\Repository\ProductCatalogViewRepository;

class CartService
{
    public function __construct(
        private CartRepository $cartRepository,
        private ProductCatalogViewRepository $productCatalogView, // Własny read model!
    ) {}

    public function addItem(Cart $cart, int $productId, int $quantity): void
    {
        // Sprawdzamy we własnym read modelu - zero komunikacji z Catalog!
        $productView = $this->productCatalogView->find($productId);

        if ($productView === null || !$productView->exists()) {
            throw new ProductNotFoundException($productId);
        }

        $item = new CartItem();
        $item->setProductId($productId);
        $item->setQuantity($quantity);
        $item->setPriceAtAdd($productView->getPrice());

        $cart->addItem($item);
    }
}
```

### Zalety Event Sourcing

1. **Pełna niezależność** - moduły nie wywołują się nawzajem
2. **Audit log** - pełna historia zmian
3. **Temporal queries** - "jaki był stan na dzień X?"
4. **Rebuild** - można odtworzyć read model z eventów
5. **Async-native** - eventy naturalnie pasują do kolejek
6. **Skalowalność** - każdy moduł skaluje się niezależnie

### Wady Event Sourcing

1. **Ogromna złożoność** - eventy, projekcje, eventual consistency
2. **Eventual consistency** - dane mogą być nieaktualne
3. **Event versioning** - jak obsłużyć stare eventy?
4. **Steep learning curve** - zespół musi znać paradygmat
5. **Debugging** - trudno śledzić przepływ
6. **Duplikacja danych** - każdy moduł ma kopię

### Kiedy używać?

- **Bardzo duże systemy** - miliony eventów, wiele zespołów
- **Wymagany audit log** - compliance, finanse
- **Temporal queries** - analityka historyczna
- **Zespół ekspertów** - nie dla początkujących
- **Mikroserwisy** - naturalne podejście dla distributed systems

---

## Porównanie podejść

### Tabela porównawcza

| Kryterium | Port/Adapter | Facade | Query Bus | Shared DTOs | Event Sourcing |
|-----------|-------------|--------|-----------|-------------|----------------|
| **Złożoność** | Średnia | Niska | Wysoka | Niska | Bardzo wysoka |
| **Boilerplate** | Wysoki | Niski | Średni | Średni | Bardzo wysoki |
| **Type safety** | Pełna | Pełna | Ograniczona | Pełna | Średnia |
| **Testowalność** | Bardzo dobra | Dobra | Bardzo dobra | Dobra | Złożona |
| **Izolacja** | Pełna | Częściowa | Pełna | Częściowa | Pełna |
| **Skalowalność** | Dobra | Ograniczona | Bardzo dobra | Ograniczona | Doskonała |
| **Learning curve** | Średnia | Niska | Wysoka | Niska | Bardzo wysoka |
| **Przygotowanie do mikroserwisów** | Tak | Nie | Tak | Częściowe | Tak |

### Diagram decyzyjny

```
Czy planujesz mikroserwisy w przyszłości?
│
├── TAK
│   │
│   └── Czy zespół zna CQRS/Event Sourcing?
│       │
│       ├── TAK ──────▶ Event Sourcing lub Query Bus
│       │
│       └── NIE ──────▶ Port/Adapter
│
└── NIE
    │
    └── Jak duży jest projekt?
        │
        ├── Mały (<5 modułów) ──────▶ Facade
        │
        └── Średni/Duży
            │
            └── Czy potrzebujesz batch operations?
                │
                ├── TAK ──────▶ Shared DTOs
                │
                └── NIE ──────▶ Port/Adapter lub Facade
```

### Przykłady zastosowań

#### Startup/MVP
```
Podejście: Facade
Powód: Szybkość developmentu, mała złożoność, łatwy onboarding
```

#### Scale-up z planami na mikroserwisy
```
Podejście: Port/Adapter
Powód: Jasne kontrakty, przygotowanie do podziału
```

#### Enterprise z wieloma zespołami
```
Podejście: Query Bus lub Event Sourcing
Powód: Pełna izolacja, możliwość async, skalowanie zespołów
```

#### System finansowy z wymaganiami audytowymi
```
Podejście: Event Sourcing
Powód: Pełna historia, temporal queries, compliance
```

---

## Rekomendacje

### Dla małych projektów (1-3 moduły)

**Rekomendacja: Facade**

```php
// Prosto i efektywnie
class CartService
{
    public function __construct(
        private CatalogFacade $catalog,
        private InventoryFacade $inventory,
    ) {}
}
```

### Dla średnich projektów (4-10 modułów)

**Rekomendacja: Port/Adapter**

```php
// Jasne kontrakty między modułami
interface StockInfoInterface { ... }
class StockInfoAdapter implements StockInfoInterface { ... }
```

### Dla dużych projektów (10+ modułów)

**Rekomendacja: Hybrid Port/Adapter + Query Bus**

```php
// Port/Adapter dla prostych operacji
interface ProductNameProvider { ... }

// Query Bus dla złożonych zapytań
$result = $queryBus->query(new GetProductsWithStockQuery($categoryId));
```

### Dla systemów planujących migrację na mikroserwisy

**Rekomendacja: Query Bus + Events**

```php
// Już teraz przygotuj się na async
$queryBus->query(new GetStockQuery($productId));
$eventBus->dispatch(new ProductAddedToCartEvent(...));
```

---

## Podsumowanie

### Kluczowe wnioski

1. **Nie ma jednego słusznego podejścia** - wybór zależy od kontekstu projektu

2. **Prostota > Elegancja** - dla małych projektów Facade wystarczy

3. **Port/Adapter to bezpieczny domyślny wybór** - dobry balans między izolacją a złożonością

4. **Query Bus świeci przy dużej skali** - gdy masz wiele modułów i zespołów

5. **Event Sourcing to commitment** - nie wprowadzaj, jeśli nie jesteś pewien potrzeby

6. **Można łączyć podejścia** - Port/Adapter dla odczytów, Events dla zmian

### Pytania do samodzielnej refleksji

1. Ile modułów będzie w twoim systemie za 2 lata?
2. Czy planujesz podział na mikroserwisy?
3. Jak duży jest zespół i jaki ma poziom doświadczenia?
4. Czy są wymagania audytowe/compliance?
5. Jak ważna jest niezależność zespołów?

### Co dalej?

1. **Zacznij prosto** - Facade lub Port/Adapter
2. **Monitoruj** - ile interfejsów/adapterów tworzysz?
3. **Refaktoruj stopniowo** - gdy poczujesz ból, zmień podejście
4. **Dokumentuj decyzje** - ADR (Architecture Decision Records)

---

## Załączniki

### A. Struktura plików dla każdego podejścia

#### Port/Adapter
```
src/
├── Catalog/
│   ├── Port/
│   │   ├── StockInfoInterface.php
│   │   └── CartQuantityInterface.php
│   └── Adapter/
│       ├── InventoryProductAdapter.php
│       └── CartProductAdapter.php
├── Inventory/
│   ├── Port/
│   │   └── ProductCatalogInterface.php
│   └── Adapter/
│       ├── StockAvailabilityAdapter.php
│       └── StockInfoAdapter.php
└── Cart/
    ├── Port/
    │   ├── CartProductProviderInterface.php
    │   └── StockAvailabilityInterface.php
    └── Adapter/
        └── CartQuantityAdapter.php
```

#### Facade
```
src/
├── Catalog/
│   └── Facade/
│       └── CatalogFacade.php
├── Inventory/
│   └── Facade/
│       └── InventoryFacade.php
└── Cart/
    └── Facade/
        └── CartFacade.php
```

#### Query Bus
```
src/
├── Shared/
│   ├── Bus/
│   │   └── QueryBus.php
│   └── Query/
│       ├── Catalog/
│       │   ├── GetProductQuery.php
│       │   └── GetProductPriceQuery.php
│       └── Inventory/
│           ├── GetStockQuantityQuery.php
│           └── CheckAvailabilityQuery.php
├── Catalog/
│   └── Query/
│       ├── GetProductHandler.php
│       └── GetProductPriceHandler.php
└── Inventory/
    └── Query/
        ├── GetStockQuantityHandler.php
        └── CheckAvailabilityHandler.php
```

#### Shared DTOs
```
src/
├── Shared/
│   ├── DTO/
│   │   ├── ProductInfo.php
│   │   ├── StockInfo.php
│   │   └── ProductWithStock.php
│   └── Contract/
│       ├── ProductCatalogContract.php
│       └── StockQueryContract.php
├── Catalog/
│   ├── Adapter/
│   │   └── ProductCatalogAdapter.php
│   └── Service/
│       └── ProductDtoFactory.php
└── Inventory/
    ├── Adapter/
    │   └── StockQueryAdapter.php
    └── Service/
        └── StockDtoFactory.php
```

#### Event Sourcing
```
src/
├── Shared/
│   └── Event/
│       ├── ProductCreatedEvent.php
│       ├── ProductPriceChangedEvent.php
│       ├── StockReplenishedEvent.php
│       └── ProductAddedToCartEvent.php
├── Catalog/
│   └── EventSubscriber/
│       └── (brak - nie słucha innych modułów)
├── Inventory/
│   ├── Projection/
│   │   └── ProductCatalogProjection.php
│   └── EventSubscriber/
│       └── ProductCatalogProjector.php
└── Cart/
    ├── Entity/
    │   └── ProductCatalogView.php
    ├── Projection/
    │   └── ProductCatalogProjection.php
    └── EventSubscriber/
        ├── ProductCatalogProjector.php
        └── StockProjector.php
```

### B. Checklist wyboru podejścia

- [ ] Określiłem przewidywaną liczbę modułów
- [ ] Zdefiniowałem czy planuję mikroserwisy
- [ ] Oceniłem doświadczenie zespołu
- [ ] Zidentyfikowałem wymagania audytowe
- [ ] Przeanalizowałem wzorce komunikacji (sync vs async)
- [ ] Rozważyłem opcję hybrydową
- [ ] Udokumentowałem decyzję w ADR

---

*Artykuł przygotowany na podstawie rzeczywistego projektu modularnego monolitu e-commerce w Symfony.*
