<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Identity\Infrastructure\Security;

use Erpify\Backoffice\Identity\Infrastructure\Security\ProblemDetailsAuthenticationFailureHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;

/**
 * @internal
 */
#[CoversClass(ProblemDetailsAuthenticationFailureHandler::class)]
final class ProblemDetailsAuthenticationFailureHandlerTest extends TestCase
{
    public function testNormalisesEveryFailureToASingleMessageSoAccountsCannotBeEnumerated(): void
    {
        // Symfony's own message ("The presented password is invalid.") would reveal the email exists;
        // the handler must re-throw one constant message the 401 arm then surfaces verbatim.
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid credentials.');

        (new ProblemDetailsAuthenticationFailureHandler())->onAuthenticationFailure(
            new Request(),
            new BadCredentialsException('The presented password is invalid.'),
        );
    }
}
