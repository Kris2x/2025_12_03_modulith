# Query Bus - Poradnik użycia

> **Zobacz też:** [Event Bus](#event-bus) - zunifikowany mechanizm do publikowania eventów domenowych.

## Czym jest Query Bus?

Query Bus to wzorzec architektoniczny umożliwiający odpytywanie o dane między modułami bez bezpośrednich zależności. Jest to **jedyny wzorzec komunikacji odczytu** używany w tym projekcie.

```
┌─────────────────┐         ┌─────────────────┐
│     CATALOG     │         │    INVENTORY    │
│                 │         │                 │
│  QueryBus.query │         │   Handler       │
│       │         │         │       ▲         │
│       ▼         │         │       │         │
│  GetStockQuery  │ ───────►│  GetStockHandler│
│                 │         │                 │
└─────────────────┘         └─────────────────┘
```

---

## Dlaczego Query Bus?

### Zalety Query Bus

| Cecha | Korzyść |
|-------|---------|
| **Jeden wzorzec** | Spójność w całym projekcie |
| **Centralizacja** | Wszystkie Query w `Shared/Query/` |
| **Middleware** | Cache, logging, metrics w jednym miejscu |
| **Mniej plików** | 2 pliki na operację (Query + Handler) |
| **Async-ready** | Łatwe przejście na asynchroniczne |

### Porównanie ilości plików

Dla jednej operacji cross-module (np. "pobierz cenę produktu"):

```
Query Bus:
├── src/Shared/Query/Catalog/GetProductPriceQuery.php    # Kontrakt
└── src/Catalog/QueryHandler/GetProductPriceHandler.php  # Implementacja

Razem: 2 pliki
```

---

## Implementacja w projekcie

### 1. Infrastruktura (Shared)

**QueryBusInterface:**

```php
// src/Shared/Bus/QueryBusInterface.php
namespace App\Shared\Bus;

interface QueryBusInterface
{
    /**
     * @template T
     * @param object $query Query object
     * @return mixed Result from handler
     */
    public function query(object $query): mixed;
}
```

**QueryBus (implementacja):**

```php
// src/Shared/Bus/QueryBus.php
namespace App\Shared\Bus;

use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

class QueryBus implements QueryBusInterface
{
    public function __construct(
        private MessageBusInterface $queryBus,
    ) {}

    public function query(object $query): mixed
    {
        $envelope = $this->queryBus->dispatch($query);
        $handledStamp = $envelope->last(HandledStamp::class);

        return $handledStamp?->getResult();
    }
}
```

### 2. Query (kontrakt w Shared)

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

```php
// src/Shared/Query/Inventory/CheckStockAvailabilityQuery.php
namespace App\Shared\Query\Inventory;

readonly class CheckStockAvailabilityQuery
{
    public function __construct(
        public int $productId,
        public int $quantity,
    ) {}
}
```

### 3. Handler (implementacja w module źródłowym)

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
        $stock = $this->stockService->getStockForProduct($query->productId);
        return $stock ? $stock->getQuantity() : 0;
    }
}
```

### 4. Konfiguracja Messenger

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        default_bus: command.bus
        buses:
            command.bus: ~
            query.bus: ~
            event.bus:
                default_middleware:
                    enabled: true
                    allow_no_handlers: true
```

```yaml
# config/services.yaml
services:
    App\Shared\Bus\QueryBusInterface:
        alias: App\Shared\Bus\QueryBus

    App\Shared\Bus\QueryBus:
        arguments:
            $queryBus: '@query.bus'
```

---

## Użycie w kodzie

### W kontrolerze:

