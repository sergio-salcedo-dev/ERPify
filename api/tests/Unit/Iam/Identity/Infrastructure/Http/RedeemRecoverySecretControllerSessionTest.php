<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Http;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\KeepOnlyCurrentSession;
use Erpify\Iam\Identity\Application\RecordRecoverySecretAuditBestEffort;
use Erpify\Iam\Identity\Application\RedeemRecoverySecret;
use Erpify\Iam\Identity\Application\RevokeCurrentSessionBestEffort;
use Erpify\Iam\Identity\Domain\Entity\RecoverySecret;
use Erpify\Iam\Identity\Infrastructure\Http\RedeemRecoverySecretController;
use Erpify\Iam\Identity\Infrastructure\Http\RedeemRecoverySecretRequest;
use Erpify\Iam\Identity\Infrastructure\Security\PasswordRecoveryThrottle;
use Erpify\Iam\Identity\Infrastructure\Security\ReauthenticateDevice;
use Erpify\Iam\Session\Application\EvictOtherSessions;
use Erpify\Iam\Session\Application\RevokeSession;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Persistence\Domain\Exception\TransientTransactionFailure;
use Erpify\Tests\Double\Clock\FixedClock;
use Erpify\Tests\Unit\Iam\Identity\Application\InlineTransactionManager;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryRecoverySecretRepository;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryUserRepository;
use Erpify\Tests\Unit\Iam\Identity\Application\RecordingEventBus;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Iam\Session\Application\InMemorySessionRepository;
use Erpify\Tests\Unit\Iam\Session\Application\RecordingCurrentSessionReference;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session as NativeSession;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;

/**
 * What the endpoint does to the DEVICE's native session when a redemption is interrupted.
 *
 * The login has already written this device's token and the correlation of the session it minted into the
 * native session. When another session of the identity revokes that one before the consuming transaction
 * takes the lock, the redemption answers a retryable 503 — and a retry carrying the dead correlation would meet
 * the admission gate's 401 before the route ever ran. So the native session is dropped on that cause, and only
 * on that one: a deadlock answering the same 503 leaves the minted session alive.
 *
 * The login seam is the real {@see Security} over a minimal container, whose authenticator manager does what
 * the minting listener does — persist a session row and stash its id — because the property here is about
 * what happens after the login, not inside it.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") — the endpoint is woven for real, as its sibling test does,
 * because the property is the composition of the use case's outcome and the adapter's reaction to it.
 */
#[CoversClass(RedeemRecoverySecretController::class)]
final class RedeemRecoverySecretControllerSessionTest extends TestCase
{
    private const string NOW = '2026-08-28T12:00:00+00:00';

    private const string ORGANIZATION_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4001';

    private InMemorySessionRepository $sessions;

    private RecordingCurrentSessionReference $correlation;

    #[Override]
    protected function setUp(): void
    {
        SystemClock::set(FixedClock::at(self::NOW));
        $this->sessions = new InMemorySessionRepository();
        $this->correlation = new RecordingCurrentSessionReference();
    }

    protected function tearDown(): void
    {
        SystemClock::reset();
        parent::tearDown();
    }

    #[Test]
    public function anInterruptedRedemptionDropsTheDevicesNativeSessionSoTheRetryIsNotRefusedByTheGate(): void
    {
        $secrets = new InMemoryRecoverySecretRepository();
        $plaintext = $this->mintFor($secrets);
        $stolen = $this->persistSession();
        $sessions = $this->sessions;
        $sessions->beforeLockActive = static function () use ($sessions, $stolen): void {
            $sessions->revokeOthersForUser(UserMother::DEFAULT_ID, $stolen);
        };
        $request = $this->requestWithNativeSession();

        try {
            $this->endpoint($secrets)(new RedeemRecoverySecretRequest($plaintext), $request);
            $this->fail('Expected the interrupted redemption to answer the retryable 503.');
        } catch (TransientTransactionFailure) {
            // the 503 the retry follows
        }

        $this->assertFalse(
            $request->getSession()->has('_security_main'),
            'the device still carries the token of a session another device revoked, so its retry meets a 401',
        );
    }

