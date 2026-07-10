<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Controller;

use Erpify\Iam\Identity\Infrastructure\Http\MeResourceMapper;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Shared\Http\Infrastructure\Responder\ResourceResponder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * `GET /me` — the who-am-I read the PWA hydrates its session from. Gated by the firewall (`IS_AUTHENTICATED_FULLY`)
 * and the Session Admission Gate like any `^/api` route, so a request without a live session never reaches it.
 * There is no `#[IsGranted]` by design: every authenticated identity may read its own identity.
 */
#[Route('/me', name: 'iam_me', methods: ['GET'])]
final readonly class MeController
{
    public function __construct(
        private MeResourceMapper $meResourceMapper,
        private ResourceResponder $resourceResponder,
    ) {
    }

    public function __invoke(#[CurrentUser] SecurityUser $user): Response
    {
        return $this->resourceResponder->respond($this->meResourceMapper->toResource($user));
    }
}
