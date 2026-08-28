<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use DateTimeImmutable;
use Doctrine\DBAL\Exception as DbalException;
use Erpify\Iam\Identity\Application\LoginAttemptRegistrar;
use Erpify\Iam\Identity\Application\RecordLockoutAuditBestEffort;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Exception\LockoutStoreUnavailable;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Iam\Identity\Infrastructure\Security\ClearLockoutOnLoginSuccess;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Tests\Unit\Iam\Identity\Application\FixedClock;
use Erpify\Tests\Unit\Iam\Identity\Application\InlineTransactionManager;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryUserRepository;
use Erpify\Tests\Unit\Iam\Identity\Application\RecordingEventBus;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Exercises the listener over its real object graph — the {@see LoginAttemptRegistrar}, its four collaborators
 * and the login-success event — which is inherently what makes it broadly coupled: the fakes are what let the
 * store-failure (503) path be driven at all.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(ClearLockoutOnLoginSuccess::class)]
final class ClearLockoutOnLoginSuccessTest extends TestCase
{
    private const string NOW = '2026-07-11T12:00:00+00:00';

    /**
     * The key the firewall serialises the authenticated token under; holding it is what makes a session "logged
     * in" to the next request.
     */
    private const string TOKEN_KEY = '_security_main';

    public function testClearsTheLockoutForTheAdmittedIdentity(): void
    {
        $user = $this->lockedUser();
        $repository = new InMemoryUserRepository($user);

        $this->listener($repository)($this->loginSuccessFor(new SecurityUser($user)));

        $this->assertContains($user, $repository->saved);
        $this->assertFalse($user->isLockedAt(new DateTimeImmutable(self::NOW)));
    }

    public function testAStoreFailureWhileClearingBecomesARetryable503AndDropsTheSession(): void
    {
        $user = $this->lockedUser();
        $repository = $this->createStub(UserRepository::class);
        $repository->method('findById')->willReturn($user);
        $repository->method('save')->willThrowException($this->createStub(DbalException::class));

        $session = new Session(new MockArraySessionStorage());
        $session->set(self::TOKEN_KEY, 'serialized-authenticated-token');

        $request = new Request();
        $request->setSession($session);

        $thrown = null;

        try {
            $this->listener($repository)($this->loginSuccessFor(new SecurityUser($user), $request));
        } catch (LockoutStoreUnavailable $lockoutStoreUnavailable) {
            $thrown = $lockoutStoreUnavailable;
        }

        $this->assertInstanceOf(LockoutStoreUnavailable::class, $thrown);
        $this->assertFalse(
            $session->has(self::TOKEN_KEY),
            'A 503 must not answer with a session the firewall would replay as an admitted identity.',
        );
    }

    public function testIgnoresAnUnexpectedAuthenticatedUserType(): void
    {
        $repository = new InMemoryUserRepository(UserMother::create());

        $this->listener($repository)($this->loginSuccessFor($this->createStub(UserInterface::class)));

        $this->assertSame([], $repository->saved);
    }

    private function listener(UserRepository $repository): ClearLockoutOnLoginSuccess
    {
        $registrar = new LoginAttemptRegistrar(
            $repository,
            new RecordingEventBus(),
            new InlineTransactionManager(),
            new FixedClock(new DateTimeImmutable(self::NOW)),
            new RecordLockoutAuditBestEffort(new RecordingAuditLogger(), new NullLogger()),
        );

        return new ClearLockoutOnLoginSuccess($registrar);
    }

    private function loginSuccessFor(UserInterface $user, ?Request $request = null): LoginSuccessEvent
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $event = $this->createStub(LoginSuccessEvent::class);
        $event->method('getAuthenticatedToken')->willReturn($token);
        $event->method('getRequest')->willReturn($request ?? new Request());

        return $event;
    }

    private function lockedUser(): User
    {
        $user = UserMother::create();
        $now = new DateTimeImmutable(self::NOW);

        for ($attempt = 0; $attempt < User::MAX_FAILED_ATTEMPTS; ++$attempt) {
            $user->recordFailedAttempt($now);
        }

        $user->pullDomainEvents();

        return $user;
    }
}
