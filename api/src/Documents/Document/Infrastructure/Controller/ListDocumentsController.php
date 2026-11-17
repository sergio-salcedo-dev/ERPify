<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Controller;

use Erpify\Documents\Document\Application\Listing\ListDocuments;
use Erpify\Documents\Document\Application\Read\ErasureLedgerOverride;
use Erpify\Documents\Document\Domain\Exception\DocumentGone;
use Erpify\Documents\Document\Infrastructure\Http\DocumentListingMapper;
use Erpify\Documents\Document\Infrastructure\Http\UploadRoutes;
use Erpify\Documents\Document\Infrastructure\Security\DocumentPermission;
use Erpify\Shared\Search\Application\Http\SearchQuery;
use Erpify\Shared\Search\Infrastructure\Http\SearchResponder;
use JsonException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Exception\ExceptionInterface;

/**
 * `GET /api/v1/documents`: a keyset page of documents' metadata with the opened original filename, newest first.
 * Gated by `document.custody`, which only the ADMIN wildcard carries.
 *
 * It serves no bytes, so `documents.read_enabled` does not gate it; what does is the erasure ledger's override:
 * after a restore without the ledger an erased document's key is back and its name would open, so the route answers
 * 410 `gone` whatever the id, before any lookup. HEAD is refused for the same reason `ReadDocumentController` refuses
 * it: its body is dropped after the fact, and the names would have been opened for nothing.
 *
 * No `_audit_canonical`: the generic access log writes this route's activity row, and no row of the `DOCUMENT_READ`
 * class exists for a listing (that audits byte reads only). The response carries names, so it is `no-store`.
 */
#[Route('/documents', name: UploadRoutes::LIST, methods: ['GET'])]
#[IsGranted(DocumentPermission::CUSTODY)]
final readonly class ListDocumentsController
{
    public function __construct(
        private ListDocuments $listDocuments,
        private DocumentListingMapper $mapper,
        private SearchResponder $searchResponder,
        private ErasureLedgerOverride $ledgerOverride,
    ) {
    }

    /**
     * @throws ExceptionInterface
     * @throws JsonException
     */
    public function __invoke(
        Request $request,
        #[MapQueryString]
        SearchQuery $query = new SearchQuery(),
    ): Response {
        if ($request->isMethod(Request::METHOD_HEAD)) {
            throw new MethodNotAllowedHttpException([Request::METHOD_GET]);
        }

        if ($this->ledgerOverride->isActive()) {
            throw DocumentGone::create();
        }

        $response = $this->searchResponder->respond(
            $this->mapper->toListPage($this->listDocuments->list($query->toCriteria())),
            $query,
            UploadRoutes::LIST,
        );
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
