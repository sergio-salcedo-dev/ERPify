<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Read;

use Erpify\Documents\Document\Application\Read\ErasureLedgerOverride;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * The adapter bound until the erasure ledger ships its flag and replaces this class: there is no ledger, so there is
 * no override to be set. Replacing it is a wiring change, not an edit of the read route.
 */
#[AsAlias(ErasureLedgerOverride::class)]
final readonly class NeverSetErasureLedgerOverride implements ErasureLedgerOverride
{
    #[Override]
    public function isActive(): bool
    {
        return false;
    }
}
