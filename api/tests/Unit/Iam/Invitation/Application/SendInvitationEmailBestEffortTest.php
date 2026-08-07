<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Invitation\Application;

use Erpify\Iam\Invitation\Application\InvitationEmailSender;
use Erpify\Iam\Invitation\Application\SendInvitationEmailBestEffort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use RuntimeException;
use Stringable;

/**
 * @internal
 */
#[CoversClass(SendInvitationEmailBestEffort::class)]
final class SendInvitationEmailBestEffortTest extends TestCase
{
    private const string RECIPIENT = 'invitee@erpify.test';

    private const string ACCEPT_TOKEN = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b.plaintext-secret';

    public function testDelegatesToTheInnerSender(): void
    {
        $recording = new SpyInvitationEmailSender();

        $accepted = (new SendInvitationEmailBestEffort($recording, new NullLogger()))
            ->send(self::RECIPIENT, self::ACCEPT_TOKEN)
        ;

        $this->assertSame([['recipient' => self::RECIPIENT, 'token' => self::ACCEPT_TOKEN]], $recording->sent);
        $this->assertTrue($accepted);
    }

    public function testSwallowsASendFailureButReportsItToTheCaller(): void
    {
        $failing = $this->createStub(InvitationEmailSender::class);
        $failing->method('send')->willThrowException(new RuntimeException('mailer down'));
        $logger = new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<array-key, mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        // Must not raise: the invitation already committed, so the caller still hands the token over.
        $accepted = (new SendInvitationEmailBestEffort($failing, $logger))->send(self::RECIPIENT, self::ACCEPT_TOKEN);

        $this->assertCount(1, $logger->records);
        $this->assertSame(LogLevel::WARNING, $logger->records[0]['level']);

        // The log line reaches ops; only the return value reaches the operator holding the prompt, who is the
        // one that can still hand the token over.
        $this->assertFalse($accepted);
    }
}
