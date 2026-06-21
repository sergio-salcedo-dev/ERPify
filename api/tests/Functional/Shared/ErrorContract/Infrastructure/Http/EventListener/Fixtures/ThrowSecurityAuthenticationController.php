<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\ErrorContract\Infrastructure\Http\EventListener\Fixtures;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Test-only controller that throws an anonymous subclass of `AuthenticationException` carrying a
 * constructor-set message — exercises the factory's direct Security Core branch
 * (`type: 'unauthenticated'`).
 */
final class ThrowSecurityAuthenticationController
{
    public function __invoke(): Response
    {
        throw new class ('Bad credentials.') extends AuthenticationException {
        };
    }
}
