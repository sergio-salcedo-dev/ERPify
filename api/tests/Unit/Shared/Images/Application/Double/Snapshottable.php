<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Application\Double;

/**
 * State a {@see RollbackingTransactionManager} puts back when the unit of work it ran threw.
 *
 * @internal
 */
interface Snapshottable
{
    public function snapshot(): mixed;

    public function restore(mixed $snapshot): void;
}
