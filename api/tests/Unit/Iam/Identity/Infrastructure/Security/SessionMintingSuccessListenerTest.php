<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Doctrine\DBAL\Exception as DbalException;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Iam\Identity\Infrastructure\Security\SessionMintingSuccessListener;
use Erpify\Iam\Session\Application\StartSession;
use Erpify\Iam\Session\Domain\Exception\SessionAdmissionUnresolvable;
use Erpify\Iam\Session\Domain\Exception\SessionStoreUnavailable;
use Erpify\Iam\Session\Domain\Exception\SessionSubjectNotAdmissible;
use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Organization\Membership\Application\FindUserOrganizationId;
use Erpify\Organization\Membership\Domain\Entity\Membership;
use Erpify\Organization\Membership\Domain\Exception\MembershipCardinalityViolated;
use Erpify\Organization\Membership\Domain\Repository\MembershipRepository;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Double\Clock\FixedClock;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Iam\Session\Application\InlineTransactionManager;
use Erpify\Tests\Unit\Iam\Session\Application\InMemorySessionRepository;
use Erpify\Tests\Unit\Iam\Session\Application\RecordingCurrentSessionReference;
use Erpify\Tests\Unit\Iam\Session\Application\RecordingEventBus;
use Erpify\Tests\Unit\Iam\Session\Application\RecordingSessionSubjectLock;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Throwable;

/**
 * Every failure of the minting drops the native session — the firewall would otherwise replay its token as an
 * admitted identity — and no `iam_session` row is written. What differs is the ANSWER, one per cause: a store
 * outage is the gate's 503, an identity with no organization is its own 500, and everything else propagates
 * exactly as raised.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(SessionMintingSuccessListener::class)]
final class SessionMintingSuccessListenerTest extends TestCase
{
    /**
     * The key the firewall serialises the authenticated token under.
     */
    private const string TOKEN_KEY = '_security_main';

    private InMemorySessionRepository $sessions;

    private RecordingSessionSubjectLock $subjectLock;

    private Session $nativeSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessions = new InMemorySessionRepository();
        $this->subjectLock = new RecordingSessionSubjectLock();
        $this->nativeSession = new Session(new MockArraySessionStorage());
        $this->nativeSession->set(self::TOKEN_KEY, 'serialized-authenticated-token');
    }

    public function testMintsTheSessionInTheOrganizationTheMembershipNames(): void
    {
        $organizationId = Uuid::generate();
        $memberships = $this->createStub(MembershipRepository::class);
        $memberships->method('findByUserId')->willReturn(
            Membership::grant(Uuid::generate(), UserMother::DEFAULT_ID, $organizationId),
        );

        $this->listener($memberships)($this->loginSuccess());

        $this->assertCount(1, $this->sessions->saved);
        $this->assertSame($organizationId, $this->sessions->saved[0]->organizationId());
        $this->assertTrue($this->nativeSession->has(self::TOKEN_KEY));
    }

    public function testAMembershipStoreOutageIsTheGates503(): void
    {
        $outage = $this->createStub(DbalException::class);
        $memberships = $this->createStub(MembershipRepository::class);
        $memberships->method('findByUserId')->willThrowException($outage);

        $thrown = $this->failingLogin($memberships);

        $this->assertInstanceOf(SessionStoreUnavailable::class, $thrown);
        $this->assertSame('service-unavailable', $thrown->type());
        $this->assertSame($outage, $thrown->getPrevious());
        $this->assertFailedClosed();
    }

    /**
     * Permanent rather than transient: a 503 would invite a retry that meets the same gap and send the
     * operator to a store that is up.
     */
    public function testAnIdentityWithNoMembershipIsUnresolvableRatherThanAnOutage(): void
    {
        $memberships = $this->createStub(MembershipRepository::class);
        $memberships->method('findByUserId')->willReturn(null);

        $thrown = $this->failingLogin($memberships);

        $this->assertInstanceOf(SessionAdmissionUnresolvable::class, $thrown);
        $this->assertSame('session-admission-unresolvable', $thrown->type());
        $this->assertSame([], $thrown->context(), 'the identity is a person, so no id travels');
        $this->assertFailedClosed();
    }

    /** The session store's own 503 is already the right answer, so it is not wrapped a second time. */
    public function testTheSessionStoresOwnUnavailabilityPropagatesAsRaised(): void
    {
        $outage = SessionStoreUnavailable::storeUnreachable();
        $store = $this->createStub(SessionRepository::class);
        $store->method('save')->willThrowException($outage);

        $this->assertSame($outage, $this->failingLogin($this->memberOfAnOrganization(), $store));
        $this->assertFailedClosed();
    }

    /**
     * An identity suspended or erased while the login was in flight is refused by the mint itself, under the
     * identity's row lock, and that 401 reaches the client as raised.
     */
    public function testAnIdentityNoLongerAdmissibleAtTheMintIsRefusedAsRaised(): void
    {
        $this->subjectLock->live = false;

        $thrown = $this->failingLogin($this->memberOfAnOrganization());

        $this->assertInstanceOf(SessionSubjectNotAdmissible::class, $thrown);
        $this->assertFailedClosed();
    }

    #[DataProvider('provideAnUnexpectedFailurePropagatesUntouchedCases')]
    public function testAnUnexpectedFailurePropagatesUntouched(Throwable $failure): void
    {
        $memberships = $this->createStub(MembershipRepository::class);
        $memberships->method('findByUserId')->willThrowException($failure);

        $this->assertSame($failure, $this->failingLogin($memberships));
        $this->assertFailedClosed();
    }

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function provideAnUnexpectedFailurePropagatesUntouchedCases(): iterable
    {
        yield 'a programming error' => [new LogicException('a bug in the minting')];
        yield 'a broken membership invariant' => [MembershipCardinalityViolated::severalForOneUser()];
    }

    private function failingLogin(MembershipRepository $memberships, ?SessionRepository $store = null): Throwable
    {
        try {
            $this->listener($memberships, $store)($this->loginSuccess());
        } catch (Throwable $throwable) {
            return $throwable;
        }

        $this->fail('Expected the minting to fail.');
    }

    private function assertFailedClosed(): void
    {
        $this->assertFalse(
            $this->nativeSession->has(self::TOKEN_KEY),
            'a failed minting must not answer with a session the firewall would replay as an admitted identity',
        );
        $this->assertSame([], $this->sessions->saved, 'no registry row may exist for a login that failed');
    }

    private function memberOfAnOrganization(): MembershipRepository
    {
        $memberships = $this->createStub(MembershipRepository::class);
        $memberships->method('findByUserId')->willReturn(
            Membership::grant(Uuid::generate(), UserMother::DEFAULT_ID, Uuid::generate()),
        );

        return $memberships;
    }

    private function listener(
        MembershipRepository $memberships,
        ?SessionRepository $store = null,
    ): SessionMintingSuccessListener {
        return new SessionMintingSuccessListener(
            new StartSession(
                $store ?? $this->sessions,
                new RecordingCurrentSessionReference(),
                new RecordingEventBus(),
                new InlineTransactionManager(),
                new FixedClock(SystemClock::now()),
                $this->subjectLock,
            ),
            new FindUserOrganizationId($memberships),
        );
    }

    private function loginSuccess(): LoginSuccessEvent
    {
        $request = new Request();
        $request->setSession($this->nativeSession);

        $event = $this->createStub(LoginSuccessEvent::class);
        $event->method('getUser')->willReturn(new SecurityUser(UserMother::create()));
        $event->method('getRequest')->willReturn($request);

        return $event;
    }
}
