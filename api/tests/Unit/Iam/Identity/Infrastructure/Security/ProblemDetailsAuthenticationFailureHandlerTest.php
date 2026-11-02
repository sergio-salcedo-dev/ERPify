<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Infrastructure\Security\ProblemDetailsAuthenticationFailureHandler;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryUserRepository;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;

/**
 * @internal
 */
#[CoversClass(ProblemDetailsAuthenticationFailureHandler::class)]
final class ProblemDetailsAuthenticationFailureHandlerTest extends TestCase
{
    use BuildsFailureHandler;

    /**
     * Throwing here would preempt `LoginFailureEvent`, which the authenticator dispatches only once this handler
     * has returned — and with it the throttle, which spends its budget on that event. The handler must return.
     */
    public function testReturnsSoTheAuthenticatorGoesOnToDispatchTheFailureEvent(): void
    {
        $response = $this->handler(new InMemoryUserRepository())->onAuthenticationFailure(
            $this->loginRequest(UserMother::DEFAULT_EMAIL),
            new BadCredentialsException('The presented password is invalid.'),
        );

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
    }

    public function testRecordsTheFailedAttemptAgainstTheSubmittedIdentity(): void
    {
        $user = UserMother::create();
        $repository = new InMemoryUserRepository($user);

        $this->handler($repository)->onAuthenticationFailure(
            $this->loginRequest(UserMother::DEFAULT_EMAIL),
            new BadCredentialsException('wrong'),
        );

        $this->assertSame([$user], $repository->saved);
    }

    public function testDoesNotRecordAThrottledAttemptAgainstThePersistentCounter(): void
    {
        // A throttled failure never reached a credential check, so counting it would let a single IP drive the
        // per-identity lock the per-IP throttle exists to stop short of. The record must be skipped.
        $user = UserMother::create();
        $repository = new InMemoryUserRepository($user);

        $this->handler($repository)->onAuthenticationFailure(
            $this->loginRequest(UserMother::DEFAULT_EMAIL),
            new TooManyLoginAttemptsAuthenticationException(),
        );

        $this->assertSame([], $repository->saved);
    }

    public function testAbsorbsAStoreFaultWhileRecordingSoTheUniform401IsNotLeakedAsA500(): void
    {
        // A store fault while persisting the failed attempt must be swallowed: a resolved email must end exactly
        // as an unknown one does — never a leaked DBALException (→ 500) that would both regress the neutral
        // failure path and distinguish a real account on the wire.
        $response = $this->handlerFailingToPersist()->onAuthenticationFailure(
            $this->loginRequest(UserMother::DEFAULT_EMAIL),
            new BadCredentialsException('wrong'),
        );

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }
}
