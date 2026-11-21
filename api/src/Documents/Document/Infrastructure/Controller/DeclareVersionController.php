<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Controller;

use Erpify\Documents\Document\Application\Upload\Command\DeclareUploadCommand;
use Erpify\Documents\Document\Application\Upload\DeclareUpload;
use Erpify\Documents\Document\Domain\Exception\UploadInProgress;
use Erpify\Documents\Document\Infrastructure\Http\UploadResourceMapper;
use Erpify\Documents\Document\Infrastructure\Http\UploadRetryAfter;
use Erpify\Documents\Document\Infrastructure\Http\UploadRoutes;
use Erpify\Documents\Document\Infrastructure\Security\DeclareUploadThrottle;
use Erpify\Documents\Document\Infrastructure\Security\DocumentPermission;
use Erpify\Shared\Audit\Application\ActorContextFactory;
use Erpify\Shared\Audit\Domain\ActorType;
use Erpify\Shared\Http\Infrastructure\Responder\ResourceResponder;
use Erpify\Shared\Http\Infrastructure\StrictRequestPayload;
use Erpify\Shared\Uuid\Domain\Uuid;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Exception\ExceptionInterface;

/**
 * Step 1 of a new version: `POST /api/v1/documents/{id}/versions` declares an upload whose document is to succeed
 * `{id}`. The body, the answers and the PUT that follows are the declare's own (`DeclareUploadController`); only the
 * predecessor is new, and it rides in the path, so the declare's payload keeps no predecessor field.
 *
 * Gated by `document.write` AND `document.download`, both RBAC with no row-level voter
 * (`docs/adr/authorization-model-boundaries.md`): replacing a document is filing evidence about one the caller must be
 * allowed to read, so filing evidence alone is not enough — today that is the ADMIN wildcard, as for the read route.
 * The per-actor declare budget is spent before the use case runs. Answers 409 `document-predecessor-not-ready` for a
 * predecessor that is absent or not `Ready` (one answer for both, so the route is no existence oracle) and 409
 * `document-version-slot-taken` when a live version already succeeds it; the commit at the end of the PUT decides both
 * again under the series lock. It re-proves no credential (`api/.credential-proof-policy`).
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the HTTP edge translating one request into one command
 */
#[Route('/documents/{id}/versions', name: UploadRoutes::VERSION_DECLARE, methods: ['POST'])]
#[IsGranted(DocumentPermission::WRITE)]
#[IsGranted(DocumentPermission::DOWNLOAD)]
final readonly class DeclareVersionController
{
    public function __construct(
        private DeclareUpload $declareUpload,
        private DeclareUploadThrottle $throttle,
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
        DeclareUploadCommand $command,
        Request $request,
    ): Response {
        // A malformed id is a request-target error (400 `invalid-uuid`), answered before any lookup.
        Uuid::ensure($id);

        $actor = $this->actorContext->current();

        if (ActorType::USER !== $actor->type || null === $actor->actorId) {
            throw new AccessDeniedException();
        }

        $this->throttle->ensureWithinBudget($actor->actorId);

        try {
            $outcome = $this->declareUpload->declareVersion($command, $actor->actorId, $id);
        } catch (UploadInProgress $uploadInProgress) {
            UploadRetryAfter::stampOn($request, $uploadInProgress);

            throw $uploadInProgress;
        }

        return $this->resourceResponder->respond(
            $this->mapper->toUploadResource($outcome),
            $outcome->created ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }
}
