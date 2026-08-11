<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
use Erpify\Iam\Identity\Application\LoginAttemptRegistrar;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\FailingAuditLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The registrar's audit projection: a tripped lockout is recorded on the operator's `security` surface, and
 * that recording can never cost the lockout itself.
 *
 * Split from {@see LoginAttemptRegistrarTest} rather than added to it — the counter's own behaviour and its
 * observability are separate concerns with separate collaborators, and holding both in one class pushed it
 * past the public-method and object-coupling thresholds.
 *
 * @internal
 */
#[CoversClass(LoginAttemptRegistrar::class)]
final class LoginAttemptRegistrarAuditTest extends TestCase
{
    use BuildsLockoutRegistrar;

    /**
     * `committed` is the assertion that carries this test. "A row was written" is true on both sides of the
     * transaction boundary, so without the stamp the whole case stays green when the write is moved back
     * inside the closure — the one placement that lets a failed INSERT roll the lockout back.
     */
    public function testTheLockoutAuditRowIsWrittenOnceAndOnlyOnceTheTransactionHasCommitted(): void
    {
        $transactionManager = new InlineTransactionManager();
        $auditLogger = new TransactionAwareAuditLogger($transactionManager);

        $this->registrarWith(
            new InMemoryUserRepository($this->userOneAttemptFromLockout()),
            new RecordingEventBus(),
            $transactionManager,
            $auditLogger,
        )->recordFailure(UserMother::DEFAULT_EMAIL);

        $this->assertCount(1, $auditLogger->records);

        $record = $auditLogger->records[0];
        $resource = $record['resource'];

        $this->assertTrue($record['committed']);
        $this->assertSame('USER_LOCKED', $record['action']);
        $this->assertSame(AuditLevel::SECURITY, $record['level']);
        $this->assertInstanceOf(AuditResource::class, $resource);
        $this->assertSame(FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, $resource->type);
        $this->assertSame(UserMother::DEFAULT_ID, $resource->id);
        $this->assertSame([], $record['metadata']);
    }

    public function testAnAttemptBelowTheThresholdWritesNoAuditRow(): void
    {
        $transactionManager = new InlineTransactionManager();
        $auditLogger = new TransactionAwareAuditLogger($transactionManager);

        $this->registrarWith(
            new InMemoryUserRepository(UserMother::create()),
            new RecordingEventBus(),
            $transactionManager,
            $auditLogger,
        )->recordFailure(UserMother::DEFAULT_EMAIL);

        $this->assertSame([], $auditLogger->records);
    }

    /**
     * The lockout is the security control; its audit row is a projection of a fact `event_store` already
     * holds. A write that could take the lock down with it would hand whoever can break `audit_log` an
     * unlimited supply of password guesses.
     */
    public function testAFailingAuditWriteLeavesTheCommittedLockoutStanding(): void
    {
        $user = $this->userOneAttemptFromLockout();
        $repository = new InMemoryUserRepository($user);
        $transactionManager = new InlineTransactionManager();

        $this->registrarWith(
            $repository,
            new RecordingEventBus(),
            $transactionManager,
            new FailingAuditLogger(),
        )->recordFailure(UserMother::DEFAULT_EMAIL);

        $this->assertTrue($transactionManager->committed);
        $this->assertContains($user, $repository->saved);
        $this->assertTrue($user->isLockedAt(self::lockoutProbeInstant()));
    }
}
