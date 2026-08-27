<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
use Erpify\Iam\Identity\Application\NotifyLockedIdentities;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\FailingAuditLogger;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The sweep's audit projection: a delivered notice is recorded on the operator's `security` surface, and
 * that recording can never cost the notice or the stamp already committed.
 *
 * Split from {@see NotifyLockedIdentitiesTest} rather than added to it — the sweep's own behaviour and its
 * observability are separate concerns with separate collaborators, and holding both in one class pushed it
 * past the public-method and object-coupling thresholds. Mirrors {@see LoginAttemptRegistrarAuditTest}'s own
 * split from {@see LoginAttemptRegistrarTest} for the trip's row.
 *
 * @internal
 */
#[CoversClass(NotifyLockedIdentities::class)]
final class NotifyLockedIdentitiesAuditTest extends TestCase
{
    use BuildsLockoutNotifier;

    private const string NOW = '2026-08-11T12:00:00+00:00';

    private const string FIRST_ID = '0190a1b2-c3d4-7e5f-8a9b-000000000001';

    /**
     * The delivery is projected onto `security`, distinct from the trip's own `USER_LOCKED` row: this one
     * answers "the owner was actually told", named by the identity and carrying the expiry the mail quoted.
     */
    public function testWritesASecurityAuditRowWhenTheNoticeSendsSuccessfully(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $repository = $this->repositoryOf($this->lockedAt($now, self::FIRST_ID));
        $auditLogger = new RecordingAuditLogger();
        $directory = new InMemoryLockedIdentityDirectory([self::FIRST_ID]);

        $this->notifierWith(
            $repository,
            new RecordingAccountLockedEmailSender(),
            new FixedClock($now),
            $directory,
            $auditLogger,
        )->notifyLockedOwners();

        $this->assertCount(1, $auditLogger->records);
        $record = $auditLogger->records[0];
        $this->assertSame('ACCOUNT_LOCKOUT_NOTIFIED', $record['action']);
        $this->assertSame(AuditLevel::SECURITY, $record['level']);

        $resource = $record['resource'];
        $this->assertInstanceOf(AuditResource::class, $resource);
        $this->assertSame(FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, $resource->type);
        $this->assertSame(self::FIRST_ID, $resource->id);
        $this->assertArrayHasKey('lockedUntil', $record['metadata']);
    }

    /**
     * A suppressed or failed send must leave no trail entry at all — pairing the row with the stamp rather
     * than with the candidate query is the whole point, and this is the negative half of that pairing.
     */
    public function testWritesNoAuditRowWhenTheSendFails(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $repository = $this->repositoryOf($this->lockedAt($now, self::FIRST_ID));
        $auditLogger = new RecordingAuditLogger();
        $sender = new RecordingAccountLockedEmailSender([$this->emailFor(self::FIRST_ID)]);
        $directory = new InMemoryLockedIdentityDirectory([self::FIRST_ID]);

        $this->notifierWith($repository, $sender, new FixedClock($now), $directory, $auditLogger)
            ->notifyLockedOwners()
        ;

        $this->assertSame([], $auditLogger->records);
    }

    /**
     * A second tick inside the suppression window sends no mail, and it must therefore also write no second
     * row — an owner already told once must not be told twice in the trail either.
     */
    public function testWritesNoAuditRowWhenTheOwnerIsNotYetDueForANotice(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $repository = $this->repositoryOf($this->lockedAt($now, self::FIRST_ID));
        $auditLogger = new RecordingAuditLogger();
        $sender = new RecordingAccountLockedEmailSender();
        $directory = new InMemoryLockedIdentityDirectory([self::FIRST_ID]);
        $notifier = $this->notifierWith($repository, $sender, new FixedClock($now), $directory, $auditLogger);

        $notifier->notifyLockedOwners();
        $notifier->notifyLockedOwners();

        $this->assertCount(1, $auditLogger->records, 'The window must survive the tick for the trail too.');
    }

    /**
     * The audit write is best-effort exactly as the mail send is not: it runs AFTER the stamp already
     * committed, so a failure here may not look like the notification itself failed — the mail already sent
     * and the suppression window already stands, neither of which a lost trail row may undo. The swallow-and-
     * log contract itself is pinned on its own in {@see RecordLockoutNoticeAuditBestEffortTest}.
     */
    public function testAFailedAuditWriteLeavesTheAlreadySentNoticeAndStampStanding(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $repository = $this->repositoryOf($this->lockedAt($now, self::FIRST_ID));
        $sender = new RecordingAccountLockedEmailSender();
        $directory = new InMemoryLockedIdentityDirectory([self::FIRST_ID]);

        $this->notifierWith(
            $repository,
            $sender,
            new FixedClock($now),
            $directory,
            new FailingAuditLogger(),
        )->notifyLockedOwners();

        $this->assertSame($this->emailsOf(self::FIRST_ID), $sender->sentTo, 'The mail already sent.');
        $this->assertCount(1, $repository->saved, 'A lost audit projection must not undo the stamp.');
    }
}
