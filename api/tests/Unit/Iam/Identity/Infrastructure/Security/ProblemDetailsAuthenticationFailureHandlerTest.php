<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Domain\Exception\AccountDeactivated;
use Erpify\Iam\Identity\Domain\Exception\AccountLocked;
use Erpify\Iam\Identity\Domain\Exception\AccountSuspended;
use Erpify\Iam\Identity\Infrastructure\Security\DeactivatedAccountException;
use Erpify\Iam\Identity\Infrastructure\Security\LockedAccountException;
use Erpify\Iam\Identity\Infrastructure\Security\ProblemDetailsAuthenticationFailureHandler;
use Erpify\Iam\Identity\Infrastructure\Security\SuspendedAccountException;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryUserRepository;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Throwable;

/**
 * @internal
 */
#[CoversClass(ProblemDetailsAuthenticationFailureHandler::class)]
final class ProblemDetailsAuthenticationFailureHandlerTest extends TestCase
{
    use BuildsFailureHandler;

    public function testNormalisesTheFailureToOneMessageAndKeepsTheRealCauseAsPrevious(): void
    {
        // Symfony's own message ("The presented password is invalid.") would reveal the email exists; the
        // handler must re-throw one constant message the 401 arm surfaces verbatim, while the real cause
        // survives as `previous` so the dev/test debug extension can still show what actually failed.
        $cause = new BadCredentialsException('The presented password is invalid.');
        $caught = null;

        try {
            $this->handler(new InMemoryUserRepository())->onAuthenticationFailure($this->loginRequest(''), $cause);
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

        $this->handler(new InMemoryUserRepository())
            ->onAuthenticationFailure($this->loginRequest(''), new SuspendedAccountException())
        ;
    }

    public function testGraduatesADeactivatedAccountFailureToTheGenericForbiddenWall(): void
    {
        $this->expectException(AccountDeactivated::class);

        $this->handler(new InMemoryUserRepository())
            ->onAuthenticationFailure($this->loginRequest(''), new DeactivatedAccountException())
        ;
    }

    public function testGraduatesALockedAccountFailureToTheSpecificForbiddenWall(): void
    {
        // A post-identity locked failure leaves the uniform 401 for a specific 403 account-locked.
        $this->expectException(AccountLocked::class);

        $this->handler(new InMemoryUserRepository())
            ->onAuthenticationFailure($this->loginRequest(''), new LockedAccountException())
        ;
    }

    public function testRecordsTheFailedAttemptAgainstTheSubmittedIdentityBeforeReporting(): void
    {
        $user = UserMother::create();
        $repository = new InMemoryUserRepository($user);

        try {
            $this->handler($repository)->onAuthenticationFailure(
                $this->loginRequest(UserMother::DEFAULT_EMAIL),
                new BadCredentialsException('wrong'),
            );
        } catch (AuthenticationException) {
            // the uniform 401 is asserted elsewhere; here we only care that the attempt was recorded
        }

        $this->assertSame([$user], $repository->saved);
    }

    public function testDoesNotRecordAThrottledAttemptAgainstThePersistentCounter(): void
    {
        // A throttled failure never reached a credential check, so counting it would let a single IP drive the
        // per-identity lock the per-IP throttle exists to stop short of. The record must be skipped.
        $user = UserMother::create();
        $repository = new InMemoryUserRepository($user);

        try {
            $this->handler($repository)->onAuthenticationFailure(
                $this->loginRequest(UserMother::DEFAULT_EMAIL),
                new TooManyLoginAttemptsAuthenticationException(),
            );
        } catch (AuthenticationException) {
            // the uniform 401 is asserted elsewhere; here we only care that nothing was recorded
        }

        $this->assertSame([], $repository->saved);
    }

    public function testAbsorbsAStoreFaultWhileRecordingSoTheUniform401IsNotLeakedAsA500(): void
    {
        // A store fault while persisting the failed attempt must be swallowed: the response stays the uniform
        // 401 for a resolved email exactly as for an unknown one — never a leaked DBALException (→ 500) that
        // would both regress the neutral failure path and distinguish a real account on the wire.
        $caught = null;

        try {
            $this->handlerFailingToPersist()->onAuthenticationFailure(
                $this->loginRequest(UserMother::DEFAULT_EMAIL),
                new BadCredentialsException('wrong'),
            );
        } catch (Throwable $throwable) {
            $caught = $throwable;
        }

        $this->assertInstanceOf(AuthenticationException::class, $caught);
        $this->assertSame('Invalid credentials.', $caught->getMessage());
    }
}
