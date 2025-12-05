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
| Komunikacja | Bezpośrednie wywołania | Interfejsy/Eventy | HTTP/Message Queue |
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
│   ├── Adapter/                # Implementacje interfejsów (porty wyjściowe)
│   ├── Controller/             # Warstwa prezentacji
│   ├── Entity/                 # Encje Doctrine (model domeny)
│   ├── Event/                  # Eventy domenowe
│   ├── Form/                   # Formularze Symfony
│   ├── Repository/             # Dostęp do danych
│   └── Service/                # Logika biznesowa
│
├── Inventory/                  # Moduł magazynu
│   ├── Controller/
│   ├── Entity/
│   ├── EventSubscriber/        # Nasłuchiwacze eventów z innych modułów
│   ├── Port/                   # Interfejsy (porty wejściowe)
│   ├── Repository/
│   └── Service/
│
├── Cart/                       # Moduł koszyka
│   ├── Controller/
│   ├── Entity/
│   ├── Port/                   # Interfejsy definiujące potrzeby modułu
│   ├── Repository/
│   └── Service/
│
├── Order/                      # Moduł zamówień (do implementacji)
│
└── Customer/                   # Moduł klientów (do implementacji)
```

### Dlaczego taka struktura?

Każdy moduł jest **samodzielną jednostką** z własnymi:
- Encjami (tabele z prefiksem modułu: `catalog_product`, `cart_item`)
- Repozytoriami (dostęp tylko do swoich tabel)
- Serwisami (logika biznesowa)
- Kontrolerami (API/widoki)
- Portami/Adapterami (komunikacja z innymi modułami)

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
- `ProductService` - logika CRUD dla produktów
- `CategoryService` - logika CRUD dla kategorii
- `ProductCreatedEvent` / `ProductDeletedEvent` - eventy domenowe
- `CartProductCatalogProvider` - adapter implementujący interfejsy dla innych modułów

**Tabele:** `catalog_product`, `catalog_category`

---

### Inventory (Magazyn)

**Dokumentacja:** [docs/modules/INVENTORY.md](docs/modules/INVENTORY.md)

**Odpowiedzialność:** Śledzenie stanów magazynowych.

**Encje:**
- `StockItem` - stan magazynowy (productId, quantity)

**Kluczowe elementy:**
- `StockService` - logika zarządzania stanami
- `ProductEventSubscriber` - reaguje na `ProductCreatedEvent` i `ProductDeletedEvent`
- `ProductCatalogInterface` - port definiujący co Inventory potrzebuje od Catalog

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
- `CartService` - logika koszyka (add, remove, clear, update)
- `CartProductProviderInterface` - port definiujący co Cart potrzebuje od Catalog
- `ProductDeletedSubscriber` - reaguje na usunięcie produktu

**Ważne:** `CartItem.priceAtAdd` przechowuje cenę z momentu dodania - cena może się zmienić, ale w koszyku zostaje stara.

**Tabele:** `cart_cart`, `cart_item`

---

## Wzorce architektoniczne

### 1. Ports & Adapters (Hexagonal Architecture)

```
┌─────────────────────────────────────────────────────────────┐
│                         INVENTORY                            │
│  ┌─────────────────────────────────────────────────────┐    │
│  │                    CORE (Service)                    │    │
│  │                                                      │    │
│  │   StockService                                       │    │
│  │      │                                               │    │
│  │      │ potrzebuje danych o produktach                │    │
│  │      ▼                                               │    │
│  │   ProductCatalogInterface  ◄─── PORT (interfejs)     │    │
│  │                                                      │    │
│  └──────────────────────────────────────────────────────┘    │
│                            ▲                                 │
└────────────────────────────│─────────────────────────────────┘
                             │
                             │ implementuje
                             │
