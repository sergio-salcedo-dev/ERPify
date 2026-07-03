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
 * Resolves the actor for an audit record from the security token: a request carrying an authenticated
 * {@see SecurityUser} is attributed to that user by UUID, everything else keeps the request-presence
 * classification the audit axis had before authentication existed — an in-flight request with no user
 * maps to anonymous, and off-request execution (CLI, scheduler, message worker) maps to system.
 *
 * It lives in `Backoffice/Identity/Infrastructure`, not next to the {@see ActorContextFactory} port in
 * `Shared/Audit`, because reading the actor's UUID means depending on {@see SecurityUser} — a business
 * context Shared may not import (bounded-context isolation / deptrac). Identity is the home of the token,
 * so the one place the actor's UUID is read sits here; the port, its signature and every downstream
 * consumer stay untouched.
 *
 * The user's id is read defensively ({@see SecurityUser::id()} is `?string`): a null id degrades to the
 * request-presence fallback rather than throwing, so this cannot turn the per-request audit seal into a
 * 5xx. A token whose user is not a {@see SecurityUser} takes the same fallback.
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
        $user = $this->tokenStorage->getToken()?->getUser();

        if ($user instanceof SecurityUser) {
            $id = $user->id();

            if (null !== $id) {
                return ActorContext::forUser($id);
            }
        }

        return $this->requestStack->getCurrentRequest() instanceof Request
            ? ActorContext::anonymous()
            : ActorContext::system();
    }
}