```php
// src/Catalog/Controller/ProductController.php
use App\Shared\Bus\QueryBusInterface;
use App\Shared\Query\Inventory\GetStockQuantityQuery;
use App\Shared\Query\Cart\GetCartQuantityQuery;

class ProductController extends AbstractController
{
    public function __construct(
        private QueryBusInterface $queryBus,
    ) {}

    public function show(int $id, Request $request): Response
    {
        $product = $this->productService->getProduct($id);
        $sessionId = $request->getSession()->getId();

        // Pobierz dane przez Query Bus
        $stockQuantity = $this->queryBus->query(
            new GetStockQuantityQuery($id)
        );

        $cartQuantity = $this->queryBus->query(
            new GetCartQuantityQuery($id, $sessionId)
        );

        return $this->render('catalog/product/show.html.twig', [
            'product' => $product,
            'stockQuantity' => $stockQuantity,
            'cartQuantity' => $cartQuantity,
        ]);
    }
}
```

### W serwisie:

```php
// src/Cart/Service/CartService.php
use App\Shared\Bus\QueryBusInterface;
use App\Shared\Query\Inventory\CheckStockAvailabilityQuery;
use App\Shared\Query\Catalog\ProductExistsQuery;
use App\Shared\Query\Catalog\GetProductPriceQuery;

class CartService
{
    public function __construct(
        private QueryBusInterface $queryBus,
    ) {}

    public function addItem(Cart $cart, int $productId, int $quantity): void
    {
        // Sprawdź czy produkt istnieje
        $exists = $this->queryBus->query(new ProductExistsQuery($productId));
        if (!$exists) {
            throw new InvalidArgumentException("Product $productId not found");
        }

        // Sprawdź dostępność przez Query Bus
        $isAvailable = $this->queryBus->query(
            new CheckStockAvailabilityQuery($productId, $quantity)
        );

        if (!$isAvailable) {
            throw new InsufficientStockException($productId, $quantity);
        }

        // Pobierz cenę
        $price = $this->queryBus->query(new GetProductPriceQuery($productId));

        // ... reszta logiki
    }
}
```

---

## Dostępne Query w projekcie

### Catalog (udostępnia)

| Query | Parametry | Zwraca | Opis |
|-------|-----------|--------|------|
| `ProductExistsQuery` | `productId` | `bool` | Czy produkt istnieje |
| `GetProductPriceQuery` | `productId` | `?string` | Cena produktu |
| `GetProductNamesQuery` | `productIds[]` | `array<int,string>` | Nazwy produktów |

### Inventory (udostępnia)

| Query | Parametry | Zwraca | Opis |
|-------|-----------|--------|------|
| `GetStockQuantityQuery` | `productId` | `int` | Ilość na stanie |
| `CheckStockAvailabilityQuery` | `productId`, `quantity` | `bool` | Czy dostępne |

### Cart (udostępnia)

| Query | Parametry | Zwraca | Opis |
|-------|-----------|--------|------|
| `GetCartQuantityQuery` | `productId`, `sessionId` | `int` | Ilość w koszyku |

---

## Struktura plików

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
│   └── QueryHandler/
│       ├── GetStockQuantityHandler.php
│       └── CheckStockAvailabilityHandler.php
└── Cart/
    └── QueryHandler/
        └── GetCartQuantityHandler.php
```

---

## Dodawanie middleware

Jedną z zalet Query Bus jest możliwość dodania middleware:

### Cache middleware

```php
// src/Shared/Bus/Middleware/CacheMiddleware.php
namespace App\Shared\Bus\Middleware;

use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Contracts\Cache\CacheInterface;

class CacheMiddleware implements MiddlewareInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();

        // Tylko dla Query z cache
        if (!$message instanceof CacheableQueryInterface) {
            return $stack->next()->handle($envelope, $stack);
        }

        $key = $message->getCacheKey();

        return $this->cache->get($key, function () use ($envelope, $stack) {
            return $stack->next()->handle($envelope, $stack);
        });
    }
}
```

### Logging middleware

```php
// src/Shared/Bus/Middleware/LoggingMiddleware.php
class LoggingMiddleware implements MiddlewareInterface
{
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $start = microtime(true);

        $result = $stack->next()->handle($envelope, $stack);

        $this->logger->info('Query executed', [
            'query' => get_class($envelope->getMessage()),
            'duration' => microtime(true) - $start,
        ]);

