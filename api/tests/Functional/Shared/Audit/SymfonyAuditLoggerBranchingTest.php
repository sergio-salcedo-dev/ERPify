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

        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $resourceId = Uuid::generate();
            $before = new DateTimeImmutable();
            $auditLogger->log('BANK_ACCOUNTS_VIEWED', AuditLevel::ACTIVITY, AuditResource::of('Bank', $resourceId));
            $after = new DateTimeImmutable();

            $messages = [...$transport->get()];
            $this->assertCount(1, $messages, 'activity routes to the dedicated audit transport');
            $this->assertSame(
                0,
                $this->countAuditRowsByResourceId($connection, $resourceId),
                'activity writes no audit_log row in the request cycle',
            );

            $message = \reset($messages)->getMessage();
            $this->assertInstanceOf(RecordAuditEntry::class, $message);

            $entry = $message->entry;
            $this->assertSame('BANK_ACCOUNTS_VIEWED', $entry->action);
            $this->assertSame(AuditLevel::ACTIVITY, $entry->level);
            $this->assertSame('Bank', $entry->resourceType);
            $this->assertSame($resourceId, $entry->resourceId);
            $this->assertSame(ActorType::SYSTEM, $entry->actor->type, 'off-request the sealed actor is system');
            $this->assertTrue(
                CorrelationIdListener::isCanonical($entry->correlationId),
                'a canonical fallback correlation id is minted',
            );
            $this->assertGreaterThanOrEqual($before, $entry->occurredOn);
            $this->assertLessThanOrEqual($after, $entry->occurredOn);
            $this->assertMatchesRegularExpression(
                '/\.\d{6}[+-]\d{2}:\d{2}$/',
                $entry->occurredOn->format('Y-m-d\TH:i:s.uP'),
                'sub-second precision survives the serializer round-trip',
            );
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
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
