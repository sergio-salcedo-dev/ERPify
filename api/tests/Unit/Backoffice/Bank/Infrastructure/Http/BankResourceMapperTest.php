<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Infrastructure\Http;

use DateTimeImmutable;
use Erpify\Backoffice\Bank\Application\BankWithAccountCount;
use Erpify\Backoffice\Bank\Application\Resource\BankListResource;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Infrastructure\Http\BankResourceMapper;
use Erpify\Shared\Media\Application\Port\MediaPublicUrlGenerator;
use Erpify\Shared\Media\Domain\Entity\Media;
use Erpify\Shared\Search\Domain\Page;
use Erpify\Shared\Storage\Application\Port\StoredObjectPublicUrlGenerator;
use Erpify\Shared\Storage\Domain\StoredObject;
use Erpify\Tests\Unit\Backoffice\Bank\Domain\Entity\Mother\BankMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Byte-stability gate for the bank wire contract: every view pins its EXACT key set (in order), the
 * ATOM timestamp strings, and the synthesized logo / stored-object URLs — the one transformation
 * with no other deterministic net. The URL ports are stubbed to echo their content hash, so a
 * non-null URL proves both that the port was called and that the bank's own hash reached it.
 *
 * @internal
 */
#[CoversClass(BankResourceMapper::class)]
final class BankResourceMapperTest extends TestCase
{
    private const string BANK_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    private const string MEDIA_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4aaa';

    private const string CREATED_AT = '2021-06-01T12:00:00+00:00';

    private const string UPDATED_AT = '2021-06-02T08:30:00+00:00';

    public function testDetailResourcePinsEveryKeyAtomTimestampsAndSynthesizedUrls(): void
    {
        $resource = $this->mapper()->toDetailResource(new BankWithAccountCount($this->bankWithImages(), 7));

        $this->assertSame(
            ['id', 'name', 'shortName', 'createdAt', 'updatedAt', 'logoUrl', 'storedObjectUrl', 'accountCount'],
            \array_keys(\get_object_vars($resource)),
        );
        $this->assertSame(self::BANK_ID, $resource->id);
        $this->assertSame('JPMorgan Chase', $resource->name);
        $this->assertSame('JPM', $resource->shortName);
        $this->assertSame(self::CREATED_AT, $resource->createdAt);
        $this->assertSame(self::UPDATED_AT, $resource->updatedAt);
        $this->assertSame('https://media.test/logo-hash', $resource->logoUrl);
        $this->assertSame('https://stored.test/stored-hash', $resource->storedObjectUrl);
        $this->assertSame(7, $resource->accountCount);
    }

    public function testDetailResourceEmitsNullUrlsWhenNoImageIsAttached(): void
    {
        $resource = $this->mapper()->toDetailResource(new BankWithAccountCount(BankMother::create(), 0));

        $this->assertNull($resource->logoUrl);
        $this->assertNull($resource->storedObjectUrl);
        $this->assertSame(0, $resource->accountCount);
    }

    public function testCreateResourceCarriesUrlsButOmitsTheAccountCountKey(): void
    {
        $resource = $this->mapper()->toCreateResource($this->bankWithImages());

        $this->assertSame(
            ['id', 'name', 'shortName', 'createdAt', 'updatedAt', 'logoUrl', 'storedObjectUrl'],
            \array_keys(\get_object_vars($resource)),
        );
        $this->assertSame('https://media.test/logo-hash', $resource->logoUrl);
        $this->assertSame('https://stored.test/stored-hash', $resource->storedObjectUrl);
        $this->assertSame(self::CREATED_AT, $resource->createdAt);
    }

    public function testUpdateResourceIsTheNarrowFiveKeyViewWithoutUrlsOrCount(): void
    {
        // The bank carries images, yet the update view must still expose neither URL nor count keys.
        $resource = $this->mapper()->toUpdateResource($this->bankWithImages());

        $this->assertSame(
            ['id', 'name', 'shortName', 'createdAt', 'updatedAt'],
            \array_keys(\get_object_vars($resource)),
        );
        $this->assertSame(self::BANK_ID, $resource->id);
        $this->assertSame(self::UPDATED_AT, $resource->updatedAt);
    }

    public function testListResourceCarriesAccountCountButNoUrlKeys(): void
    {
        $resource = $this->mapper()->toListResource(new BankWithAccountCount($this->bankWithImages(), 3));

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

    private function bankWithImages(): Bank
    {
        $media = Media::create(self::MEDIA_ID, 'logo-hash', 'image/png', 70, 'png-bytes');
        $storedObject = new StoredObject('banks/logo.png', 'image/png', 70, 'stored-hash');
        $bank = Bank::create(self::BANK_ID, 'JPMorgan Chase', 'JPM', $media, $storedObject);

        return $bank
            ->setCreatedAt(new DateTimeImmutable(self::CREATED_AT))
            ->setUpdatedAt(new DateTimeImmutable(self::UPDATED_AT))
        ;
    }

    private function mapper(): BankResourceMapper
    {
        $mediaUrls = $this->createStub(MediaPublicUrlGenerator::class);
        $mediaUrls->method('urlForContentHash')->willReturnCallback(
            static fn (string $contentHash): string => 'https://media.test/' . $contentHash,
        );

        $storedObjectUrls = $this->createStub(StoredObjectPublicUrlGenerator::class);
        $storedObjectUrls->method('urlForContentHash')->willReturnCallback(
            static fn (string $contentHash): string => 'https://stored.test/' . $contentHash,
        );

        return new BankResourceMapper($mediaUrls, $storedObjectUrls);
    }
}
