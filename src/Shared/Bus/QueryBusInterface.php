<?php

declare(strict_types=1);

namespace App\Shared\Bus;

/**
 * Interfejs Query Bus.
 *
 * Query Bus służy do wysyłania zapytań (query) do systemu.
 * Query to pytanie o dane - nie modyfikuje stanu systemu.
 *
 * Różnica między Query a Command:
 * - Query: "Jaki jest stan magazynowy produktu X?" (zwraca dane)
 * - Command: "Zmniejsz stan magazynowy produktu X o 5" (zmienia stan)
 */
interface QueryBusInterface
{
    /**
     * Wysyła query i zwraca wynik.
     *
     * @template T
     * @param object $query Obiekt query do wykonania
     * @return mixed Wynik wykonania query
     */
    public function query(object $query): mixed;
}
