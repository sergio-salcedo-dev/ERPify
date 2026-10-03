<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\IdentityRowSerialiser;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The order is the whole contract: the subject's row is locked inside the transaction that then writes, and
 * a subject whose row is gone gets no write at all. Taken after the write, or in a transaction of its own,
 * the lock would serialise nothing against an erasure — and every recorder would still emit its row.
 *
 * @internal
 */
#[CoversClass(IdentityRowSerialiser::class)]
final class IdentityRowSerialiserTest extends TestCase
{
    public function testLocksTheSubjectInsideTheTransactionBeforeTheWriteRuns(): void
    {
        $subjectId = Uuid::generate();
        $transactions = new InlineTransactionManager();
        $rows = new InMemoryIdentityRowLock();
        $journal = [];
        $rows->onLock = static function (string $userId) use (&$journal, $transactions): void {
            $journal[] = ['lock', $userId, $transactions->inside];
        };

        $ran = (new IdentityRowSerialiser($rows, $transactions))->whileLive(
            $subjectId,
            static function () use (&$journal, $transactions): void {
                $journal[] = ['write', null, $transactions->inside];
            },
        );

        $this->assertTrue($ran);
        $this->assertSame([['lock', $subjectId, true], ['write', null, true]], $journal);
        $this->assertTrue($transactions->committed);
    }

    public function testSkipsTheWriteWhenTheSubjectsRowIsGone(): void
    {
        $subjectId = Uuid::generate();
        $rows = new InMemoryIdentityRowLock();
        $rows->gone[] = $subjectId;
        $written = false;

        $ran = (new IdentityRowSerialiser($rows, new InlineTransactionManager()))->whileLive(
            $subjectId,
            static function () use (&$written): void {
                $written = true;
            },
        );

        $this->assertFalse($ran);
        $this->assertFalse($written, 'a row naming an erased subject is what the lock exists to prevent');
        $this->assertSame([$subjectId], $rows->locked, 'the absence is learnt from the lock, not from a read');
    }

    public function testAFailingWriteLeavesTheTransactionUncommittedAndReachesTheCaller(): void
    {
        // The caller is a best-effort recorder whose catch is the only place a failure is reported; swallowing
        // it here would make the lost row silent.
        $transactions = new InlineTransactionManager();
        $failure = new RuntimeException('audit_log is unavailable');

        try {
            (new IdentityRowSerialiser(new InMemoryIdentityRowLock(), $transactions))->whileLive(
                Uuid::generate(),
                static function () use ($failure): never {
                    throw $failure;
                },
            );
            $this->fail('the write failure must reach the caller');
        } catch (RuntimeException $runtimeException) {
            $this->assertSame($failure, $runtimeException);
        }

        $this->assertFalse($transactions->committed);
    }
}
