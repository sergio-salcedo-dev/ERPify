<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Application;

use Erpify\Shared\Audit\Application\ActorAnonymisationResult;
use Erpify\Shared\Audit\Application\AuditActorAnonymiser;
use Override;

/**
 * An anonymiser that reports into a {@see TransactionTrace} when it runs and answers a fixed outcome.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.UnusedFormalParameter") the interface's `$actorId`; the answer is fixed
 */
final readonly class TracingAuditActorAnonymiser implements AuditActorAnonymiser
{
    public const string PSEUDONYM = 'a1b2c3d4-0000-7000-8000-000000000001';

    public function __construct(
        private TransactionTrace $trace,
        private int $affectedRows,
    ) {
    }

    #[Override]
    public function countFor(string $actorId): int
    {
        return $this->affectedRows;
    }

    #[Override]
    public function anonymise(string $actorId): ActorAnonymisationResult
    {
        $this->trace->record('anonymise');

        return new ActorAnonymisationResult(self::PSEUDONYM, $this->affectedRows);
    }
}
