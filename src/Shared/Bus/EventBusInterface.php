<?php

declare(strict_types=1);

namespace App\Shared\Bus;

/**
 * Interfejs Event Bus do publikowania zdarzeń domenowych.
 *
 * Event Bus służy do powiadamiania innych modułów o zmianach stanu.
 * W przeciwieństwie do Query Bus:
 * - NIE zwraca wartości (fire & forget)
 * - Może mieć WIELU subskrybentów
 * - Może być asynchroniczny
 *
 * Przykład użycia:
 *     $this->eventBus->dispatch(new ProductCreatedEvent($productId, $name));
 */
interface EventBusInterface
{
    /**
     * Publikuje event do wszystkich zainteresowanych subskrybentów.
     *
     * @param object $event Event domenowy do opublikowania
     */
    public function dispatch(object $event): void;
}
