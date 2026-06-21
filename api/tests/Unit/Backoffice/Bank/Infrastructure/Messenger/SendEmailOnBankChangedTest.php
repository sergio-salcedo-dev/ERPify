<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Infrastructure\Messenger;

use Erpify\Backoffice\Bank\Infrastructure\Messenger\SendEmailOnBankChanged;
use Erpify\Tests\Unit\Backoffice\Bank\Domain\Event\Mother\BankCreatedDomainEventMother;
use Erpify\Tests\Unit\Backoffice\Bank\Domain\Event\Mother\BankUpdatedDomainEventMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

/**
 * @internal
 */
#[CoversClass(SendEmailOnBankChanged::class)]
final class SendEmailOnBankChangedTest extends TestCase
{
    private const string NOTIFY_TO = 'ops@erpify.test';

    public function testSendsNotificationWhenClaimSucceeds(): void
    {
        $mailer = new RecordingNotificationMailer();
        $handler = $this->makeHandler(RecordingDomainEventHandlerDeduplicator::granting(), $mailer);

        $handler->onBankCreated(BankCreatedDomainEventMother::create());

        $this->assertSame(1, $mailer->sendCalls);
        $this->assertSame('[ERPify] Bank created', $mailer->lastSubject);
    }

    public function testDoesNotSendWhenClaimIsAlreadyHeld(): void
    {
        $mailer = new RecordingNotificationMailer();
        $handler = $this->makeHandler(RecordingDomainEventHandlerDeduplicator::rejecting(), $mailer);

        $handler->onBankUpdated(BankUpdatedDomainEventMother::create());

        $this->assertSame(0, $mailer->sendCalls);
    }

    public function testReleasesClaimAndRethrowsWhenSendFails(): void
    {
        $sendFailure = new RuntimeException('SMTP unreachable');
        $deduplicator = RecordingDomainEventHandlerDeduplicator::granting();
        $handler = $this->makeHandler($deduplicator, new RecordingNotificationMailer(sendFailure: $sendFailure));

        try {
            $handler->onBankCreated(BankCreatedDomainEventMother::create());
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
        $handler = $this->makeHandler($deduplicator, new RecordingNotificationMailer(sendFailure: $sendFailure));

        try {
            $handler->onBankCreated(BankCreatedDomainEventMother::create());
            $this->fail('Expected the original send failure to propagate.');
        } catch (Throwable $throwable) {
            // The release failure must not mask the real cause the transport needs to record.
            $this->assertSame($sendFailure, $throwable);
        }
    }

    private function makeHandler(
        RecordingDomainEventHandlerDeduplicator $deduplicator,
        RecordingNotificationMailer $mailer,
    ): SendEmailOnBankChanged {
        return new SendEmailOnBankChanged($deduplicator, $mailer, new NullLogger(), self::NOTIFY_TO);
    }
}
