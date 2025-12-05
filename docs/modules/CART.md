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
├── EventSubscriber/
│   └── ProductDeletedSubscriber.php      # Reaguje na usunięcie produktu
├── Port/
│   └── CartProductProviderInterface.php  # Interfejs do pobierania danych produktów
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
- `CartProductProviderInterface` - **port** do pobierania danych produktów

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

    // 2. Sprawdź czy już jest w koszyku
    foreach ($cart->getItems() as $item) {
        if ($item->getProductId() === $productId) {
            // Zwiększ ilość
            $item->setQuantity($item->getQuantity() + $quantity);
            $this->em->flush();
            return;
        }
    }

    // 3. Nowa pozycja - zapisz cenę z momentu dodania
    $item = new CartItem();
    $item->setProductId($productId);
    $item->setQuantity($quantity);
    $item->setPriceAtAdd($this->priceProvider->getPrice($productId));

    $cart->addItem($item);
    $this->em->flush();
}
```

---

## Port (interfejs wejściowy)

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

**Implementacja:** `Catalog\Adapter\CartProductCatalogProvider`

**Dlaczego interfejs?**
- **Odwrócenie zależności** - Cart nie zależy od Catalog, tylko od abstrakcji
- **Testowalność** - łatwo mockować w testach
- **Elastyczność** - można podmienić implementację bez zmiany kodu Cart

---

## Event Subscribers

### ProductDeletedSubscriber

Reaguje na usunięcie produktu z modułu Catalog.

```php
class ProductDeletedSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            ProductDeletedEvent::class => 'onProductDeleted',
        ];
    }

    public function onProductDeleted(ProductDeletedEvent $event): void
    {
        // Usuń wszystkie pozycje koszyka z tym produktem
        $this->cartService->removeItemsByProductId($event->productId);
    }
}
```

**Dlaczego?**
Bez tego subscriber'a, po usunięciu produktu w koszykach zostałyby "osierocone" pozycje wyświetlające "Nieznany produkt".

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
```

Moduł Cart używa interfejsu `CartProductProviderInterface` do:
- Sprawdzenia czy produkt istnieje
- Pobrania aktualnej ceny przy dodawaniu
- Pobrania nazw produktów do wyświetlenia

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
│  └─────────────────────────────────────────────────────┘   │
│                          ▲                                  │
│                          │ implements                       │
│  ┌───────────────────────│──────────────────────────────┐   │
│  │  ProductDeletedSubscriber                             │   │
│  │  listens: ProductDeletedEvent (from Catalog)          │   │
│  └───────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
                           ▲
                           │ implements
┌──────────────────────────│──────────────────────────────────┐
│                       CATALOG                               │
│           CartProductCatalogProvider                        │
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
