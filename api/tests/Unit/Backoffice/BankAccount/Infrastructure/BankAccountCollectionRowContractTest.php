<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Infrastructure;

use DateTimeImmutable;
use Erpify\Backoffice\BankAccount\Domain\Enum\BankAccountStatus;
use Erpify\Backoffice\BankAccount\Domain\Projection\BankAccountCollectionRow;
use Erpify\Backoffice\BankAccount\Infrastructure\Http\BankAccountResourceMapper;
use Erpify\Shared\Kernel\Domain\Enum\Currency;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Byte-stability gate for the cross-bank collection wire contract: pins the EXACT ten-key set (the
 * owning bank identity `bankId` / `bankName` / `bankShortName` is present, the keyset-only timestamps
 * are dropped), the enum-identity `currency` / `status` values, and the nullable `bic` / `alias` branch.
 *
 * @internal
 */
#[CoversClass(BankAccountResourceMapper::class)]
final class BankAccountCollectionRowContractTest extends TestCase
{
    private const string ACCOUNT_ID = '33333333-3333-7000-8000-000000000001';

    private const string BANK_ID = '11111111-1111-7000-8000-000000000001';

    public function testCollectionResourcePinsTenKeysWithBankIdentityAndEnumValues(): void
    {
        $resource = (new BankAccountResourceMapper())->toCollectionResource($this->row(
            bic: 'DEUTDEFFXXX',
            alias: 'Globex Treasury',
            status: BankAccountStatus::INACTIVE,
        ));

        $this->assertSame(
            ['id', 'bankId', 'bankName', 'bankShortName', 'holderName', 'iban', 'bic', 'alias', 'currency', 'status'],
            \array_keys(\get_object_vars($resource)),
        );
        $this->assertSame(self::ACCOUNT_ID, $resource->id);
        $this->assertSame(self::BANK_ID, $resource->bankId);
        $this->assertSame('JPMorgan Chase', $resource->bankName);
        $this->assertSame('JPM', $resource->bankShortName);
        $this->assertSame('Globex Corporation', $resource->holderName);
        $this->assertSame('DE89370400440532013000', $resource->iban);
        $this->assertSame('DEUTDEFFXXX', $resource->bic);
        $this->assertSame('Globex Treasury', $resource->alias);
        $this->assertSame('EUR', $resource->currency);
        $this->assertSame('INACTIVE', $resource->status);
    }

    public function testCollectionResourceEmitsNullForAbsentOptionalFields(): void
    {
        $resource = (new BankAccountResourceMapper())->toCollectionResource($this->row(
            bic: null,
            alias: null,
            status: BankAccountStatus::ACTIVE,
        ));

        $this->assertNull($resource->bic);
        $this->assertNull($resource->alias);
        $this->assertSame('ACTIVE', $resource->status);
        $this->assertSame('JPM', $resource->bankShortName);
    }

    private function row(?string $bic, ?string $alias, BankAccountStatus $status): BankAccountCollectionRow
    {
        return new BankAccountCollectionRow(
            self::ACCOUNT_ID,
            self::BANK_ID,
            'JPMorgan Chase',
            'JPM',
            'Globex Corporation',
            'DE89370400440532013000',
            $bic,
            $alias,
            Currency::EUR,
            $status,
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            new DateTimeImmutable('2026-01-02T00:00:00+00:00'),
        );
    }
}
