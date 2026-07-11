<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Infrastructure\Controller;

use Erpify\Iam\Session\Application\CurrentSessionReference;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\Exception\SessionNoLongerActive;
use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Iam\Session\Infrastructure\Http\SessionResourceMapper;
use Erpify\Shared\Http\Infrastructure\Responder\ResourceResponder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /sessions` — the signed-in user's own active sessions, the "my sessions" self-service list (not a
 * back-office view of other users). The user is read from the session in hand: the gate already validated the
 * current correlation, so its `userId` is the authoritative subject; the row matching it is flagged `current`.
 */
#[Route('/sessions', name: 'iam_my_sessions', methods: ['GET'])]
final readonly class MySessionsController
{
    public function __construct(
        private CurrentSessionReference $currentSession,
        private SessionRepository $sessions,
        private SessionResourceMapper $sessionResourceMapper,
        private ResourceResponder $resourceResponder,
    ) {
    }

    public function __invoke(): Response
    {
        $currentSessionId = $this->currentSession->get();

        if (!$currentSessionId instanceof SessionId) {
            throw SessionNoLongerActive::forRequest();
        }

        $current = $this->sessions->findActiveById($currentSessionId);

        if (!$current instanceof Session) {
            throw SessionNoLongerActive::forRequest();
        }

        $resources = \array_map(
            fn (Session $session): object => $this->sessionResourceMapper->toResource($session, $currentSessionId),
            $this->sessions->findByUserId($current->userId()),
        );

        return $this->resourceResponder->respondCollection($resources);
    }
}
