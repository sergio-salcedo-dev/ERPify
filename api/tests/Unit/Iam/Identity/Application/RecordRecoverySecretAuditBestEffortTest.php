<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
use Erpify\Iam\Identity\Application\RecordRecoverySecretAuditBestEffort;
use Erpify\Iam\Identity\Application\ReportsAuditFailureSafely;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\FailingAuditLogger;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The swallow-and-log contract of the recovery-secret projection, pinned on its own.
 *
 * The use-case tests reach this class and assert the ROW — which action, and that the selector is absent —
 * but a best-effort projection fails by being silent, so deleting the `logger->error` call and leaving a bare
 * `catch` is invisible to every one of them. That is the same reason its lockout sibling has a test of its
 * own.
 *
 * @internal
 */
#[CoversClass(RecordRecoverySecretAuditBestEffort::class)]
#[CoversTrait(ReportsAuditFailureSafely::class)]
final class RecordRecoverySecretAuditBestEffortTest extends TestCase
{
    #[Test]
    #[DataProvider('transitions')]
    public function eachTransitionProjectsItsActionOntoTheSubject(string $method, string $action): void
    {
        $subjectId = Uuid::generate();
        $auditLogger = new RecordingAuditLogger();
        $logger = new RecordingLogger();

        (new RecordRecoverySecretAuditBestEffort($auditLogger, $logger))->{$method}($subjectId);

        $this->assertCount(1, $auditLogger->records);
        $record = $auditLogger->records[0];
        $this->assertSame($action, $record['action']);
        $this->assertSame(AuditLevel::SECURITY, $record['level']);

        $resource = $record['resource'];
        $this->assertInstanceOf(AuditResource::class, $resource);
        // The subject's own type, reached through the erasure's constant so the anonymiser that erasure runs
        // reaches these rows like every other one, and no new classification joins the resource registry.
        $this->assertSame(FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, $resource->type);
        $this->assertSame($subjectId, $resource->id);
        $this->assertSame([], $record['metadata'], 'metadata would reach json_encode on a path that cannot throw');
        $this->assertSame([], $logger->records, 'a successful projection must not log');
    }

    #[Test]
    #[DataProvider('transitions')]
    public function everyTransitionSwallowsAFailedWriteAndReportsItAtError(string $method, string $action): void
    {
        // `AuditLevel::SECURITY` propagates by design inside the real logger, so without the catch a trail
        // outage turns these endpoints into 500s over work that already succeeded — a mint whose plaintext
        // has been shown once and can never be shown again.
        $failure = new RuntimeException('audit_log is unavailable');
        $logger = new RecordingLogger();

        (new RecordRecoverySecretAuditBestEffort(new FailingAuditLogger($failure), $logger))
            ->{$method}(Uuid::generate())
        ;

        $this->assertCount(1, $logger->records, 'a swallowed failure that logs nothing did not happen at all');
        $this->assertSame('error', $logger->records[0]['level']);
        $this->assertSame($failure, $logger->records[0]['context']['exception'] ?? null);
        // The action rides in the CONTEXT rather than in four separate messages, so which transition lost its
        // row is answerable without the message becoming a per-transition sentence somebody keeps in step.
        $this->assertSame($action, $logger->records[0]['context']['action'] ?? null);
    }

    #[Test]
    #[DataProvider('transitions')]
    public function theReportNamesNoSubject(string $method, string $action): void
    {
        // The id is the personal datum this class handles, and this report goes to the always-on
        // `observability` channel — a sink with no retention bound and no owner of its deletion. A line
        // saying a person's audit row went missing may not answer by writing that person's identifier there.
        $subjectId = Uuid::generate();
        $logger = new RecordingLogger();

        (new RecordRecoverySecretAuditBestEffort(new FailingAuditLogger(), $logger))->{$method}($subjectId);

        $this->assertCount(1, $logger->records);

        $encoded = \json_encode($logger->records[0], JSON_THROW_ON_ERROR | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $this->assertStringNotContainsString($subjectId, $encoded);
        // And the action still is there, so this is asserting an absence beside a presence rather than over
        // a record that carries nothing at all.
        $this->assertStringContainsString($action, $encoded);
    }

    /**
     * The four transitions and the token each projects. Declared as data rather than as four near-identical
     * cases so a fifth cannot be added with its action left unasserted.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function transitions(): iterable
    {
        yield 'minted' => ['recordMinted', 'RECOVERY_SECRET_MINTED'];
        yield 'redeemed' => ['recordRedeemed', 'RECOVERY_SECRET_REDEEMED'];
        yield 'revoked' => ['recordRevoked', 'RECOVERY_SECRET_REVOKED'];
        yield 'redemption compensated' => [
            'recordRedemptionCompensated',
            'RECOVERY_SECRET_REDEMPTION_COMPENSATED',
        ];
    }
}
