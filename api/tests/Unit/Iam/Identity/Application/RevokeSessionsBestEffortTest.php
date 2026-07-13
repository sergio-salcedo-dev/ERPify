<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\RevokeSessionsBestEffort;
use Erpify\Iam\Session\Application\RevokeAllSessions;
use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Tests\Unit\Iam\Session\Application\InMemorySessionRepository;
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
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(RevokeSessionsBestEffort::class)]
final class RevokeSessionsBestEffortTest extends TestCase
{
    private const string USER_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    public function testDelegatesToRevokeAllSessions(): void
    {
        $sessions = new InMemorySessionRepository();

        $this->revoker($sessions, new NullLogger())->revoke(self::USER_ID);

        $this->assertSame([self::USER_ID], $sessions->revokeAllCalls);
    }

    public function testSwallowsAndLogsARevokeFailureInsteadOfRaisingIt(): void
    {
        $store = $this->createStub(SessionRepository::class);
        $store->method('revokeAllForUser')->willThrowException(new RuntimeException('store down'));
        $logger = new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<array-key, mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        // Must not raise: the credential change already committed, so the eager revoke is best-effort only.
        $this->revoker($store, $logger)->revoke(self::USER_ID);

        $this->assertCount(1, $logger->records);
        $this->assertSame(LogLevel::WARNING, $logger->records[0]['level']);
    }

    private function revoker(SessionRepository $sessions, LoggerInterface $logger): RevokeSessionsBestEffort
    {
        $clock = new FixedClock(new DateTimeImmutable('2026-07-13T12:00:00+00:00'));

        return new RevokeSessionsBestEffort(
            new RevokeAllSessions($sessions, new RecordingEventBus(), new InlineTransactionManager(), $clock),
            $logger,
        );
    }
}
