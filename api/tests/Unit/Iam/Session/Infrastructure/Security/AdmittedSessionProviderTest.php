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

    private const string A_SUBJECT_ID = '0190c1d2-e3f4-7a5b-8c6d-1e2f3a4b5c6a';

    public function testTheIdItAnswersWithComesFromTheCorrelationAndNeverFromTheEntity(): void
    {
        $this->assertTheseDisagree(
            'A_DIFFERENT_ID',
            'CORRELATED_ID',
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

    /**
     * The subject comes off the CARRIED session: the correlation is an authority on which session this is,
     * never on whose it is, so an implementation deriving the subject from the id reads the wrong member.
     *
     * What this does NOT pin is the Tell-Don't-Ask half its docblock elsewhere claims — `$pair->userId()`
     * and `$pair->session->userId()` are observably identical, so no test can separate them and nothing
     * here pretends to.
     */
    public function testTheSubjectItDelegatesIsTheCarriedSessionsOwn(): void
    {
        $this->assertTheseDisagree(
            'A_SUBJECT_ID',
            'CORRELATED_ID',
            'The subject and the correlation must differ, or an implementation deriving the subject from '
            . 'the id passes this case too.',
        );

        $carried = SessionMother::active(userId: self::A_SUBJECT_ID);
        $provider = $this->provider($this->correlation(self::CORRELATED_ID), $this->storeThatIsNeverAsked());

        $admitted = $provider->requireAdmitted($this->requestCarrying($carried));

        $this->assertSame(self::A_SUBJECT_ID, $admitted->userId());
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

    /**
     * Two of this class's own constants, asserted to still differ.
     *
     * Read through reflection because PHPStan narrows a pair of literals to "always true" and refuses the
     * assertion, which is how a guard against vacuity becomes vacuous itself. Reflection also decides the
     * SUBJECT of the comparison: the values a case's discriminating power rests on are the ones it uses, so
     * comparing against some other class's constant is only ever accidentally equivalent — measured here,
     * `SessionMother::DEFAULT_ID` and `CORRELATED_ID` are the same literal in two files with no link
     * between them, and a guard reading the first would have gone on passing while the pair it was meant to
     * separate collapsed.
     *
     * `getConstant()` answers `false` for a name that no longer exists, and `assertNotSame` over `false`
     * passes — so the read is asserted to have found something before anything is compared. Without that
     * the guard fails OPEN on a rename, which is the direction that costs.
     */
    private function assertTheseDisagree(string $first, string $second, string $because): void
    {
        $constants = (new ReflectionClass(self::class))->getConstants();

        $this->assertArrayHasKey($first, $constants, \sprintf('%s is no longer declared here.', $first));
        $this->assertArrayHasKey($second, $constants, \sprintf('%s is no longer declared here.', $second));
        $this->assertNotSame($constants[$first], $constants[$second], $because);
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
