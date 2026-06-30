<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Infrastructure\Http;

use DateTimeInterface;
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

    private const string OTHER_ACCOUNT_ID = '33333333-3333-7000-8000-000000000002';

    public function testResourcePinsTenKeysIncludingBankIdAndAtomTimestamps(): void
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

        $resource = (new BankAccountResourceMapper())->toResource($account);

        $this->assertSame(
            ['id', 'bankId', 'holderName', 'iban', 'bic', 'alias', 'currency', 'status', 'createdAt', 'updatedAt'],
            \array_keys(\get_object_vars($resource)),
        );
        $this->assertSame(self::ACCOUNT_ID, $resource->id);
        $this->assertSame(BankAccountMother::DEFAULT_BANK_ID, $resource->bankId);
        $this->assertSame('Globex Corporation', $resource->holderName);
        $this->assertSame('DE89370400440532013000', $resource->iban);
        $this->assertSame('DEUTDEFFXXX', $resource->bic);
        $this->assertSame('Globex Treasury', $resource->alias);
        $this->assertSame('EUR', $resource->currency);
        $this->assertSame('INACTIVE', $resource->status);
        $this->assertSame(
            $account->getCreatedAt()->format(DateTimeInterface::ATOM),
            $resource->createdAt,
        );
        $this->assertSame(
            $account->getUpdatedAt()->format(DateTimeInterface::ATOM),
            $resource->updatedAt,
        );
    }

    public function testResourceEmitsNullForAbsentOptionalFields(): void
    {
        $resource = (new BankAccountResourceMapper())->toResource(BankAccountMother::create());

        $this->assertNull($resource->bic);
        $this->assertNull($resource->alias);
        $this->assertSame('ACTIVE', $resource->status);
    }

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
        $account = BankAccountMother::create(status: BankAccountStatus::ACTIVE);

        $resource = (new BankAccountResourceMapper())->toListResource($account);

        $this->assertNull($resource->bic);
        $this->assertNull($resource->alias);
        $this->assertSame('ACTIVE', $resource->status);
    }

    public function testToListPagePreservesNavigationAndMapsEachItemInOrder(): void
    {
        $page = new Page(
            [
                BankAccountMother::create(id: self::ACCOUNT_ID),
                BankAccountMother::create(id: self::OTHER_ACCOUNT_ID),
            ],
            hasNext: true,
            hasPrev: false,
            count: 7,
            nextCursor: 'next-cursor',
        );

        $mapped = (new BankAccountResourceMapper())->toListPage($page);

        $this->assertTrue($mapped->hasNext);
        $this->assertFalse($mapped->hasPrev);
        $this->assertSame(7, $mapped->count);
        $this->assertSame('next-cursor', $mapped->nextCursor);
        $this->assertNull($mapped->prevCursor);
        $this->assertContainsOnlyInstancesOf(BankAccountListResource::class, $mapped->items);
        $this->assertSame(
            [self::ACCOUNT_ID, self::OTHER_ACCOUNT_ID],
            \array_map(static fn (BankAccountListResource $resource): string => $resource->id, $mapped->items),
        );
    }

    public function testToListPageMapsAnEmptyPageToNoItems(): void
    {
        $page = new Page([], hasNext: false, hasPrev: false);

        $mapped = (new BankAccountResourceMapper())->toListPage($page);

        $this->assertSame([], $mapped->items);
        $this->assertFalse($mapped->hasNext);
        $this->assertFalse($mapped->hasPrev);
        $this->assertNull($mapped->count);
    }
}
