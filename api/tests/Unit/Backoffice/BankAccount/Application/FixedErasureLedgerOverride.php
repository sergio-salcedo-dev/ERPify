<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application;

use Erpify\Documents\Document\Application\Read\ErasureLedgerOverride;
use Override;

/**
 * An erasure-ledger override the test sets once, on or off.
 *
 * @internal
 */
final readonly class FixedErasureLedgerOverride implements ErasureLedgerOverride
{
    public function __construct(private bool $active)
    {
    }

    #[Override]
    public function isActive(): bool
    {
        return $this->active;
    }
}