    #[Test]
    public function aDeadlockAnsweringTheSame503KeepsTheSessionThisRequestEstablished(): void
    {
        $secrets = new InMemoryRecoverySecretRepository();
        $plaintext = $this->mintFor($secrets);
        $this->sessions->beforeLockActive = static fn (): never => throw new TransientTransactionFailure(
            new RuntimeException('deadlock detected'),
        );
        $request = $this->requestWithNativeSession();

        try {
            $this->endpoint($secrets)(new RedeemRecoverySecretRequest($plaintext), $request);
            $this->fail('Expected the deadlock to answer the retryable 503.');
        } catch (TransientTransactionFailure) {
            // the same 503, from a cause that leaves the minted session alive
        }

        $this->assertTrue(
            $request->getSession()->has('_security_main'),
            'a deadlock signed the device out of a session that is still alive',
        );
    }

    private function requestWithNativeSession(): Request
    {
        $native = new NativeSession(new MockArraySessionStorage());
        $native->set('_security_main', 'the token the login wrote');

        $request = new Request();
        $request->setSession($native);

        return $request;
    }

    private function mintFor(InMemoryRecoverySecretRepository $secrets): string
    {
        $generated = RecoverySecret::mint(UserMother::DEFAULT_ID, new DateTimeImmutable(self::NOW));
        $generated->secret->pullDomainEvents();

        $secrets->save($generated->secret);

        return $generated->plaintext();
    }

    private function persistSession(): SessionId
    {
        $id = SessionId::generate();
        $session = Session::start(
            $id->toString(),
            UserMother::DEFAULT_ID,
            self::ORGANIZATION_ID,
            'test-device',
            null,
            (new DateTimeImmutable(self::NOW))->modify('+7 days'),
        );
        $session->pullDomainEvents();

        $this->sessions->save($session);

        return $id;
    }

    private function endpoint(InMemoryRecoverySecretRepository $secrets): RedeemRecoverySecretController
    {
        $useCase = new RedeemRecoverySecret(
            new InMemoryUserRepository(UserMother::create()),
            $secrets,
            new RecordRecoverySecretAuditBestEffort(new RecordingAuditLogger(), new NullLogger()),
            new RevokeCurrentSessionBestEffort(
                $this->correlation,
                new RevokeSession($this->sessions, new RecordingEventBus(), new InlineTransactionManager()),
                new NullLogger(),
            ),
            new KeepOnlyCurrentSession(
                $this->correlation,
                new EvictOtherSessions($this->sessions, new RecordingEventBus(), FixedClock::at(self::NOW)),
            ),
            new RecordingEventBus(),
            new InlineTransactionManager(),
            FixedClock::at(self::NOW),
        );

        $unbounded = static fn (string $id): RateLimiterFactory => new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => 99, 'interval' => '15 minutes'],
            new InMemoryStorage(),
        );

        return new RedeemRecoverySecretController(
            $useCase,
            new ReauthenticateDevice(
                new InMemoryUserRepository(UserMother::create()),
                $this->mintingSecurity(),
            ),
            new PasswordRecoveryThrottle($unbounded('per_email'), $unbounded('per_selector')),
        );
    }

    /**
     * The real {@see Security}, over a container holding only what `login()` reaches: the request, a firewall
     * map, the user checker and an authenticator manager whose `authenticateUser()` does what the minting
     * listener does — persist the row and stash its id. That last one is the whole of the stand-in.
     */
    private function mintingSecurity(): Security
    {
        $requestStack = new RequestStack([new Request()]);

        $manager = new MintingUserAuthenticator(fn (): SessionId => $this->persistSession(), $this->correlation);

        $container = new Container();
        $container->set('request_stack', $requestStack);
        $container->set('security.firewall.map', $this->createStub(FirewallMap::class));
        $container->set('security.user_checker_locator', new ServiceLocator([
            'main' => fn (): UserCheckerInterface => $this->createStub(UserCheckerInterface::class),
        ]));
        $container->set('security.authenticator.managers_locator', new ServiceLocator([
            'main' => static fn (): UserAuthenticatorInterface => $manager,
        ]));

        return new Security($container, [
            'main' => new ServiceLocator([
                'security.authenticator.json_login.main' => fn (): AuthenticatorInterface => $this->createStub(
                    AuthenticatorInterface::class,
                ),
            ]),
        ]);
    }
}
