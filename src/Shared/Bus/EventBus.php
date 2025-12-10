<?php

declare(strict_types=1);

namespace App\Shared\Bus;

use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Implementacja Event Bus oparta na Symfony Messenger.
 *
 * EventBus to wrapper na Symfony Messenger, który:
 * 1. Publikuje eventy do wszystkich zarejestrowanych handlerów
 * 2. Nie zwraca wartości (fire & forget)
 * 3. Może być łatwo skonfigurowany jako async
 *
 * Dlaczego Messenger zamiast EventDispatcher?
 * - Jeden mechanizm dla Query, Command i Event
 * - Łatwa konfiguracja async (routing w messenger.yaml)
 * - Wbudowane retry, middleware, dead letter queue
 * - Przygotowanie pod Outbox Pattern
 */
final class EventBus implements EventBusInterface
{
    public function __construct(
        private MessageBusInterface $eventBus,
    ) {}

    public function dispatch(object $event): void
    {
        $this->eventBus->dispatch($event);
    }
}
