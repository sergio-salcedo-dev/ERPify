<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Application;

use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Tests\Unit\Iam\Identity\Application\InlineTransactionManager;

/**
 * Records each step with whether it ran inside the unit of work, which is the one fact an ordering assertion needs.
 *
 * @internal
 */
final class TransactionTrace
{
    /** @var list<string> */
    public array $events = [];

    /** @var list<array{action: string, level: AuditLevel, metadata: array<string, mixed>}> */
    public array $logged = [];

    public function __construct(private readonly InlineTransactionManager $transaction)
    {
    }

    public function record(string $step): void
    {
        $this->events[] = $step . ($this->transaction->inside ? ':inside' : ':outside');
    }
}
