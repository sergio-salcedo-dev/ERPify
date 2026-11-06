<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Controller;

use Erpify\Documents\Document\Application\Upload\Command\DeclareUploadCommand;
use Erpify\Documents\Document\Application\Upload\DeclareUpload;
use Erpify\Documents\Document\Infrastructure\Http\UploadResourceMapper;
use Erpify\Documents\Document\Infrastructure\Http\UploadRoutes;
use Erpify\Documents\Document\Infrastructure\Security\DeclareUploadThrottle;
use Erpify\Documents\Document\Infrastructure\Security\DocumentPermission;
use Erpify\Shared\Audit\Application\ActorContextFactory;
use Erpify\Shared\Audit\Domain\ActorType;
use Erpify\Shared\Http\Infrastructure\Responder\ResourceResponder;
use Erpify\Shared\Http\Infrastructure\StrictRequestPayload;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Exception\ExceptionInterface;

/**
 * Step 1 of an evidence upload: `POST /api/v1/documents/uploads` declares the size, the optional sha256 and
 * idempotency key, and the retention terms, and answers the upload id to PUT the body to (201), or — on a replay
 * of the same key — what the first declare produced (200). Gated by `document.write`; the per-actor budget is
 * spent before the use case runs. No filename here, ever: it travels with the body.
 */
#[Route('/documents/uploads', name: UploadRoutes::DECLARE, methods: ['POST'])]
#[IsGranted(DocumentPermission::WRITE)]
final readonly class DeclareUploadController
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
        #[StrictRequestPayload]
        DeclareUploadCommand $command,
    ): Response {
        $actor = $this->actorContext->current();

        if (ActorType::USER !== $actor->type || null === $actor->actorId) {
            throw new AccessDeniedException();
        }

        $this->throttle->ensureWithinBudget($actor->actorId);
        $outcome = $this->declareUpload->declare($command, $actor->actorId);

        return $this->resourceResponder->respond(
            $this->mapper->toUploadResource($outcome),
            $outcome->created ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }
}
