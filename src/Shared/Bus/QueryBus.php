<?php

declare(strict_types=1);

namespace App\Shared\Bus;

use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Implementacja Query Bus oparta na Symfony Messenger.
 *
 * QueryBus to wrapper na Symfony Messenger, który:
 * 1. Wysyła query do odpowiedniego handlera
 * 2. Automatycznie wyciąga wynik z envelope
 * 3. Zapewnia spójne API dla wszystkich query
 *
 * Dlaczego wrapper zamiast bezpośredniego użycia Messenger?
 * - Prostsze API: $queryBus->query($query) zamiast dispatch + stamp extraction
 * - Możliwość dodania logiki wspólnej dla wszystkich query
 * - Łatwiejsze mockowanie w testach
 */
final class QueryBus implements QueryBusInterface
{
    public function __construct(
        private MessageBusInterface $messageBus,
    ) {}

    public function query(object $query): mixed
    {
        $envelope = $this->messageBus->dispatch($query);

        /** @var HandledStamp|null $handled */
        $handled = $envelope->last(HandledStamp::class);

        if ($handled === null) {
            throw new \RuntimeException(
                sprintf(
                    'Query "%s" nie ma zarejestrowanego handlera. ' .
                    'Upewnij się, że handler ma atrybut #[AsMessageHandler].',
                    get_class($query)
                )
            );
        }

        return $handled->getResult();
    }
}
