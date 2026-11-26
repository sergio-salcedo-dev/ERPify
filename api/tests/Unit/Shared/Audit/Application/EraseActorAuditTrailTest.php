<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Application;

use Erpify\Shared\Audit\Application\EraseActorAuditTrail;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Tests\Unit\Iam\Identity\Application\InlineTransactionManager;
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
        $this->assertSame(TracingAuditActorAnonymiser::PSEUDONYM, $result->pseudonym);
        $this->assertSame(
            [['action' => 'GDPR_ERASURE_EXECUTED', 'level' => AuditLevel::SECURITY, 'metadata' => [
                'anonymized_actor_id' => TracingAuditActorAnonymiser::PSEUDONYM,
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
            $this->logger($steps, new RuntimeException('the audit writer is down')),
            $transaction,
        );

        try {
            $useCase->erase(self::ACTOR_ID);
            $this->fail('A failed self-audit must propagate so the transaction rolls back.');
        } catch (RuntimeException $runtimeException) {
            $this->assertSame('the audit writer is down', $runtimeException->getMessage());
        }

        $this->assertSame(['anonymise:inside', 'log:inside'], $steps->events);
        $this->assertFalse($transaction->committed, 'the unit of work never completed, so nothing was committed');
    }

    private function anonymiser(TransactionTrace $trace, int $affectedRows): TracingAuditActorAnonymiser
    {
        return new TracingAuditActorAnonymiser($trace, $affectedRows);
    }

    private function logger(TransactionTrace $trace, ?RuntimeException $failure = null): TracingAuditLogger
    {
        return new TracingAuditLogger($trace, $failure);
    }
}
