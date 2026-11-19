<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Audit;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Erpify\Shared\Audit\Application\AuditLogEntry;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditProvenance;
use Erpify\Shared\Audit\Domain\AuditRedaction;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Audit\Domain\AuditRetentionPolicy;
use Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditActorAnonymiser;
use Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditLogPruner;
use Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditLogWriter;
use Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditResourceAnonymiser;
use Erpify\Shared\Persistence\Infrastructure\PostgresAdvisoryLock;
use Erpify\Shared\Uuid\Domain\Uuid;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What an exempt row keeps once retention reaches it: the prune never deletes the trail's erasure evidence,
 * and clears the request metadata those rows carry once each is past its own level's threshold.
 *
 * Its own file rather than a case in `ErasureEvidenceRetentionFunctionalTest`, which pins that the exempt row
 * SURVIVES; this pins what it KEEPS, needs both erasure passes to build its rows, and fails for a different
 * reason. Each case runs inside a transaction that is always rolled back, so nothing escapes the shared test
 * database.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(DbalAuditLogPruner::class)]
final class ExemptRowRequestMetadataFunctionalTest extends KernelTestCase
{
    private const string ANCHOR = '2026-06-25T00:00:00+00:00';

    private const string IP = '192.0.2.10';

    private const string USER_AGENT = 'seeded-agent/1.0';

    /**
     * What the exemption keeps is the row and its `actor_id`; what it does not keep is the acting party's `ip`
     * and `user_agent` once the row is past the threshold of ITS OWN level — the cut-off the `DELETE` of that
     * level uses, never a fixed `security` window. Every other row is seeded beside one that is cleared, so each
     * term of the predicate but one has a row that goes red without it: the level threshold (an `activity`
     * evidence row cleared at 200 days next to a `security` one kept), the strict `<` (an evidence row and an
     * ordinary one exactly AT the threshold, both untouched), the two redaction terms (a row each erasure pass
     * already redacted keeps that pass's sentinel), and each half of the `ip`/`user_agent` term (a row holding
     * only one of them is cleared, a row with neither is not counted). The exception is the `action` term,
     * which the order of the two statements makes redundant; `AuditPruneStatementGateTest` reads it instead.
     * The count is exact because the backlog is cleared first, so every row past a threshold is one this case
     * seeded.
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

            foreach (['pastSecurity', 'userAgentOnly', 'ipOnly', 'pastActivity'] as $cleared) {
                $this->assertSame(
                    [null, null],
                    $this->requestMetadataOf($connection, $rows[$cleared]),
                    \sprintf('%s: evidence past the threshold of its own level keeps no request metadata', $cleared),
                );
            }

            $kept = [
                'withinSecurity' => 'a security evidence row is judged by the security threshold, not the shortest',
                'evidenceAtThreshold' => 'an evidence row exactly at its threshold is not yet past it',
                'ordinaryAtThreshold' => 'an ordinary row exactly at its threshold is not deleted either',
                'ordinaryWithin' => 'a row inside its window is not touched',
            ];

            foreach ($kept as $name => $why) {
                $this->assertSame(
                    [self::IP, self::USER_AGENT],
                    $this->requestMetadataOf($connection, $rows[$name]),
                    $why,
                );
            }

            foreach (['actorAxisResolved', 'resourceAxisResolved'] as $resolved) {
                $this->assertSame(
                    [AuditRedaction::SENTINEL, AuditRedaction::SENTINEL],
                    $this->requestMetadataOf($connection, $rows[$resolved]),
                    \sprintf("%s: a row an erasure pass redacted keeps that pass's sentinel", $resolved),
                );
            }

            $this->assertSame([null, null], $this->requestMetadataOf($connection, $rows['neverCaptured']));
            $this->assertSame(
                $rows['administrator'],
                $connection->fetchOne('SELECT actor_id FROM audit_log WHERE id = :id', ['id' => $rows['pastSecurity']]),
                'the attribution is what the evidence is for, so the actor id stays',
            );
            $this->assertTrue(
                $log->hasInfoThatPasses(
                    static fn (LogRecord $record): bool => ['removed' => 0, 'cleared' => 4] === $record->context,
                ),
                'exactly the four rows holding metadata past their threshold are cleared, and nothing is deleted',
            );
            $this->assertNoRowLeftMatchingTheClear($connection, $policy, $anchor);
        });
    }

    /**
     * The rule as an aggregate rather than per seeded row: after a run, no exempt row of any level that no
     * erasure pass redacted still carries request metadata past that level's threshold. The predicate is
     * written out here rather than taken from the pruner, so a pruner that drifted from it reds this.
     */
    private function assertNoRowLeftMatchingTheClear(
        Connection $connection,
        AuditRetentionPolicy $policy,
        DateTimeImmutable $anchor,
    ): void {
        foreach ($policy->thresholdsAt($anchor) as $threshold) {
            $left = $connection->fetchOne(
                'SELECT COUNT(*) FROM audit_log WHERE level = :level AND occurred_on < :threshold '
                . "AND action IN ('GDPR_SUBJECT_ERASED', 'GDPR_ERASURE_EXECUTED') AND actor_erased = FALSE "
                . "AND NOT (actor_type = 'anonymous' AND resource_erased) "
                . 'AND (ip IS NOT NULL OR user_agent IS NOT NULL)',
                ['level' => $threshold->level->value, 'threshold' => $threshold->deleteBefore],
                ['threshold' => Types::DATETIMETZ_IMMUTABLE],
            );
            $this->assertIsNumeric($left);
            $this->assertSame(0, (int) $left, \sprintf(
                'an exempt %s row past its threshold still carries request metadata',
                $threshold->level->value,
            ));
        }
    }

