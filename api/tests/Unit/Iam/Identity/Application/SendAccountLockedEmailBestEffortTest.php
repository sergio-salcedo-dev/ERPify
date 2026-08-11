<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\SendAccountLockedEmailBestEffort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

/**
 * The swallow-report-and-log contract, pinned on its own. The sweep drives this class with a null logger, so
 * replacing the `catch` body with a bare `return false` would leave the whole suite green — and on this
 * channel the warning is the ONLY operator signal that mail is failing: a lockout notice is unsolicited, so
 * unlike a password reset nobody is waiting for it and its absence produces no report.
 *
 * The returned `bool` is the half that separates this wrapper from its siblings, because the caller stamps a
 * day-long suppression window on a `true`.
 *
 * @internal
 */
#[CoversClass(SendAccountLockedEmailBestEffort::class)]
final class SendAccountLockedEmailBestEffortTest extends TestCase
{
    private const string RECIPIENT = 'locked@erpify.test';

    public function testReportsSuccessAndLogsNothingWhenTheSendGoesThrough(): void
    {
        $sender = new RecordingAccountLockedEmailSender();
        $logger = new RecordingLogger();

        $sent = (new SendAccountLockedEmailBestEffort($sender, $logger))->send(self::RECIPIENT);

        $this->assertTrue($sent);
        $this->assertSame([self::RECIPIENT], $sender->sentTo);
        $this->assertSame([], $logger->records, 'A successful send must not log.');
    }

    public function testReportsFailureAndLogsItAtWarningWhenTheMailerThrows(): void
    {
        $logger = new RecordingLogger();
        $sender = new RecordingAccountLockedEmailSender([self::RECIPIENT]);

        $sent = (new SendAccountLockedEmailBestEffort($sender, $logger))->send(self::RECIPIENT);

        $this->assertFalse($sent, 'A false here is what stops the caller consuming the suppression window.');
        $this->assertCount(
            1,
            $logger->records,
            'A swallowed send failure that logs nothing is an effect that silently did not happen.',
        );
        $this->assertSame(LogLevel::WARNING, $logger->records[0]['level']);
        $this->assertArrayHasKey('exception', $logger->records[0]['context']);
    }

    public function testTheLogLineCarriesNoRecipient(): void
    {
        // The address is the personal datum this control handles, and this path is unattended: it retries
        // every five minutes for as long as the lock stands and the stamp is unwritten, so naming the
        // recipient would write a repeating stream of addresses into log storage, which has its own
        // retention and no declared owner of their erasure.
        $logger = new RecordingLogger();
        $sender = new RecordingAccountLockedEmailSender([self::RECIPIENT]);

        (new SendAccountLockedEmailBestEffort($sender, $logger))->send(self::RECIPIENT);

        $this->assertCount(1, $logger->records);
        $record = $logger->records[0];
        $this->assertStringNotContainsString(self::RECIPIENT, $record['message']);
        $this->assertSame(['exception'], \array_keys($record['context']));
    }
}
