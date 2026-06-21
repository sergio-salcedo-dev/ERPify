<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Event\Application\DomainEventHandlerDeduplicator;
use Erpify\Shared\Event\Application\HandledDomainEventPruner;
use Erpify\Shared\Event\Infrastructure\Messenger\DbalDomainEventHandlerDeduplicator;
use Erpify\Shared\Event\Infrastructure\Messenger\DbalHandledDomainEventPruner;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end lock for the `handled_domain_event` claim store backing at-most-once email delivery:
 * the first `claim` of a pair wins, a concurrent re-claim is a silent no-op (`INSERT … ON CONFLICT
 * DO NOTHING`), `release` re-opens it, and the periodic pruner drops rows past the retention window.
 *
 * Each case runs inside a transaction that is always rolled back, so the suite leaves no rows behind
 * — it has no DAMA auto-rollback and shares the dev database connection.
 *
 * @internal
 */
#[CoversClass(DbalDomainEventHandlerDeduplicator::class)]
#[CoversClass(DbalHandledDomainEventPruner::class)]
final class HandledDomainEventDeduplicatorFunctionalTest extends KernelTestCase
{
    private const string HANDLER = 'test-handler';

    public function testFirstClaimWinsAndConcurrentReclaimIsANoOp(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $deduplicator = $this->deduplicator();
            $eventId = Uuid::generate();

            $this->assertTrue($deduplicator->claim($eventId, self::HANDLER), 'first claim must win');
            $this->assertFalse($deduplicator->claim($eventId, self::HANDLER), 'second claim must be a no-op');
            $this->assertSame(1, $this->countRows($connection, $eventId), 'exactly one claim row exists');
        });
    }

    public function testReleaseReopensTheClaimForARetry(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $deduplicator = $this->deduplicator();
            $eventId = Uuid::generate();

            $this->assertTrue($deduplicator->claim($eventId, self::HANDLER));
            $deduplicator->release($eventId, self::HANDLER);

            $this->assertSame(0, $this->countRows($connection, $eventId), 'release must delete the claim');
            $this->assertTrue($deduplicator->claim($eventId, self::HANDLER), 'a released claim can be re-claimed');
        });
    }

    public function testPruneDropsClaimsPastRetentionAndKeepsRecentOnes(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $staleEventId = Uuid::generate();
            $connection->executeStatement(
                'INSERT INTO handled_domain_event (event_id, handler, claimed_at) VALUES (:e, :h, :t)',
                ['e' => $staleEventId, 'h' => self::HANDLER, 't' => '2000-01-01 00:00:00'],
            );

            $freshEventId = Uuid::generate();
            $this->deduplicator()->claim($freshEventId, self::HANDLER);

            $removed = $this->pruner()->pruneClaimedBefore(new DateTimeImmutable('2020-01-01'));

            $this->assertGreaterThanOrEqual(1, $removed);
            $this->assertSame(0, $this->countRows($connection, $staleEventId), 'a stale claim must be pruned');
            $this->assertSame(1, $this->countRows($connection, $freshEventId), 'a recent claim must survive');
        });
    }

    private function inRolledBackTransaction(callable $body): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $body($connection);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    private function deduplicator(): DomainEventHandlerDeduplicator
    {
        $deduplicator = self::getContainer()->get(DomainEventHandlerDeduplicator::class);
        $this->assertInstanceOf(DomainEventHandlerDeduplicator::class, $deduplicator);

        return $deduplicator;
    }

    private function pruner(): HandledDomainEventPruner
    {
        $pruner = self::getContainer()->get(HandledDomainEventPruner::class);
        $this->assertInstanceOf(HandledDomainEventPruner::class, $pruner);

        return $pruner;
    }

    private function countRows(Connection $connection, string $eventId): int
    {
        $count = $connection->fetchOne(
            'SELECT COUNT(*) FROM handled_domain_event WHERE event_id = :eventId',
            ['eventId' => $eventId],
        );
        $this->assertIsNumeric($count);

        return (int) $count;
    }
}
