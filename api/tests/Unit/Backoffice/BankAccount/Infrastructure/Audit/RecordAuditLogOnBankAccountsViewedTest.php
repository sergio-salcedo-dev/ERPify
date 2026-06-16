<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Infrastructure\Audit;

use DateTimeImmutable;
use Erpify\Backoffice\BankAccount\Application\Audit\BankAccountsViewedAuditEvent;
use Erpify\Backoffice\BankAccount\Infrastructure\Audit\RecordAuditLogOnBankAccountsViewed;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\BufferingLogger;

/**
 * @internal
 */
#[CoversClass(RecordAuditLogOnBankAccountsViewed::class)]
final class RecordAuditLogOnBankAccountsViewedTest extends TestCase
{
    private const string BANK_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    private const string VIEWED_AT = '2026-06-16T12:34:56+00:00';

    public function testRecordsAStructuredInfoLineCarryingWhatAndWhen(): void
    {
        $logger = new BufferingLogger();

        $this->handle($logger);

        [$level, $message, $context] = $this->singleLog($logger);
        $this->assertSame('info', $level);
        $this->assertSame('bank_accounts.viewed', $message);
        $this->assertSame([
            'bankId' => self::BANK_ID,
            'occurredOn' => self::VIEWED_AT,
        ], $context);
    }

    public function testLogsOnlyWhatAndWhenSoNoAccountPiiCanLeak(): void
    {
        $logger = new BufferingLogger();

        $this->handle($logger);

        [, , $context] = $this->singleLog($logger);
        $this->assertSame(['bankId', 'occurredOn'], \array_keys($context));
    }

    private function handle(BufferingLogger $logger): void
    {
        (new RecordAuditLogOnBankAccountsViewed($logger))(
            new BankAccountsViewedAuditEvent(self::BANK_ID, new DateTimeImmutable(self::VIEWED_AT)),
        );
    }

    /**
     * @return array{string, string, array<string, mixed>}
     */
    private function singleLog(BufferingLogger $logger): array
    {
        $logs = \array_values($logger->cleanLogs());
        $this->assertCount(1, $logs, 'Exactly one audit line expected.');

        /** @phpstan-var array{string, string, array<string, mixed>} */
        return $logs[0];
    }
}
