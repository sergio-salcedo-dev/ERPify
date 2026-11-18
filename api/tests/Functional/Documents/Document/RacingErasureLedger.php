<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Documents\Document\Application\Erasure\ErasureLedger;
use Erpify\Documents\Document\Application\Erasure\ErasureOverrideSwitch;
use Erpify\Documents\Document\Domain\Erasure\LedgerOverrideReason;
use Override;

/**
 * A ledger that sets an override the first time it is LISTED, so a test can put one into the window between a clearing
 * run's snapshot of the overrides and the clearing itself. Everything else is the wrapped ledger's.
 *
 * @internal
 */
final class RacingErasureLedger implements ErasureLedger
{
    /** The `record_ref` of the override it set while being read, once it has. */
    public ?string $lateReference = null;

    public function __construct(
        private readonly ErasureLedger $inner,
        private readonly ErasureOverrideSwitch $overrides,
    ) {
    }

    #[Override]
    public function record(string $scopeId): void
    {
        $this->inner->record($scopeId);
    }

    /**
     * @return list<\Erpify\Documents\Document\Application\Erasure\LedgerEntry>
     */
    #[Override]
    public function entries(): array
    {
        $this->lateReference ??= $this->overrides->activate(LedgerOverrideReason::LedgerCorrupt);

        return $this->inner->entries();
    }

    #[Override]
    public function verifyReadableInFull(): void
    {
        $this->inner->verifyReadableInFull();
    }
}
