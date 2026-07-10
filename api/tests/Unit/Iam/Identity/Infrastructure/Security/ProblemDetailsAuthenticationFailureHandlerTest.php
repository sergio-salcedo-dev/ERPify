<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Domain\Exception\AccountDeactivated;
use Erpify\Iam\Identity\Domain\Exception\AccountSuspended;
use Erpify\Iam\Identity\Infrastructure\Security\DeactivatedAccountException;
use Erpify\Iam\Identity\Infrastructure\Security\ProblemDetailsAuthenticationFailureHandler;
use Erpify\Iam\Identity\Infrastructure\Security\SuspendedAccountException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Throwable;

/**
 * @internal
 */
#[CoversClass(ProblemDetailsAuthenticationFailureHandler::class)]
final class ProblemDetailsAuthenticationFailureHandlerTest extends TestCase
{
    public function testNormalisesTheFailureToOneMessageAndKeepsTheRealCauseAsPrevious(): void
    {
        // Symfony's own message ("The presented password is invalid.") would reveal the email exists; the
        // handler must re-throw one constant message the 401 arm surfaces verbatim, while the real cause
        // survives as `previous` so the dev/test debug extension can still show what actually failed.
        $cause = new BadCredentialsException('The presented password is invalid.');
        $caught = null;

        try {
            (new ProblemDetailsAuthenticationFailureHandler())->onAuthenticationFailure(new Request(), $cause);
        } catch (Throwable $throwable) {
            $caught = $throwable;
        }

        $this->assertInstanceOf(AuthenticationException::class, $caught);
        $this->assertSame('Invalid credentials.', $caught->getMessage());
        $this->assertSame($cause, $caught->getPrevious());
    }

    public function testGraduatesASuspendedAccountFailureToTheSpecificForbiddenWall(): void
    {
        // A post-identity SUSPENDED failure leaves the uniform 401 for a specific 403 account-suspended.
        $this->expectException(AccountSuspended::class);

        (new ProblemDetailsAuthenticationFailureHandler())
            ->onAuthenticationFailure(new Request(), new SuspendedAccountException())
        ;
    }

    public function testGraduatesADeactivatedAccountFailureToTheGenericForbiddenWall(): void
    {
        $this->expectException(AccountDeactivated::class);

        (new ProblemDetailsAuthenticationFailureHandler())
            ->onAuthenticationFailure(new Request(), new DeactivatedAccountException())
        ;
    }
}