    /**
     * One row per term of the clear's predicate, each named for what it must show, plus the administrator
     * the first of them is attributed to.
     *
     * @return array{
     *     administrator: string,
     *     pastSecurity: string,
     *     userAgentOnly: string,
     *     ipOnly: string,
     *     pastActivity: string,
     *     withinSecurity: string,
     *     evidenceAtThreshold: string,
     *     ordinaryAtThreshold: string,
     *     ordinaryWithin: string,
     *     actorAxisResolved: string,
     *     resourceAxisResolved: string,
     *     neverCaptured: string,
     * }
     */
    private function seedClearCandidates(Connection $connection, DateTimeImmutable $anchor): array
    {
        $writer = new DbalAuditLogWriter($connection);
        $seed = fn (
            string $action,
            AuditLevel $level,
            int $age,
            ?string $ip = self::IP,
            ?string $userAgent = self::USER_AGENT,
        ): string => $this->seedCaptured(
            $writer,
            new AuditProvenance(
                ActorContext::forUser(Uuid::generate()),
                Uuid::generate(),
                $this->daysBefore($anchor, $age),
                $ip,
                $userAgent,
            ),
            $action,
            $level,
        );

        $administrator = Uuid::generate();
        $pastSecurity = $this->seedCaptured(
            $writer,
            new AuditProvenance(
                ActorContext::forUser($administrator),
                Uuid::generate(),
                $this->daysBefore($anchor, 400),
                self::IP,
                self::USER_AGENT,
            ),
        );

        [$actorAxisResolved, $resourceAxisResolved] = $this->seedResolvedByEachErasurePass(
            $connection,
            $writer,
            $anchor,
        );

        return [
            'administrator' => $administrator,
            'pastSecurity' => $pastSecurity,
            'userAgentOnly' => $seed('GDPR_ERASURE_EXECUTED', AuditLevel::SECURITY, 400, null),
            'ipOnly' => $seed('GDPR_SUBJECT_ERASED', AuditLevel::SECURITY, 400, self::IP, null),
            'pastActivity' => $seed('GDPR_SUBJECT_ERASED', AuditLevel::ACTIVITY, 200),
            'withinSecurity' => $seed('GDPR_SUBJECT_ERASED', AuditLevel::SECURITY, 200),
            'evidenceAtThreshold' => $seed('GDPR_SUBJECT_ERASED', AuditLevel::SECURITY, 365),
            'ordinaryAtThreshold' => $seed('BANK_ACCOUNTS_VIEWED', AuditLevel::SECURITY, 365),
            'ordinaryWithin' => $seed('BANK_ACCOUNTS_VIEWED', AuditLevel::SECURITY, 200),
            'actorAxisResolved' => $actorAxisResolved,
            'resourceAxisResolved' => $resourceAxisResolved,
            'neverCaptured' => $this->seedSecurityRow(
                $writer,
                'GDPR_SUBJECT_ERASED',
                $this->daysBefore($anchor, 400),
            ),
        ];
    }

    /**
     * One row each erasure pass has already redacted — the only rows either writes the sentinel on — so the
     * clear can be shown to leave that pass's evidence alone.
     *
     * @return array{string, string}
     */
    private function seedResolvedByEachErasurePass(
        Connection $connection,
        DbalAuditLogWriter $writer,
        DateTimeImmutable $anchor,
    ): array {
        $erasedActor = Uuid::generate();
        $actorAxisResolved = $this->seedCaptured(
            $writer,
            new AuditProvenance(
                ActorContext::forUser($erasedActor),
                Uuid::generate(),
                $this->daysBefore($anchor, 400),
                self::IP,
                self::USER_AGENT,
            ),
        );
        (new DbalAuditActorAnonymiser($connection))->anonymise($erasedActor);

        // The resource pass writes the sentinel only where the actor was never identified, and raises
        // `resource_erased` without touching `actor_erased` — so this row is invisible to the actor term.
        $resource = AuditResource::of('SeededSubject', Uuid::generate());
        $resourceAxisResolved = $this->seedCaptured(
            $writer,
            new AuditProvenance(
                ActorContext::anonymous(),
                Uuid::generate(),
                $this->daysBefore($anchor, 400),
                self::IP,
                self::USER_AGENT,
            ),
            resource: $resource,
        );
        (new DbalAuditResourceAnonymiser($connection))->anonymise($resource, Uuid::generate());

        return [$actorAxisResolved, $resourceAxisResolved];
    }

    private function seedCaptured(
        DbalAuditLogWriter $writer,
        AuditProvenance $provenance,
        string $action = 'GDPR_SUBJECT_ERASED',
        AuditLevel $level = AuditLevel::SECURITY,
        ?AuditResource $resource = null,
    ): string {
        $entry = AuditLogEntry::create($action, $level, $provenance, $resource);
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

    private function seedSecurityRow(DbalAuditLogWriter $writer, string $action, DateTimeImmutable $occurredOn): string
    {
        return $this->seedCaptured(
            $writer,
            new AuditProvenance(ActorContext::system(), Uuid::generate(), $occurredOn),
            $action,
        );
    }

    private function daysBefore(DateTimeImmutable $anchor, int $days): DateTimeImmutable
    {
        return $anchor->modify('-' . $days . ' days');
    }

    /**
     * The latest of the plan's per-level cut-offs, derived rather than written as a literal so a change to the
     * policy cannot quietly return the backlog while every assertion here stays green.
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
}
