<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Controller;

use Erpify\Documents\Document\Application\Erasure\EraseDocument;
use Erpify\Documents\Document\Infrastructure\Http\DocumentSeriesMapper;
use Erpify\Documents\Document\Infrastructure\Http\EraseDocumentRequest;
use Erpify\Documents\Document\Infrastructure\Http\UploadRoutes;
use Erpify\Documents\Document\Infrastructure\Security\DocumentPermission;
use Erpify\Iam\Identity\Application\ProveActorPassword;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Infrastructure\Security\CurrentPasswordProofThrottle;
use Erpify\Iam\Identity\Infrastructure\Security\PasswordHasher;
use Erpify\Shared\Audit\Application\ActorContextFactory;
use Erpify\Shared\Audit\Domain\ActorType;
use Erpify\Shared\Http\Infrastructure\Responder\ResourceResponder;
use Erpify\Shared\Http\Infrastructure\StrictRequestPayload;
use Erpify\Shared\Uuid\Domain\Uuid;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Exception\ExceptionInterface;

/**
 * `POST /api/v1/documents/series/{seriesId}/erase`: an operator erases every version of a series that is not `Erased`,
 * newest first, each through the single-document erasure — its own ledger entry and its own transaction — with one
 * reason from the closed list. Gated by `document.erase`, which only the ADMIN wildcard carries.
 *
 * **It re-proves the caller's current password** exactly as the single erasure does, before the use case and so before
 * any ledger write, spending the shared {@see CurrentPasswordProofThrottle} budget; it is classified
 * `credential-affecting` in `api/.credential-proof-policy`. The three collaborators are named in this body because
 * `make php.lint.credential-proof` reads the calls as tokens inside the bound action.
 *
 * Answers 200 with the versions this call erased and those still not `Erased` — empty once the run completes, since the
 * use case closes the series before it lists it, and a series already erased answers with both lists empty. 404 for a
 * series with no document, 422 for a reason outside the list or a missing password, the wrong-password problem for a
 * wrong one, and the single erasure's 503s, which stop the run where it stands: versions already erased stay erased.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[Route('/documents/series/{seriesId}/erase', name: UploadRoutes::SERIES_ERASE, methods: ['POST'])]
#[IsGranted(DocumentPermission::ERASE)]
final readonly class EraseDocumentSeriesController
{
    public function __construct(
        private EraseDocument $eraseDocument,
        private ProveActorPassword $proveActorPassword,
        private PasswordHasher $passwordHasher,
        private CurrentPasswordProofThrottle $currentPasswordProofThrottle,
        private ActorContextFactory $actorContext,
        private DocumentSeriesMapper $mapper,
        private ResourceResponder $resourceResponder,
    ) {
    }

    /**
     * @throws ExceptionInterface
     */
    public function __invoke(
        string $seriesId,
        #[StrictRequestPayload]
        EraseDocumentRequest $request,
    ): Response {
        // A malformed id is a request-target error (400 `invalid-uuid`), answered before any lookup.
        Uuid::ensure($seriesId);

        $actor = $this->actorContext->current();

        if (ActorType::USER !== $actor->type || null === $actor->actorId) {
            throw new AccessDeniedException();
        }

        // Before the use case and so before any ledger write, as the single erasure: nothing here can be undone even
        // from a backup, and the shared per-identity budget is spent first.
        $this->currentPasswordProofThrottle->ensureWithinBudget($actor->actorId);

        $this->proveActorPassword->ensure(
            $actor->actorId,
            fn (HashedPassword $stored): bool => $this->passwordHasher->verify(
                $request->currentPassword,
                $stored->toString(),
            ),
        );

        $erasure = $this->eraseDocument->eraseSeries($seriesId, $request->reason, $actor->actorId);

        return $this->resourceResponder->respond($this->mapper->toErasureResource($erasure));
    }
}