        return $result;
    }
}
```

---

## Best practices

### 1. Nazwij Query opisowo

```php
// ✅ Dobrze
GetStockQuantityQuery
CheckStockAvailabilityQuery
GetProductNamesQuery

// ❌ Źle
StockQuery
ProductQuery
```

### 2. Używaj readonly i konstruktora

```php
// ✅ Dobrze
readonly class GetStockQuantityQuery
{
    public function __construct(
        public int $productId,
    ) {}
}

// ❌ Źle
class GetStockQuantityQuery
{
    public int $productId;
}
```

### 3. Grupuj Query według modułu źródłowego

```
src/Shared/Query/
├── Inventory/          # Query obsługiwane przez Inventory
│   ├── GetStockQuantityQuery.php
│   └── CheckStockAvailabilityQuery.php
├── Catalog/            # Query obsługiwane przez Catalog
│   ├── ProductExistsQuery.php
│   ├── GetProductPriceQuery.php
│   └── GetProductNamesQuery.php
└── Cart/               # Query obsługiwane przez Cart
    └── GetCartQuantityQuery.php
```

### 4. Handler w module źródłowym

```
src/Inventory/QueryHandler/
├── GetStockQuantityHandler.php
└── CheckStockAvailabilityHandler.php
```

### 5. Nie mieszaj Command i Query

Query Bus jest tylko do odczytu. Operacje zmieniające stan powinny używać Command Bus lub serwisów.

```php
// ✅ Query - tylko odczyt
$this->queryBus->query(new GetStockQuantityQuery($id));

// ✅ Command - zmiana stanu (osobny bus lub serwis)
$this->productService->createProduct($data);
```

---

## Kiedy NIE używać Query Bus?

1. **Operacje mutujące** - zmiany stanu powinny być przez serwisy lub Command Bus
2. **Proste, lokalne zapytania** - bezpośrednio przez repozytorium w tym samym module
3. **Type-safety krytyczne** - Query Bus zwraca `mixed`, rozważ wrappery z typami

---

## Event Bus

Event Bus jest zunifikowany z Query Bus - oba używają Symfony Messenger. To pozwala na:
- Spójne API (`#[AsMessageHandler]`)
- Łatwe przejście na async
- Wspólne middleware

### EventBusInterface

```php
// src/Shared/Bus/EventBusInterface.php
namespace App\Shared\Bus;

interface EventBusInterface
{
    /**
     * Publikuje event do wszystkich zainteresowanych subskrybentów.
     */
    public function dispatch(object $event): void;
}
```

### Użycie

```php
// Publisher (Catalog)
class ProductService
{
    public function __construct(
        private EventBusInterface $eventBus,
    ) {}

    public function createProduct(Product $product): void
    {
        $this->em->persist($product);
        $this->em->flush();

        $this->eventBus->dispatch(new ProductCreatedEvent(
            $product->getId(),
            $product->getName(),
        ));
    }
}

// Subscriber (Inventory)
#[AsMessageHandler(bus: 'event.bus')]
final class ProductCreatedHandler
{
    public function __invoke(ProductCreatedEvent $event): void
    {
        $this->stockService->createStockItem($event->productId);
    }
}
```

### Konfiguracja Messenger

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        buses:
            query.bus:
                middleware:
                    - validation
            event.bus:
                default_middleware:
                    enabled: true
                    allow_no_handlers: true  # Event może nie mieć subskrybentów

        # Łatwe przejście na async:
        # routing:
        #     'App\Shared\Event\*': async
```

### Query vs Event

| Aspekt | Query Bus | Event Bus |
|--------|-----------|-----------|
| Cel | Pobierz dane | Powiadom o zmianie |
| Zwraca | Wartość | Nic (void) |
| Handlerów | Dokładnie 1 | 0 lub więcej |
| Timing | Sync | Sync lub async |
| Przykład | `GetStockQuantityQuery` | `ProductCreatedEvent` |
