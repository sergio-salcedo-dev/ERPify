<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Infrastructure\Http;

use DateTimeImmutable;
use Erpify\Backoffice\Bank\Application\BankWithAccountCount;
use Erpify\Backoffice\Bank\Application\Resource\BankListResource;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Infrastructure\Http\BankResourceMapper;
use Erpify\Shared\Search\Domain\Page;
use Erpify\Tests\Unit\Backoffice\Bank\Domain\Entity\Mother\BankMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Byte-stability gate for the bank wire contract: every view pins its EXACT key set (in order) and
 * the ATOM timestamp strings. The create and update views currently share a key set (as do list and
 * detail); they stay distinct DTO types so either can evolve without dragging the other, and the
 * mapper's declared return types are what keep the four apart. These pins catch a shape drift.
 *
 * @internal
 */
#[CoversClass(BankResourceMapper::class)]
final class BankResourceMapperTest extends TestCase
{
    private const string BANK_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    private const string CREATED_AT = '2021-06-01T12:00:00+00:00';

    private const string UPDATED_AT = '2021-06-02T08:30:00+00:00';

    public function testDetailResourcePinsEveryKeyAndAtomTimestamps(): void
    {
        $resource = $this->mapper()->toDetailResource(new BankWithAccountCount($this->pinnedBank(), 7));

        $this->assertSame(
            ['id', 'name', 'shortName', 'createdAt', 'updatedAt', 'accountCount'],
            \array_keys(\get_object_vars($resource)),
        );
        $this->assertSame(self::BANK_ID, $resource->id);
        $this->assertSame('JPMorgan Chase', $resource->name);
        $this->assertSame('JPM', $resource->shortName);
        $this->assertSame(self::CREATED_AT, $resource->createdAt);
        $this->assertSame(self::UPDATED_AT, $resource->updatedAt);
        $this->assertSame(7, $resource->accountCount);
    }

    public function testCreateResourceOmitsTheAccountCountKey(): void
    {
        $resource = $this->mapper()->toCreateResource($this->pinnedBank());

        $this->assertSame(
            ['id', 'name', 'shortName', 'createdAt', 'updatedAt'],
            \array_keys(\get_object_vars($resource)),
        );
        $this->assertSame(self::BANK_ID, $resource->id);
        $this->assertSame(self::CREATED_AT, $resource->createdAt);
    }

    public function testUpdateResourceIsTheNarrowFiveKeyViewWithoutTheCount(): void
    {
        $resource = $this->mapper()->toUpdateResource($this->pinnedBank());

        $this->assertSame(
            ['id', 'name', 'shortName', 'createdAt', 'updatedAt'],
            \array_keys(\get_object_vars($resource)),
        );
        $this->assertSame(self::BANK_ID, $resource->id);
        $this->assertSame(self::UPDATED_AT, $resource->updatedAt);
    }

    public function testListResourceCarriesTheAccountCount(): void
    {
        $resource = $this->mapper()->toListResource(new BankWithAccountCount($this->pinnedBank(), 3));

        $this->assertSame(
            ['id', 'name', 'shortName', 'createdAt', 'updatedAt', 'accountCount'],
            \array_keys(\get_object_vars($resource)),
        );
        $this->assertSame(3, $resource->accountCount);
        $this->assertSame(self::CREATED_AT, $resource->createdAt);
    }

    public function testToListPagePreservesNavigationAndMapsEachItemInOrder(): void
    {
        $other = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4ac2';
        $page = new Page(
            [
                new BankWithAccountCount(BankMother::create(self::BANK_ID, 'Bank A', 'BA'), 1),
                new BankWithAccountCount(BankMother::create($other, 'Bank B', 'BB'), 2),
            ],
            hasNext: true,
            hasPrev: false,
            count: 31,
            nextCursor: 'next-cursor',
        );

        $mapped = $this->mapper()->toListPage($page);

        $this->assertTrue($mapped->hasNext);
        $this->assertFalse($mapped->hasPrev);
        $this->assertSame(31, $mapped->count);
        $this->assertSame('next-cursor', $mapped->nextCursor);
        $this->assertNull($mapped->prevCursor);
        $this->assertContainsOnlyInstancesOf(BankListResource::class, $mapped->items);
        $this->assertSame(
            [self::BANK_ID, $other],
            \array_map(static fn (BankListResource $resource): string => $resource->id, $mapped->items),
        );
        $this->assertSame(
            [1, 2],
            \array_map(static fn (BankListResource $resource): int => $resource->accountCount, $mapped->items),
        );
    }

    private function pinnedBank(): Bank
    {
        return Bank::create(self::BANK_ID, 'JPMorgan Chase', 'JPM')
            ->setCreatedAt(new DateTimeImmutable(self::CREATED_AT))
            ->setUpdatedAt(new DateTimeImmutable(self::UPDATED_AT))
        ;
    }

    private function mapper(): BankResourceMapper
    {
        return new BankResourceMapper();
    }
}
