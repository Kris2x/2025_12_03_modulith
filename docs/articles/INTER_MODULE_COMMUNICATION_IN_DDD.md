# Komunikacja między modułami w DDD/CQRS

## Spis treści

1. [Wprowadzenie](#1-wprowadzenie)
2. [Podstawy - co to jest moduł?](#2-podstawy)
3. [Trzy rodzaje komunikacji](#3-trzy-rodzaje-komunikacji)
4. [Query Bus](#4-query-bus)
5. [Event Bus](#5-event-bus)
6. [Outbox Pattern](#6-outbox-pattern)
7. [Praktyczny przykład](#7-praktyczny-przykład)
8. [Podsumowanie](#8-podsumowanie)

---

## 1. Wprowadzenie

Modularny monolit wymaga jasnych zasad komunikacji między modułami. Ten artykuł wyjaśnia jak działa komunikacja w tym projekcie.

### Zasada numer jeden: IZOLACJA

```php
// ❌ ŹLE - Moduł Cart sięga do wnętrzności Catalog
class CartService
{
    public function __construct(
        private ProductRepository $productRepository  // Import z Catalog!
    ) {}
}

// ✅ DOBRZE - Moduł Cart pyta przez Query Bus
class CartService
{
    public function __construct(
        private QueryBusInterface $queryBus  // Uniwersalny interfejs
    ) {}

    public function addItem(int $productId): void
    {
        $price = $this->queryBus->query(new GetProductPriceQuery($productId));
    }
}
```

---

## 2. Podstawy

### Co to jest moduł?

```
┌─────────────────────────────────────────┐
│              MODUŁ CATALOG              │
│                                         │
│  ┌─────────────────────────────────┐   │
│  │         WNĘTRZE MODUŁU          │   │
│  │  • Encje (Product, Category)    │   │
│  │  • Repozytoria                  │   │
│  │  • Serwisy domenowe             │   │
│  │  ⚠️ PRYWATNE                    │   │
│  └─────────────────────────────────┘   │
│                                         │
│  ┌─────────────────────────────────┐   │
│  │     PUBLICZNY INTERFEJS         │   │
│  │  • QueryHandler (odpowiada)     │   │
│  │  • EventHandler (nasłuchuje)    │   │
│  │  ✅ PUBLICZNE                   │   │
│  └─────────────────────────────────┘   │
└─────────────────────────────────────────┘
```

---

## 3. Trzy rodzaje komunikacji

```
┌─────────────────────────────────────────────────────────────────┐
│                    KOMUNIKACJA MIĘDZY MODUŁAMI                  │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌─────────────┐   ┌─────────────┐   ┌─────────────────────┐   │
│  │   QUERY     │   │   COMMAND   │   │       EVENT         │   │
│  │  (Zapytanie)│   │  (Polecenie)│   │    (Zdarzenie)      │   │
│  ├─────────────┤   ├─────────────┤   ├─────────────────────┤   │
│  │ "Powiedz mi │   │ "Zrób coś"  │   │ "Coś się stało"     │   │
│  │  coś"       │   │             │   │                     │   │
│  ├─────────────┤   ├─────────────┤   ├─────────────────────┤   │
│  │ Synchron.   │   │ Synchron.   │   │ Fire & forget       │   │
│  │ Zwraca dane │   │ Zmienia stan│   │ Wielu odbiorców     │   │
│  └─────────────┘   └─────────────┘   └─────────────────────┘   │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

| Typ | Analogia | Przykład |
|-----|----------|----------|
| **Query** | Pytasz "Która godzina?" | "Jaka jest cena produktu #5?" |
| **Command** | Mówisz kelnerowi "Poproszę kawę" | "Złóż zamówienie #123" |
| **Event** | Ogłaszasz "Urodziło mi się dziecko!" | "Zamówienie #123 zostało złożone" |

---

## 4. Query Bus

### Czym jest Query?

Query to **pytanie wymagające natychmiastowej odpowiedzi**. Nie zmienia danych - tylko czyta.

```
┌─────────────┐                      ┌─────────────┐
│   CATALOG   │  GetStockQuery(5)    │  INVENTORY  │
│             │ ───────────────────► │             │
│             │                      │             │
│             │ ◄─────────────────── │             │
│             │      return 42       │             │
└─────────────┘                      └─────────────┘
```

### Implementacja

**Query (kontrakt):**

```php
// src/Shared/Query/Inventory/GetStockQuantityQuery.php
readonly class GetStockQuantityQuery
{
    public function __construct(
        public int $productId,
    ) {}
}
```

**Handler:**

```php
// src/Inventory/QueryHandler/GetStockQuantityHandler.php
#[AsMessageHandler(bus: 'query.bus')]
class GetStockQuantityHandler
{
    public function __invoke(GetStockQuantityQuery $query): int
    {
        $stock = $this->stockService->getStockForProduct($query->productId);
        return $stock?->getQuantity() ?? 0;
    }
}
```

**Użycie:**

```php
$stockQuantity = $this->queryBus->query(new GetStockQuantityQuery($productId));
```

### Zasady Query

| Zasada | Dlaczego? |
|--------|-----------|
| Query NIE zmienia danych | Bezpieczne wielokrotne wywołanie |
| Query zwraca wartość | To pytanie - musi być odpowiedź |
| Query jest synchroniczne | Pytający czeka na odpowiedź |

---

## 5. Event Bus

### Czym jest Event?

Event to **ogłoszenie że coś się wydarzyło**. Nadawca nie wie (i nie obchodzi go) kto słucha.

```
                                    ┌─────────────┐
                                    │  INVENTORY  │
                               ┌───►│  createStock│
                               │    └─────────────┘
┌─────────────┐                │
│    ORDER    │  OrderPlaced   │    ┌─────────────┐
│             │ ───────────────┼───►│    EMAIL    │
│             │   (broadcast)  │    │  sendEmail  │
└─────────────┘                │    └─────────────┘
                               │
                               │    ┌─────────────┐
                               └───►│  ANALYTICS  │
                                    │  track      │
                                    └─────────────┘
```

### Kluczowa różnica: Fire and Forget

```php
// QUERY - czekamy na wynik
$price = $this->queryBus->query(new GetProductPriceQuery($id));

// EVENT - nie czekamy
$this->eventBus->dispatch(new OrderPlacedEvent($orderId));
// Idziemy dalej, nie obchodzi nas kto to obsłuży
```

### Implementacja

**Event:**

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

**Handler:**

```php
// src/Inventory/EventHandler/ProductCreatedHandler.php
#[AsMessageHandler(bus: 'event.bus')]
final class ProductCreatedHandler
{
    public function __invoke(ProductCreatedEvent $event): void
    {
        $this->stockService->createStockItem($event->productId);
    }
}
```

### Zasady Event

| Zasada | Dlaczego? |
|--------|-----------|
| Event opisuje PRZESZŁOŚĆ | "OrderPlaced" nie "PlaceOrder" |
| Nadawca nie zna odbiorców | Loose coupling |
| Wielu subskrybentów OK | Jeden event, wiele reakcji |

---

## 6. Outbox Pattern

### Problem: Dual Write

Co się stanie gdy:
1. Zapisujesz zamówienie do bazy ✅
2. Wysyłasz event... i aplikacja crashuje ❌

```
PROBLEM:
$this->em->persist($order);
$this->em->flush();           ← Commit do bazy ✅

--- CRASH! ---

$this->eventBus->dispatch(...) ← Nigdy nie wykonane ❌

REZULTAT: System NIESPÓJNY!
```

### Rozwiązanie: Outbox Pattern

Zapisujemy event do tabeli **w tej samej transakcji** co dane biznesowe.

```
BEGIN TRANSACTION
│
│  INSERT INTO orders (...);
│  INSERT INTO outbox_events (...);
│
COMMIT  ← Atomowo: albo OBA albo ŻADEN
```

### Przepływ

```
SYNCHRONICZNIE (w request):
┌─────────────────────────────────────┐
│  1. INSERT INTO orders              │
│  2. INSERT INTO outbox_events       │
│  3. COMMIT                          │
└─────────────────────────────────────┘
         │
         ▼
ASYNCHRONICZNIE (w tle):
┌─────────────────────────────────────┐
│  OUTBOX PROCESSOR                   │
│  - Odczytaj eventy z outbox         │
│  - Wyślij na Event Bus              │
│  - Oznacz jako przetworzone         │
└─────────────────────────────────────┘
```

---

## 7. Praktyczny przykład

### Dodawanie produktu do koszyka

```php
// src/Cart/Service/CartService.php
class CartService
{
    public function addItem(Cart $cart, int $productId, int $quantity): void
    {
        // 1. Sprawdź czy produkt istnieje (Query)
        $exists = $this->queryBus->query(new ProductExistsQuery($productId));
        if (!$exists) {
            throw new InvalidArgumentException("Product not found");
        }

        // 2. Sprawdź dostępność (Query)
        $isAvailable = $this->queryBus->query(
            new CheckStockAvailabilityQuery($productId, $quantity)
        );
        if (!$isAvailable) {
            throw new InsufficientStockException($productId, $quantity);
        }

        // 3. Pobierz cenę (Query)
        $price = $this->queryBus->query(new GetProductPriceQuery($productId));

        // 4. Dodaj do koszyka
        $item = new CartItem();
        $item->setProductId($productId);
        $item->setQuantity($quantity);
        $item->setPriceAtAdd($price);

        $cart->addItem($item);
        $this->em->flush();
    }
}
```

### Tworzenie produktu

```php
// src/Catalog/Service/ProductService.php
class ProductService
{
    public function createProduct(Product $product): void
    {
        $this->em->persist($product);
        $this->em->flush();

        // Publikuj event (fire & forget)
        $this->eventBus->dispatch(new ProductCreatedEvent(
            $product->getId(),
            $product->getName(),
        ));
    }
}
```

---

## 8. Podsumowanie

### Złote zasady

```
┌─────────────────────────────────────────────────────────────────────┐
│                      ZASADY KOMUNIKACJI                             │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  1. Moduły NIE importują encji z innych modułów                     │
│  2. Komunikacja TYLKO przez Query Bus i Event Bus                   │
│  3. Query = odczyt, synchroniczne                                   │
│  4. Event = powiadomienie, asynchroniczne                           │
│  5. Kontrakty (Query/Event) w Shared                                │
│  6. Handlery w module źródłowym                                     │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

### Kiedy używać czego?

```
Potrzebuję odpowiedzi NATYCHMIAST?
│
├── TAK ──► Czy ZMIENIAM dane?
│           │
│           ├── TAK ──► Serwis/Command
│           └── NIE ──► QUERY BUS
│
└── NIE ──► EVENT BUS
```

### Diagram architektury

```
┌─────────────────────────────────────────────────────────────────────┐
│                        SHARED KERNEL                                │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────────────────────┐ │
│  │   Query/    │  │   Event/    │  │     Bus Interfaces          │ │
│  │  Catalog/   │  │ Product*    │  │ QueryBusInterface           │ │
│  │  Inventory/ │  │             │  │ EventBusInterface           │ │
│  │  Cart/      │  │             │  │                             │ │
│  └─────────────┘  └─────────────┘  └─────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────┘
        │                    │
        ▼                    ▼
┌───────────────┐     ┌───────────────┐     ┌───────────────┐
│    CATALOG    │     │   INVENTORY   │     │     CART      │
│               │     │               │     │               │
│ QueryHandler/ │     │ QueryHandler/ │     │ QueryHandler/ │
│ EventHandler/ │     │ EventHandler/ │     │ EventHandler/ │
│               │     │               │     │               │
│ Service/      │     │ Service/      │     │ Service/      │
│ Repository/   │     │ Repository/   │     │ Repository/   │
└───────────────┘     └───────────────┘     └───────────────┘
```

---

## Źródła

- [Symfony Messenger](https://symfony.com/doc/current/messenger.html)
- [kgrzybek/modular-monolith-with-ddd](https://github.com/kgrzybek/modular-monolith-with-ddd)
- [Microsoft - CQRS Pattern](https://docs.microsoft.com/en-us/azure/architecture/patterns/cqrs)
- [Microservices.io - Outbox Pattern](https://microservices.io/patterns/data/transactional-outbox.html)