┌────────────────────────────│─────────────────────────────────┐
│                         CATALOG                              │
│                            │                                 │
│   CartProductCatalogProvider ◄─── ADAPTER (implementacja)    │
│                                                              │
└──────────────────────────────────────────────────────────────┘
```

**Port (interfejs)** - definiuje CO moduł potrzebuje:
```php
// src/Inventory/Port/ProductCatalogInterface.php
interface ProductCatalogInterface
{
    public function getProductNames(array $productIds): array;
}
```

**Adapter (implementacja)** - dostarcza JAK to zrealizować:
```php
// src/Catalog/Adapter/CartProductCatalogProvider.php
class CartProductCatalogProvider implements ProductCatalogInterface
{
    public function getProductNames(array $productIds): array
    {
        // implementacja używająca ProductRepository
    }
}
```

**Dlaczego tak?**
- **Odwrócenie zależności** (Dependency Inversion) - Inventory nie zależy od Catalog, tylko od abstrakcji
- **Testowalność** - łatwo mockować interfejs w testach
- **Elastyczność** - można podmienić implementację bez zmiany kodu modułu

---

### 2. Event-Driven Architecture

```
┌──────────────┐    ProductCreatedEvent    ┌──────────────┐
│   CATALOG    │ ─────────────────────────▶│  INVENTORY   │
│              │                           │              │
│ ProductService                           │ Subscriber   │
│   │                                      │   │          │
│   ├─ save()                              │   └─ create  │
│   └─ dispatch(event)                     │      StockItem
└──────────────┘                           └──────────────┘
```

**Event (zdarzenie)**:
```php
// src/Catalog/Event/ProductCreatedEvent.php
class ProductCreatedEvent
{
    public function __construct(
        public readonly int $productId,
        public readonly string $productName,
    ) {}
}
```

**Emitowanie eventu**:
```php
// src/Catalog/Service/ProductService.php
$this->dispatcher->dispatch(new ProductCreatedEvent(
    $product->getId(),
    $product->getName()
));
```

**Nasłuchiwanie eventu**:
```php
// src/Inventory/EventSubscriber/ProductCreatedSubscriber.php
class ProductCreatedSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [ProductCreatedEvent::class => 'onProductCreated'];
    }

    public function onProductCreated(ProductCreatedEvent $event): void
    {
        $this->stockService->createStockItem($event->productId);
    }
}
```

**Dlaczego eventy?**
- **Luźne powiązanie** - Catalog nie wie kto nasłuchuje
- **Rozszerzalność** - łatwo dodać kolejnych subskrybentów
- **Asynchroniczność** - można przenieść na kolejkę (Symfony Messenger)

---

### 3. Interface Segregation Principle (ISP)

Każdy moduł definiuje **własny interfejs** z metodami których potrzebuje:

```php
// Inventory potrzebuje tylko nazw produktów
interface ProductCatalogInterface
{
    public function getProductNames(array $productIds): array;
}

// Cart potrzebuje cen i sprawdzenia istnienia
interface CartProductProviderInterface
{
    public function getPrice(int $productId): string;
    public function productExists(int $productId): bool;
    public function getProductName(int $productId): string;
    public function getProductNames(array $productIds): array;
}
```

**Jeden adapter implementuje oba**:
```php
class CartProductCatalogProvider implements
    ProductCatalogInterface,
    CartProductProviderInterface
{
    // implementacje wszystkich metod
}
```

**Dlaczego osobne interfejsy?**
- Moduł zależy tylko od tego co potrzebuje
- Zmiany w jednym interfejsie nie wpływają na inne moduły
- Łatwiejsze testowanie (mniejsze mocki)

---

## Komunikacja między modułami

### Dozwolone wzorce komunikacji

| Wzorzec | Kiedy używać | Przykład |
|---------|--------------|----------|
| **Przez interfejs** | Potrzebujesz danych synchronicznie | Cart → ProductCatalogInterface → Catalog |
| **Przez event** | Powiadomienie o zmianie | Catalog → ProductCreatedEvent → Inventory |
| **Przez ID** | Referencja do encji innego modułu | CartItem.productId (int) |

### Diagram zależności

```
                    ┌─────────────┐
                    │   CATALOG   │
                    │  ─────────  │
                    │  Product    │
                    │  Category   │
                    └──────┬──────┘
                           │
           ┌───────────────┼───────────────┐
           │               │               │
           ▼ event         ▼ interface     ▼ interface
    ┌──────────────┐ ┌──────────────┐ ┌──────────────┐
    │  INVENTORY   │ │    CART      │ │    ORDER     │
    │  ──────────  │ │  ──────────  │ │  ──────────  │
    │  StockItem   │ │  Cart        │ │  Order       │
    │  (productId) │ │  CartItem    │ │  OrderItem   │
    └──────────────┘ │  (productId) │ │  (productId) │
                     └──────────────┘ │  (customerId)│
                                      └──────┬───────┘
                                             │
                                             ▼ ID ref
                                      ┌──────────────┐
                                      │   CUSTOMER   │
                                      └──────────────┘
```

---

## Zasady i ograniczenia

### DOZWOLONE

- Import **interfejsów (portów)** z innych modułów
- Import **eventów** z innych modułów
- Przechowywanie **ID** encji z innego modułu (jako int)
- Implementacja interfejsów innych modułów w adapterach

### ZABRONIONE

- Bezpośredni import **encji** z innego modułu
- Bezpośrednie użycie **repozytorium** innego modułu
- Tworzenie **relacji Doctrine** między encjami różnych modułów
- Współdzielenie tabel w bazie danych
- Cykliczne zależności między modułami

### Przykład - Źले vs DOBRZE

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

// DOBRZE - przez interfejs
use App\Cart\Port\CartProductProviderInterface;

class CartService
{
    public function __construct(
        private CartProductProviderInterface $productProvider  // OK
    ) {}
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

---

## Następne kroki

1. **Order** - moduł zamówień (checkout, historia)
2. **Customer** - moduł klientów (rejestracja, logowanie)
3. **Testy** - unit testy dla serwisów, integration testy dla modułów
4. **Async events** - Symfony Messenger dla eventów

---

## Materiały

- [Modular Monolith Primer](https://www.kamilgrzybek.com/design/modular-monolith-primer/)
- [Symfony Messenger](https://symfony.com/doc/current/messenger.html)
- [Hexagonal Architecture](https://alistair.cockburn.us/hexagonal-architecture/)
- [Domain-Driven Design](https://www.domainlanguage.com/ddd/)
