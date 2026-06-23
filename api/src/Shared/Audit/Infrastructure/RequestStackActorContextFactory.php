<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure;

use Erpify\Shared\Audit\Application\ActorContextFactory;
use Erpify\Shared\Audit\Domain\ActorContext;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Resolves the current actor from the request stack before real authentication exists: any in-flight
 * HTTP request maps to anonymous (there is no authenticated identity yet), and the absence of a request
 * — CLI, scheduler, message worker — maps to system.
 *
 * A sub-request (ESI fragment, forward) is still a Request on the stack, so it correctly stays anonymous:
 * the failure to avoid is degrading to system off-request, which cannot happen while any Request is in the
 * stack, so classifying by the main request would buy nothing here. The day authentication lands this is
 * the only class that changes.
 */
#[AsAlias(ActorContextFactory::class)]
final readonly class RequestStackActorContextFactory implements ActorContextFactory
{
    public function __construct(private RequestStack $requestStack)
    {
    }

    #[Override]
    public function current(): ActorContext
    {
        return $this->requestStack->getCurrentRequest() instanceof Request
            ? ActorContext::anonymous()
            : ActorContext::system();
    }
}
