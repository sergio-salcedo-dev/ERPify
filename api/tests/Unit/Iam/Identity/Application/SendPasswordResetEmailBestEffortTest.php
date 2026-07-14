<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\PasswordResetEmailSender;
use Erpify\Iam\Identity\Application\SendPasswordResetEmailBestEffort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use RuntimeException;
use Stringable;

/**
 * @internal
 */
#[CoversClass(SendPasswordResetEmailBestEffort::class)]
final class SendPasswordResetEmailBestEffortTest extends TestCase
{
    private const string RECIPIENT = 'alice@erpify.test';

    private const string RESET_TOKEN = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b.plaintext-secret';

    public function testDelegatesToTheInnerSender(): void
    {
        $recording = new RecordingPasswordResetEmailSender();

        $this->sender($recording, new NullLogger())->send(self::RECIPIENT, self::RESET_TOKEN);

        $this->assertCount(1, $recording->sent);
        $this->assertSame(self::RECIPIENT, $recording->sent[0]['email']);
        $this->assertSame(self::RESET_TOKEN, $recording->sent[0]['token']);
    }

    public function testSwallowsAndLogsASendFailureInsteadOfRaisingIt(): void
    {
        $failing = $this->createStub(PasswordResetEmailSender::class);
        $failing->method('send')->willThrowException(new RuntimeException('mailer down'));
        $logger = new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<array-key, mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        // Must not raise: the request already committed, so the reset email is best-effort only.
        $this->sender($failing, $logger)->send(self::RECIPIENT, self::RESET_TOKEN);

        $this->assertCount(1, $logger->records);
        $this->assertSame(LogLevel::WARNING, $logger->records[0]['level']);
    }

    private function sender(
        PasswordResetEmailSender $inner,
        LoggerInterface $logger,
    ): SendPasswordResetEmailBestEffort {
        return new SendPasswordResetEmailBestEffort($inner, $logger);
    }
}
