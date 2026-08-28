<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Infrastructure\Security;

use Erpify\Iam\Session\Application\CurrentSessionReference;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\Exception\SessionNoLongerActive;
use Erpify\Iam\Session\Domain\Exception\SessionStoreUnavailable;
use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Iam\Session\Infrastructure\Security\AdmittedSession;
use Erpify\Iam\Session\Infrastructure\Security\AdmittedSessionProvider;
use Erpify\Iam\Session\Infrastructure\Security\SessionAdmissionGate;
use Erpify\Tests\Unit\Iam\Session\Domain\Entity\Mother\SessionMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;

/**
 * The resolution both `/sessions` controllers delegate, and the first coverage its fallback arm has ever had.
 *
 * The arm matters more than its size suggests: no acceptance scenario can reach it, because Behat always
 * drives a main `/api` request with a full-fledged token, which is exactly the condition under which the gate
 * runs and publishes the attribute. So the repository read is unreachable from the outside, and a unit test
 * is the only instrument that can hold it.
 *
 * @internal
 */
#[CoversClass(AdmittedSessionProvider::class)]
#[CoversClass(AdmittedSession::class)]
final class AdmittedSessionProviderTest extends TestCase
{
    private const string CORRELATED_ID = '0190c1d2-e3f4-7a5b-8c6d-1e2f3a4b5c6d';

    /**
     * Deliberately not the id of the session the request carries. Production cannot produce this pair — the
     * gate publishes the very row it loaded for the correlated id — and that is the point: it is the only
     * input on which "the id comes from the correlation" and "the id comes from the entity" disagree, so it
     * is the only input that can tell the two implementations apart.
     */
    private const string A_DIFFERENT_ID = '0190c1d2-e3f4-7a5b-8c6d-1e2f3a4b5c6e';

    public function testTheIdItAnswersWithComesFromTheCorrelationAndNeverFromTheEntity(): void
    {
        // Read through reflection rather than compared as two literals: PHPStan narrows a literal pair to
        // "always true" and the guard stops guarding, which is the shape it exists to prevent.
        $this->assertNotSame(
            self::A_DIFFERENT_ID,
            (new ReflectionClass(SessionMother::class))->getConstant('DEFAULT_ID'),
            'This case can only tell the two implementations apart while the carried session and the '
            . 'correlation disagree; equal values would make it assert that a thing equals itself.',
        );

        $carried = SessionMother::active(id: self::A_DIFFERENT_ID);
        $provider = $this->provider($this->correlation(self::CORRELATED_ID), $this->storeThatIsNeverAsked());

        $admitted = $provider->requireAdmitted($this->requestCarrying($carried));

        $this->assertSame(
            self::CORRELATED_ID,
            $admitted->id->toString(),
            'The correlation is the authority on which session this request is. Deriving the id from the '
            . 'entity would also reintroduce Uuid::ensure, and with it a 400 on a path that answers 401/503.',
        );
        $this->assertSame($carried, $admitted->session);
    }

    public function testItReadsThePublishedSessionWithoutAskingTheRepository(): void
    {
        $sessions = $this->createMock(SessionRepository::class);
        $sessions->expects($this->never())->method('findActiveById');
        $provider = $this->provider($this->correlation(self::CORRELATED_ID), $sessions);

        $provider->requireAdmitted($this->requestCarrying(SessionMother::active()));
    }

    public function testItFallsBackToTheRepositoryAskingForTheCallersOwnSession(): void
    {
        $stored = SessionMother::active();
        $sessions = $this->createMock(SessionRepository::class);
        $sessions->expects($this->once())
            ->method('findActiveById')
            ->with(SessionId::fromString(self::CORRELATED_ID))
            ->willReturn($stored)
        ;
        $provider = $this->provider($this->correlation(self::CORRELATED_ID), $sessions);

        $admitted = $provider->requireAdmitted($this->requestCarrying(null));

        $this->assertSame($stored, $admitted->session);
        $this->assertSame(self::CORRELATED_ID, $admitted->id->toString());
    }

    public function testItForcesReloginWhenThereIsNoCorrelationWithoutReachingTheStore(): void
    {
        $provider = $this->provider($this->correlation(null), $this->storeThatIsNeverAsked());

        $this->expectException(SessionNoLongerActive::class);

        $provider->requireAdmitted($this->requestCarrying(null));
    }

    public function testItForcesReloginWhenNeitherTheRequestNorTheStoreHoldsTheSession(): void
    {
        $sessions = $this->createStub(SessionRepository::class);
        $sessions->method('findActiveById')->willReturn(null);
        $provider = $this->provider($this->correlation(self::CORRELATED_ID), $sessions);

        $this->expectException(SessionNoLongerActive::class);

        $provider->requireAdmitted($this->requestCarrying(null));
    }

    /**
     * An unreachable store is a 503 that reaches Sentry, and the natural instinct when writing something
     * called a fallback is to wrap it. Swallowing it here would answer an outage with 401 `session-expired`
     * and delete the incident from the log.
     */
    public function testAnUnreachableStoreKeepsItsOwnFailureInsteadOfBecomingARelogin(): void
    {
        $sessions = $this->createStub(SessionRepository::class);
        $sessions->method('findActiveById')->willThrowException(SessionStoreUnavailable::storeUnreachable());
        $provider = $this->provider($this->correlation(self::CORRELATED_ID), $sessions);

        $this->expectException(SessionStoreUnavailable::class);

        $provider->requireAdmitted($this->requestCarrying(null));
    }

    /**
     * The provider is a container service in a worker-mode runtime, so it outlives the request and a session
     * remembered on it would be handed to the next one. `readonly` denies it the property that would hold
     * that, and this keeps the denial from being dropped in a later diff.
     *
     * It is a floor and not a proof: a `static` inside the method body is still legal in a `readonly` class
     * and nothing here sees it. The pair is held to the same bar for a different reason — it is a value
     * handed to a caller, and a caller must not be able to rewrite the session this request was admitted
     * with.
     */
    public function testBothTypesAreReadonlySoNoRequestStateCanOutliveItsRequest(): void
    {
        foreach ([AdmittedSessionProvider::class, AdmittedSession::class] as $class) {
            $this->assertTrue(
                (new ReflectionClass($class))->isReadOnly(),
                \sprintf('%s must stay readonly; see this case for what that does and does not cover.', $class),
            );
        }
    }

    private function provider(
        CurrentSessionReference $currentSession,
        SessionRepository $sessions,
    ): AdmittedSessionProvider {
        return new AdmittedSessionProvider($currentSession, $sessions);
    }

    private function correlation(?string $sessionId): CurrentSessionReference
    {
        $currentSession = $this->createStub(CurrentSessionReference::class);
        $currentSession->method('get')->willReturn(null === $sessionId ? null : SessionId::fromString($sessionId));

        return $currentSession;
    }

    private function storeThatIsNeverAsked(): SessionRepository
    {
        $sessions = $this->createMock(SessionRepository::class);
        $sessions->expects($this->never())->method('findActiveById');

        return $sessions;
    }

    private function requestCarrying(?Session $session): Request
    {
        $request = Request::create('/api/v1/sessions');

        if ($session instanceof Session) {
            $request->attributes->set(SessionAdmissionGate::ADMITTED_SESSION_ATTRIBUTE, $session);
        }

        return $request;
    }
}
