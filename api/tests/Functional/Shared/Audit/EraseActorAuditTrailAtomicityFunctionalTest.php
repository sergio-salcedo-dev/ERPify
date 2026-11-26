<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Audit;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Erpify\Shared\Audit\Application\AuditActorAnonymiser;
use Erpify\Shared\Audit\Application\AuditLogEntry;
use Erpify\Shared\Audit\Application\EraseActorAuditTrail;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditProvenance;
use Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditLogWriter;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\FailingAuditLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

/**
 * The erasure of an actor's trail and the entry that attests it commit together or not at all, against a real
 * database. The sibling functional test wraps its body in a transaction that is always rolled back, which
 * cannot tell a rollback from an enclosing one: here nothing encloses the use case, so the only thing that can
 * restore the original ids after a failed self-audit is the use case's own transaction.
 *
 * The rows it seeds are committed, so they are deleted again by id in `finally` — the one place a test may
 * touch `audit_log` with a `DELETE`, which the production sources are forbidden.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the price of driving the real use case against the real
 * database rather than doubling either, for the reason the sibling functional tests state
 */
#[CoversClass(EraseActorAuditTrail::class)]
final class EraseActorAuditTrailAtomicityFunctionalTest extends KernelTestCase
{
    public function testAFailedSelfAuditRestoresTheOriginalActorIdOnEveryRow(): void
    {
        self::bootKernel();
        $connection = $this->connection();
        $actorId = Uuid::generate();
        $correlationIds = [Uuid::generate(), Uuid::generate()];

        try {
            $this->seed($connection, $actorId, $correlationIds);

            $useCase = new EraseActorAuditTrail(
                $this->service(AuditActorAnonymiser::class, AuditActorAnonymiser::class),
                new FailingAuditLogger(),
                $this->service(TransactionManager::class, TransactionManager::class),
            );

            try {
                $useCase->erase($actorId);
                $this->fail('A failing self-audit must propagate.');
            } catch (Throwable) {
                // Expected: the rewrite and its evidence are one unit, and the unit failed.
            }

            $rows = $connection->fetchAllAssociative(
                'SELECT actor_id, actor_erased, ip, user_agent FROM audit_log WHERE correlation_id IN (?, ?)',
                $correlationIds,
            );

            $this->assertCount(2, $rows);

            foreach ($rows as $row) {
                $this->assertSame(
                    $actorId,
                    $row['actor_id'] ?? null,
                    'the rewrite must be rolled back with the failed audit',
                );
                $this->assertFalse((bool) ($row['actor_erased'] ?? true));
                $this->assertNotSame('[REDACTED]', $row['ip'] ?? null);
            }

            $this->assertSame(
                2,
                $this->countRows($connection, 'SELECT COUNT(*) FROM audit_log WHERE actor_id = ?', [$actorId]),
                'the original id still matches, so a repeat run has something to erase',
            );
        } finally {
            $connection->executeStatement('DELETE FROM audit_log WHERE correlation_id IN (?, ?)', $correlationIds);
        }
    }

    public function testASuccessfulRunCommitsTheRewriteAndItsEvidenceTogether(): void
    {
        self::bootKernel();
        $connection = $this->connection();
        $actorId = Uuid::generate();
        $correlationIds = [Uuid::generate(), Uuid::generate()];
        $evidenceBefore = $this->countRows(
            $connection,
            "SELECT COUNT(*) FROM audit_log WHERE action = 'GDPR_ERASURE_EXECUTED'",
        );

        try {
            $this->seed($connection, $actorId, $correlationIds);

            $result = $this->service(EraseActorAuditTrail::class, EraseActorAuditTrail::class)->erase($actorId);

            $this->assertSame(2, $result->affectedRows);
            $this->assertSame(
                0,
                $this->countRows($connection, 'SELECT COUNT(*) FROM audit_log WHERE actor_id = ?', [$actorId]),
            );
            $this->assertSame(
                2,
                $this->countRows(
                    $connection,
                    'SELECT COUNT(*) FROM audit_log WHERE actor_id = ? AND actor_erased = TRUE',
                    [$result->pseudonym],
                ),
            );
            $this->assertSame(
                $evidenceBefore + 1,
                $this->countRows($connection, "SELECT COUNT(*) FROM audit_log WHERE action = 'GDPR_ERASURE_EXECUTED'"),
                'the rewrite and the entry that attests it landed together',
            );
        } finally {
            $connection->executeStatement(
                'DELETE FROM audit_log WHERE correlation_id IN (?, ?) OR actor_id = ?',
                [...$correlationIds, $actorId],
            );
            $connection->executeStatement(
                "DELETE FROM audit_log WHERE action = 'GDPR_ERASURE_EXECUTED' AND metadata::text LIKE ?",
                ['%' . ($result->pseudonym ?? 'none') . '%'],
            );
        }
    }

    /**
     * @param list<string> $params
     */
    private function countRows(Connection $connection, string $sql, array $params = []): int
    {
        $count = $connection->fetchOne($sql, $params);
        $this->assertIsNumeric($count);

        return (int) $count;
    }

    private function connection(): Connection
    {
        return $this->service(Connection::class, Connection::class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     * @param class-string<T> $expected
     *
     * @return T
     */
    private function service(string $id, string $expected): object
    {
        $service = self::getContainer()->get($id);
        $this->assertInstanceOf($expected, $service);

        return $service;
    }

    /**
     * @param list<string> $correlationIds
     */
    private function seed(Connection $connection, string $actorId, array $correlationIds): void
    {
        $writer = new DbalAuditLogWriter($connection);

        foreach ($correlationIds as $correlationId) {
            $writer->write(AuditLogEntry::create(
                'BANK_ACCOUNTS_VIEWED',
                AuditLevel::ACTIVITY,
                new AuditProvenance(
                    ActorContext::forUser($actorId),
                    $correlationId,
                    new DateTimeImmutable('2026-06-25T00:00:00+00:00'),
                ),
            ));
        }
    }
}
