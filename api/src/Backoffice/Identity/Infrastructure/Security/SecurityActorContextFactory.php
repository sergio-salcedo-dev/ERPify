<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Identity\Infrastructure\Security;

use Erpify\Shared\Audit\Application\ActorContextFactory;
use Erpify\Shared\Audit\Domain\ActorContext;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Resolves the actor for an audit record. Off-request execution (CLI, scheduler, message worker) is always
 * `system`: request presence is checked first, so a security token still sitting in storage never attributes
 * an off-request write to a user. Within an in-flight request an authenticated {@see SecurityUser} is
 * attributed by UUID; anything else in a request — no token, or a token whose user is not a
 * {@see SecurityUser} — maps to anonymous.
 *
 * It lives in `Backoffice/Identity/Infrastructure`, not next to the {@see ActorContextFactory} port in
 * `Shared/Audit`, because reading the actor's UUID means depending on {@see SecurityUser} — a business
 * context Shared may not import (bounded-context isolation / deptrac). Identity is the home of the token,
 * so the one place the actor's UUID is read sits here; the port, its signature and every downstream
 * consumer stay untouched.
 *
 * The user's id is read defensively ({@see SecurityUser::id()} is `?string`): an absent id degrades to the
 * anonymous fallback rather than being passed on. A token whose user is not a {@see SecurityUser} takes the
 * same fallback.
 */
#[AsAlias(ActorContextFactory::class)]
final readonly class SecurityActorContextFactory implements ActorContextFactory
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private RequestStack $requestStack,
    ) {
    }

    #[Override]
    public function current(): ActorContext
    {
        if (!$this->requestStack->getCurrentRequest() instanceof Request) {
            return ActorContext::system();
        }

        $user = $this->tokenStorage->getToken()?->getUser();

        if ($user instanceof SecurityUser) {
            $id = $user->id();

            if (null !== $id) {
                return ActorContext::forUser($id);
            }
        }

        return ActorContext::anonymous();
    }
}
