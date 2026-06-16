<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application\Projection;

/**
 * Read-model port for the `bank_count` projection: a single running total maintained by
 * {@see BankCountProjector} and read by the count endpoint. {@see truncate()} exists for a rebuild,
 * which empties it before replaying the event store from `sequence` 0.
 */
interface BankCountReadModel
{
    public function increment(): void;

    public function decrement(): void;

    public function total(): int;

    public function truncate(): void;
}
