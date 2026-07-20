<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Audit;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Audit\Infrastructure\SymfonyAuditLogger;
use Erpify\Shared\Http\Infrastructure\CorrelationIdListener;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock as SymfonyClockFacade;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * End-to-end lock on the per-level write wired through the real container: both `activity` and `security`
 * write their `audit_log` row synchronously in the request cycle, sealing the request context (anonymous
 * pre-auth actor, canonical correlation id, sub-second instant) as it enters the writer. The asymmetric
 * failure boundary (activity swallows, security propagates) is pinned by the unit {@see SymfonyAuditLogger}
 * test, which can inject throwing doubles cleanly.
 *
 * Work runs inside a rolled-back transaction (the suite has no DAMA and shares the dev connection).
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(SymfonyAuditLogger::class)]
final class SymfonyAuditLoggerBranchingTest extends KernelTestCase
{
    public function testActivityWritesSynchronouslyWithTheSealedRequestContext(): void
    {
        self::bootKernel();
        $auditLogger = self::getContainer()->get(AuditLogger::class);
        $this->assertInstanceOf(AuditLogger::class, $auditLogger);

        // Drive the real web path: a request in flight makes the sealed actor anonymous (pre-auth)
        // and its canonical correlation id is the one adopted onto the entry.
        $correlationId = Uuid::generate();
        $requestStack = $this->pushRequestWithCorrelationId($correlationId);

        // Freeze the shared time source so the sealed instant is known to the microsecond; the row read
        // back from Postgres must return it byte-for-byte, not truncated.
        $sealedInstant = new DateTimeImmutable('2026-03-02T10:11:12.654321+00:00');
        SymfonyClockFacade::set(new MockClock($sealedInstant));

        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $resourceId = Uuid::generate();
            $auditLogger->log('BANK_ACCOUNTS_VIEWED', AuditLevel::ACTIVITY, AuditResource::of('Bank', $resourceId));

            $row = $this->fetchAuditRowByResourceId($connection, $resourceId);
            $this->assertActivityRowSealedInRequestPath($row, $correlationId, $sealedInstant);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            $requestStack->pop();
            SymfonyClockFacade::set(new NativeClock());
        }
    }

    public function testSecurityWritesSynchronously(): void
    {
        self::bootKernel();
        $auditLogger = self::getContainer()->get(AuditLogger::class);
        $this->assertInstanceOf(AuditLogger::class, $auditLogger);

        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $resourceId = Uuid::generate();
            $auditLogger->log('ACCESS_DENIED', AuditLevel::SECURITY, AuditResource::of('Bank', $resourceId));

            $row = $this->fetchAuditRowByResourceId($connection, $resourceId);
            $this->assertSame(
                AuditLevel::SECURITY->value,
                $row['level'] ?? null,
                'security writes the audit_log row before the response is sent',
            );
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    private function pushRequestWithCorrelationId(string $correlationId): RequestStack
    {
        $requestStack = self::getContainer()->get('request_stack');
        $this->assertInstanceOf(RequestStack::class, $requestStack);
        $request = Request::create('/api/banks');
        $request->attributes->set(CorrelationIdListener::ATTRIBUTE_KEY, $correlationId);

        $requestStack->push($request);

        return $requestStack;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function assertActivityRowSealedInRequestPath(
        array $row,
        string $correlationId,
        DateTimeImmutable $sealedInstant,
    ): void {
        $this->assertSame('BANK_ACCOUNTS_VIEWED', $row['action'] ?? null);
        $this->assertSame(AuditLevel::ACTIVITY->value, $row['level'] ?? null);
        $this->assertSame('Bank', $row['resource_type'] ?? null);
        $this->assertSame(
            'anonymous',
            $row['actor_type'] ?? null,
            'on the request path the sealed actor is anonymous (pre-auth)',
        );
        $this->assertNull($row['actor_id'] ?? null, 'an anonymous actor carries no id');
        $this->assertSame($correlationId, $row['correlation_id'] ?? null, 'the request correlation id is adopted');

        $occurredOn = $row['occurred_on'] ?? null;
        $this->assertIsString($occurredOn);
        $this->assertSame(
            $sealedInstant->format('Y-m-d\TH:i:s.uP'),
            (new DateTimeImmutable($occurredOn))->format('Y-m-d\TH:i:s.uP'),
            'the sealed sub-second instant survives the round-trip to Postgres',
        );
    }

    private function connection(): Connection
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager->getConnection();
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchAuditRowByResourceId(Connection $connection, string $resourceId): array
    {
        $row = $connection->fetchAssociative(
            'SELECT action, level, actor_type, actor_id, correlation_id, resource_type, occurred_on
             FROM audit_log WHERE resource_id = :resourceId',
            ['resourceId' => $resourceId],
        );
        $this->assertIsArray($row, 'exactly one row is written synchronously for the resource');

        return $row;
    }
}
