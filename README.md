# Modularny Monolit w Symfony

Projekt edukacyjny demonstrujący architekturę **modularnego monolitu** w Symfony 7.4.

## Spis treści

1. [Czym jest modularny monolit?](#czym-jest-modularny-monolit)
2. [Struktura projektu](#struktura-projektu)
3. [Moduły](#moduły)
4. [Wzorce architektoniczne](#wzorce-architektoniczne)
5. [Komunikacja między modułami](#komunikacja-między-modułami)
6. [Zasady i ograniczenia](#zasady-i-ograniczenia)
7. [Uruchomienie projektu](#uruchomienie-projektu)

---

## Czym jest modularny monolit?

**Modularny monolit** to architektura, która łączy zalety monolitu i mikroserwisów:

| Cecha | Klasyczny Monolit | Modularny Monolit | Mikroserwisy |
|-------|-------------------|-------------------|--------------|
| Deployment | Jeden artefakt | Jeden artefakt | Wiele artefaktów |
| Granice modułów | Brak/rozmyte | Jasne, egzekwowane | Jasne (sieć) |
| Komunikacja | Bezpośrednie wywołania | Bus/Eventy | HTTP/Message Queue |
| Baza danych | Jedna, współdzielona | Jedna, ale tabele per moduł | Osobne per serwis |
| Złożoność operacyjna | Niska | Niska | Wysoka |
| Skalowalność | Ograniczona | Ograniczona | Wysoka |

### Dlaczego modularny monolit?

1. **Prostota wdrożenia** - jeden deployment, jedna baza, łatwe debugowanie
2. **Jasne granice** - moduły są izolowane, można je później wydzielić do mikroserwisów
3. **Ewolucja** - łatwo zacząć, trudniej popsuć architekturę
4. **Wydajność** - brak narzutu sieciowego między modułami

---

## Struktura projektu

```
src/
├── Catalog/                    # Moduł katalogu produktów
│   ├── Controller/             # Warstwa prezentacji
│   ├── Entity/                 # Encje Doctrine (model domeny)
│   ├── Form/                   # Formularze Symfony
│   ├── QueryHandler/           # Handlery Query Bus
│   ├── Repository/             # Dostęp do danych
│   └── Service/                # Logika biznesowa
│
├── Inventory/                  # Moduł magazynu
│   ├── Controller/
│   ├── Entity/
│   ├── EventHandler/           # Handlery eventów (Symfony Messenger)
│   ├── QueryHandler/           # Handlery Query Bus
│   ├── Repository/
│   └── Service/
│
├── Cart/                       # Moduł koszyka
│   ├── Controller/
│   ├── Entity/
│   ├── EventHandler/           # Handlery eventów (Symfony Messenger)
│   ├── Exception/              # Wyjątki domenowe
│   ├── QueryHandler/           # Handlery Query Bus
│   ├── Repository/
│   └── Service/
│
├── Order/                      # Moduł zamówień (do implementacji)
├── Customer/                   # Moduł klientów (do implementacji)
│
└── Shared/                     # Komponenty współdzielone (SharedKernel)
    ├── Bus/                    # Query Bus i Event Bus
    │   ├── QueryBusInterface.php
    │   ├── QueryBus.php
    │   ├── EventBusInterface.php
    │   └── EventBus.php
    ├── Event/                  # Eventy domenowe
    │   ├── ProductCreatedEvent.php
    │   └── ProductDeletedEvent.php
    └── Query/                  # Definicje Query per moduł
        ├── Catalog/
        │   ├── GetProductPriceQuery.php
        │   ├── GetProductNamesQuery.php
        │   └── ProductExistsQuery.php
        ├── Inventory/
        │   ├── GetStockQuantityQuery.php
        │   └── CheckStockAvailabilityQuery.php
        └── Cart/
            └── GetCartQuantityQuery.php
```

### Dlaczego taka struktura?

Każdy moduł jest **samodzielną jednostką** z własnymi:
- Encjami (tabele z prefiksem modułu: `catalog_product`, `cart_item`)
- Repozytoriami (dostęp tylko do swoich tabel)
- Serwisami (logika biznesowa)
- Kontrolerami (API/widoki)
- QueryHandlerami (obsługa zapytań z Query Bus)
- EventHandlerami (reakcja na eventy)

---

## Moduły

> **Szczegółowa dokumentacja każdego modułu znajduje się w folderze [docs/modules/](docs/modules/)**

### Catalog (Katalog produktów)

**Dokumentacja:** [docs/modules/CATALOG.md](docs/modules/CATALOG.md)

**Odpowiedzialność:** Zarządzanie produktami i kategoriami.

**Encje:**
- `Product` - produkt (nazwa, cena, opis)
- `Category` - kategoria produktów

**Kluczowe elementy:**
- `ProductService` - logika CRUD dla produktów, emituje eventy przez `EventBusInterface`
- `CategoryService` - logika CRUD dla kategorii
- QueryHandlery: `GetProductPriceHandler`, `GetProductNamesHandler`, `ProductExistsHandler`

**Tabele:** `catalog_product`, `catalog_category`

---

### Inventory (Magazyn)

**Dokumentacja:** [docs/modules/INVENTORY.md](docs/modules/INVENTORY.md)

**Odpowiedzialność:** Śledzenie stanów magazynowych.

**Encje:**
- `StockItem` - stan magazynowy (productId, quantity)

**Kluczowe elementy:**
- `StockService` - logika zarządzania stanami
- EventHandlery: `ProductCreatedHandler`, `ProductDeletedHandler`
- QueryHandlery: `GetStockQuantityHandler`, `CheckStockAvailabilityHandler`

**Ważne:** `StockItem.productId` to **int**, NIE relacja Doctrine! Moduły nie mają relacji bazodanowych między sobą.

**Tabele:** `inventory_stock_item`

---

### Cart (Koszyk)

**Dokumentacja:** [docs/modules/CART.md](docs/modules/CART.md)

**Odpowiedzialność:** Zarządzanie koszykiem zakupowym.

**Encje:**
- `Cart` - koszyk (sessionId)
- `CartItem` - pozycja w koszyku (productId, quantity, priceAtAdd)

**Kluczowe elementy:**
- `CartService` - logika koszyka (add, remove, clear, update), używa `QueryBusInterface`
- EventHandlery: `ProductDeletedHandler`
- QueryHandlery: `GetCartQuantityHandler`

**Ważne:** `CartItem.priceAtAdd` przechowuje cenę z momentu dodania - cena może się zmienić, ale w koszyku zostaje stara.

**Tabele:** `cart_cart`, `cart_item`

---

## Wzorce architektoniczne

### 1. Query Bus i Event Bus (Symfony Messenger)

Projekt używa **Symfony Messenger** jako zunifikowanego mechanizmu komunikacji:

```
┌─────────────────────────────────────────────────────────────────┐
│                    SYMFONY MESSENGER                            │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌─────────────┐        ┌─────────────┐                        │
│  │  query.bus  │        │  event.bus  │                        │
│  │  (sync)     │        │ (sync/async)│                        │
│  ├─────────────┤        ├─────────────┤                        │
│  │ Pobieranie  │        │ Powiadamianie│                       │
│  │ danych      │        │ o zmianach   │                       │
│  └─────────────┘        └─────────────┘                        │
│                                                                 │
│  #[AsMessageHandler(bus: 'query.bus')]                         │
│  #[AsMessageHandler(bus: 'event.bus')]                         │
└─────────────────────────────────────────────────────────────────┘
```

**Query Bus** - pobieranie danych między modułami:
```php
// Definicja Query (Shared)
readonly class GetStockQuantityQuery
{
    public function __construct(public int $productId) {}
}

// Handler (Inventory)
#[AsMessageHandler(bus: 'query.bus')]
final class GetStockQuantityHandler
{
    public function __invoke(GetStockQuantityQuery $query): int
    {
        return $this->stockService->getQuantity($query->productId);
    }
}

// Użycie (Catalog)
$stock = $this->queryBus->query(new GetStockQuantityQuery($productId));
```

**Event Bus** - powiadamianie o zmianach:
```php
// Definicja Event (Shared)
readonly class ProductCreatedEvent
{
    public function __construct(
        public int $productId,
        public string $productName,
    ) {}
}

// Publikacja (Catalog)
$this->eventBus->dispatch(new ProductCreatedEvent($id, $name));

// Handler (Inventory)
#[AsMessageHandler(bus: 'event.bus')]
final class ProductCreatedHandler
{
    public function __invoke(ProductCreatedEvent $event): void
    {
        $this->stockService->createStockItem($event->productId);
    }
}
```

**Dlaczego Messenger?**
- Jeden mechanizm dla Query i Event
- Łatwe przejście na async (zmiana w `messenger.yaml`)
- Wbudowane middleware (validation, logging)
- Przygotowanie pod Outbox Pattern

---

### 2. SharedKernel Pattern

Eventy i Query są współdzielone przez wszystkie moduły:

```
src/Shared/
├── Bus/                    # Infrastruktura
│   ├── QueryBusInterface.php
│   ├── QueryBus.php
│   ├── EventBusInterface.php
│   └── EventBus.php
├── Event/                  # Kontrakty eventów
│   ├── ProductCreatedEvent.php
│   └── ProductDeletedEvent.php
└── Query/                  # Kontrakty query
    ├── Catalog/
    │   ├── GetProductPriceQuery.php
    │   ├── GetProductNamesQuery.php
    │   └── ProductExistsQuery.php
    ├── Inventory/
    │   ├── GetStockQuantityQuery.php
    │   └── CheckStockAvailabilityQuery.php
    └── Cart/
        └── GetCartQuantityQuery.php
```

**Dlaczego SharedKernel?**
- Moduły nie importują się nawzajem
- Wszystkie zależą tylko od Shared
- Łatwe zarządzanie kontraktami

---

## Komunikacja między modułami

### Dozwolone wzorce komunikacji

| Wzorzec | Kiedy używać | Przykład |
|---------|--------------|----------|
| **Query Bus** | Potrzebujesz danych synchronicznie | `GetStockQuantityQuery` → Inventory |
| **Event Bus** | Powiadomienie o zmianie (fire & forget) | `ProductCreatedEvent` → Inventory, Cart |
| **Przez ID** | Referencja do encji innego modułu | CartItem.productId (int) |

### Diagram przepływu

```
                    ┌─────────────┐
                    │   SHARED    │
                    │  ─────────  │
                    │  Event/     │
                    │  Query/     │
                    │  Bus/       │
                    └──────┬──────┘
                           │
           ┌───────────────┼───────────────┐
           │               │               │
           ▼               ▼               ▼
    ┌──────────────┐ ┌──────────────┐ ┌──────────────┐
    │   CATALOG    │ │  INVENTORY   │ │    CART      │
    │  ──────────  │ │  ──────────  │ │  ──────────  │
    │  Product     │ │  StockItem   │ │  Cart        │
    │  Category    │ │  (productId) │ │  CartItem    │
    │              │ │              │ │  (productId) │
    │  EventBus ───┼─┼──► Handlers  │ │              │
    │  QueryHandlers◄┼──── QueryBus │ │  Handlers ◄──┤
    │              │ │  QueryHandlers│ │  QueryHandlers│
    └──────────────┘ └──────────────┘ └──────────────┘
```

---

## Zasady i ograniczenia

### DOZWOLONE

- Import **eventów** z `Shared/Event/`
- Import **query** z `Shared/Query/`
- Użycie `QueryBusInterface` dla cross-module queries
- Użycie `EventBusInterface` dla publikacji eventów
- Przechowywanie **ID** encji z innego modułu (jako int)

### ZABRONIONE

- Bezpośredni import **encji** z innego modułu
- Bezpośrednie użycie **repozytorium** innego modułu
- Tworzenie **relacji Doctrine** między encjami różnych modułów
- Współdzielenie tabel w bazie danych
- Cykliczne zależności między modułami
- Bezpośrednie wywołanie serwisu z innego modułu

### Przykład - ZŁE vs DOBRE

```php
// ŹLE - bezpośrednia zależność od encji
use App\Catalog\Entity\Product;

class CartItem
{
    #[ORM\ManyToOne(targetEntity: Product::class)]
    private Product $product;  // ZABRONIONE!
}

// DOBRZE - referencja przez ID
class CartItem
{
    #[ORM\Column]
    private int $productId;  // Tylko ID, nie relacja
}
```

```php
// ŹLE - bezpośrednie użycie repozytorium
use App\Catalog\Repository\ProductRepository;

class CartService
{
    public function __construct(
        private ProductRepository $productRepo  // ZABRONIONE!
    ) {}
}

// DOBRZE - przez Query Bus
use App\Shared\Bus\QueryBusInterface;
use App\Shared\Query\Catalog\GetProductPriceQuery;

class CartService
{
    public function __construct(
        private QueryBusInterface $queryBus
    ) {}

    public function getPrice(int $productId): string
    {
        return $this->queryBus->query(new GetProductPriceQuery($productId));
    }
}
```

---

## Uruchomienie projektu

### Wymagania

- PHP 8.2+
- Composer
- Docker (dla PostgreSQL i pgAdmin)

### Instalacja

```bash
# Klonowanie
git clone <repo-url>
cd modulith

# Zależności
composer install

# Uruchomienie bazy danych
docker-compose up -d

# Utworzenie bazy i migracje
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate

# Serwer deweloperski
php -S localhost:8000 -t public
```

### Dostęp

- Aplikacja: http://localhost:8000
- Produkty: http://localhost:8000/catalog/product
- Koszyk: http://localhost:8000/cart
- Magazyn: http://localhost:8000/inventory/stock
- pgAdmin: http://localhost:5050 (admin@admin.com / admin)

### Debugowanie

```bash
# Podgląd zarejestrowanych busów i handlerów
php bin/console debug:messenger

# Lista wszystkich route'ów
php bin/console debug:router

# Wyczyszczenie cache
php bin/console cache:clear
```

---

## Następne kroki

1. **Order** - moduł zamówień (checkout, historia)
2. **Customer** - moduł klientów (rejestracja, logowanie)
3. **Outbox Pattern** - niezawodne eventy (transakcyjność)
4. **Async events** - Symfony Messenger z transportem async
5. **Testy** - unit testy dla serwisów, integration testy dla modułów

---

## Dokumentacja

- [docs/PLAN_MODULARNY_MONOLIT.md](docs/PLAN_MODULARNY_MONOLIT.md) - Plan i postępy
- [docs/CHANGELOG.md](docs/CHANGELOG.md) - Historia zmian architektonicznych
- [docs/modules/](docs/modules/) - Dokumentacja modułów
- [docs/diagrams/](docs/diagrams/) - Diagramy architektury
- [FUTURE_IMPROVEMENTS.md](FUTURE_IMPROVEMENTS.md) - Planowane ulepszenia

## Materiały

- [Modular Monolith Primer](https://www.kamilgrzybek.com/design/modular-monolith-primer/)
- [Symfony Messenger](https://symfony.com/doc/current/messenger.html)
- [Domain-Driven Design](https://www.domainlanguage.com/ddd/)

test