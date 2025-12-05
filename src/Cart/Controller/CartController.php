<?php

namespace App\Cart\Controller;

use App\Cart\Service\CartService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/cart', name: 'cart_')]
class CartController extends AbstractController
{
    public function __construct(
        private CartService $cartService,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $cartId = $request->getSession()->get('cart_id');
        $cart = $cartId ? $this->cartService->findCart($cartId) : null;

        $productNames = [];
        if ($cart) {
            $productNames = $this->cartService->getProductNames($cart);
        }

        return $this->render('cart/index.html.twig', [
            'cart' => $cart,
            'productNames' => $productNames,
            'total' => $cart ? $this->cartService->getTotal($cart) : '0.00',
        ]);
    }

    #[Route('/add/{productId}', name: 'add', methods: ['POST'])]
    public function add(Request $request, int $productId): Response
    {
        $session = $request->getSession();
        $cartId = $session->get('cart_id');

        if ($cartId) {
            $cart = $this->cartService->findCart($cartId);
        }

        if (!isset($cart) || !$cart) {
            $cartId = bin2hex(random_bytes(16));
            $cart = $this->cartService->createCart($cartId);
            $session->set('cart_id', $cartId);
        }

        $quantity = (int) $request->request->get('quantity', 1);
        $this->cartService->addItem($cart, $productId, $quantity);

        $this->addFlash('success', 'Dodano do koszyka');

        return $this->redirectToRoute('cart_index');
    }

    #[Route('/remove/{productId}', name: 'remove', methods: ['POST'])]
    public function remove(Request $request, int $productId): Response
    {
        $cartId = $request->getSession()->get('cart_id');
        $cart = $cartId ? $this->cartService->findCart($cartId) : null;

        if ($cart) {
            $this->cartService->removeItem($cart, $productId);
            $this->addFlash('success', 'Usunięto z koszyka');
        }

        return $this->redirectToRoute('cart_index');
    }

    #[Route('/clear', name: 'clear', methods: ['POST'])]
    public function clear(Request $request): Response
    {
        $cartId = $request->getSession()->get('cart_id');
        $cart = $cartId ? $this->cartService->findCart($cartId) : null;

        if ($cart) {
            $this->cartService->clear($cart);
            $this->addFlash('success', 'Koszyk wyczyszczony');
        }

        return $this->redirectToRoute('cart_index');
    }
}
