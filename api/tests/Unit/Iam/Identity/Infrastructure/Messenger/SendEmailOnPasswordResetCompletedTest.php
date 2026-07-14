<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Messenger;

use Erpify\Iam\Identity\Application\PasswordChangedEmailSender;
use Erpify\Iam\Identity\Domain\Event\PasswordResetCompleted;
use Erpify\Iam\Identity\Infrastructure\Messenger\SendEmailOnPasswordResetCompleted;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryUserRepository;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Shared\Event\Application\RecordingDomainEventHandlerDeduplicator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

/**
 * @internal
 */
#[CoversClass(SendEmailOnPasswordResetCompleted::class)]
final class SendEmailOnPasswordResetCompletedTest extends TestCase
{
    public function testNotifiesTheIdentityOnceWhenTheClaimSucceeds(): void
    {
        $emails = new RecordingPasswordChangedEmailSender();
        $handler = $this->makeHandler(
            RecordingDomainEventHandlerDeduplicator::granting(),
            $emails,
            new InMemoryUserRepository(UserMother::create()),
        );

        $handler->onPasswordResetCompleted($this->event());

        $this->assertSame([UserMother::DEFAULT_EMAIL], $emails->sentTo);
    }

    public function testDoesNotSendWhenTheClaimIsAlreadyHeld(): void
    {
        $emails = new RecordingPasswordChangedEmailSender();
        $handler = $this->makeHandler(
            RecordingDomainEventHandlerDeduplicator::rejecting(),
            $emails,
            new InMemoryUserRepository(UserMother::create()),
        );

        $handler->onPasswordResetCompleted($this->event());

        $this->assertSame([], $emails->sentTo);
    }

    public function testSkipsSilentlyWhenTheUserWasHardDeletedBeforeTheAsyncSend(): void
    {
        // The event payload is the aggregate id alone (PII-free), so an erased identity has no address to
        // fall back on — skipping is what keeps a GDPR-erased user from being resurrected into a mail.
        $emails = new RecordingPasswordChangedEmailSender();
        $handler = $this->makeHandler(
            RecordingDomainEventHandlerDeduplicator::granting(),
            $emails,
            new InMemoryUserRepository(),
        );

        $handler->onPasswordResetCompleted($this->event());

        $this->assertSame([], $emails->sentTo);
    }

    public function testReleasesTheClaimAndRethrowsWhenTheSendFails(): void
    {
        $sendFailure = new RuntimeException('SMTP unreachable');
        $deduplicator = RecordingDomainEventHandlerDeduplicator::granting();
        $handler = $this->makeHandler(
            $deduplicator,
            new RecordingPasswordChangedEmailSender($sendFailure),
            new InMemoryUserRepository(UserMother::create()),
        );

        try {
            $handler->onPasswordResetCompleted($this->event());
            $this->fail('Expected the send failure to propagate.');
        } catch (Throwable $throwable) {
            $this->assertSame($sendFailure, $throwable);
        }

        $this->assertSame(1, $deduplicator->releaseCalls, 'a failed send must release the claim for the retry');
    }

    public function testPreservesTheSendFailureWhenReleaseAlsoFails(): void
    {
        $sendFailure = new RuntimeException('SMTP unreachable');
        $deduplicator = RecordingDomainEventHandlerDeduplicator::failingToRelease(
            new RuntimeException('claim store unreachable'),
        );
        $handler = $this->makeHandler(
            $deduplicator,
            new RecordingPasswordChangedEmailSender($sendFailure),
            new InMemoryUserRepository(UserMother::create()),
        );

        try {
            $handler->onPasswordResetCompleted($this->event());
            $this->fail('Expected the original send failure to propagate.');
        } catch (Throwable $throwable) {
            // The release failure must not mask the real cause the transport needs to record.
            $this->assertSame($sendFailure, $throwable);
        }
    }

    private function makeHandler(
        RecordingDomainEventHandlerDeduplicator $deduplicator,
        PasswordChangedEmailSender $emails,
        InMemoryUserRepository $users,
    ): SendEmailOnPasswordResetCompleted {
        return new SendEmailOnPasswordResetCompleted($deduplicator, $emails, $users, new NullLogger());
    }

    private function event(): PasswordResetCompleted
    {
        return PasswordResetCompleted::fromPrimitives(
            UserMother::DEFAULT_ID,
            [],
            '0190e1f2-a3b4-7c5d-8e6f-1a2b3c4d5e77',
            '2026-07-14T12:00:00+00:00',
        );
    }
}
