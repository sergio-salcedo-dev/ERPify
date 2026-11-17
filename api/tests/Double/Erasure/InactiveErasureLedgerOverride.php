<?php

declare(strict_types=1);

namespace Erpify\Tests\Double\Erasure;

use Erpify\Documents\Document\Application\Read\ErasureLedgerOverride;
use Override;

/**
 * The override a controller test hands the read routes when it is not the subject: never set. The database-backed
 * adapter has its own functional test.
 *
 * @internal
 */
final readonly class InactiveErasureLedgerOverride implements ErasureLedgerOverride
{
    #[Override]
    public function isActive(): bool
    {
        return false;
    }
}
