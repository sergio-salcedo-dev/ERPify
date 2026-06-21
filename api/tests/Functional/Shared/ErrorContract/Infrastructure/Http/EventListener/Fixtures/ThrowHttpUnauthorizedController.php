<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\ErrorContract\Infrastructure\Http\EventListener\Fixtures;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Test-only controller that throws `UnauthorizedHttpException` (status 401). Uses the named-arg
 * form to avoid the first-arg-is-`$challenge` footgun (the constructor signature is
 * `__construct(string $challenge, string $message = '', ?\Throwable $previous = null,`
 * ` int $code = 0, array $headers = [])`).
 */
final class ThrowHttpUnauthorizedController
{
    public function __invoke(): Response
    {
        throw new UnauthorizedHttpException(challenge: 'Bearer realm="api"', message: 'Token expired.');
    }
}
