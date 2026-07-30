<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\SendPasswordChangedEmailBestEffort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use RuntimeException;

/**
 * The swallow-and-log contract, pinned on its own rather than through the use case. Exercised only from
 * {@see CompletePasswordResetNotificationTest} with a null logger, replacing the whole `catch` body with a
 * bare `// ignore` would pass every other test in the suite — and silence is the failure mode here, since
 * this notification is the only out-of-band channel by which a takeover victim learns their password changed.
 *
 * @internal
 */
#[CoversClass(SendPasswordChangedEmailBestEffort::class)]
final class SendPasswordChangedEmailBestEffortTest extends TestCase
{
    private const string RECIPIENT = 'alice@erpify.test';

    public function testSendsThroughToTheUnderlyingSender(): void
    {
        $sender = new RecordingPasswordChangedEmailSender();
        $logger = new RecordingLogger();

        $bestEffort = new SendPasswordChangedEmailBestEffort($sender, $logger);
        $bestEffort->send(self::RECIPIENT);

        $this->assertSame([self::RECIPIENT], $sender->sentTo);
        $this->assertSame([], $logger->records, 'A successful send must not log.');
    }

    public function testSwallowsAMailerFailureAndLogsItAtWarning(): void
    {
        $failure = new RuntimeException('smtp is down');
        $logger = new RecordingLogger();

        $bestEffort = new SendPasswordChangedEmailBestEffort(
            new RecordingPasswordChangedEmailSender($failure),
            $logger,
        );
        $bestEffort->send(self::RECIPIENT);

        $this->assertCount(
            1,
            $logger->records,
            'A swallowed send failure that logs nothing is an effect that silently did not happen.',
        );
        $this->assertSame(LogLevel::WARNING, $logger->records[0]['level']);
        $this->assertSame($failure, $logger->records[0]['context']['exception'] ?? null);
    }

    public function testTheLogLineCarriesNoRecipient(): void
    {
        // The recipient is the personal datum this story is about. Its sibling RevokeSessionsBestEffort logs
        // a userId; this one deliberately does not, so a mailer outage cannot write a stream of addresses
        // into the log. (The swallowed exception's own message is outside this class's control.)
        $logger = new RecordingLogger();

        $bestEffort = new SendPasswordChangedEmailBestEffort(
            new RecordingPasswordChangedEmailSender(new RuntimeException('smtp is down')),
            $logger,
        );
        $bestEffort->send(self::RECIPIENT);

        $this->assertCount(1, $logger->records);
        $record = $logger->records[0];
        $this->assertStringNotContainsString(self::RECIPIENT, $record['message']);
        $this->assertSame(['exception'], \array_keys($record['context']));
    }
}
