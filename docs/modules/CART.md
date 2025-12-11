# Moduł Cart

## Przegląd

Moduł **Cart** odpowiada za zarządzanie koszykiem zakupowym. Przechowuje produkty dodane przez użytkownika wraz z ilością i ceną z momentu dodania.

## Struktura

```
src/Cart/
├── Controller/
│   └── CartController.php                # Operacje na koszyku
├── Entity/
│   ├── Cart.php                          # Encja koszyka
│   └── CartItem.php                      # Pozycja w koszyku
├── EventHandler/                         # Handlery eventów (Symfony Messenger)
│   └── ProductDeletedHandler.php         # Reaguje na usunięcie produktu
├── Exception/
│   └── InsufficientStockException.php    # Wyjątek braku towaru
├── QueryHandler/
│   └── GetCartQuantityHandler.php        # Handler dla Query Bus
├── Repository/
│   ├── CartRepository.php                # Repozytorium koszyków
│   └── CartItemRepository.php            # Repozytorium pozycji koszyka
└── Service/
    └── CartService.php                   # Logika biznesowa koszyka
```

---

## Encje

### Cart

**Tabela:** `cart_cart`

| Pole | Typ | Opis |
|------|-----|------|
| `id` | int | Klucz główny (auto-increment) |
| `sessionId` | string(255) | ID sesji użytkownika |
| `createdAt` | datetime_immutable | Data utworzenia koszyka |

**Relacje:**
- `OneToMany` do `CartItem` z kaskadowym usuwaniem (`cascade: ['persist', 'remove']`)

```php
#[ORM\Entity(repositoryClass: CartRepository::class)]
#[ORM\Table(name: 'cart_cart')]
class Cart
{
    #[ORM\OneToMany(
        targetEntity: CartItem::class,
        mappedBy: 'cart',
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $items;
}
```

### CartItem

**Tabela:** `cart_item`

| Pole | Typ | Opis |
|------|-----|------|
| `id` | int | Klucz główny (auto-increment) |
| `cart_id` | int (FK) | Relacja do koszyka |
| `productId` | int | **ID produktu (NIE relacja!)** |
| `quantity` | int | Ilość sztuk |
| `priceAtAdd` | decimal(10,2) | Cena z momentu dodania |

**Ważne:** `productId` to zwykły `int`, NIE relacja Doctrine. To kluczowa zasada modularnego monolitu - moduły nie tworzą relacji bazodanowych między sobą.

```php
#[ORM\Entity(repositoryClass: CartItemRepository::class)]
#[ORM\Table(name: 'cart_item')]
class CartItem
{
    #[ORM\Column]
    private int $productId;  // ID, nie relacja!

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $priceAtAdd;  // Cena zamrożona w momencie dodania
}
```

---

## Serwisy

### CartService

Główny serwis do zarządzania koszykiem.

**Zależności:**
- `CartRepository` - dostęp do koszyków
- `CartItemRepository` - dostęp do pozycji
- `EntityManagerInterface` - operacje na bazie
- `QueryBusInterface` - pobieranie danych z innych modułów

**Metody:**

| Metoda | Opis |
|--------|------|
| `findCart(string $sessionId)` | Znajduje koszyk dla sesji |
| `createCart(string $sessionId)` | Tworzy nowy koszyk |
| `addItem(Cart, int $productId, int $quantity)` | Dodaje produkt do koszyka |
| `removeItem(Cart, int $productId)` | Usuwa produkt z koszyka |
| `updateItemQuantity(Cart, int $productId, int $quantity)` | Zmienia ilość (0 = usuwa) |
| `clear(Cart)` | Czyści cały koszyk |
| `getTotal(Cart)` | Oblicza sumę koszyka |
| `getProductNames(Cart)` | Pobiera nazwy produktów (batch) |
| `removeItemsByProductId(int $productId)` | Usuwa pozycje po usunięciu produktu |

**Logika dodawania produktu (z Query Bus):**

