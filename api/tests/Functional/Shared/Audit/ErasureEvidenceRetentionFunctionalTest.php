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

            $evidence = AuditLogEntry::create(
                'GDPR_SUBJECT_ERASED',
                AuditLevel::SECURITY,
                ActorContext::system(),
                Uuid::generate(),
                $this->daysBefore($anchor, 400),
                null,
                ['encryption_scope_id' => $scope],
            );
            $writer->write($evidence);

            $control = AuditLogEntry::create(
                'BANK_ACCOUNTS_VIEWED',
                AuditLevel::SECURITY,
                ActorContext::anonymous(),
                Uuid::generate(),
                $this->daysBefore($anchor, 400),
            );
            $writer->write($control);

            $pruner = new DbalAuditLogPruner($connection, new PostgresAdvisoryLock($connection), new NullLogger());
            $pruner->prune(...(new AuditRetentionPolicy(90, 365))->thresholdsAt($anchor));

            $this->assertSame(
                0,
                $this->countRowsForId($connection, $control->id),
                'the sweep did run at security and at this age — without this the assertions below are vacuous',
            );
            $this->assertSame(
                1,
                $this->countRowsForId($connection, $evidence->id),
                'the proof that an erasure was honoured outlives the tombstone it answers for',
            );
            $this->assertNotContains(
                $scope,
                (new DbalSubjectErasureReconciler($connection))->unreconciledScopes(),
                'a correctly-executed erasure never becomes a reported divergence through ageing alone',
            );
        });
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
