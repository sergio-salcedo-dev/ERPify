<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\RecordRecoverySecretAuditBestEffort;
use Erpify\Iam\Identity\Application\RevokeRecoverySecret;
use Erpify\Iam\Identity\Domain\Entity\RecoverySecret;
use Erpify\Iam\Identity\Domain\Event\RecoverySecretRevoked;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use Erpify\Tests\Unit\Shared\Persistence\Double\LockOrderJournal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Revocation: the visible eviction that pays for never destroying a secret silently.
 *
 * @internal
 */
#[CoversClass(RevokeRecoverySecret::class)]
final class RevokeRecoverySecretTest extends TestCase
{
    private const string NOW = '2026-08-28T12:00:00+00:00';

    #[Test]
    public function itRetiresTheRowUnderTheLockAndRecordsTheFact(): void
    {
        $secrets = new InMemoryRecoverySecretRepository($this->secretFor(UserMother::DEFAULT_ID));
        $journal = new LockOrderJournal();
        $secrets->lockOrderJournal = $journal;
        $eventBus = new RecordingEventBus();
        $audit = new RecordingAuditLogger();

        $this->useCase($secrets, $eventBus, $audit)->revoke(UserMother::DEFAULT_ID);

        $this->assertCount(1, $secrets->removed);
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertInstanceOf(RecoverySecretRevoked::class, $eventBus->publishedEvents[0]);
        $this->assertCount(1, $audit->records);
        $this->assertSame('RECOVERY_SECRET_REVOKED', $audit->records[0]['action']);
        // The row is read FOR UPDATE and only then removed. It costs a round trip and buys the serialisation
        // against a redemption in flight — without it a revoke could land between that flow's verify and its
        // consume, retiring a row it had already decided about.
        $this->assertSame([LockOrderJournal::RECOVERY_SECRET], $journal->crossTableOrder());
    }

    #[Test]
    public function itTakesNoLockOnTheUserRowAtAll(): void
    {
        // The deliberate asymmetry with minting and redeeming: this flow never touches the lockout, so it has
        // no second lock to order against the first — and a path holding exactly one lock cannot be part of a
        // deadlock cycle, whatever order anybody else takes.
        $secrets = new InMemoryRecoverySecretRepository($this->secretFor(UserMother::DEFAULT_ID));
        $journal = new LockOrderJournal();
        $secrets->lockOrderJournal = $journal;

        $this->useCase($secrets)->revoke(UserMother::DEFAULT_ID);

        $this->assertNotContains(LockOrderJournal::IDENTITY_USER, $journal->tablesLockedInOrder);
    }

    #[Test]
    public function revokingNothingIsSuccessfulAndLeavesNoRecordOfARevocation(): void
    {
        // Idempotent by construction, and the audit row is conditioned on a row actually going: a `security`
        // entry for an act that never happened is a false statement in the trail, not a harmless extra.
        $secrets = new InMemoryRecoverySecretRepository();
        $eventBus = new RecordingEventBus();
        $audit = new RecordingAuditLogger();

        $this->useCase($secrets, $eventBus, $audit)->revoke(UserMother::DEFAULT_ID);

        $this->assertSame([], $secrets->removed);
        $this->assertSame([], $eventBus->publishedEvents);
        $this->assertSame([], $audit->records);
    }

    #[Test]
    public function itRevokesOnlyTheCallersOwnSecret(): void
    {
        $mine = $this->secretFor(UserMother::DEFAULT_ID);
        $theirs = $this->secretFor('0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a99');
        $secrets = new InMemoryRecoverySecretRepository($mine, $theirs);

        $this->useCase($secrets)->revoke(UserMother::DEFAULT_ID);

        $this->assertSame([$mine], $secrets->removed);
        $this->assertInstanceOf(RecoverySecret::class, $secrets->findByUserId('0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a99'));
    }

    private function secretFor(string $userId): RecoverySecret
    {
        $generated = RecoverySecret::mint($userId, new DateTimeImmutable(self::NOW));
        $generated->secret->pullDomainEvents();

        return $generated->secret;
    }

    private function useCase(
        InMemoryRecoverySecretRepository $secrets,
        ?RecordingEventBus $eventBus = null,
        ?RecordingAuditLogger $audit = null,
    ): RevokeRecoverySecret {
        return new RevokeRecoverySecret(
            $secrets,
            new RecordRecoverySecretAuditBestEffort($audit ?? new RecordingAuditLogger(), new NullLogger()),
            $eventBus ?? new RecordingEventBus(),
            new InlineTransactionManager(),
        );
    }
}
