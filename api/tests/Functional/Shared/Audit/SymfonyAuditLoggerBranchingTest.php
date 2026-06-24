<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Audit;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Application\RecordAuditEntry;
use Erpify\Shared\Audit\Domain\ActorType;
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
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * End-to-end lock on the per-level branching wired through the real container: `activity` routes to the
 * dedicated `audit` transport and writes no `audit_log` row in the request cycle, while `security` writes
 * synchronously (write-before-send) and enqueues nothing. The asymmetric failure boundary (activity
 * swallows, security propagates) is pinned by the unit {@see SymfonyAuditLogger} test, which can inject
 * throwing doubles cleanly; here the real serializer round-trip (serialize=true) proves the sealed
 * context survives transport.
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
    public function testActivityRoutesToTheAuditTransportAndWritesNoRowSynchronously(): void
    {
        self::bootKernel();
        $auditLogger = self::getContainer()->get(AuditLogger::class);
        $this->assertInstanceOf(AuditLogger::class, $auditLogger);

        $transport = self::getContainer()->get('messenger.transport.audit');
        $this->assertInstanceOf(InMemoryTransport::class, $transport);

        // Drive the real web path: a request in flight makes the sealed actor anonymous (pre-auth)
        // and its canonical correlation id is the one adopted onto the entry.
        $correlationId = Uuid::generate();
        $requestStack = $this->pushRequestWithCorrelationId($correlationId);

        // Freeze the shared time source so the sealed instant is known to the microsecond; the
        // serializer round-trip (serialize=true) must return it byte-for-byte, not truncated.
        $sealedInstant = new DateTimeImmutable('2026-03-02T10:11:12.654321+00:00');
        SymfonyClockFacade::set(new MockClock($sealedInstant));

        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $resourceId = Uuid::generate();
            $auditLogger->log('BANK_ACCOUNTS_VIEWED', AuditLevel::ACTIVITY, AuditResource::of('Bank', $resourceId));

            $messages = [...$transport->get()];
            $this->assertCount(1, $messages, 'activity routes to the dedicated audit transport');
            $this->assertSame(
                0,
                $this->countAuditRowsByResourceId($connection, $resourceId),
                'activity writes no audit_log row in the request cycle',
            );

            $message = \reset($messages)->getMessage();
            $this->assertInstanceOf(RecordAuditEntry::class, $message);
            $this->assertActivityEntrySealedInRequestPath($message, $resourceId, $correlationId, $sealedInstant);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            $requestStack->pop();
            SymfonyClockFacade::set(new NativeClock());
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

    private function assertActivityEntrySealedInRequestPath(
        RecordAuditEntry $message,
        string $resourceId,
        string $correlationId,
        DateTimeImmutable $sealedInstant,
    ): void {
        $entry = $message->entry;
        $this->assertSame('BANK_ACCOUNTS_VIEWED', $entry->action);
        $this->assertSame(AuditLevel::ACTIVITY, $entry->level);
        $this->assertSame('Bank', $entry->resourceType);
        $this->assertSame($resourceId, $entry->resourceId);
        $this->assertSame(
            ActorType::ANONYMOUS,
            $entry->actor->type,
            'on the request path the sealed actor is anonymous (pre-auth)',
        );
        $this->assertSame($correlationId, $entry->correlationId, 'the request correlation id is adopted');
        $this->assertSame(
            $sealedInstant->format('Y-m-d\TH:i:s.uP'),
            $entry->occurredOn->format('Y-m-d\TH:i:s.uP'),
            'the sealed sub-second instant survives the serializer round-trip',
        );
    }

    public function testSecurityWritesSynchronouslyAndEnqueuesNothing(): void
    {
        self::bootKernel();
        $auditLogger = self::getContainer()->get(AuditLogger::class);
        $this->assertInstanceOf(AuditLogger::class, $auditLogger);

        $transport = self::getContainer()->get('messenger.transport.audit');
        $this->assertInstanceOf(InMemoryTransport::class, $transport);

        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $resourceId = Uuid::generate();
            $auditLogger->log('ACCESS_DENIED', AuditLevel::SECURITY, AuditResource::of('Bank', $resourceId));

            $this->assertSame(
                1,
                $this->countAuditRowsByResourceId($connection, $resourceId),
                'security writes the audit_log row before the response is sent',
            );
            $this->assertCount(0, [...$transport->get()], 'security does not enqueue');
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    private function connection(): Connection
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager->getConnection();
    }

    private function countAuditRowsByResourceId(Connection $connection, string $resourceId): int
    {
        $rowCount = $connection->fetchOne(
            'SELECT COUNT(*) FROM audit_log WHERE resource_id = :resourceId',
            ['resourceId' => $resourceId],
        );
        $this->assertIsNumeric($rowCount);

        return (int) $rowCount;
    }
}