```php
public function addItem(Cart $cart, int $productId, int $quantity = 1): void
{
    // 1. Walidacja - czy produkt istnieje?
    $exists = $this->queryBus->query(new ProductExistsQuery($productId));
    if (!$exists) {
        throw new InvalidArgumentException("Product $productId not found");
    }

    // 2. Oblicz całkowitą ilość (istniejąca + nowa)
    $currentQuantity = 0;
    foreach ($cart->getItems() as $item) {
        if ($item->getProductId() === $productId) {
            $currentQuantity = $item->getQuantity();
            break;
        }
    }
    $totalQuantity = $currentQuantity + $quantity;

    // 3. Walidacja dostępności w magazynie przez Query Bus
    $isAvailable = $this->queryBus->query(
        new CheckStockAvailabilityQuery($productId, $totalQuantity)
    );
    if (!$isAvailable) {
        throw new InsufficientStockException($productId, $totalQuantity);
    }

    // 4. Sprawdź czy już jest w koszyku
    foreach ($cart->getItems() as $item) {
        if ($item->getProductId() === $productId) {
            $item->setQuantity($totalQuantity);
            $this->em->flush();
            return;
        }
    }

    // 5. Nowa pozycja - pobierz cenę przez Query Bus
    $price = $this->queryBus->query(new GetProductPriceQuery($productId));

    $item = new CartItem();
    $item->setProductId($productId);
    $item->setQuantity($quantity);
    $item->setPriceAtAdd($price);

    $cart->addItem($item);
    $this->em->flush();
}
```

---

## Event Handlers

Handlery eventów są zaimplementowane jako Symfony Messenger handlers (zunifikowane z Query Bus).

### ProductDeletedHandler

Reaguje na usunięcie produktu z modułu Catalog.

```php
#[AsMessageHandler(bus: 'event.bus')]
final class ProductDeletedHandler
{
    public function __construct(
        private CartService $cartService,
    ) {}

    public function __invoke(ProductDeletedEvent $event): void
    {
        // Usuń wszystkie pozycje koszyka z tym produktem
        $this->cartService->removeItemsByProductId($event->productId);
    }
}
```

**Dlaczego?**
Bez tego handlera, po usunięciu produktu w koszykach zostałyby "osierocone" pozycje wyświetlające "Nieznany produkt".

---

## Query Handlers

Moduł Cart udostępnia dane innym modułom przez Query Bus:

### GetCartQuantityHandler

Zwraca ilość danego produktu w koszyku użytkownika.

```php
#[AsMessageHandler(bus: 'query.bus')]
final class GetCartQuantityHandler
{
    public function __construct(
        private CartItemRepository $cartItemRepository,
    ) {}

    public function __invoke(GetCartQuantityQuery $query): int
    {
        return $this->cartItemRepository->getQuantityForProduct(
            $query->productId,
            $query->sessionId
        );
    }
}
```

**Query (w Shared):**

```php
// src/Shared/Query/Cart/GetCartQuantityQuery.php
readonly class GetCartQuantityQuery
{
    public function __construct(
        public int $productId,
        public string $sessionId,
    ) {}
}
```

**Użycie w innych modułach:**

```php
// Catalog wyświetla ile sztuk jest już w koszyku
$cartQuantity = $this->queryBus->query(
    new GetCartQuantityQuery($productId, $sessionId)
);
```

---

## Kontroler i routing

### CartController

**Prefix:** `/cart`

| Route | Metoda | Akcja | Opis |
|-------|--------|-------|------|
| `/` | GET | `index` | Widok koszyka |
| `/add/{productId}` | POST | `add` | Dodaj produkt |
| `/remove/{productId}` | POST | `remove` | Usuń pozycję |
| `/update/{productId}` | POST | `update` | Zmień ilość |
| `/clear` | POST | `clear` | Wyczyść koszyk |

**Obsługa sesji:**

```php
public function index(Request $request): Response
{
    $sessionId = $request->getSession()->getId();
    $cart = $this->cartService->findCart($sessionId);

    // Jeśli brak koszyka, pokaż pusty widok
    if (!$cart) {
        return $this->render('cart/index.html.twig', [
            'cart' => null,
            'total' => '0.00',
            'productNames' => [],
        ]);
    }

    return $this->render('cart/index.html.twig', [
        'cart' => $cart,
        'total' => $this->cartService->getTotal($cart),
        'productNames' => $this->cartService->getProductNames($cart),
    ]);
}
```

---

## Integracja z innymi modułami

### Pobiera dane przez Query Bus

```
Cart ──[ProductExistsQuery]────────────► Catalog
     ──[GetProductPriceQuery]──────────► Catalog
     ──[GetProductNamesQuery]──────────► Catalog
     ──[CheckStockAvailabilityQuery]───► Inventory
```

Moduł Cart używa Query Bus do:
- **ProductExistsQuery** - sprawdzenie czy produkt istnieje
- **GetProductPriceQuery** - pobranie aktualnej ceny przy dodawaniu
- **GetProductNamesQuery** - pobranie nazw produktów do wyświetlenia
- **CheckStockAvailabilityQuery** - walidacja dostępności przed dodaniem

