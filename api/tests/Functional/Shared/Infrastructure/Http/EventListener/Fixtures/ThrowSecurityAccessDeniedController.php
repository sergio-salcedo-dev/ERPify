<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Infrastructure\Http\EventListener\Fixtures;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Test-only controller that throws Security Core's `AccessDeniedException` (extends
 * `\RuntimeException`, NOT `HttpExceptionInterface`) — exercises the factory's direct Security
 * Core branch. This is distinct from the SecurityBundle-wrapped `AccessDeniedHttpException`
 * path; both must yield `type: 'forbidden'`.
 */
final class ThrowSecurityAccessDeniedController
{
    public function __invoke(): Response
    {
        throw new AccessDeniedException('Forbidden.');
    }
}
