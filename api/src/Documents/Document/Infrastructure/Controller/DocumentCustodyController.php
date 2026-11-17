<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Controller;

use Erpify\Documents\Document\Application\Listing\ReadCustodyTrail;
use Erpify\Documents\Document\Application\Query\CustodyTrailQuery;
use Erpify\Documents\Document\Application\Read\ErasureLedgerOverride;
use Erpify\Documents\Document\Domain\Exception\DocumentGone;
use Erpify\Documents\Document\Infrastructure\Http\DocumentListingMapper;
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
 * `GET /api/v1/documents/{id}/custody`: one document's custody trail in `seq` order, paged by a plain cursor
 * (`afterSeq`, `limit`), and — on the FIRST page only — the document's metadata and opened original filename. Gated
 * by `document.custody`, which only the ADMIN wildcard carries.
 *
 * Answers 404 `document-not-found` for an unknown id on every page, 200 for an `Erased` document (trail kept, name
 * null), 410 `gone` while the erasure ledger's override is active (decided before any lookup, as the listing does),
 * 422 for a malformed or out-of-range `afterSeq`/`limit`. A trail's `actorId` is the nil UUID for a person since
 * erased: a marker, never a user.
 *
 * The route declares `_audit_resource_type` so the generic access-log row names the document (it carries no name,
 * digest or custody actor; the row names the ADMIN who read the trail, as every audit row does). There is no
 * `_audit_canonical`: no row of the `DOCUMENT_READ` class exists for a trail, which audits byte reads only.
 * The response carries a name on its first page, so every page is `no-store`.
 */
#[Route(
    '/documents/{id}/custody',
    name: UploadRoutes::CUSTODY,
    defaults: ['_audit_resource_type' => 'Document'],
    methods: ['GET'],
)]
#[IsGranted(DocumentPermission::CUSTODY)]
final readonly class DocumentCustodyController
{
    public function __construct(
        private ReadCustodyTrail $readCustodyTrail,
        private DocumentListingMapper $mapper,
        private ResourceResponder $resourceResponder,
        private ErasureLedgerOverride $ledgerOverride,
    ) {
    }

    /**
     * @throws ExceptionInterface
     */
    public function __invoke(
        Request $request,
        string $id,
        #[MapQueryString]
        CustodyTrailQuery $query = new CustodyTrailQuery(),
    ): Response {
        if ($request->isMethod(Request::METHOD_HEAD)) {
            throw new MethodNotAllowedHttpException([Request::METHOD_GET]);
        }

        // A malformed id is a request-target error (400 `invalid-uuid`), answered before any lookup.
        Uuid::ensure($id);

        if ($this->ledgerOverride->isActive()) {
            throw DocumentGone::create();
        }

        $trail = $this->readCustodyTrail->read(
            $id,
            $query->afterSeq,
            WirePaginationPolicy::wire()->limitFor($query->limit),
        );

        $response = $this->resourceResponder->respond($this->mapper->toTrailResource($trail));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
