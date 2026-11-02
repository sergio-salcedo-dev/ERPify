<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Infrastructure\Security\DeactivatedAccountException;
use Erpify\Iam\Identity\Infrastructure\Security\LockedAccountException;
use Erpify\Iam\Identity\Infrastructure\Security\SuspendedAccountException;
use Erpify\Iam\Identity\Infrastructure\Security\UniformLoginFailureListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\EventListener\LoginThrottlingListener;
use Symfony\Component\Security\Http\EventListener\RememberMeListener;
use Throwable;

/**
 * @internal
 */
#[CoversClass(UniformLoginFailureListener::class)]
final class UniformLoginFailureListenerTest extends TestCase
{
    public function testNormalisesTheFailureToOneMessageAndKeepsTheRealCauseAsPrevious(): void
    {
        // Symfony's own message ("The presented password is invalid.") would reveal the email exists; the
        // listener must throw one constant message the 401 arm surfaces verbatim, while the real cause survives
        // as `previous` so the dev/test debug extension can still show what actually failed.
        $cause = new BadCredentialsException('The presented password is invalid.');

        $caught = $this->failureOf($cause);

        $this->assertInstanceOf(CustomUserMessageAuthenticationException::class, $caught);
        $this->assertSame(UniformLoginFailureListener::GENERIC_FAILURE_MESSAGE, $caught->getMessage());
        $this->assertSame('Invalid credentials.', $caught->getMessage());
        $this->assertSame($cause, $caught->getPrevious());
    }

    /**
     * Every admission refusal is raised before the password is verified, and a throttled attempt before the
     * identity is even resolved, so answering one differently from a wrong password would tell a guesser the
     * password was right or the budget was spent. The authenticator already re-wraps the refusals into a
     * `BadCredentialsException`; the listener holds the line even for one that reaches it unwrapped.
     */
    #[DataProvider('provideEveryFailureIsTheUniformUnauthorisedAnswerCases')]
    public function testEveryFailureIsTheUniformUnauthorisedAnswer(AuthenticationException $failure): void
    {
        $caught = $this->failureOf($failure);

        $this->assertInstanceOf(CustomUserMessageAuthenticationException::class, $caught);
        $this->assertSame(UniformLoginFailureListener::GENERIC_FAILURE_MESSAGE, $caught->getMessage());
    }

    /**
     * @return iterable<string, array{AuthenticationException}>
     */
    public static function provideEveryFailureIsTheUniformUnauthorisedAnswerCases(): iterable
    {
        yield 'suspended' => [new SuspendedAccountException()];

        yield 'deactivated' => [new DeactivatedAccountException()];

        yield 'locked' => [new LockedAccountException()];

        yield 'throttled' => [new TooManyLoginAttemptsAuthenticationException()];
    }

    /**
     * Throwing ends the dispatch, so every listener Symfony registers on the event must run first — the
     * throttle spends its budget there, and remember-me clears its cookie there.
     */
    public function testRunsAfterEveryFrameworkListenerOfTheEvent(): void
    {
        $this->assertLessThan(
            $this->priorityOf(LoginThrottlingListener::getSubscribedEvents()),
            UniformLoginFailureListener::PRIORITY,
        );
        $this->assertLessThan(
            $this->priorityOf(RememberMeListener::getSubscribedEvents()),
            UniformLoginFailureListener::PRIORITY,
        );
    }

    private function failureOf(AuthenticationException $exception): Throwable
    {
        $event = new LoginFailureEvent(
            $exception,
            $this->createStub(AuthenticatorInterface::class),
            Request::create('/login', Request::METHOD_POST),
            new Response(status: Response::HTTP_UNAUTHORIZED),
            'main',
        );

        try {
            (new UniformLoginFailureListener())($event);
        } catch (Throwable $throwable) {
            return $throwable;
        }
    }

    /**
     * @param array<string, mixed> $subscribed
     */
    private function priorityOf(array $subscribed): int
    {
        $entry = $subscribed[LoginFailureEvent::class] ?? null;

        if (\is_string($entry)) {
            return 0;
        }

        $this->assertIsArray($entry);
        $priority = $entry[1] ?? 0;
        $this->assertIsInt($priority);

        return $priority;
    }
}
