<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Infrastructure\Controller;

use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Iam\Session\Infrastructure\Http\SessionResourceMapper;
use Erpify\Iam\Session\Infrastructure\Security\AdmittedSessionProvider;
use Erpify\Shared\Http\Infrastructure\Responder\ResourceResponder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /sessions` — the signed-in user's own active sessions, the "my sessions" self-service list (not a
 * back-office view of other users). The user is read from the session in hand: the gate already validated the
 * current correlation, so its `userId` is the authoritative subject; the row matching it is flagged `current`.
 *
 * Which session that is — and the 401 when the request no longer has one — belongs to
 * {@see AdmittedSessionProvider}, which also holds the reason the id travels beside the row rather than being
 * read back off it. What is left here is the listing.
 */
#[Route('/sessions', name: 'iam_my_sessions', methods: ['GET'])]
final readonly class MySessionsController
{
    public function __construct(
        private AdmittedSessionProvider $admittedSession,
        private SessionRepository $sessions,
        private SessionResourceMapper $sessionResourceMapper,
        private ResourceResponder $resourceResponder,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $current = $this->admittedSession->requireAdmitted($request);

        $resources = \array_map(
            fn (Session $session): object => $this->sessionResourceMapper->toResource($session, $current->id),
            $this->sessions->findByUserId($current->userId()),
        );

        return $this->resourceResponder->respondCollection($resources);
    }
}
