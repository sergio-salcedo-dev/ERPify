<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Infrastructure\Http;

use Erpify\Backoffice\BankAccount\Application\Resource\BankAccountListResource;
use Erpify\Backoffice\BankAccount\Domain\Enum\BankAccountStatus;
use Erpify\Backoffice\BankAccount\Infrastructure\Http\BankAccountResourceMapper;
use Erpify\Shared\Kernel\Domain\Enum\Currency;
use Erpify\Shared\Search\Domain\Page;
use Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Entity\Mother\BankAccountMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Byte-stability gate for the account wire contract: pins the EXACT seven-key set (so the owning
 * `bankId` can never leak in), the enum-identity `status` / `currency` values, and the nullable
 * `bic` / `alias` branch.
 *
 * @internal
 */
#[CoversClass(BankAccountResourceMapper::class)]
final class BankAccountResourceMapperTest extends TestCase
{
    private const string ACCOUNT_ID = '33333333-3333-7000-8000-000000000001';

    public function testListResourcePinsSevenKeysWithEnumValuesAndOmitsBankId(): void
    {
        $account = BankAccountMother::create(
            id: self::ACCOUNT_ID,
            holderName: 'Globex Corporation',
            iban: 'DE89370400440532013000',
            bic: 'DEUTDEFFXXX',
            alias: 'Globex Treasury',
            currency: Currency::EUR,
            status: BankAccountStatus::INACTIVE,
        );

        $resource = (new BankAccountResourceMapper())->toListResource($account);

        $this->assertSame(
            ['id', 'holderName', 'iban', 'bic', 'alias', 'currency', 'status'],
            \array_keys(\get_object_vars($resource)),
        );
        $this->assertSame(self::ACCOUNT_ID, $resource->id);
        $this->assertSame('Globex Corporation', $resource->holderName);
        $this->assertSame('DE89370400440532013000', $resource->iban);
        $this->assertSame('DEUTDEFFXXX', $resource->bic);
        $this->assertSame('Globex Treasury', $resource->alias);
        $this->assertSame('EUR', $resource->currency);
        $this->assertSame('INACTIVE', $resource->status);
    }

    public function testListResourceEmitsNullForAbsentOptionalFields(): void
    {
        $account = BankAccountMother::create(bic: null, alias: null, status: BankAccountStatus::ACTIVE);

        $resource = (new BankAccountResourceMapper())->toListResource($account);

        $this->assertNull($resource->bic);
        $this->assertNull($resource->alias);
        $this->assertSame('ACTIVE', $resource->status);
    }

    public function testToListPagePreservesNavigationAndMapsItems(): void
    {
        $page = new Page([BankAccountMother::create()], hasNext: false, hasPrev: false);

        $mapped = (new BankAccountResourceMapper())->toListPage($page);

        $this->assertFalse($mapped->hasNext);
        $this->assertFalse($mapped->hasPrev);
        $this->assertNull($mapped->count);
        $this->assertContainsOnlyInstancesOf(BankAccountListResource::class, $mapped->items);
    }
}
