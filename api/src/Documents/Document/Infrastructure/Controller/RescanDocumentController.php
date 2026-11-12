<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Controller;

use Erpify\Documents\Document\Application\Scanning\RescanDocument;
use Erpify\Documents\Document\Domain\Repository\DocumentRepository;
use Erpify\Documents\Document\Infrastructure\Http\RescanDocumentRequest;
use Erpify\Documents\Document\Infrastructure\Http\UploadResourceMapper;
use Erpify\Documents\Document\Infrastructure\Http\UploadRoutes;
use Erpify\Documents\Document\Infrastructure\Security\DocumentPermission;
use Erpify\Shared\Audit\Application\ActorContextFactory;
use Erpify\Shared\Audit\Domain\ActorType;
use Erpify\Shared\Http\Infrastructure\Responder\ResourceResponder;
use Erpify\Shared\Http\Infrastructure\StrictRequestPayload;
use Erpify\Shared\Uuid\Domain\Uuid;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Exception\ExceptionInterface;

/**
 * `POST /api/v1/documents/{id}/rescan`: an operator reopens a document whose scan failed, with a reason from a closed
 * list. Gated by `document.rescan`, which only the ADMIN wildcard carries. There is no console command for this by
 * decision: the operator is the custody row's actor, and a console run has no session to name one.
 *
 * Answers 200 with the document in `received` again; 404 for an absent document, 409 `document-not-rescannable` for
 * any state but `ScanFailed`, 422 for a reason outside the list. It re-proves no credential and spends no budget:
 * it creates, replaces and destroys none of the caller's own and decides no administrative authority
 * (`api/.credential-proof-policy`).
 */
#[Route('/documents/{id}/rescan', name: UploadRoutes::RESCAN, methods: ['POST'])]
#[IsGranted(DocumentPermission::RESCAN)]
final readonly class RescanDocumentController
{
    public function __construct(
        private RescanDocument $rescanDocument,
        private DocumentRepository $documents,
        private ActorContextFactory $actorContext,
        private UploadResourceMapper $mapper,
        private ResourceResponder $resourceResponder,
    ) {
    }

    /**
     * @throws ExceptionInterface
     */
    public function __invoke(
        string $id,
        #[StrictRequestPayload]
        RescanDocumentRequest $request,
    ): Response {
        // A malformed id is a request-target error (400 `invalid-uuid`), answered before any lookup.
        Uuid::ensure($id);

        $actor = $this->actorContext->current();

        if (ActorType::USER !== $actor->type || null === $actor->actorId) {
            throw new AccessDeniedException();
        }

        $this->rescanDocument->rescan($id, $request->reason, $actor->actorId);

        $document = $this->documents->findById($id) ?? throw new LogicException('A reopened document must exist.');

        return $this->resourceResponder->respond($this->mapper->toReceipt($document));
    }
}
