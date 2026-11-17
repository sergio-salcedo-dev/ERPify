<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Infrastructure\Http;

use DateTimeImmutable;
use Erpify\Documents\Document\Application\Listing\CustodyTrail;
use Erpify\Documents\Document\Application\Listing\ListedDocument;
use Erpify\Documents\Document\Application\Resource\DocumentCustodyPageResource;
use Erpify\Documents\Document\Application\Resource\DocumentCustodyResource;
use Erpify\Documents\Document\Domain\CustodyEvent;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\ErasedPersonMarker;
use Erpify\Documents\Document\Domain\Projection\CustodyTrailRow;
use Erpify\Documents\Document\Domain\Projection\DocumentListRow;
use Erpify\Documents\Document\Infrastructure\Http\DocumentListingMapper;
use Erpify\Shared\Search\Domain\Page;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The wire of the listing views: the exact ordered key set, the ATOM spelling of every timestamp, and that the
 * uploader, the scope and the ciphertext of the read model reach no resource.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") one import per resource and read model the mapper joins
 */
#[CoversClass(DocumentListingMapper::class)]
final class DocumentListingMapperTest extends TestCase
{
    #[Test]
    public function aListItemCarriesExactlyTheWireFieldsInOrderWithAtomTimestamps(): void
    {
        $page = (new DocumentListingMapper())->toListPage(
            new Page([$this->listed('plano.pdf')], true, false, null, 'n'),
        );

        $this->assertSame('n', $page->nextCursor);
        $this->assertTrue($page->hasNext);
        $item = $page->items[0] ?? null;
        $this->assertInstanceOf(\Erpify\Documents\Document\Application\Resource\DocumentListResource::class, $item);
        $this->assertSame(
            [
                'id', 'seriesId', 'predecessorId', 'state', 'digest', 'sizeBytes', 'name', 'retentionReason',
                'legalBasis', 'reviewAt', 'createdAt',
            ],
            \array_keys(\get_object_vars($item)),
        );
        $this->assertSame('plano.pdf', $item->name);
        $this->assertSame('2026-11-17T10:20:30+00:00', $item->createdAt);
        $this->assertSame('2027-01-02T00:00:00+00:00', $item->reviewAt);
        $this->assertSame('ready', $item->state);
    }

    #[Test]
    public function theFirstPageOfATrailCarriesTheMetadataAndTheEntriesAsPlainScalarArrays(): void
    {
        $trail = new CustodyTrail(
            $this->listed(null),
            [
                new CustodyTrailRow(1, CustodyEvent::Received, ErasedPersonMarker::UUID, null, $this->at()),
                new CustodyTrailRow(2, CustodyEvent::ScanReady, null, null, $this->at()),
            ],
            2,
        );

        $resource = (new DocumentListingMapper())->toTrailResource($trail);

        $this->assertInstanceOf(DocumentCustodyResource::class, $resource);
        $this->assertNull($resource->name);
        $this->assertSame(2, $resource->nextAfterSeq);
        $this->assertSame(
            [
                ['seq' => 1, 'event' => 'received', 'actorId' => ErasedPersonMarker::UUID, 'reason' => null,
                    'occurredAt' => '2026-11-17T10:20:30+00:00'],
                ['seq' => 2, 'event' => 'scan_ready', 'actorId' => null, 'reason' => null,
                    'occurredAt' => '2026-11-17T10:20:30+00:00'],
            ],
            $resource->entries,
        );
    }

    #[Test]
    public function aLaterPageCarriesTheEntriesAndTheCursorAndNothingOfTheDocument(): void
    {
        $resource = (new DocumentListingMapper())->toTrailResource(new CustodyTrail(null, [], null));

        $this->assertInstanceOf(DocumentCustodyPageResource::class, $resource);
        $this->assertSame(['entries', 'nextAfterSeq'], \array_keys(\get_object_vars($resource)));
    }

    private function listed(?string $name): ListedDocument
    {
        return new ListedDocument(
            new DocumentListRow(
                '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1b01',
                '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1b01',
                null,
                DocumentState::Ready,
                \str_repeat('a', 64),
                10,
                'scope-never-on-the-wire',
                'ciphertext-never-on-the-wire',
                'contract-execution',
                'contract',
                new DateTimeImmutable('2027-01-02T00:00:00+00:00'),
                $this->at(),
            ),
            $name,
        );
    }

    private function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-17T10:20:30+00:00');
    }
}
