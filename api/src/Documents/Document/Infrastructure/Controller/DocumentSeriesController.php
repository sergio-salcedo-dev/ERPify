<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Controller;

use Erpify\Documents\Document\Application\Listing\ReadDocumentSeries;
use Erpify\Documents\Document\Application\Query\DocumentSeriesQuery;
use Erpify\Documents\Document\Application\Read\ErasureLedgerOverride;
use Erpify\Documents\Document\Domain\Exception\DocumentGone;
use Erpify\Documents\Document\Infrastructure\Http\DocumentSeriesMapper;
use Erpify\Documents\Document\Infrastructure\Http\UploadRoutes;
use Erpify\Documents\Document\Infrastructure\Security\DocumentPermission;
use Erpify\Shared\Http\Infrastructure\Responder\ResourceResponder;
use Erpify\Shared\Search\Infrastructure\Persistence\Keyset\WirePaginationPolicy;
use Erpify\Shared\Uuid\Domain\Uuid;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Exception\ExceptionInterface;

/**
 * `GET /api/v1/documents/series/{seriesId}`: the versions of one series in creation order, `Erased` ones as tombstones,
 * paged by the last version held (`afterId`, `limit`). Gated by `document.custody`, the listing's verb, which only the
 * ADMIN wildcard carries: the page names uploaders.
 *
 * Answers 404 `document-not-found` for a series with no document, 410 `gone` while the erasure ledger's override is
 * active (decided before any lookup, as the listing and the trail do: after a restore without the ledger a version the
 * ledger erased reads as live again), 422 for a malformed `afterId` or an out-of-range `limit`. No name opens here and
 * no `_audit_canonical` is declared: the generic access-log row is the audit record. `no-store`, since it names people.
 */
#[Route('/documents/series/{seriesId}', name: UploadRoutes::SERIES, methods: ['GET'])]
#[IsGranted(DocumentPermission::CUSTODY)]
final readonly class DocumentSeriesController
{
    public function __construct(
        private ReadDocumentSeries $readDocumentSeries,
        private DocumentSeriesMapper $mapper,
        private ResourceResponder $resourceResponder,
        private ErasureLedgerOverride $ledgerOverride,
    ) {
    }

    /**
     * @throws ExceptionInterface
     */
    public function __invoke(
        Request $request,
        string $seriesId,
        #[MapQueryString]
        DocumentSeriesQuery $query = new DocumentSeriesQuery(),
    ): Response {
        if ($request->isMethod(Request::METHOD_HEAD)) {
            throw new MethodNotAllowedHttpException([Request::METHOD_GET]);
        }

        // A malformed id is a request-target error (400 `invalid-uuid`), answered before any lookup.
        Uuid::ensure($seriesId);

        if ($this->ledgerOverride->isActive()) {
            throw DocumentGone::create();
        }

        $page = $this->readDocumentSeries->read(
            $seriesId,
            $query->afterId,
            WirePaginationPolicy::wire()->limitFor($query->limit),
        );

        $response = $this->resourceResponder->respond($this->mapper->toSeriesResource($page));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
