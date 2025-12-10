# Moduł Cart

## Przegląd

Moduł **Cart** odpowiada za zarządzanie koszykiem zakupowym. Przechowuje produkty dodane przez użytkownika wraz z ilością i ceną z momentu dodania.

## Struktura

```
src/Cart/
├── Adapter/
│   └── CartQuantityAdapter.php           # Adapter dla Catalog (ilość w koszyku)
├── Controller/
│   └── CartController.php                # Operacje na koszyku
├── Entity/
│   ├── Cart.php                          # Encja koszyka
│   └── CartItem.php                      # Pozycja w koszyku
├── EventHandler/                         # Handlery eventów (Symfony Messenger)
│   └── ProductDeletedHandler.php         # Reaguje na usunięcie produktu
├── Exception/
│   └── InsufficientStockException.php    # Wyjątek braku towaru
├── Port/
│   ├── CartProductProviderInterface.php  # Interfejs do pobierania danych produktów
│   └── StockAvailabilityInterface.php    # Interfejs do sprawdzania dostępności
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
- `CartProductProviderInterface` - **port** do pobierania danych produktów (Catalog)
- `StockAvailabilityInterface` - **port** do sprawdzania dostępności (Inventory)

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

**Logika dodawania produktu:**

```php
public function addItem(Cart $cart, int $productId, int $quantity = 1): void
{
    // 1. Walidacja - czy produkt istnieje?
    if (!$this->priceProvider->productExists($productId)) {
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

    // 3. Walidacja dostępności w magazynie
    if (!$this->stockAvailability->isAvailable($productId, $totalQuantity)) {
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

    // 5. Nowa pozycja - zapisz cenę z momentu dodania
    $item = new CartItem();
    $item->setProductId($productId);
    $item->setQuantity($quantity);
    $item->setPriceAtAdd($this->priceProvider->getPrice($productId));

    $cart->addItem($item);
    $this->em->flush();
}
```

---

## Porty (interfejsy wejściowe)

### CartProductProviderInterface

Interfejs definiujący co moduł Cart potrzebuje od modułu Catalog.

```php
namespace App\Cart\Port;

interface CartProductProviderInterface
{
    public function getPrice(int $productId): string;

    public function productExists(int $productId): bool;

    public function getProductName(int $productId): string;

    /**
     * @param int[] $productIds
     * @return array<int, string> productId => name
     */
    public function getProductNames(array $productIds): array;
}
```

**Implementacja:** `Catalog\Adapter\CartProductAdapter`

### StockAvailabilityInterface

Interfejs do sprawdzania dostępności produktów w magazynie.

```php
namespace App\Cart\Port;

interface StockAvailabilityInterface
{
    /**
     * Sprawdza czy żądana ilość produktu jest dostępna
     */
    public function isAvailable(int $productId, int $quantity): bool;

    /**
     * Zwraca dostępną ilość produktu
     */
    public function getAvailableQuantity(int $productId): int;
}
```

**Implementacja:** `Inventory\Adapter\StockAvailabilityAdapter`

**Dlaczego osobny port dla dostępności?**
- **Interface Segregation** - Cart potrzebuje tylko sprawdzenia dostępności, nie pełnego API magazynu
- **Separacja odpowiedzialności** - dane produktów z Catalog, dostępność z Inventory
- **Testowalność** - łatwo mockować w testach

---

## Adapter (interfejs wyjściowy)

### CartQuantityAdapter

Adapter implementujący port `CartQuantityInterface` z modułu Catalog.

```php
namespace App\Cart\Adapter;

use App\Catalog\Port\CartQuantityInterface;

class CartQuantityAdapter implements CartQuantityInterface
{
    public function getQuantityInCart(int $productId, string $sessionId): int
    {
        // Zwraca ilość danego produktu w koszyku użytkownika
    }
}
```

**Dlaczego Cart eksportuje dane?**
Moduł Catalog wyświetla na stronie produktu ile sztuk użytkownik ma już w koszyku. Cart dostarcza tę informację przez adapter.

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

### Pobiera dane z (konsumuje)

```
Cart ──[CartProductProviderInterface]──► Catalog
     ──[StockAvailabilityInterface]───► Inventory
```

Moduł Cart używa portów do:
- **CartProductProviderInterface** (Catalog):
  - Sprawdzenia czy produkt istnieje
  - Pobrania aktualnej ceny przy dodawaniu
  - Pobrania nazw produktów do wyświetlenia
- **StockAvailabilityInterface** (Inventory):
  - Walidacji dostępności przed dodaniem do koszyka
  - Sprawdzenia ile można jeszcze dodać

### Udostępnia dane (eksportuje)

```
Catalog ──[CartQuantityInterface]──► Cart
```

Moduł Cart implementuje adapter `CartQuantityAdapter` dla Catalog, umożliwiając wyświetlenie ilości produktu w koszyku na stronie produktu.

### Reaguje na eventy (nasłuchuje)

```
Catalog ──[ProductDeletedEvent]──► Cart
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
│  │                      │ uses                          │   │
│  │                      ▼                               │   │
│  │        CartProductProviderInterface ◄─── PORT        │   │
│  │        StockAvailabilityInterface   ◄─── PORT        │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │  ProductDeletedSubscriber                             │   │
│  │  listens: ProductDeletedEvent (from Shared)           │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │  CartQuantityAdapter                                   │   │
│  │  implements: CartQuantityInterface (for Catalog)       │   │
│  └─────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
                           ▲
                           │ implements
┌──────────────────────────│──────────────────────────────────┐
│                       CATALOG                               │
│                  CartProductAdapter                         │
└─────────────────────────────────────────────────────────────┘
                           ▲
                           │ implements
┌──────────────────────────│──────────────────────────────────┐
│                      INVENTORY                              │
│                StockAvailabilityAdapter                     │
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

### 3. Batch loading nazw produktów

Zamiast pobierać nazwę każdego produktu osobno (N+1 problem), `getProductNames()` pobiera wszystkie naraz w jednym zapytaniu SQL.

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

## Query Bus (alternatywa)

Moduł Cart udostępnia również handler dla Query Bus:

### GetCartQuantityHandler

```php
namespace App\Cart\QueryHandler;

use App\Shared\Query\Cart\GetCartQuantityQuery;

class GetCartQuantityHandler
{
    public function __invoke(GetCartQuantityQuery $query): int
    {
        // Zwraca ilość produktu w koszyku użytkownika
        return $this->cartItemRepository->getQuantityForProduct(
            $query->productId,
            $query->sessionId
        );
    }
}
```

**Użycie:**
```php
$quantity = $this->queryBus->query(new GetCartQuantityQuery($productId, $sessionId));
```

Więcej o Query Bus w [docs/articles/QUERY_BUS_GUIDE.md](../articles/QUERY_BUS_GUIDE.md).
