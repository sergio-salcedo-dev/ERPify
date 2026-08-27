<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application;

use DateTimeImmutable;
use Erpify\Backoffice\BankAccount\Application\BankAccountIbanLookup;
use Erpify\Backoffice\BankAccount\Domain\Enum\BankAccountStatus;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountNotFoundException;
use Erpify\Backoffice\BankAccount\Domain\Projection\BankAccountCollectionRow;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Kernel\Domain\Enum\Currency;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BankAccountIbanLookup::class)]
final class BankAccountIbanLookupTest extends TestCase
{
    private const string ACCOUNT_ID = '33333333-3333-7000-8000-000000000001';

    private const string CANONICAL_IBAN = 'DE89370400440532013000';

    public function testReturnsTheMatchingRowAndRecordsExactlyOneActivityAccess(): void
    {
        $auditLogger = new RecordingAuditLogger();
        $repository = new InMemoryBankAccountIbanLookupRepository($this->row());
        $lookup = new BankAccountIbanLookup($repository, $auditLogger);

        $row = $lookup->lookup(self::CANONICAL_IBAN);

        $this->assertSame(self::ACCOUNT_ID, $row->id);
        $this->assertSame(self::CANONICAL_IBAN, $repository->askedFor);
        $this->assertCount(1, $auditLogger->records);

        $record = $auditLogger->records[0];
        $this->assertSame('BANK_ACCOUNT_LOOKED_UP_BY_IBAN', $record['action']);
        $this->assertSame(AuditLevel::ACTIVITY, $record['level']);
        $this->assertSame([], $record['metadata']);

        $resource = $record['resource'];
        $this->assertInstanceOf(AuditResource::class, $resource);
        $this->assertSame('BankAccount', $resource->type);
        $this->assertSame(self::ACCOUNT_ID, $resource->id);
    }

    public function testAuditMetadataNeverCarriesTheIban(): void
    {
        $auditLogger = new RecordingAuditLogger();
        $lookup = new BankAccountIbanLookup(new InMemoryBankAccountIbanLookupRepository($this->row()), $auditLogger);

        $lookup->lookup(self::CANONICAL_IBAN);

        $this->assertCount(1, $auditLogger->records);
        $record = $auditLogger->records[0];
        $this->assertStringNotContainsString(self::CANONICAL_IBAN, (string) \json_encode($record));
    }

    public function testThrowsNotFoundAndRecordsTheMissWithNoResourceOrIban(): void
    {
        $auditLogger = new RecordingAuditLogger();
        $lookup = new BankAccountIbanLookup(new InMemoryBankAccountIbanLookupRepository(null), $auditLogger);

        try {
            $lookup->lookup(self::CANONICAL_IBAN);
            $this->fail('Expected BankAccountNotFoundException.');
        } catch (BankAccountNotFoundException $bankAccountNotFoundException) {
            $this->assertCount(
                1,
                $auditLogger->records,
                'A miss is itself an auditable access — otherwise a bankAccount.read caller '
                . 'could probe arbitrary IBANs with no forensic trace of the misses.',
            );
            $record = $auditLogger->records[0];
            $this->assertSame('BANK_ACCOUNT_IBAN_LOOKUP_MISSED', $record['action']);
            $this->assertSame(AuditLevel::ACTIVITY, $record['level']);
            $this->assertNotInstanceOf(
                AuditResource::class,
                $record['resource'],
                'A miss names no account to key a resource by.',
            );
            $this->assertStringNotContainsString(self::CANONICAL_IBAN, (string) \json_encode($record));
            $this->assertStringNotContainsString(
                self::CANONICAL_IBAN,
                $bankAccountNotFoundException->getMessage(),
                'The IBAN is classified PII and must never reach the exception message.',
            );
        }
    }

    private function row(): BankAccountCollectionRow
    {
        return new BankAccountCollectionRow(
            self::ACCOUNT_ID,
            '11111111-1111-7000-8000-000000000001',
            'JPMorgan Chase',
            'JPM',
            'Globex Corporation',
            self::CANONICAL_IBAN,
            'DEUTDEFFXXX',
            'Globex Treasury',
            Currency::EUR,
            BankAccountStatus::ACTIVE,
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            new DateTimeImmutable('2026-01-02T00:00:00+00:00'),
        );
    }
}
