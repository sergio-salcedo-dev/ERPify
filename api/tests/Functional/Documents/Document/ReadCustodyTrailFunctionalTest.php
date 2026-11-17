<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Documents\Document\Application\Listing\DocumentNameOpener;
use Erpify\Documents\Document\Application\Listing\ListDocuments;
use Erpify\Documents\Document\Application\Listing\ReadCustodyTrail;
use Erpify\Documents\Document\Domain\Exception\DocumentNotFound;
use Erpify\Documents\Document\Domain\Repository\DocumentCustodyRepository;
use Erpify\Documents\Document\Domain\Repository\DocumentListingRepository;
use Erpify\Shared\Crypto\Infrastructure\Persistence\DbalKeystore;
use Erpify\Shared\Crypto\Infrastructure\ScopeKeyring;
use Erpify\Shared\Crypto\Infrastructure\SodiumEnvelopeEncryptor;
use Erpify\Shared\Search\Domain\SearchCriteria;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Unit\Shared\Crypto\CountingKeystore;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The cost the story promises, against real Postgres: a first page of a trail opens ONE name with ONE keystore read, a
 * later page of the same trail reads no key at all (and still refuses an unknown document), and a page of the listing
 * costs one read however many documents it holds.
 *
 * @internal
 */
#[CoversClass(ReadCustodyTrail::class)]
#[CoversClass(ListDocuments::class)]
final class ReadCustodyTrailFunctionalTest extends KernelTestCase
{
    use PlantsNamedDocuments;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->emptyDocumentTables();
    }

    protected function tearDown(): void
    {
        $this->emptyDocumentTables();
        parent::tearDown();
    }

    #[Test]
    public function aLaterPageCostsNoKeystoreQueryAndCarriesNoMetadata(): void
    {
        $document = $this->plantNamedDocument('long.pdf');
        $this->plantTrail($document['id'], 130);
        $keystore = new CountingKeystore(new DbalKeystore($this->connection()));
        $trail = $this->trail($keystore);

        $first = $trail->read($document['id'], null, 100);
        $this->assertSame(1, $keystore->reads(), 'the first page opens the name once');
        $this->assertSame('long.pdf', $first->document?->name);
        $this->assertCount(100, $first->entries);
        $this->assertSame(100, $first->nextAfterSeq);

        $second = $trail->read($document['id'], 100, 100);
        $this->assertSame(1, $keystore->reads(), 'a later page reads no key');
        $this->assertNull($second->document);
        $this->assertCount(30, $second->entries);
        $this->assertNull($second->nextAfterSeq);
    }

    #[Test]
    public function aLaterPageOfAnUnknownDocumentIsStillNotFoundAndReadsNoKey(): void
    {
        $keystore = new CountingKeystore(new DbalKeystore($this->connection()));

        try {
            $this->trail($keystore)->read(Uuid::generate(), 5, 25);
            $this->fail('An unknown document must be refused on every page.');
        } catch (DocumentNotFound) {
            $this->assertSame(0, $keystore->reads());
        }
    }

    #[Test]
    public function aWholePageOfTheListingCostsOneKeystoreRead(): void
    {
        for ($i = 0; $i < 12; ++$i) {
            $this->plantNamedDocument('doc-' . $i . '.pdf', ageSeconds: $i);
        }

        $keystore = new CountingKeystore(new DbalKeystore($this->connection()));
        $lister = new ListDocuments(
            $this->service(DocumentListingRepository::class),
            $this->opener($keystore),
        );

        $page = $lister->list(new SearchCriteria());

        $this->assertCount(12, $page->items);
        $this->assertSame(1, $keystore->reads());
        $this->assertSame('doc-0.pdf', $page->items[0]->name);
    }

    private function trail(CountingKeystore $keystore): ReadCustodyTrail
    {
        return new ReadCustodyTrail(
            $this->service(DocumentListingRepository::class),
            $this->service(DocumentCustodyRepository::class),
            $this->opener($keystore),
        );
    }

    private function opener(CountingKeystore $keystore): DocumentNameOpener
    {
        return new DocumentNameOpener(new SodiumEnvelopeEncryptor(new ScopeKeyring($keystore, $this->auditKek())));
    }
}
