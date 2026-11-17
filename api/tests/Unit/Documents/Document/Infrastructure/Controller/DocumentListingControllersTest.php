<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Infrastructure\Controller;

use Erpify\Documents\Document\Application\Listing\DocumentNameOpener;
use Erpify\Documents\Document\Application\Listing\ListDocuments;
use Erpify\Documents\Document\Application\Listing\ReadCustodyTrail;
use Erpify\Documents\Document\Application\Query\CustodyTrailQuery;
use Erpify\Documents\Document\Application\Read\ErasureLedgerOverride;
use Erpify\Documents\Document\Domain\Exception\DocumentGone;
use Erpify\Documents\Document\Domain\Repository\DocumentCustodyRepository;
use Erpify\Documents\Document\Domain\Repository\DocumentListingRepository;
use Erpify\Documents\Document\Infrastructure\Controller\DocumentCustodyController;
use Erpify\Documents\Document\Infrastructure\Controller\ListDocumentsController;
use Erpify\Documents\Document\Infrastructure\Http\DocumentListingMapper;
use Erpify\Documents\Document\Infrastructure\Read\NeverSetErasureLedgerOverride;
use Erpify\Shared\Crypto\Application\EnvelopeEncryptor;
use Erpify\Shared\Http\Infrastructure\Responder\ResourceResponder;
use Erpify\Shared\Search\Application\Http\SearchQuery;
use Erpify\Shared\Search\Infrastructure\Http\SearchResponder;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

/**
 * What both listing routes decide before they look anything up: HEAD is refused, a malformed id is a request-target
 * error, and an active erasure-ledger override answers `gone` whatever the id — with the repositories asserted never
 * to be reached, so "before any lookup" is the test's claim and not a reading of the code.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") two controllers and every collaborator each is built from
 */
#[CoversClass(ListDocumentsController::class)]
#[CoversClass(DocumentCustodyController::class)]
final class DocumentListingControllersTest extends TestCase
{
    #[Test]
    public function theListingRefusesHeadBeforeAnyLookup(): void
    {
        $this->expectException(MethodNotAllowedHttpException::class);

        $this->listing($this->untouchedListing(), new NeverSetErasureLedgerOverride())(
            Request::create('/documents', Request::METHOD_HEAD),
            new SearchQuery(),
        );
    }

    #[Test]
    public function theListingAnswersGoneWhileTheOverrideIsActiveBeforeAnyLookup(): void
    {
        $this->expectException(DocumentGone::class);

        $this->listing($this->untouchedListing(), $this->overridden())(new Request(), new SearchQuery());
    }

    #[Test]
    public function theTrailRefusesHeadBeforeAnyLookup(): void
    {
        $this->expectException(MethodNotAllowedHttpException::class);

        $this->trail($this->untouchedListing(), new NeverSetErasureLedgerOverride())(
            Request::create('/documents/x/custody', Request::METHOD_HEAD),
            Uuid::generate(),
            new CustodyTrailQuery(),
        );
    }

    #[Test]
    public function theTrailRefusesAMalformedIdBeforeTheOverrideIsConsulted(): void
    {
        $this->expectException(InvalidUuidException::class);

        $controller = $this->trail($this->untouchedListing(), $this->overridden());
        $controller(new Request(), 'not-a-uuid', new CustodyTrailQuery());
    }

    #[Test]
    public function theTrailAnswersGoneWhileTheOverrideIsActiveWhateverTheIdAndBeforeAnyLookup(): void
    {
        $this->expectException(DocumentGone::class);

        $controller = $this->trail($this->untouchedListing(), $this->overridden());
        $controller(new Request(), Uuid::generate(), new CustodyTrailQuery());
    }

    /**
     * A listing repository that fails the test the moment it is read.
     *
     * @return DocumentListingRepository&MockObject
     */
    private function untouchedListing(): DocumentListingRepository
    {
        $listing = $this->createMock(DocumentListingRepository::class);
        $listing->expects($this->never())->method('search');
        $listing->expects($this->never())->method('find');

        return $listing;
    }

    private function listing(
        DocumentListingRepository $repository,
        ErasureLedgerOverride $override,
    ): ListDocumentsController {
        return new ListDocumentsController(
            new ListDocuments($repository, new DocumentNameOpener($this->createStub(EnvelopeEncryptor::class))),
            new DocumentListingMapper(),
            (new ReflectionClass(SearchResponder::class))->newInstanceWithoutConstructor(),
            $override,
        );
    }

    private function trail(
        DocumentListingRepository $repository,
        ErasureLedgerOverride $override,
    ): DocumentCustodyController {
        return new DocumentCustodyController(
            new ReadCustodyTrail(
                $repository,
                $this->createStub(DocumentCustodyRepository::class),
                new DocumentNameOpener($this->createStub(EnvelopeEncryptor::class)),
            ),
            new DocumentListingMapper(),
            (new ReflectionClass(ResourceResponder::class))->newInstanceWithoutConstructor(),
            $override,
        );
    }

    private function overridden(): ErasureLedgerOverride
    {
        $override = $this->createStub(ErasureLedgerOverride::class);
        $override->method('isActive')->willReturn(true);

        return $override;
    }
}
