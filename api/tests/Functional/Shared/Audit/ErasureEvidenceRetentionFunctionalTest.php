<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Audit;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Erpify\Shared\Audit\Application\AuditLogEntry;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditRetentionPolicy;
use Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditLogPruner;
use Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditLogWriter;
use Erpify\Shared\Audit\Infrastructure\Persistence\DbalSubjectErasureReconciler;
use Erpify\Shared\Persistence\Infrastructure\PostgresAdvisoryLock;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The invariant no single class owns: **the evidence of an erasure must outlive the thing it attests.**.
 *
 * It spans three collaborators that are each correct alone. `DbalKeystore::destroy()` tombstones the DEK row
 * and keeps it for ever, on purpose, "so a shredding stays reconcilable". `DbalSubjectErasureReconciler`
 * reports every destroyed key that no `GDPR_SUBJECT_ERASED` entry accounts for, with no date bound. And
 * `DbalAuditLogPruner` deletes `security` rows past their ceiling with no predicate on `action`. Compose the
 * three and the left side of the anti-join is eternal while the right side is not.
 *
 * Its own file rather than a case in the pruner's: it fails for a different reason than every test there —
 * not "the sweep deleted the wrong rows" but "two shipped controls disagree by construction" — and proving
 * it needs the keystore and the reconciler, which the pruner's suite has no business importing.
 *
 * Each case runs inside a transaction that is always rolled back, so nothing escapes the shared dev database.
 *
 * @internal
 */
#[CoversNothing]
final class ErasureEvidenceRetentionFunctionalTest extends KernelTestCase
{
    private const string ANCHOR = '2026-06-25T00:00:00+00:00';

    /**
     * Asserted behaviourally rather than by row count, because the row surviving is not the point: the point
     * is that the detective control stays quiet. The control row is what stops this passing vacuously — it
     * proves the sweep really ran at `security` and did delete something of that age.
     */
    public function testItNeverPrunesErasureEvidenceAndKeepsTheReconcilerQuiet(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $anchor = new DateTimeImmutable(self::ANCHOR);
            $writer = new DbalAuditLogWriter($connection);
            $scope = 'BankAccount:' . Uuid::generate();
            // The tombstone is context, not the subject: one INSERT in the shape `destroy()` leaves behind
            // — the key gone, the row kept. What is under test is the pair of controls that read it.
            $connection->executeStatement(
                'INSERT INTO dek_keystore (encryption_scope_id, wrapped_dek, kek_version, created_at, '
                . 'destroyed_at) VALUES (:scope, NULL, 1, NOW(), NOW())',
                ['scope' => $scope],
            );

            $evidence = $this->seedSecurityRow(
                $writer,
                'GDPR_SUBJECT_ERASED',
                $this->daysBefore($anchor, 400),
                ['encryption_scope_id' => $scope],
            );
            // The OTHER evidence action, and the one with no detective control at all: nothing in `api/src`
            // reads `GDPR_ERASURE_EXECUTED`, so where the subject-axis proof at least surfaces as a false
            // divergence when it ages out, this one simply disappears and nothing notices. Seeded because an
            // exemption covering only the action the reconciler happens to name would leave it dying
            // silently — and every assertion below would still pass.
            $actorAxisEvidence = $this->seedSecurityRow(
                $writer,
                'GDPR_ERASURE_EXECUTED',
                $this->daysBefore($anchor, 400),
                ['anonymized_actor_id' => Uuid::generate()],
            );
            $control = $this->seedSecurityRow($writer, 'BANK_ACCOUNTS_VIEWED', $this->daysBefore($anchor, 400));

            $pruner = new DbalAuditLogPruner($connection, new PostgresAdvisoryLock($connection), new NullLogger());
            $pruner->prune(...(new AuditRetentionPolicy(90, 365))->thresholdsAt($anchor));

            $this->assertSame(
                0,
                $this->countRowsForId($connection, $control),
                'the sweep did run at security and at this age — without this the assertions below are vacuous',
            );
            $this->assertSame(
                1,
                $this->countRowsForId($connection, $evidence),
                'the proof that an erasure was honoured outlives the tombstone it answers for',
            );
            $this->assertSame(
                1,
                $this->countRowsForId($connection, $actorAxisEvidence),
                'and so does the actor-axis proof, which no detective control would miss for us',
            );
            $this->assertNotContains(
                $scope,
                (new DbalSubjectErasureReconciler($connection))->unreconciledScopes(),
                'a correctly-executed erasure never becomes a reported divergence through ageing alone',
            );
        });
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function seedSecurityRow(
        DbalAuditLogWriter $writer,
        string $action,
        DateTimeImmutable $occurredOn,
        array $metadata = [],
    ): string {
        $entry = AuditLogEntry::create(
            $action,
            AuditLevel::SECURITY,
            ActorContext::system(),
            Uuid::generate(),
            $occurredOn,
            null,
            $metadata,
        );
        $writer->write($entry);

        return $entry->id;
    }

    private function daysBefore(DateTimeImmutable $anchor, int $days): DateTimeImmutable
    {
        return $anchor->modify('-' . $days . ' days');
    }

    /**
     * @param callable(Connection): void $body
     */
    private function inRolledBackTransaction(callable $body): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->assertInstanceOf(Connection::class, $connection);
        $connection->beginTransaction();

        try {
            $body($connection);
        } finally {
            $connection->rollBack();
        }
    }

    private function countRowsForId(Connection $connection, string $id): int
    {
        $count = $connection->fetchOne('SELECT COUNT(*) FROM audit_log WHERE id = :id', ['id' => $id]);
        $this->assertIsNumeric($count);

        return (int) $count;
    }
}