### Udostępnia dane przez Query Bus

```
Catalog ──[GetCartQuantityQuery]──► Cart
```

Moduł Cart udostępnia `GetCartQuantityHandler` umożliwiający wyświetlenie ilości produktu w koszyku na stronie produktu.

### Reaguje na eventy (nasłuchuje)

```
Shared ──[ProductDeletedEvent]──► Cart
```

Gdy produkt zostaje usunięty, Cart automatycznie usuwa odpowiednie pozycje z wszystkich koszyków.

---

## Szablony Twig

```
templates/cart/
└── index.html.twig    # Widok koszyka z tabelą pozycji
```

**Przekazywane dane:**
- `cart` - obiekt Cart (lub null)
- `total` - suma koszyka (string)
- `productNames` - mapa `productId => name`

---

## Diagram zależności

```
┌─────────────────────────────────────────────────────────────┐
│                          CART                               │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │  Controller ──► CartService ──► Repository          │   │
│  │                      │                               │   │
│  │                      │ uses QueryBusInterface for:   │   │
│  │                      │   - ProductExistsQuery        │   │
│  │                      │   - GetProductPriceQuery      │   │
│  │                      │   - GetProductNamesQuery      │   │
│  │                      │   - CheckStockAvailabilityQuery│  │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │  EventHandler (nasłuchuje Shared/Event/)            │   │
│  │  - ProductDeletedHandler (removes CartItems)        │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │  QueryHandlers (udostępnia dane)                    │   │
│  │  - GetCartQuantityHandler                           │   │
│  └─────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
```

---

## Kluczowe decyzje architektoniczne

### 1. Cena zapisywana przy dodaniu (`priceAtAdd`)

Cena produktu może się zmienić, ale w koszyku zostaje cena z momentu dodania. To chroni użytkownika przed niespodziewaną zmianą ceny podczas zakupów.

### 2. Referencja przez ID, nie relację Doctrine

`CartItem.productId` to `int`, nie `#[ORM\ManyToOne]`. Dzięki temu:
- Moduły są niezależne
- Można usunąć produkt bez naruszania integralności koszyka
- Łatwiejsza migracja do mikroserwisów w przyszłości

### 3. Query Bus dla danych między modułami

Cart pobiera wszystkie dane z innych modułów przez Query Bus:
- Query definiowane w `Shared/Query/`
- Handlery w odpowiednich modułach
- Jeden wzorzec dla całego projektu

### 4. Batch loading nazw produktów

Zamiast pobierać nazwę każdego produktu osobno (N+1 problem), `GetProductNamesQuery` pobiera wszystkie naraz w jednym zapytaniu SQL.

---

## Obsługa wyjątków

### InsufficientStockException

Dedykowany wyjątek rzucany, gdy użytkownik próbuje dodać do koszyka więcej produktów niż jest dostępnych.

```php
namespace App\Cart\Exception;

class InsufficientStockException extends \RuntimeException
{
    public function __construct(
        public readonly int $productId,
        public readonly int $requestedQuantity,
        public readonly int $availableQuantity = 0,
    ) {
        parent::__construct(sprintf(
            'Insufficient stock for product %d. Requested: %d, Available: %d',
            $productId,
            $requestedQuantity,
            $availableQuantity
        ));
    }
}
```

**Obsługa w kontrolerze:**

```php
public function add(Request $request, int $productId): Response
{
    try {
        $this->cartService->addItem($cart, $productId, $quantity);
        $this->addFlash('success', 'Produkt dodany do koszyka');
    } catch (InsufficientStockException $e) {
        $this->addFlash('error', sprintf(
            'Niewystarczająca ilość produktu na stanie. Dostępne: %d',
            $e->availableQuantity
        ));
        return $this->redirectToRoute('catalog_product_show', ['id' => $productId]);
    }

    return $this->redirectToRoute('cart_index');
}
```

**Dlaczego dedykowany wyjątek?**
- Umożliwia precyzyjną obsługę błędu w kontrolerze
- Zawiera kontekst (productId, ilości) do wyświetlenia użytkownikowi
- Oddziela logikę biznesową od prezentacji błędu

---

## Przyszłe rozszerzenia

Moduł jest przygotowany na rozszerzenia:

1. **Rezerwacja stock'u** przy dodaniu do koszyka
2. **Konwersja do zamówienia** z modułem Order
3. **Koszyk zalogowanego użytkownika** (migracja z sessionId na userId)
4. **Zapisane koszyki** - możliwość powrotu do porzuconego koszyka
5. **Kody promocyjne** - rabaty na poziomie koszyka
