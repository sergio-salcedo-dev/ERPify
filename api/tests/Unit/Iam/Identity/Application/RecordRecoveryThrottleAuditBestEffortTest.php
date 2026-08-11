<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
use Erpify\Iam\Identity\Application\RecordRecoveryThrottleAuditBestEffort;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\FailingAuditLogger;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use RuntimeException;

/**
 * The swallow-and-log contract of the recovery-throttle projection, and the two things about the row that
 * carry a privacy consequence: what it names, and what it must never carry.
 *
 * @internal
 */
#[CoversClass(RecordRecoveryThrottleAuditBestEffort::class)]
final class RecordRecoveryThrottleAuditBestEffortTest extends TestCase
{
    public function testAResolvableAddressIsRecordedAgainstItsSubject(): void
    {
        $auditLogger = new RecordingAuditLogger();

        $this->recorder($auditLogger)->record(UserMother::DEFAULT_EMAIL);

        $this->assertCount(1, $auditLogger->records);
        $record = $auditLogger->records[0];
        $this->assertSame('PASSWORD_RECOVERY_THROTTLED', $record['action']);
        $this->assertSame(AuditLevel::SECURITY, $record['level']);

        $resource = $record['resource'];
        $this->assertInstanceOf(AuditResource::class, $resource);
        // Reached through the erasure owner's constant, never a second 'User' literal: the type denotes a
        // natural person, so the file spelling it is the one the registry names as obliged to erase it.
        $this->assertSame(FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, $resource->type);
        $this->assertSame(UserMother::DEFAULT_ID, $resource->id);
    }

    public function testAnAddressThatNamesNobodyIsStillRecorded(): void
    {
        // Withholding the row would carry the same existence bit in its absence while losing the signal of a
        // sweep against addresses that match no identity.
        $auditLogger = new RecordingAuditLogger();

        $this->recorder($auditLogger)->record('nobody@erpify.test');

        $this->assertCount(1, $auditLogger->records);
        $this->assertNotInstanceOf(AuditResource::class, $auditLogger->records[0]['resource']);
    }

    public function testAMalformedAddressIsRecordedWithoutAResource(): void
    {
        $auditLogger = new RecordingAuditLogger();

        $this->recorder($auditLogger)->record('not-an-address');

        $this->assertCount(1, $auditLogger->records);
        $this->assertNotInstanceOf(AuditResource::class, $auditLogger->records[0]['resource']);
    }

    public function testTheRowCarriesNoTraceOfTheRequestedAddress(): void
    {
        // The address is a person datum whose erasure nothing owns: the anonymisers rewrite actor_id and
        // resource_id and never touch metadata, and every control keeping person ids out of metadata matches
        // by id against identity_user, so an address there would be invisible to all of them.
        $auditLogger = new RecordingAuditLogger();

        $this->recorder($auditLogger)->record(UserMother::DEFAULT_EMAIL);

        $this->assertCount(1, $auditLogger->records);
        $metadata = $auditLogger->records[0]['metadata'];
        $this->assertSame([], $metadata);
        $this->assertStringNotContainsStringIgnoringCase(
            UserMother::DEFAULT_EMAIL,
            \json_encode($metadata, JSON_THROW_ON_ERROR),
        );
    }

    public function testASpentBudgetSuppressesTheWriteEntirely(): void
    {
        $auditLogger = new RecordingAuditLogger();

        $this->recorder($auditLogger, new FixedRecoveryThrottleAuditBudget(granted: false))
            ->record(UserMother::DEFAULT_EMAIL)
        ;

        $this->assertSame([], $auditLogger->records);
    }

    public function testAFailedWriteIsSwallowedAndLoggedAtWarning(): void
    {
        $failure = new RuntimeException('audit_log is unavailable');
        $logger = new RecordingLogger();

        (new RecordRecoveryThrottleAuditBestEffort(
            new FixedRecoveryThrottleAuditBudget(granted: true),
            new InMemoryUserRepository(UserMother::create()),
            new FailingAuditLogger($failure),
            $logger,
        ))->record(UserMother::DEFAULT_EMAIL);

        $this->assertCount(
            1,
            $logger->records,
            'A swallowed projection failure that logs nothing is an observation that silently did not happen.',
        );
        $this->assertSame(LogLevel::WARNING, $logger->records[0]['level']);
        $this->assertSame($failure, $logger->records[0]['context']['exception'] ?? null);
    }

    public function testTheLogLineNamesNeitherTheAddressNorTheSubject(): void
    {
        $logger = new RecordingLogger();

        (new RecordRecoveryThrottleAuditBestEffort(
            new FixedRecoveryThrottleAuditBudget(granted: true),
            new InMemoryUserRepository(UserMother::create()),
            new FailingAuditLogger(),
            $logger,
        ))->record(UserMother::DEFAULT_EMAIL);

        $this->assertCount(1, $logger->records);
        $record = $logger->records[0];
        $this->assertStringNotContainsStringIgnoringCase(UserMother::DEFAULT_EMAIL, $record['message']);
        $this->assertStringNotContainsString(UserMother::DEFAULT_ID, $record['message']);
        $this->assertSame(['exception'], \array_keys($record['context']));
    }

    private function recorder(
        RecordingAuditLogger $auditLogger,
        ?FixedRecoveryThrottleAuditBudget $budget = null,
    ): RecordRecoveryThrottleAuditBestEffort {
        return new RecordRecoveryThrottleAuditBestEffort(
            $budget ?? new FixedRecoveryThrottleAuditBudget(granted: true),
            new InMemoryUserRepository(UserMother::create()),
            $auditLogger,
            new RecordingLogger(),
        );
    }
}
