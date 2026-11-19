<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Audit;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Erpify\Shared\Audit\Application\AuditLogEntry;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditProvenance;
use Erpify\Shared\Audit\Domain\AuditRedaction;
use Erpify\Shared\Audit\Domain\AuditRetentionPolicy;
use Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditActorAnonymiser;
use Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditLogPruner;
use Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditLogWriter;
use Erpify\Shared\Audit\Infrastructure\Persistence\DbalSubjectErasureReconciler;
use Erpify\Shared\Persistence\Infrastructure\PostgresAdvisoryLock;
use Erpify\Shared\Uuid\Domain\Uuid;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
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
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversNothing]
final class ErasureEvidenceRetentionFunctionalTest extends KernelTestCase
{
    private const string ANCHOR = '2026-06-25T00:00:00+00:00';

    private const string IP = '192.0.2.10';

    private const string USER_AGENT = 'seeded-agent/1.0';

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
                . "destroyed_at, destroy_reason) VALUES (:scope, NULL, 1, NOW(), NOW(), 'erasure')",
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
            new AuditProvenance(
                ActorContext::system(),
                Uuid::generate(),
                $occurredOn,
            ),
            null,
            $metadata,
        );
        $writer->write($entry);

        return $entry->id;
    }

    /**
     * Pins the exemption to the **inner** select. Moved onto the outer `DELETE` the batch would still be
     * filled by exempt ids, fewer rows would delete, `$deleted !== $batchSize` would end the loop, and rows
     * genuinely past retention would survive — silently, with no error anywhere.
     *
     * A batch of one and an evidence row older than the control is what makes that deterministic: `ORDER BY
     * id` alone would not, since ids are UUIDv7 and order by mint time rather than by `occurred_on`, so the
     * two are seeded in the order the batch must visit them. Without the ordering in production this
     * mutation is only probabilistically observable, which is why the ordering is not merely a lock-order
     * concern.
     *
     * The batch of one is also why the backlog is cleared first: the drain loop stops only on a short batch,
     * so at `batchSize = 1` it issues one `DELETE` per eligible row at all three levels — making the runtime
     * a function of whatever the shared test database happens to hold rather than of what this case seeds.
     * One statement bounds it, and the cutoff is the least restrictive of the three windows, so it is a
     * superset of everything the sweep could take.
     */
    public function testItDrainsThePrunableBacklogEvenWhenAnExemptRowIsFirstInTheBatch(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $anchor = new DateTimeImmutable(self::ANCHOR);
            $writer = new DbalAuditLogWriter($connection);

            $policy = new AuditRetentionPolicy(90, 365);
            $connection->executeStatement(
                'DELETE FROM audit_log WHERE occurred_on < :cutoff',
                ['cutoff' => $this->leastRestrictiveThreshold($policy, $anchor)],
            );

            $evidence = $this->seedSecurityRow($writer, 'GDPR_SUBJECT_ERASED', $this->daysBefore($anchor, 500));
            $control = $this->seedSecurityRow($writer, 'BANK_ACCOUNTS_VIEWED', $this->daysBefore($anchor, 400));

            $pruner = new DbalAuditLogPruner(
                $connection,
                new PostgresAdvisoryLock($connection),
                new NullLogger(),
                1,
            );
            $pruner->prune(...$policy->thresholdsAt($anchor));

            $this->assertSame(
                0,
                $this->countRowsForId($connection, $control),
                'an exempt row at the head of the batch must not stop the sweep draining what is prunable',
            );
            $this->assertSame(1, $this->countRowsForId($connection, $evidence), 'and the evidence still survives');
        });
    }

    /**
     * What the exemption keeps is the row and its `actor_id`; what it no longer keeps is the acting party's
     * `ip` and `user_agent` once the row is past the threshold of ITS OWN level — the cut-off the `DELETE` of
     * that level uses, never a fixed `security` window. Every other row is seeded beside one that is cleared, so
     * each term of the predicate has a row that goes red without it: the level threshold (an `activity`
     * evidence row cleared at 200 days next to a `security` one kept), the actor term (a row the actor-axis
     * erasure already redacted keeps that pass's sentinel), and the `ip`/`user_agent` term (a row with neither
     * is not counted as cleared). The count is exact because the backlog is cleared first, so every row past a
     * threshold is one this case seeded.
     */
    public function testItClearsTheRequestMetadataOfEvidencePastItsOwnLevelsThresholdAndNothingElse(): void
    {
        $this->inRolledBackTransaction(function (Connection $connection): void {
            $anchor = new DateTimeImmutable(self::ANCHOR);
            $policy = new AuditRetentionPolicy(90, 365);
            $connection->executeStatement(
                'DELETE FROM audit_log WHERE occurred_on < :cutoff',
                ['cutoff' => $this->leastRestrictiveThreshold($policy, $anchor)],
            );
            $rows = $this->seedClearCandidates($connection, $anchor);

            $log = new TestHandler();
            (new DbalAuditLogPruner($connection, new PostgresAdvisoryLock($connection), new Logger('audit', [$log])))
                ->prune(...$policy->thresholdsAt($anchor))
            ;

            foreach (['pastSecurity', 'userAgentOnly', 'pastActivity'] as $cleared) {
                $this->assertSame(
                    [null, null],
                    $this->requestMetadataOf($connection, $rows[$cleared]['id']),
                    \sprintf('%s: evidence past the threshold of its own level keeps no request metadata', $cleared),
                );
            }

            $this->assertSame(
                $rows['pastSecurity']['actor'],
                $connection->fetchOne(
                    'SELECT actor_id FROM audit_log WHERE id = :id',
                    ['id' => $rows['pastSecurity']['id']],
                ),
                'the attribution is what the evidence is for, so the actor id stays',
            );
            $this->assertSame(
                [self::IP, self::USER_AGENT],
                $this->requestMetadataOf($connection, $rows['withinSecurity']['id']),
                'a security evidence row is judged by the security threshold, not by the shortest window',
            );
            $this->assertSame(
                [self::IP, self::USER_AGENT],
                $this->requestMetadataOf($connection, $rows['ordinaryWithin']['id']),
                'a row inside its window is not touched',
            );
            $this->assertSame(
                [AuditRedaction::SENTINEL, AuditRedaction::SENTINEL],
                $this->requestMetadataOf($connection, $rows['actorAxisResolved']['id']),
                "a row the actor-axis erasure resolved keeps that pass's sentinel",
            );
            $this->assertSame([null, null], $this->requestMetadataOf($connection, $rows['neverCaptured']['id']));
            $this->assertTrue(
                $log->hasInfoThatPasses(
                    static fn (LogRecord $record): bool => ['removed' => 0, 'cleared' => 3] === $record->context,
                ),
                'exactly the three rows holding metadata past their threshold are cleared, and nothing is deleted',
            );
        });
    }

    /**
     * One row per term of the clear's predicate, each named for what it must show.
     *
     * @return array{
     *     pastSecurity: array{id: string, actor: string},
     *     userAgentOnly: array{id: string, actor: string},
     *     pastActivity: array{id: string, actor: string},
     *     withinSecurity: array{id: string, actor: string},
     *     ordinaryWithin: array{id: string, actor: string},
     *     actorAxisResolved: array{id: string, actor: string},
     *     neverCaptured: array{id: string, actor: null},
     * }
     */
    private function seedClearCandidates(Connection $connection, DateTimeImmutable $anchor): array
    {
        $writer = new DbalAuditLogWriter($connection);
        /** @return array{id: string, actor: string} */
        $seed = function (
            string $action,
            AuditLevel $level,
            int $age,
            ?string $ip = self::IP,
        ) use (
            $writer,
            $anchor,
        ): array {
            $actor = Uuid::generate();

            return [
                'id' => $this->seedCaptured($writer, $action, $level, $this->daysBefore($anchor, $age), $actor, $ip),
                'actor' => $actor,
            ];
        };

        $actorAxisResolved = $seed('GDPR_SUBJECT_ERASED', AuditLevel::SECURITY, 400);
        (new DbalAuditActorAnonymiser($connection))->anonymise($actorAxisResolved['actor']);

        return [
            'pastSecurity' => $seed('GDPR_SUBJECT_ERASED', AuditLevel::SECURITY, 400),
            'userAgentOnly' => $seed('GDPR_ERASURE_EXECUTED', AuditLevel::SECURITY, 400, null),
            'pastActivity' => $seed('GDPR_SUBJECT_ERASED', AuditLevel::ACTIVITY, 200),
            'withinSecurity' => $seed('GDPR_SUBJECT_ERASED', AuditLevel::SECURITY, 200),
            'ordinaryWithin' => $seed('BANK_ACCOUNTS_VIEWED', AuditLevel::SECURITY, 200),
            'actorAxisResolved' => $actorAxisResolved,
            'neverCaptured' => [
                'id' => $this->seedSecurityRow($writer, 'GDPR_SUBJECT_ERASED', $this->daysBefore($anchor, 400)),
                'actor' => null,
            ],
        ];
    }

    private function seedCaptured(
        DbalAuditLogWriter $writer,
        string $action,
        AuditLevel $level,
        DateTimeImmutable $occurredOn,
        string $actorId,
        ?string $ip = self::IP,
    ): string {
        $entry = AuditLogEntry::create(
            $action,
            $level,
            new AuditProvenance(
                ActorContext::forUser($actorId),
                Uuid::generate(),
                $occurredOn,
                $ip,
                self::USER_AGENT,
            ),
        );
        $writer->write($entry);

        return $entry->id;
    }

    /**
     * @return array{mixed, mixed}
     */
    private function requestMetadataOf(Connection $connection, string $id): array
    {
        $row = $connection->fetchAssociative('SELECT ip, user_agent FROM audit_log WHERE id = :id', ['id' => $id]);
        $this->assertIsArray($row, 'the row is still there');
        $this->assertArrayHasKey('ip', $row);
        $this->assertArrayHasKey('user_agent', $row);

        return [$row['ip'], $row['user_agent']];
    }

    private function daysBefore(DateTimeImmutable $anchor, int $days): DateTimeImmutable
    {
        return $anchor->modify('-' . $days . ' days');
    }

    /**
     * The latest of the plan's per-level cut-offs, so the bound is a superset of everything the sweep could
     * take whatever the windows are. Derived rather than written as a literal: a duplicated `90` would let a
     * change to the policy quietly return the backlog — and the O(backlog) round-trips with it — while every
     * assertion here stayed green.
     */
    private function leastRestrictiveThreshold(AuditRetentionPolicy $policy, DateTimeImmutable $anchor): string
    {
        $latest = null;

        foreach ($policy->thresholdsAt($anchor) as $auditRetentionThreshold) {
            if (null === $latest || $auditRetentionThreshold->deleteBefore > $latest) {
                $latest = $auditRetentionThreshold->deleteBefore;
            }
        }

        $this->assertInstanceOf(
            DateTimeImmutable::class,
            $latest,
            'the policy produced no threshold, so this bound covers nothing',
        );

        return $latest->format(DateTimeImmutable::ATOM);
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
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    private function countRowsForId(Connection $connection, string $id): int
    {
        $count = $connection->fetchOne('SELECT COUNT(*) FROM audit_log WHERE id = :id', ['id' => $id]);
        $this->assertIsNumeric($count);

        return (int) $count;
    }
}
