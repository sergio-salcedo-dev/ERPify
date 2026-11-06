<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Controller;

use Erpify\Documents\Document\Application\Upload\Command\UploadEvidenceCommand;
use Erpify\Documents\Document\Application\Upload\UploadEvidence;
use Erpify\Documents\Document\Domain\Exception\UploadInProgress;
use Erpify\Documents\Document\Domain\FilenameSubmission;
use Erpify\Documents\Document\Infrastructure\Http\FilenameQuery;
use Erpify\Documents\Document\Infrastructure\Http\UploadResourceMapper;
use Erpify\Documents\Document\Infrastructure\Http\UploadRetryAfter;
use Erpify\Documents\Document\Infrastructure\Http\UploadRoutes;
use Erpify\Documents\Document\Infrastructure\Security\DocumentPermission;
use Erpify\Shared\Audit\Application\ActorContextFactory;
use Erpify\Shared\Audit\Domain\ActorType;
use Erpify\Shared\Http\Infrastructure\Responder\ResourceResponder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Exception\ExceptionInterface;

/**
 * Step 2 of an evidence upload: `PUT /api/v1/documents/uploads/{id}/content` with the original as the raw body,
 * a mandatory `Content-Length` equal to the declared size, and the original filename percent-encoded in the
 * single `filename` query parameter. Answers 201 with the filed document, or 200 with the document an earlier
 * PUT filed when the upload is already committed.
 *
 * The body is never parsed or buffered here: it is handed to the use case as the request's own stream and
 * sealed as it is read. What this edge does is translate the request — the length header (absent → NULL,
 * malformed → -1), the raw `filename` pairs, the actor — and nothing else; every decision is the use case's,
 * under the intent row's lock.
 *
 * In dev the profiler is disabled for this route ({@see
 * \Erpify\Documents\Document\Infrastructure\Http\UploadProfilerGuard}):
 * its request collector reads the body again, whole.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the HTTP edge translating one request into one command
 */
#[Route(
    '/documents/uploads/{id}/content',
    name: UploadRoutes::CONTENT,
    methods: ['PUT'],
)]
#[IsGranted(DocumentPermission::WRITE)]
final readonly class UploadEvidenceController
{
    public function __construct(
        private UploadEvidence $uploadEvidence,
        private ActorContextFactory $actorContext,
        private UploadResourceMapper $mapper,
        private ResourceResponder $resourceResponder,
    ) {
    }

    /**
     * @throws ExceptionInterface
     */
    public function __invoke(Request $request, string $id): Response
    {
        $actor = $this->actorContext->current();

        if (ActorType::USER !== $actor->type || null === $actor->actorId) {
            throw new AccessDeniedException();
        }

        // Nothing below writes to the session, and an upload may stream for minutes: release the session's lock
        // now, or every other request of the same caller waits behind the upload (measured on the prod image: a
        // GET /me of the uploading session blocked for the whole 400 s stream).
        if ($request->hasSession() && $request->getSession()->isStarted()) {
            $request->getSession()->save();
        }

        $command = new UploadEvidenceCommand(
            uploadId: $id,
            actorId: $actor->actorId,
            contentLength: $this->contentLengthOf($request),
            filename: FilenameSubmission::fromQueryValues(
                FilenameQuery::valuesIn($request->server->getString('QUERY_STRING')),
            ),
            body: $request->getContent(true),
        );

        try {
            $outcome = $this->uploadEvidence->upload($command);
        } catch (UploadInProgress $uploadInProgress) {
            UploadRetryAfter::stampOn($request, $uploadInProgress);

            throw $uploadInProgress;
        }

        return $this->resourceResponder->respond(
            $this->mapper->toReceipt($outcome->document),
            $outcome->created ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    private function contentLengthOf(Request $request): ?int
    {
        $header = $request->headers->get('Content-Length');

        if (null === $header) {
            return null;
        }

        return 1 === \preg_match('/^\d{1,18}$/D', $header) ? (int) $header : -1;
    }
}
