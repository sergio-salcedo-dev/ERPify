<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Persistence;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\ErrorContract\Application\ProblemDetailsFactory;
use Erpify\Shared\Persistence\Domain\Exception\ReferentialIntegrityViolation;
use Erpify\Shared\Persistence\Domain\Exception\TransientTransactionFailure;
use Erpify\Shared\Persistence\Infrastructure\DoctrineTransactionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @internal
 */
#[CoversClass(DoctrineTransactionManager::class)]
#[CoversClass(ReferentialIntegrityViolation::class)]
final class DoctrineTransactionManagerTest extends TestCase
{
    use TransactionManagerDoubles;

    private const string INSTANCE = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c';

    private const string CORRELATION_ID = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2d';

    #[Test]
    public function itRunsTheOperationInsideWrapInTransactionAndReturnsItsResult(): void
    {
        // A runtime-computed value (not a literal) so the round-trip assertion is a real check, not one
        // PHPStan narrows to an always-true literal comparison.
        $expected = \bin2hex(\random_bytes(8));
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects($this->once())
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $operation): mixed => $operation())
        ;

        $result = $this->transactionManager($entityManager)->transactional(static fn (): string => $expected);

        $this->assertSame($expected, $result);
    }

    /**
     * A deadlock (`40P01`) and a serialization failure (`40001`) both arrive as `DeadlockException`, and
     * untranslated they leave the RFC 9457 pipeline as a bare 500 `unhandled-exception` — which tells a
     * client the server is broken when retrying the identical request is expected to work.
     */
    #[Test]
    public function itTranslatesARetryableDatabaseFailureIntoTheServiceUnavailableMarker(): void
    {
        $deadlock = $this->deadlockException();
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager
            ->method('wrapInTransaction')
            ->willThrowException($deadlock)
        ;

        try {
            $this->transactionManager($entityManager)->transactional(static fn (): int => 1);
            $this->fail('A retryable database failure escaped the transaction seam untranslated.');
        } catch (TransientTransactionFailure $transientTransactionFailure) {
            // The wire answer, not the marker interface: `implements ServiceUnavailable` is compile-time,
            // so asserting it proves nothing a reader could not see. What matters is the status a caller
            // receives — 500 "the server is broken" is what this exists to stop being the answer.
            $problemDetails = (new ProblemDetailsFactory('prod', new NullLogger()))
                ->fromThrowable($transientTransactionFailure, self::CORRELATION_ID, self::INSTANCE)
            ;

            $this->assertSame(503, $problemDetails->status);
            $this->assertSame('transient-transaction-failure', $problemDetails->type);
            // The driver exception has to survive as `previous`, or the log line and the dev debug chain
            // lose the SQLSTATE and an operator cannot tell this from any other 503.
            $this->assertSame($deadlock, $transientTransactionFailure->getPrevious());
        }
    }

    /**
     * The catch must not swallow ordinary failures: a rolled-back business error is not retryable, and
     * relabelling it 503 would tell the caller to repeat a request that will never succeed.
     */
    #[Test]
    public function itLetsANonRetryableFailureThrough(): void
    {
        $failure = new RuntimeException('bank not found');
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager
            ->method('wrapInTransaction')
            ->willThrowException($failure)
        ;

        $this->expectExceptionObject($failure);

        $this->transactionManager($entityManager)->transactional(static fn (): int => 1);
    }

    /**
     * The mirror of the retryable case, and its opposite for the caller: a foreign key rejected at flush is
     * not something a retry resolves, so it becomes a 409 the client can act on rather than the bare 500 an
     * untranslated DBAL exception produces.
     */
    #[Test]
    public function itTranslatesAForeignKeyViolationIntoTheConflictMarker(): void
    {
        $violation = $this->foreignKeyViolation();
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager
            ->method('wrapInTransaction')
            ->willThrowException($violation)
        ;

        try {
            $this->transactionManager($entityManager)->transactional(static fn (): int => 1);
            $this->fail('A foreign key violation escaped the transaction seam untranslated.');
        } catch (ReferentialIntegrityViolation $referentialIntegrityViolation) {
            $problemDetails = (new ProblemDetailsFactory('prod', new NullLogger()))
                ->fromThrowable($referentialIntegrityViolation, self::CORRELATION_ID, self::INSTANCE)
            ;

            $this->assertSame(409, $problemDetails->status);
            $this->assertSame('referential-integrity-violation', $problemDetails->type);
            $this->assertSame($violation, $referentialIntegrityViolation->getPrevious());
        }
    }
}
