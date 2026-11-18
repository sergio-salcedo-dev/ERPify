<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Controller;

use Erpify\Documents\Document\Application\Erasure\EraseDocument;
use Erpify\Documents\Document\Domain\Exception\DocumentNotFound;
use Erpify\Documents\Document\Domain\Repository\DocumentRepository;
use Erpify\Documents\Document\Infrastructure\Http\EraseDocumentRequest;
use Erpify\Documents\Document\Infrastructure\Http\UploadResourceMapper;
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
 * `POST /api/v1/documents/{id}/erase`: an operator erases a document by destroying its key, with a reason from a closed
 * list. Gated by `document.erase`, which only the ADMIN wildcard carries. There is no console command for this by
 * decision: the operator is the custody row's actor, and a console run has no session to name one.
 *
 * **It re-proves the caller's current password**, as the administrative-authority routes do: the act is irreversible
 * even from a backup and enumerable through the listing, and evicting a stolen session undoes nothing. The proof spends
 * the shared {@see CurrentPasswordProofThrottle} budget and runs before the use case, so a wrong password writes
 * nothing to the ledger; it is classified `credential-affecting` in `api/.credential-proof-policy`. The three
 * collaborators are named in this body because `make php.lint.credential-proof` reads the calls as tokens inside the
 * bound action.
 *
 * Answers 200 with the document in `erased`, and answers a repeat the same way having written nothing. 404 for an
 * absent document, 422 for a reason outside the list (the system reasons included) or a missing password, the
 * existing wrong-password problem for a wrong one, 503 `erasure-ledger-unavailable` when the ledger entry could not be
 * made durable (nothing was erased) and 503 `erasure-outcome-unknown` when its write timed out or the transaction
 * failed after it (the erasure may still complete).
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[Route('/documents/{id}/erase', name: UploadRoutes::ERASE, methods: ['POST'])]
#[IsGranted(DocumentPermission::ERASE)]
final readonly class EraseDocumentController
{
    public function __construct(
        private EraseDocument $eraseDocument,
        private ProveActorPassword $proveActorPassword,
        private PasswordHasher $passwordHasher,
        private CurrentPasswordProofThrottle $currentPasswordProofThrottle,
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
        EraseDocumentRequest $request,
    ): Response {
        // A malformed id is a request-target error (400 `invalid-uuid`), answered before any lookup.
        Uuid::ensure($id);

        $actor = $this->actorContext->current();

        if (ActorType::USER !== $actor->type || null === $actor->actorId) {
            throw new AccessDeniedException();
        }

        // Before the use case and so before any ledger write: the erasure cannot be undone even from a backup, and a
        // stolen session must not be enough to perform it. The shared per-identity budget is spent first, so no wrong
        // password buys more guesses here than on the routes that change the credential itself.
        $this->currentPasswordProofThrottle->ensureWithinBudget($actor->actorId);

        $this->proveActorPassword->ensure(
            $actor->actorId,
            fn (HashedPassword $stored): bool => $this->passwordHasher->verify(
                $request->currentPassword,
                $stored->toString(),
            ),
        );

        $this->eraseDocument->erase($id, $request->reason, $actor->actorId);

        $document = $this->documents->findById($id) ?? throw DocumentNotFound::create();

        return $this->resourceResponder->respond($this->mapper->toReceipt($document));
    }
}
