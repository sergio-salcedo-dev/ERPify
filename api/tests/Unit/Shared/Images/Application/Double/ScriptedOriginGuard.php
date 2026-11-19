<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Application\Double;

use Erpify\Shared\Images\Application\OriginGuard;
use Erpify\Shared\Images\Domain\DerivativeOrigin;
use Override;

/**
 * Answers what the case scripts and records the unit of work each question was asked in, so a case can prove the guard
 * runs inside the transaction that inserts the row.
 */
final class ScriptedOriginGuard implements OriginGuard
{
    /** @var list<int> */
    public array $askedInUnits = [];

    /** @var list<DerivativeOrigin> */
    public array $asked = [];

    public function __construct(
        private readonly bool $admits,
        private readonly RollbackingTransactionManager $transactions,
    ) {
    }

    #[Override]
    public function admits(DerivativeOrigin $origin): bool
    {
        $this->askedInUnits[] = $this->transactions->current;
        $this->asked[] = $origin;

        return $this->admits;
    }
}
