<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Application;

use Erpify\Shared\Audit\Application\ActorAnonymisationResult;
use Erpify\Shared\Audit\Application\AuditActorAnonymiser;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Application\EraseActorAuditTrail;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Tests\Unit\Iam\Identity\Application\InlineTransactionManager;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The erasure and its evidence are one unit: both writes happen INSIDE the transaction, in that order, and the
 * evidence is never written for an `UPDATE` that matched nothing. Whether a failed self-audit really undoes the
 * rewrite is the database's, proved by the functional test; what a unit can pin is that the use case puts both
 * under the one boundary and lets a failure leave it.
 *
 * @internal
 */
#[CoversClass(EraseActorAuditTrail::class)]
final class EraseActorAuditTrailTest extends TestCase
{
    private const string ACTOR_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c';

    public const string PSEUDONYM = 'a1b2c3d4-0000-7000-8000-000000000001';

    public function testTheRewriteAndItsEvidenceBothHappenInsideTheTransactionInThatOrder(): void
    {
        $transaction = new InlineTransactionManager();
        $steps = new TransactionTrace($transaction);

        $result = (new EraseActorAuditTrail(
            $this->anonymiser($steps, affectedRows: 3),
            $this->logger($steps),
            $transaction,
        ))->erase(self::ACTOR_ID);

        $this->assertSame(['anonymise:inside', 'log:inside'], $steps->events);
        $this->assertSame(3, $result->affectedRows);
        $this->assertSame(self::PSEUDONYM, $result->pseudonym);
        $this->assertSame(
            [['action' => 'GDPR_ERASURE_EXECUTED', 'level' => AuditLevel::SECURITY, 'metadata' => [
                'anonymized_actor_id' => self::PSEUDONYM,
                'affected_rows' => 3,
            ]]],
            $steps->logged,
            'the evidence names the pseudonym and the count, never the original id',
        );
    }

    public function testAStatementThatMatchedNothingWritesNoEvidence(): void
    {
        $transaction = new InlineTransactionManager();
        $steps = new TransactionTrace($transaction);

        $result = (new EraseActorAuditTrail(
            $this->anonymiser($steps, affectedRows: 0),
            $this->logger($steps),
            $transaction,
        ))->erase(self::ACTOR_ID);

        $this->assertSame(0, $result->affectedRows);
        $this->assertSame(['anonymise:inside'], $steps->events, 'an immortal evidence row would claim an erasure');
    }

    public function testAFailedSelfAuditLeavesTheTransactionByThrowing(): void
    {
        $transaction = new InlineTransactionManager();
        $steps = new TransactionTrace($transaction);
        $useCase = new EraseActorAuditTrail(
            $this->anonymiser($steps, affectedRows: 2),
            $this->logger($steps, failing: true),
            $transaction,
        );

        try {
            $useCase->erase(self::ACTOR_ID);
            $this->fail('A failed self-audit must propagate so the transaction rolls back.');
        } catch (RuntimeException $failure) {
            $this->assertSame('the audit writer is down', $failure->getMessage());
        }

        $this->assertSame(['anonymise:inside', 'log:inside'], $steps->events);
        $this->assertFalse($transaction->committed, 'the unit of work never completed, so nothing was committed');
    }

    private function anonymiser(TransactionTrace $steps, int $affectedRows): AuditActorAnonymiser
    {
        return new class($steps, $affectedRows) implements AuditActorAnonymiser {
            public function __construct(
                private readonly TransactionTrace $steps,
                private readonly int $affectedRows,
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
                $this->steps->record('anonymise');

                return new ActorAnonymisationResult(EraseActorAuditTrailTest::PSEUDONYM, $this->affectedRows);
            }
        };
    }

    private function logger(TransactionTrace $steps, bool $failing = false): AuditLogger
    {
        return new class($steps, $failing) implements AuditLogger {
            public function __construct(
                private readonly TransactionTrace $steps,
                private readonly bool $failing,
            ) {
            }

            #[Override]
            public function log(
                string $action,
                AuditLevel $level,
                ?AuditResource $resource = null,
                array $metadata = [],
            ): void {
                $this->steps->record('log');

                if ($this->failing) {
                    throw new RuntimeException('the audit writer is down');
                }

                $this->steps->logged[] = ['action' => $action, 'level' => $level, 'metadata' => $metadata];
            }
        };
    }
}

/**
 * Records each step with whether it ran inside the unit of work, which is the one fact the ordering assertion needs.
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
