# Komunikacja między modułami w dużych projektach DDD/CQRS

## Spis treści

1. [Wprowadzenie - dlaczego to takie ważne?](#1-wprowadzenie)
2. [Podstawy - co to jest moduł?](#2-podstawy)
3. [Problem - jak moduły mają ze sobą rozmawiać?](#3-problem)
4. [Rozwiązanie - trzy rodzaje komunikacji](#4-rozwiązanie)
5. [Query - "Hej, powiedz mi coś"](#5-query)
6. [Command - "Zrób coś dla mnie"](#6-command)
7. [Event - "Słuchajcie wszyscy, coś się stało!"](#7-event)
8. [Outbox Pattern - jak nie zgubić wiadomości](#8-outbox-pattern)
9. [Praktyczny przykład - składanie zamówienia](#9-praktyczny-przykład)
10. [Porównanie podejść](#10-porównanie)
11. [Ewolucja architektury](#11-ewolucja)
12. [Podsumowanie](#12-podsumowanie)

---

## 1. Wprowadzenie

### Dlaczego to takie ważne?

Wyobraź sobie miasto. Każda dzielnica (moduł) ma swoje sprawy:
- **Dzielnica handlowa** (Catalog) - wie wszystko o produktach
- **Magazyn** (Inventory) - pilnuje ile czego jest na stanie
- **Centrum zakupów** (Cart) - obsługuje koszyki klientów
- **Urząd zamówień** (Order) - przetwarza zamówienia

Teraz pytanie: **jak te dzielnice mają się komunikować?**

Złe podejście:
```
Magazyn dzwoni bezpośrednio do Katalogu: "Daj mi produkt #123"
Katalog sięga do szuflady Magazynu: "Sprawdzę sobie ile masz na stanie"
```

To jest **chaos**. Każdy grzebie w sprawach każdego. Zmiana w jednej dzielnicy psuje wszystkie inne.

Dobre podejście:
```
Każda dzielnica ma RECEPCJĘ (interfejs)
Komunikacja tylko przez oficjalne kanały
Nikt nie wchodzi do środka bez zaproszenia
```

Ten artykuł wyjaśni jak zbudować te "oficjalne kanały".

---

## 2. Podstawy

### Co to jest moduł?

Moduł to **samodzielna część systemu** odpowiedzialna za jeden obszar biznesowy.

```
┌─────────────────────────────────────────┐
│              MODUŁ CATALOG              │
│                                         │
│  ┌─────────────────────────────────┐   │
│  │         WNĘTRZE MODUŁU          │   │
│  │                                 │   │
│  │  • Encje (Product, Category)    │   │
│  │  • Repozytoria                  │   │
│  │  • Serwisy domenowe             │   │
│  │  • Logika biznesowa             │   │
│  │                                 │   │
│  │  ⚠️ PRYWATNE - nikt z zewnątrz  │   │
│  │     nie ma tu dostępu!          │   │
│  └─────────────────────────────────┘   │
│                                         │
│  ┌─────────────────────────────────┐   │
│  │         RECEPCJA MODUŁU         │   │
│  │     (publiczny interfejs)       │   │
│  │                                 │   │
│  │  • Przyjmuje Command/Query      │   │
│  │  • Publikuje Events             │   │
│  │                                 │   │
│  │  ✅ PUBLICZNE - każdy może      │   │
│  │     wysłać zapytanie            │   │
│  └─────────────────────────────────┘   │
└─────────────────────────────────────────┘
```

### Zasada numer jeden: IZOLACJA

```php
// ❌ ŹLE - Moduł Cart sięga do wnętrzności Catalog
class CartService
{
    public function __construct(
        private ProductRepository $productRepository  // Import z Catalog!
    ) {}

    public function addItem(int $productId): void
    {
        $product = $this->productRepository->find($productId);  // Bezpośredni dostęp!
        // ...
    }
}

// ✅ DOBRZE - Moduł Cart pyta przez oficjalny kanał
class CartService
{
    public function __construct(
        private QueryBusInterface $queryBus  // Uniwersalny interfejs
    ) {}

    public function addItem(int $productId): void
    {
        $price = $this->queryBus->query(new GetProductPriceQuery($productId));
        // Cart nie wie JAK Catalog przechowuje produkty
        // Cart tylko PYTA o cenę
    }
}
```

---

## 3. Problem

### Scenariusze komunikacji

W systemie e-commerce moduły muszą "rozmawiać" w różnych sytuacjach:

| Scenariusz | Kto pyta? | Kogo? | O co? |
|------------|-----------|-------|-------|
| Wyświetl stronę produktu | Catalog | Inventory | Ile jest na stanie? |
| Dodaj do koszyka | Cart | Catalog | Jaka jest cena? |
| Waliduj koszyk | Cart | Inventory | Czy tyle jest dostępne? |
| Złóż zamówienie | Order | Cart | Co jest w koszyku? |
| Po złożeniu zamówienia | Order → wszyscy | - | "Hej, mam nowe zamówienie!" |

### Trzy fundamentalne pytania

1. **Czy potrzebuję odpowiedzi TERAZ?** (synchroniczne vs asynchroniczne)
2. **Czy zmieniam stan czy tylko czytam?** (command vs query)
3. **Czy mówię do konkretnego modułu czy do wszystkich?** (request vs event)

---

## 4. Rozwiązanie

### Trzy rodzaje komunikacji

```
┌─────────────────────────────────────────────────────────────────┐
│                    KOMUNIKACJA MIĘDZY MODUŁAMI                  │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌─────────────┐   ┌─────────────┐   ┌─────────────────────┐   │
│  │   QUERY     │   │   COMMAND   │   │       EVENT         │   │
│  │  (Zapytanie)│   │  (Polecenie)│   │    (Zdarzenie)      │   │
│  ├─────────────┤   ├─────────────┤   ├─────────────────────┤   │
│  │ "Powiedz mi │   │ "Zrób coś"  │   │ "Coś się stało,     │   │
│  │  coś"       │   │             │   │  kogo to obchodzi?" │   │
│  ├─────────────┤   ├─────────────┤   ├─────────────────────┤   │
│  │ Synchron.   │   │ Synchron.   │   │ Asynchroniczne      │   │
│  │ Zwraca dane │   │ Zmienia stan│   │ Fire & forget       │   │
│  │ Tylko odczyt│   │ Jeden odbiorca│ │ Wielu odbiorców     │   │
│  └─────────────┘   └─────────────┘   └─────────────────────┘   │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### Analogia do życia codziennego

| Typ | Analogia | Przykład IT |
|-----|----------|-------------|
| **Query** | Pytasz kolegę "Która godzina?" | "Jaka jest cena produktu #5?" |
| **Command** | Mówisz kelnerowi "Poproszę kawę" | "Złóż zamówienie #123" |
| **Event** | Ogłaszasz "Urodziło mi się dziecko!" | "Zamówienie #123 zostało złożone" |

---

## 5. Query - "Hej, powiedz mi coś"

### Czym jest Query?

Query to **pytanie wymagające natychmiastowej odpowiedzi**. Nie zmienia żadnych danych - tylko czyta.

```
┌─────────────┐                      ┌─────────────┐
│   CATALOG   │  GetStockQuery(5)    │  INVENTORY  │
│             │ ───────────────────► │             │
│             │                      │             │
│             │ ◄─────────────────── │             │
│             │      return 42       │             │
└─────────────┘                      └─────────────┘

Catalog: "Inventory, ile masz produktu #5?"
Inventory: "Mam 42 sztuki"
Catalog: "Dzięki!" (wyświetla użytkownikowi)
```

### Implementacja Query Bus

**Krok 1: Definicja Query (kontrakt)**

```php
// src/Shared/Query/Inventory/GetStockQuantityQuery.php

namespace App\Shared\Query\Inventory;

/**
 * Zapytanie: Ile jest produktu na stanie?
 *
 * Wysyłane przez: dowolny moduł
 * Obsługiwane przez: Inventory
 */
readonly class GetStockQuantityQuery
{
    public function __construct(
        public int $productId,
    ) {}
}
```

**Krok 2: Handler (implementacja w module źródłowym)**

```php
// src/Inventory/QueryHandler/GetStockQuantityHandler.php

namespace App\Inventory\QueryHandler;

use App\Shared\Query\Inventory\GetStockQuantityQuery;
use App\Inventory\Repository\StockItemRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class GetStockQuantityHandler
{
    public function __construct(
        private StockItemRepository $repository,
    ) {}

    public function __invoke(GetStockQuantityQuery $query): int
    {
        $stock = $this->repository->findByProductId($query->productId);

        return $stock ? $stock->getQuantity() : 0;
    }
}
```

**Krok 3: Użycie**

```php
// W dowolnym module, np. Catalog/Controller/ProductController.php

class ProductController
{
    public function show(int $id): Response
    {
        $product = $this->productService->getProduct($id);

        // Zapytaj Inventory o stan magazynowy
        $stockQuantity = $this->queryBus->query(
            new GetStockQuantityQuery($id)
        );

        return $this->render('product/show.html.twig', [
            'product' => $product,
            'stock' => $stockQuantity,  // "Na stanie: 42 szt."
        ]);
    }
}
```

### Dlaczego Query jest synchroniczne?

Użytkownik czeka na stronę produktu. Musimy pokazać stan magazynowy **TERAZ**, nie za 5 sekund.

```
Użytkownik klika "Zobacz produkt"
         │
         ▼
    [Request]
         │
         ├── Pobierz dane produktu (Catalog)
         ├── Pobierz stan magazynowy (Query → Inventory)  ← MUSI BYĆ TERAZ
         ├── Pobierz ilość w koszyku (Query → Cart)       ← MUSI BYĆ TERAZ
         │
         ▼
    [Response - strona produktu]
```

### Zasady Query

| Zasada | Dlaczego? |
|--------|-----------|
| Query NIE zmienia danych | Wielokrotne wywołanie daje ten sam wynik |
| Query zwraca wartość | To pytanie - musi być odpowiedź |
| Query jest synchroniczne | Pytający czeka na odpowiedź |
| Query wie do kogo pyta | `GetStockQuantityQuery` → Inventory |

---

## 6. Command - "Zrób coś dla mnie"

### Czym jest Command?

Command to **polecenie wykonania akcji**. Zmienia stan systemu.

```
┌─────────────┐                        ┌─────────────┐
│     API     │  PlaceOrderCommand     │    ORDER    │
│             │ ─────────────────────► │             │
│             │                        │  [tworzy    │
│             │                        │  zamówienie]│
│             │ ◄───────────────────── │             │
│             │    return OrderId      │             │
└─────────────┘                        └─────────────┘

API: "Order, złóż zamówienie dla klienta #7"
Order: "Zrobione, numer zamówienia: #123"
```

### Różnica między Query a Command

```php
// QUERY - tylko czyta
$price = $this->queryBus->query(new GetProductPriceQuery($productId));
// Mogę wywołać 100 razy - nic się nie zmieni w bazie

// COMMAND - zmienia stan
$orderId = $this->commandBus->dispatch(new PlaceOrderCommand($cartId));
// Każde wywołanie tworzy NOWE zamówienie!
```

### Implementacja Command Bus

**Krok 1: Definicja Command**

```php
// src/Shared/Command/Order/PlaceOrderCommand.php

namespace App\Shared\Command\Order;

readonly class PlaceOrderCommand
{
    public function __construct(
        public int $cartId,
        public int $customerId,
        public string $shippingAddress,
    ) {}
}
```

**Krok 2: Handler**

```php
// src/Order/CommandHandler/PlaceOrderHandler.php

namespace App\Order\CommandHandler;

use App\Shared\Command\Order\PlaceOrderCommand;
use App\Order\Service\OrderService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class PlaceOrderHandler
{
    public function __construct(
        private OrderService $orderService,
    ) {}

    public function __invoke(PlaceOrderCommand $command): int
    {
        $order = $this->orderService->createOrder(
            $command->cartId,
            $command->customerId,
            $command->shippingAddress
        );

        return $order->getId();
    }
}
```

### Command vs bezpośrednie wywołanie serwisu

```php
// ❌ Bezpośrednie wywołanie - tight coupling
class CheckoutController
{
    public function __construct(
        private OrderService $orderService,  // Zależność od WNĘTRZNOŚCI modułu Order
    ) {}

    public function checkout(): Response
    {
        $order = $this->orderService->createOrder(...);  // Bezpośredni dostęp
    }
}

// ✅ Przez Command Bus - loose coupling
class CheckoutController
{
    public function __construct(
        private CommandBusInterface $commandBus,  // Uniwersalny interfejs
    ) {}

    public function checkout(): Response
    {
        $orderId = $this->commandBus->dispatch(
            new PlaceOrderCommand(...)
        );
        // Kontroler nie wie JAK Order tworzy zamówienia
        // Zna tylko KONTRAKT (PlaceOrderCommand)
    }
}
```

### Zasady Command

| Zasada | Dlaczego? |
|--------|-----------|
| Command ZMIENIA dane | To polecenie działania |
| Command może zwrócić ID | Żeby wiedzieć co zostało utworzone |
| Command jest synchroniczny | Musimy wiedzieć czy się udało |
| Jeden handler na Command | Jasna odpowiedzialność |

---

## 7. Event - "Słuchajcie wszyscy, coś się stało!"

### Czym jest Event?

Event to **ogłoszenie że coś się wydarzyło**. Nadawca nie wie (i nie obchodzi go) kto słucha.

```
                                    ┌─────────────┐
                                    │  INVENTORY  │
                                    │  "Zmniejsz  │
                               ┌───►│   stock"    │
                               │    └─────────────┘
┌─────────────┐                │
│    ORDER    │  OrderPlaced   │    ┌─────────────┐
│             │ ───────────────┼───►│    EMAIL    │
│  "Złożono   │    Event       │    │  "Wyślij    │
│ zamówienie" │                │    │ potwierdzenie"
└─────────────┘                │    └─────────────┘
                               │
                               │    ┌─────────────┐
                               └───►│  ANALYTICS  │
                                    │  "Zapisz    │
                                    │  statystyki"│
                                    └─────────────┘

Order: "Hej wszyscy! Zamówienie #123 zostało złożone!"
Inventory: "OK, zmniejszam stan magazynowy"
Email: "OK, wysyłam maila do klienta"
Analytics: "OK, zapisuję do statystyk"
Order: (nie czeka na odpowiedź, idzie dalej)
```

### Kluczowa różnica: Fire and Forget

```php
// COMMAND - czekamy na wynik
$orderId = $this->commandBus->dispatch(new PlaceOrderCommand(...));
// Musimy wiedzieć czy się udało, żeby pokazać użytkownikowi

// EVENT - nie czekamy
$this->eventBus->publish(new OrderPlacedEvent($orderId));
// Nie obchodzi nas kto to obsłuży i kiedy
// Idziemy dalej
```

### Implementacja Event Bus

**Krok 1: Definicja Event (w SharedKernel)**

```php
// src/Shared/Event/OrderPlacedEvent.php

namespace App\Shared\Event;

/**
 * Zdarzenie: Zamówienie zostało złożone
 *
 * Publikowane przez: Order
 * Subskrybenci: Inventory, Email, Analytics, ...
 */
readonly class OrderPlacedEvent
{
    public function __construct(
        public int $orderId,
        public int $customerId,
        public array $items,  // [{productId: 1, quantity: 2}, ...]
        public string $totalAmount,
        public \DateTimeImmutable $occurredAt,
    ) {}
}
```

**Krok 2: Publikacja (w module Order)**

```php
// src/Order/Service/OrderService.php

class OrderService
{
    public function createOrder(int $cartId, ...): Order
    {
        // 1. Utwórz zamówienie
        $order = new Order(...);
        $this->em->persist($order);

        // 2. Zapisz event do Outbox (w tej samej transakcji!)
        $event = new OrderPlacedEvent(
            orderId: $order->getId(),
            customerId: $order->getCustomerId(),
            items: $order->getItemsArray(),
            totalAmount: $order->getTotal(),
            occurredAt: new \DateTimeImmutable(),
        );

        $this->outbox->store($event);  // Więcej o tym w sekcji Outbox

        // 3. Commit
        $this->em->flush();

        return $order;
    }
}
```

**Krok 3: Subskrybenci (w różnych modułach)**

```php
// src/Inventory/EventSubscriber/OrderPlacedSubscriber.php

namespace App\Inventory\EventSubscriber;

use App\Shared\Event\OrderPlacedEvent;

#[AsMessageHandler]
class OrderPlacedSubscriber
{
    public function __invoke(OrderPlacedEvent $event): void
    {
        foreach ($event->items as $item) {
            $this->stockService->decreaseStock(
                $item['productId'],
                $item['quantity']
            );
        }
    }
}
```

```php
// src/Email/EventSubscriber/OrderPlacedSubscriber.php

namespace App\Email\EventSubscriber;

use App\Shared\Event\OrderPlacedEvent;

#[AsMessageHandler]
class OrderPlacedSubscriber
{
    public function __invoke(OrderPlacedEvent $event): void
    {
        $this->mailer->sendOrderConfirmation(
            $event->customerId,
            $event->orderId
        );
    }
}
```

### Dlaczego Event jest asynchroniczny?

1. **Nadawca nie musi czekać** - użytkownik dostaje odpowiedź szybciej
2. **Izolacja awarii** - błąd w Email nie psuje zamówienia
3. **Skalowalność** - subskrybenci mogą działać równolegle
4. **Rozszerzalność** - dodanie nowego subskrybenta nie wymaga zmian w Order

```
SYNCHRONICZNE (złe dla eventów):
┌─────────────────────────────────────────────────────────┐
│ Order.create() ──► Inventory ──► Email ──► Analytics    │
│                                                         │
│ Czas: 100ms + 50ms + 200ms + 30ms = 380ms              │
│ Problem: Email wolny = użytkownik czeka                 │
│ Problem: Email padł = cała operacja się wywala          │
└─────────────────────────────────────────────────────────┘

ASYNCHRONICZNE (dobre):
┌─────────────────────────────────────────────────────────┐
│ Order.create() ──► Outbox ──► Response (100ms)          │
│                       │                                 │
│                       │ (później, w tle)                │
│                       ├──► Inventory (równolegle)       │
│                       ├──► Email (równolegle)           │
│                       └──► Analytics (równolegle)       │
│                                                         │
│ Czas dla użytkownika: 100ms                             │
│ Email padł? Retry później, zamówienie OK                │
└─────────────────────────────────────────────────────────┘
```

### Zasady Event

| Zasada | Dlaczego? |
|--------|-----------|
| Event opisuje PRZESZŁOŚĆ | "OrderPlaced" nie "PlaceOrder" |
| Event jest niezmienny (immutable) | Historia się nie zmienia |
| Nadawca nie zna odbiorców | Loose coupling |
| Event jest asynchroniczny | Wydajność i izolacja |
| Wielu subskrybentów OK | Jeden event, wiele reakcji |

---

## 8. Outbox Pattern - jak nie zgubić wiadomości

### Problem: Dual Write

Co się stanie gdy:
1. Zapisujesz zamówienie do bazy ✅
2. Wysyłasz event... i aplikacja się crashuje ❌

```
┌─────────────────────────────────────────────────────────┐
│                    PROBLEM                              │
│                                                         │
│  $this->em->persist($order);                            │
│  $this->em->flush();           ← Commit do bazy ✅      │
│                                                         │
│  --- CRASH! Serwer pada ---                             │
│                                                         │
│  $this->eventBus->publish(...) ← Nigdy nie wykonane ❌  │
│                                                         │
│  REZULTAT:                                              │
│  • Zamówienie w bazie: JEST                             │
│  • Inventory zaktualizowany: NIE                        │
│  • Email wysłany: NIE                                   │
│  • System NIESPÓJNY!                                    │
└─────────────────────────────────────────────────────────┘
```

### Rozwiązanie: Outbox Pattern

Zamiast wysyłać event bezpośrednio, **zapisujemy go do tabeli** w tej samej transakcji co dane biznesowe.

```
┌─────────────────────────────────────────────────────────┐
│                    OUTBOX PATTERN                       │
│                                                         │
│  BEGIN TRANSACTION                                      │
│  │                                                      │
│  │  INSERT INTO orders (...) VALUES (...);              │
│  │  INSERT INTO outbox_events (...) VALUES (...);       │
│  │                                                      │
│  COMMIT  ← Atomowo: albo OBA albo ŻADEN                 │
│                                                         │
│  REZULTAT:                                              │
│  • Crash przed COMMIT: oba rollback, spójne             │
│  • Crash po COMMIT: oba zapisane, spójne                │
└─────────────────────────────────────────────────────────┘
```

### Implementacja Outbox

**Krok 1: Tabela Outbox**

```sql
CREATE TABLE outbox_events (
    id SERIAL PRIMARY KEY,
    event_type VARCHAR(255) NOT NULL,      -- 'OrderPlacedEvent'
    payload JSONB NOT NULL,                 -- {orderId: 123, ...}
    occurred_at TIMESTAMP NOT NULL,
    processed_at TIMESTAMP NULL,            -- NULL = do przetworzenia
    retry_count INT DEFAULT 0
);
```

**Krok 2: Zapis do Outbox**

```php
// src/Order/Service/OrderService.php

class OrderService
{
    public function createOrder(...): Order
    {
        $this->em->wrapInTransaction(function () use (...) {
            // 1. Zapisz zamówienie
            $order = new Order(...);
            $this->em->persist($order);

            // 2. Zapisz event do outbox (TA SAMA TRANSAKCJA!)
            $outboxEvent = new OutboxEvent(
                eventType: OrderPlacedEvent::class,
                payload: [
                    'orderId' => $order->getId(),
                    'customerId' => $order->getCustomerId(),
                    // ...
                ],
                occurredAt: new \DateTimeImmutable(),
            );
            $this->em->persist($outboxEvent);

            // 3. Commit obu naraz
        });

        return $order;
    }
}
```

**Krok 3: Outbox Processor (osobny proces)**

```php
// src/Shared/Command/ProcessOutboxCommand.php
// Uruchamiany przez cron lub supervisor: php bin/console app:process-outbox

class ProcessOutboxCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        while (true) {
            $events = $this->outboxRepository->findUnprocessed(limit: 100);

            foreach ($events as $outboxEvent) {
                try {
                    // Odtwórz event
                    $event = $this->deserialize($outboxEvent);

                    // Wyślij na Event Bus
                    $this->eventBus->publish($event);

                    // Oznacz jako przetworzony
                    $outboxEvent->markAsProcessed();
                    $this->em->flush();

                } catch (\Throwable $e) {
                    $outboxEvent->incrementRetry();
                    $this->em->flush();

                    $this->logger->error('Outbox processing failed', [
                        'event_id' => $outboxEvent->getId(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            sleep(1);  // Czekaj sekundę przed kolejnym sprawdzeniem
        }
    }
}
```

### Diagram przepływu z Outbox

```
┌────────────────────────────────────────────────────────────────────┐
│                         PRZEPŁYW Z OUTBOX                          │
└────────────────────────────────────────────────────────────────────┘

  SYNCHRONICZNIE (w request użytkownika):

  ┌─────────┐    ┌─────────────────────────────────────┐    ┌────────┐
  │  User   │───►│           ORDER MODULE              │───►│  User  │
  │ Request │    │                                     │    │Response│
  └─────────┘    │  ┌─────────────────────────────┐   │    └────────┘
                 │  │      JEDNA TRANSAKCJA       │   │
                 │  │                             │   │
                 │  │  1. INSERT INTO orders      │   │
                 │  │  2. INSERT INTO outbox      │   │
                 │  │  3. COMMIT                  │   │
                 │  │                             │   │
                 │  └─────────────────────────────┘   │
                 └───────────────┬─────────────────────┘
                                 │
                                 │ (dane w bazie)
                                 ▼
  ┌─────────────────────────────────────────────────────────────────┐
  │                         BAZA DANYCH                             │
  │                                                                 │
  │  orders:           outbox_events:                               │
  │  ┌────────────┐    ┌─────────────────────────────────────┐     │
  │  │ id: 123    │    │ id: 456                             │     │
  │  │ total: 100 │    │ type: OrderPlacedEvent              │     │
  │  │ ...        │    │ payload: {orderId: 123, ...}        │     │
  │  └────────────┘    │ processed_at: NULL  ← do wysłania   │     │
  │                    └─────────────────────────────────────┘     │
  └─────────────────────────────────────────────────────────────────┘
                                 │
                                 │ (odczyt przez processor)
                                 ▼
  ASYNCHRONICZNIE (osobny proces w tle):

  ┌─────────────────────────────────────────────────────────────────┐
  │                    OUTBOX PROCESSOR                             │
  │                                                                 │
  │  while (true) {                                                 │
  │      $events = findUnprocessed();                               │
  │      foreach ($events as $event) {                              │
  │          $eventBus->publish($event);                            │
  │          $event->markAsProcessed();                             │
  │      }                                                          │
  │      sleep(1);                                                  │
  │  }                                                              │
  └───────────────────────────┬─────────────────────────────────────┘
                              │
                              │ (publikacja na Event Bus)
                              ▼
  ┌─────────────────────────────────────────────────────────────────┐
  │                        EVENT BUS                                │
  │                                                                 │
  │                    OrderPlacedEvent                             │
  │                          │                                      │
  │           ┌──────────────┼──────────────┐                      │
  │           ▼              ▼              ▼                      │
  │     ┌──────────┐  ┌──────────┐  ┌──────────────┐               │
  │     │INVENTORY │  │  EMAIL   │  │  ANALYTICS   │               │
  │     │ Handler  │  │ Handler  │  │   Handler    │               │
  │     └──────────┘  └──────────┘  └──────────────┘               │
  └─────────────────────────────────────────────────────────────────┘
```

### Inbox Pattern (bonus)

Co jeśli subskrybent otrzyma ten sam event dwa razy? (np. retry po timeout)

```php
// src/Inventory/EventSubscriber/OrderPlacedSubscriber.php

class OrderPlacedSubscriber
{
    public function __invoke(OrderPlacedEvent $event): void
    {
        // Sprawdź czy już przetworzony (idempotentność)
        if ($this->inbox->wasProcessed($event->eventId)) {
            return;  // Już obsłużone, pomijam
        }

        // Przetwórz
        $this->stockService->decreaseStock(...);

        // Oznacz jako przetworzony
        $this->inbox->markAsProcessed($event->eventId);
    }
}
```

---

## 9. Praktyczny przykład - składanie zamówienia

### Pełny przepływ

```
┌─────────────────────────────────────────────────────────────────────┐
│                  SKŁADANIE ZAMÓWIENIA - PEŁNY PRZEPŁYW              │
└─────────────────────────────────────────────────────────────────────┘

Użytkownik klika "Złóż zamówienie"
         │
         ▼
┌─────────────────────────────────────────────────────────────────────┐
│ KROK 1: API przyjmuje request                                       │
│                                                                     │
│ POST /api/checkout                                                  │
│ {                                                                   │
│   "cartId": 42,                                                     │
│   "shippingAddress": "ul. Główna 1, Warszawa"                       │
│ }                                                                   │
└───────────────────────────────┬─────────────────────────────────────┘
                                │
                                ▼
┌─────────────────────────────────────────────────────────────────────┐
│ KROK 2: Walidacja (Query synchroniczne)                             │
│                                                                     │
│ // Czy koszyk istnieje i ma produkty?                               │
│ $cart = $this->queryBus->query(new GetCartQuery($cartId));          │
│                                                                     │
│ // Czy wszystkie produkty są dostępne?                              │
│ foreach ($cart->items as $item) {                                   │
│     $available = $this->queryBus->query(                            │
│         new CheckStockAvailabilityQuery(                            │
│             $item->productId,                                       │
│             $item->quantity                                         │
│         )                                                           │
│     );                                                              │
│     if (!$available) {                                              │
│         throw new InsufficientStockException(...);                  │
│     }                                                               │
│ }                                                                   │
└───────────────────────────────┬─────────────────────────────────────┘
                                │
                                ▼
┌─────────────────────────────────────────────────────────────────────┐
│ KROK 3: Złóż zamówienie (Command synchroniczny)                     │
│                                                                     │
│ $orderId = $this->commandBus->dispatch(                             │
│     new PlaceOrderCommand(                                          │
│         cartId: $cartId,                                            │
│         customerId: $currentUser->getId(),                          │
│         shippingAddress: $shippingAddress,                          │
│     )                                                               │
│ );                                                                  │
│                                                                     │
│ // Wewnątrz handlera (w jednej transakcji):                         │
│ // - Utwórz Order                                                   │
│ // - Zapisz OrderPlacedEvent do Outbox                              │
│ // - COMMIT                                                         │
└───────────────────────────────┬─────────────────────────────────────┘
                                │
                                ▼
┌─────────────────────────────────────────────────────────────────────┐
│ KROK 4: Odpowiedź do użytkownika                                    │
│                                                                     │
│ return new JsonResponse([                                           │
│     'orderId' => $orderId,                                          │
│     'message' => 'Zamówienie złożone pomyślnie!',                   │
│ ]);                                                                 │
│                                                                     │
│ // Użytkownik widzi potwierdzenie w < 500ms                         │
└───────────────────────────────┬─────────────────────────────────────┘
                                │
                                │ (request zakończony, ale...)
                                ▼
┌─────────────────────────────────────────────────────────────────────┐
│ KROK 5: Outbox Processor (w tle, asynchronicznie)                   │
│                                                                     │
│ // Co sekundę sprawdza nowe eventy                                  │
│ $event = OutboxEvent {                                              │
│     type: OrderPlacedEvent,                                         │
│     payload: {orderId: 123, items: [...], ...}                      │
│ }                                                                   │
│                                                                     │
│ $this->eventBus->publish($event);                                   │
└───────────────────────────────┬─────────────────────────────────────┘
                                │
         ┌──────────────────────┼──────────────────────┐
         │                      │                      │
         ▼                      ▼                      ▼
┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐
│ KROK 6a:        │  │ KROK 6b:        │  │ KROK 6c:        │
│ INVENTORY       │  │ CART            │  │ EMAIL           │
│                 │  │                 │  │                 │
│ Zmniejsz stock  │  │ Wyczyść koszyk  │  │ Wyślij          │
│ dla każdego     │  │ użytkownika     │  │ potwierdzenie   │
│ produktu        │  │                 │  │ na email        │
└─────────────────┘  └─────────────────┘  └─────────────────┘
```

### Kod implementacji

```php
// src/Api/Controller/CheckoutController.php

#[Route('/api/checkout', methods: ['POST'])]
class CheckoutController
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CommandBusInterface $commandBus,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $cartId = $data['cartId'];
        $shippingAddress = $data['shippingAddress'];

        // KROK 2: Walidacja przez Query
        $cart = $this->queryBus->query(new GetCartQuery($cartId));

        if (empty($cart->items)) {
            throw new EmptyCartException();
        }

        foreach ($cart->items as $item) {
            $available = $this->queryBus->query(
                new CheckStockAvailabilityQuery($item->productId, $item->quantity)
            );

            if (!$available) {
                throw new InsufficientStockException($item->productId);
            }
        }

        // KROK 3: Złóż zamówienie przez Command
        $orderId = $this->commandBus->dispatch(
            new PlaceOrderCommand(
                cartId: $cartId,
                customerId: $this->getUser()->getId(),
                shippingAddress: $shippingAddress,
            )
        );

        // KROK 4: Odpowiedź
        return new JsonResponse([
            'orderId' => $orderId,
            'message' => 'Zamówienie złożone pomyślnie!',
        ], 201);
    }
}
```

```php
// src/Order/CommandHandler/PlaceOrderHandler.php

#[AsMessageHandler]
class PlaceOrderHandler
{
    public function __invoke(PlaceOrderCommand $command): int
    {
        return $this->em->wrapInTransaction(function () use ($command) {
            // Pobierz dane koszyka
            $cart = $this->queryBus->query(new GetCartQuery($command->cartId));

            // Utwórz zamówienie
            $order = new Order(
                customerId: $command->customerId,
                shippingAddress: $command->shippingAddress,
                total: $cart->total,
            );

            foreach ($cart->items as $item) {
                $order->addItem(new OrderItem(
                    productId: $item->productId,
                    quantity: $item->quantity,
                    price: $item->price,
                ));
            }

            $this->em->persist($order);

            // Zapisz event do Outbox (ta sama transakcja!)
            $this->outbox->store(new OrderPlacedEvent(
                orderId: $order->getId(),
                customerId: $command->customerId,
                items: $cart->items,
                totalAmount: $cart->total,
                occurredAt: new \DateTimeImmutable(),
            ));

            return $order->getId();
        });
    }
}
```

---

## 10. Porównanie podejść

### Tabela zbiorcza

| Aspekt | Query | Command | Event |
|--------|-------|---------|-------|
| **Cel** | Pobierz dane | Zmień stan | Powiadom o zmianie |
| **Kierunek** | Request → Response | Request → Response | Publish → Subscribe |
| **Timing** | Synchroniczne | Synchroniczne | Asynchroniczne |
| **Odbiorcy** | Jeden | Jeden | Wielu |
| **Zwraca** | Dane | ID/void | Nic |
| **Retry** | Bezpieczne | Niebezpieczne | Bezpieczne (z Inbox) |
| **Przykład** | GetProductPrice | PlaceOrder | OrderPlaced |

### Kiedy używać czego?

```
                    ┌─────────────────────────────┐
                    │  Potrzebuję odpowiedzi      │
                    │       NATYCHMIAST?          │
                    └─────────────┬───────────────┘
                                  │
                    ┌─────────────┴───────────────┐
                    │                             │
                   TAK                           NIE
                    │                             │
                    ▼                             ▼
        ┌───────────────────┐         ┌───────────────────┐
        │  Czy ZMIENIAM     │         │      EVENT        │
        │      dane?        │         │                   │
        └─────────┬─────────┘         │ "Coś się stało,   │
                  │                   │  powiadom innych" │
        ┌─────────┴─────────┐         └───────────────────┘
        │                   │
       TAK                 NIE
        │                   │
        ▼                   ▼
┌───────────────┐   ┌───────────────┐
│    COMMAND    │   │    QUERY      │
│               │   │               │
│ "Zrób to"     │   │ "Powiedz mi"  │
└───────────────┘   └───────────────┘
```

### Przykłady z życia wzięte

| Sytuacja | Podejście | Uzasadnienie |
|----------|-----------|--------------|
| Wyświetl cenę produktu | Query | Tylko odczyt, potrzebuję teraz |
| Sprawdź dostępność | Query | Tylko odczyt, walidacja |
| Dodaj do koszyka | Command | Zmiana stanu, potrzebuję potwierdzenia |
| Złóż zamówienie | Command | Zmiana stanu, krytyczne |
| Zaktualizuj stock po zamówieniu | Event | Nie blokuj użytkownika |
| Wyślij email potwierdzenia | Event | Może być opóźniony |
| Zapisz do analytics | Event | Może być opóźniony |

---

## 11. Ewolucja architektury

### Poziomy zaawansowania

```
POZIOM 1: Prosty monolit
───────────────────────
• Bezpośrednie wywołania między klasami
• Wszystko synchroniczne
• Shared database

    ┌─────────────────────────────────┐
    │  Catalog ←→ Inventory ←→ Cart  │
    │         (spaghetti)             │
    └─────────────────────────────────┘


POZIOM 2: Modularny monolit (Port/Adapter)
──────────────────────────────────────────
• Interfejsy między modułami
• Synchroniczne wywołania
• Shared database z prefixami

    ┌─────────────────────────────────┐
    │  Catalog ──► Port ◄── Cart     │
    │         (interfejsy)            │
    └─────────────────────────────────┘


POZIOM 3: CQRS + Events (synchroniczne)
───────────────────────────────────────
• Query Bus dla odczytu
• Command Bus dla zapisu
• Events dla powiadomień (sync)
• Shared database

    ┌─────────────────────────────────┐
    │  Query Bus | Command Bus        │
    │        Event Dispatcher         │
    └─────────────────────────────────┘


POZIOM 4: CQRS + Async Events + Outbox   ← CEL
─────────────────────────────────────────
• Query Bus dla odczytu
• Command Bus dla zapisu
• Events asynchroniczne (Outbox/Inbox)
• Możliwe osobne schematy/bazy

    ┌─────────────────────────────────┐
    │  Query Bus | Command Bus        │
    │   Outbox → Event Bus → Inbox    │
    └─────────────────────────────────┘


POZIOM 5: Mikroserwisy
──────────────────────
• Osobne deployments
• Message broker (RabbitMQ, Kafka)
• Osobne bazy danych
• API Gateway

    ┌────────┐  ┌────────┐  ┌────────┐
    │Catalog │  │Inventory│ │ Cart   │
    │Service │  │ Service │  │Service │
    └───┬────┘  └────┬────┘  └───┬────┘
        │           │            │
        └─────┬─────┴────────────┘
              │
        ┌─────▼─────┐
        │  Message  │
        │  Broker   │
        └───────────┘
```

### Ścieżka migracji

```
DZISIAJ (Twój projekt)                    CEL (POZIOM 4)
─────────────────────                     ─────────────────

Port/Adapter ──────────────────────────►  Usuń (duplikacja)
     +
Query Bus ─────────────────────────────►  Query Bus (zostaje)
     +
Sync Events ───────────────────────────►  Async Events + Outbox
     +
Shared DB ─────────────────────────────►  Osobne schematy (opcjonalnie)
```

### Co zrobić w Twoim projekcie?

**Krok 1: Usuń duplikację** (teraz)
```
- Zostaw Query Bus dla odczytu cross-module
- Usuń Port/Adapter (robi to samo)
- Zostaw Events dla powiadomień
```

**Krok 2: Dodaj Outbox Pattern** (priorytet!)
```
- Tabela outbox_events
- Zapis eventów w tej samej transakcji
- Outbox Processor (cron/supervisor)
```

**Krok 3: Async Events** (później)
```
- Symfony Messenger z doctrine:// transport
- Osobny worker: php bin/console messenger:consume
```

**Krok 4: Rozważ Command Bus** (opcjonalnie)
```
- Jeśli API rośnie, zunifikuj przez Command Bus
- Jeden interfejs dla wszystkich operacji zapisu
```

---

## 12. Podsumowanie

### Złote zasady

```
┌─────────────────────────────────────────────────────────────────────┐
│                      10 ZŁOTYCH ZASAD                               │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  1. Moduły NIE importują encji z innych modułów                     │
│                                                                     │
│  2. Komunikacja TYLKO przez Query/Command/Event                     │
│                                                                     │
│  3. Query = odczyt, synchroniczne                                   │
│                                                                     │
│  4. Command = zapis, synchroniczne                                  │
│                                                                     │
│  5. Event = powiadomienie, asynchroniczne                           │
│                                                                     │
│  6. Event zawsze z Outbox Pattern (spójność!)                       │
│                                                                     │
│  7. Kontrakty (Query/Command/Event) w SharedKernel                  │
│                                                                     │
│  8. Handlery w module źródłowym                                     │
│                                                                     │
│  9. Nadawca eventu NIE wie kto słucha                               │
│                                                                     │
│  10. Eventual Consistency > Strong Consistency (dla eventów)        │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

### Diagram końcowy - docelowa architektura

```
┌─────────────────────────────────────────────────────────────────────┐
│                              API                                    │
│                     (cienka warstwa)                                │
└─────────────────────────────┬───────────────────────────────────────┘
                              │
                              │ Query/Command
                              ▼
┌─────────────────────────────────────────────────────────────────────┐
│                        SHARED KERNEL                                │
│                                                                     │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────────────────────┐ │
│  │   Query/    │  │  Command/   │  │          Event/             │ │
│  │  Catalog/   │  │   Order/    │  │  OrderPlacedEvent.php       │ │
│  │  Inventory/ │  │   Cart/     │  │  ProductCreatedEvent.php    │ │
│  └─────────────┘  └─────────────┘  └─────────────────────────────┘ │
│                                                                     │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │                     Bus Interfaces                           │   │
│  │  QueryBusInterface | CommandBusInterface | EventBusInterface │   │
│  └─────────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────────┘
                              │
        ┌─────────────────────┼─────────────────────┐
        │                     │                     │
        ▼                     ▼                     ▼
┌───────────────┐     ┌───────────────┐     ┌───────────────┐
│    CATALOG    │     │   INVENTORY   │     │     CART      │
│               │     │               │     │               │
│ QueryHandler/ │     │ QueryHandler/ │     │ QueryHandler/ │
│ CommandHandler│     │ CommandHandler│     │ CommandHandler│
│ EventHandler/ │     │ EventHandler/ │     │ EventHandler/ │
│               │     │               │     │               │
│ Domain/       │     │ Domain/       │     │ Domain/       │
│ Repository/   │     │ Repository/   │     │ Repository/   │
│ Service/      │     │ Service/      │     │ Service/      │
└───────┬───────┘     └───────┬───────┘     └───────┬───────┘
        │                     │                     │
        │                     │                     │
        └─────────┬───────────┴───────────┬─────────┘
                  │                       │
                  ▼                       ▼
        ┌─────────────────┐     ┌─────────────────────────┐
        │    DATABASE     │     │       EVENT BUS         │
        │                 │     │                         │
        │  catalog_*      │     │  ┌─────────────────┐   │
        │  inventory_*    │     │  │     OUTBOX      │   │
        │  cart_*         │     │  │   PROCESSOR     │   │
        │  outbox_events  │     │  └────────┬────────┘   │
        └─────────────────┘     │           │            │
                                │           ▼            │
                                │  Publish to handlers   │
                                └─────────────────────────┘
```

### Checklist przed wdrożeniem

```
□ Czy mam Query Bus dla odczytu cross-module?
□ Czy mam Command Bus dla operacji zapisu? (opcjonalne)
□ Czy eventy są asynchroniczne?
□ Czy mam Outbox Pattern?
□ Czy kontrakty są w SharedKernel?
□ Czy handlery są w modułach źródłowych?
□ Czy moduły NIE importują encji z innych modułów?
□ Czy mam Inbox Pattern dla idempotentności? (opcjonalne)
```

---

## Źródła i dalsze czytanie

- [kgrzybek/modular-monolith-with-ddd](https://github.com/kgrzybek/modular-monolith-with-ddd) - referencyjna implementacja
- [Kamil Grzybek - Modular Monolith](http://www.kamilgrzybek.com/design/modular-monolith-domain-centric-design/)
- [The Reformed Programmer](https://www.thereformedprogrammer.net/my-experience-of-using-modular-monolith-and-ddd-architectures/)
- [Microsoft - CQRS Pattern](https://docs.microsoft.com/en-us/azure/architecture/patterns/cqrs)
- [Microservices.io - Outbox Pattern](https://microservices.io/patterns/data/transactional-outbox.html)
