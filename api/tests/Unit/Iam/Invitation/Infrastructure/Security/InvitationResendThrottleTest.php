<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Invitation\Infrastructure\Security;

use Erpify\Iam\Invitation\Infrastructure\Security\InvitationResendThrottle;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\ErrorContract\Domain\Exception\RateLimitExceeded;
use Erpify\Shared\ErrorContract\Infrastructure\Http\RateLimitSnapshot;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\FixedActorContextFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/**
 * @internal
 */
#[CoversClass(InvitationResendThrottle::class)]
final class InvitationResendThrottleTest extends TestCase
{
    private const string INVITEE_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a70';

    private const string ADMIN_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a66';

    private const string OTHER_ADMIN_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a67';

    private const int UNBOUNDED = 100;

    private InMemoryStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new InMemoryStorage();
    }

    #[Test]
    public function itAdmitsResendsWhileTheInviteeStillHasBudget(): void
    {
        $throttle = $this->throttle(sharedLimit: 2);

        $throttle->ensureWithinBudget(self::INVITEE_ID);
        $throttle->ensureWithinBudget(self::INVITEE_ID);

        $this->expectException(RateLimitExceeded::class);
        $throttle->ensureWithinBudget(self::INVITEE_ID);
    }

    /**
     * The column is a Postgres `uuid`, so every spelling of the id names one person. A key that kept the spelling
     * would give that person a bucket per spelling — thousands for a v7 id — and the limit would stop being one.
     */
    #[Test]
    public function everySpellingOfOneInviteeSpendsTheSameBucket(): void
    {
        $throttle = $this->throttle(sharedLimit: 1);
        $throttle->ensureWithinBudget(self::INVITEE_ID);

        $this->expectException(RateLimitExceeded::class);
        $throttle->ensureWithinBudget(\strtoupper(self::INVITEE_ID));
    }

    #[Test]
    public function anotherInviteeKeepsItsOwnBudget(): void
    {
        $throttle = $this->throttle(sharedLimit: 1);
        $throttle->ensureWithinBudget(self::INVITEE_ID);

        // No exception: draining one person's bucket cannot block the resend of somebody else's invitation.
        $throttle->ensureWithinBudget('0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a71');

        $this->expectException(RateLimitExceeded::class);
        $throttle->ensureWithinBudget(self::INVITEE_ID);
    }

    /**
     * The share is what stops one session — stolen, or retrying through a mail outage — from draining the shared
     * bucket and holding every other administrator's resend of that person shut.
     */
    #[Test]
    public function oneCallerOverItsShareLeavesTheSharedBudgetToTheOthers(): void
    {
        $admin = $this->throttle(sharedLimit: 3, actorLimit: 1, actor: self::ADMIN_ID);
        $otherAdmin = $this->throttle(sharedLimit: 3, actorLimit: self::UNBOUNDED, actor: self::OTHER_ADMIN_ID);

        $admin->ensureWithinBudget(self::INVITEE_ID);

        try {
            $admin->ensureWithinBudget(self::INVITEE_ID);
            $this->fail('A caller over its share must be refused.');
        } catch (RateLimitExceeded) {
            // Asserted below: the refusal spent nothing of the shared bucket.
        }

        // Two of the three shared slots are still there for another administrator, then the shared bucket says no.
        $otherAdmin->ensureWithinBudget(self::INVITEE_ID);
        $otherAdmin->ensureWithinBudget(self::INVITEE_ID);

        $this->expectException(RateLimitExceeded::class);
        $otherAdmin->ensureWithinBudget(self::INVITEE_ID);
    }

    #[Test]
    public function theCallersShareIsPerInvitee(): void
    {
        $throttle = $this->throttle(sharedLimit: self::UNBOUNDED, actorLimit: 1);
        $throttle->ensureWithinBudget(self::INVITEE_ID);

        // No exception: spending its share on one person leaves the caller its share on another.
        $throttle->ensureWithinBudget('0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a71');

        $this->expectException(RateLimitExceeded::class);
        $throttle->ensureWithinBudget(\strtoupper(self::INVITEE_ID));
    }

    /**
     * The limiter serialises its key into the bucket it stores, and that store is a cache no erasure reaches, so
     * no key may BE a person's id in any spelling; each must still be one stable key per person, or pair.
     */
    #[Test]
    public function theBucketKeysNameNobodyAndAreStablePerPerson(): void
    {
        $throttle = $this->throttle(sharedLimit: 1);
        $actor = ActorContext::forUser(self::ADMIN_ID);
        $key = $throttle->keyOf(self::INVITEE_ID);
        $actorKey = $throttle->actorKeyOf($actor, self::INVITEE_ID);

        foreach ([$key, $actorKey] as $candidate) {
            $this->assertStringNotContainsStringIgnoringCase(self::INVITEE_ID, $candidate);
            $this->assertStringNotContainsStringIgnoringCase(self::ADMIN_ID, $candidate);
        }

        $this->assertSame($key, $throttle->keyOf(\strtoupper(self::INVITEE_ID)));
        $this->assertSame(
            $actorKey,
            $throttle->actorKeyOf(ActorContext::forUser(\strtoupper(self::ADMIN_ID)), \strtoupper(self::INVITEE_ID)),
        );
        $this->assertNotSame(
            $actorKey,
            $throttle->actorKeyOf(ActorContext::forUser(self::OTHER_ADMIN_ID), self::INVITEE_ID),
        );
        $this->assertNotSame($key, $this->throttle(sharedLimit: 1, secret: 'another-secret')->keyOf(self::INVITEE_ID));
    }

    #[Test]
    public function theRefusalCarriesTheNumbersAndNamesNobody(): void
    {
        $throttle = $this->throttle(sharedLimit: 1);
        $throttle->ensureWithinBudget(self::INVITEE_ID);

        try {
            $throttle->ensureWithinBudget(self::INVITEE_ID);
            $this->fail('A spent budget must refuse out loud.');
        } catch (RateLimitExceeded $rateLimitExceeded) {
            $this->assertSame(1, $rateLimitExceeded->limit);
            $this->assertSame(0, $rateLimitExceeded->remaining);
            $this->assertGreaterThanOrEqual(1, $rateLimitExceeded->retryAfterSeconds);
            $this->assertSame($throttle->keyOf(self::INVITEE_ID), $rateLimitExceeded->limiterKey);
            $this->assertStringNotContainsString(
                self::INVITEE_ID,
                (string) \json_encode($rateLimitExceeded->context()),
            );
        }
    }

    #[Test]
    public function theRefusalStampsTheBudgetThatSaidNo(): void
    {
        $request = Request::create('/api/v1/backoffice/users/x/invitation/resend', Request::METHOD_POST);
        $throttle = $this->throttle(
            sharedLimit: self::UNBOUNDED,
            actorLimit: 1,
            requestStack: new RequestStack([$request]),
        );
        $throttle->ensureWithinBudget(self::INVITEE_ID);

        $this->assertNotInstanceOf(
            RateLimitSnapshot::class,
            RateLimitSnapshot::readFrom($request),
            'An admitted resend must leave the per-IP snapshot alone.',
        );

        try {
            $throttle->ensureWithinBudget(self::INVITEE_ID);
        } catch (RateLimitExceeded) {
            // Expected — the snapshot is stamped before the throw, so the response listener still reads it.
        }

        $snapshot = RateLimitSnapshot::readFrom($request);
        $this->assertInstanceOf(RateLimitSnapshot::class, $snapshot);
        $this->assertFalse($snapshot->accepted);
        $this->assertSame(1, $snapshot->limit);
        $this->assertSame(0, $snapshot->remaining);
    }

    #[Test]
    public function itStillRefusesWhenThereIsNoRequestToStamp(): void
    {
        $throttle = $this->throttle(sharedLimit: 1, requestStack: new RequestStack());
        $throttle->ensureWithinBudget(self::INVITEE_ID);

        $this->expectException(RateLimitExceeded::class);
        $throttle->ensureWithinBudget(self::INVITEE_ID);
    }

    /**
     * Throttles built here share one storage, so two of them stand for two administrators hitting the same
     * buckets — what the shared-budget cases need.
     */
    private function throttle(
        int $sharedLimit,
        int $actorLimit = self::UNBOUNDED,
        string $actor = self::ADMIN_ID,
        ?RequestStack $requestStack = null,
        string $secret = 'test-kernel-secret',
    ): InvitationResendThrottle {
        return new InvitationResendThrottle(
            $this->limiter('per_invitee', $sharedLimit, $this->storage),
            $this->limiter('per_actor_invitee', $actorLimit, $this->storage),
            $requestStack ?? new RequestStack(),
            new FixedActorContextFactory(ActorContext::forUser($actor)),
            $secret,
        );
    }

    private function limiter(string $id, int $limit, StorageInterface $storage): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 day'],
            $storage,
        );
    }
}
