<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Invitation\Infrastructure\Security;

use Erpify\Iam\Invitation\Infrastructure\Security\InvitationResendThrottle;
use Erpify\Shared\ErrorContract\Domain\Exception\RateLimitExceeded;
use Erpify\Shared\ErrorContract\Infrastructure\Http\RateLimitSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * @internal
 */
#[CoversClass(InvitationResendThrottle::class)]
final class InvitationResendThrottleTest extends TestCase
{
    private const string INVITEE_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a70';

    #[Test]
    public function itAdmitsResendsWhileTheInviteeStillHasBudget(): void
    {
        $throttle = $this->throttle(limit: 2);

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
        $throttle = $this->throttle(limit: 1);
        $throttle->ensureWithinBudget(self::INVITEE_ID);

        $this->expectException(RateLimitExceeded::class);
        $throttle->ensureWithinBudget(' ' . \strtoupper(self::INVITEE_ID) . ' ');
    }

    #[Test]
    public function anotherInviteeKeepsItsOwnBudget(): void
    {
        $throttle = $this->throttle(limit: 1);
        $throttle->ensureWithinBudget(self::INVITEE_ID);

        // No exception: draining one person's bucket cannot block the resend of somebody else's invitation.
        $throttle->ensureWithinBudget('0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a71');

        $this->expectException(RateLimitExceeded::class);
        $throttle->ensureWithinBudget(self::INVITEE_ID);
    }

    #[Test]
    public function theRefusalCarriesTheNumbersAndKeepsThePersonInsideTheException(): void
    {
        $throttle = $this->throttle(limit: 1);
        $throttle->ensureWithinBudget(self::INVITEE_ID);

        try {
            $throttle->ensureWithinBudget(self::INVITEE_ID);
            $this->fail('A spent budget must refuse out loud.');
        } catch (RateLimitExceeded $rateLimitExceeded) {
            $this->assertSame(1, $rateLimitExceeded->limit);
            $this->assertSame(0, $rateLimitExceeded->remaining);
            $this->assertGreaterThanOrEqual(1, $rateLimitExceeded->retryAfterSeconds);
            $this->assertSame(self::INVITEE_ID, $rateLimitExceeded->limiterKey);
            $this->assertStringNotContainsString(self::INVITEE_ID, (string) \json_encode($rateLimitExceeded->context()));
        }
    }

    #[Test]
    public function theRefusalStampsItsOwnBudgetForTheResponseHeaders(): void
    {
        $request = Request::create('/api/v1/backoffice/users/x/invitation/resend', Request::METHOD_POST);
        $throttle = $this->throttle(limit: 1, requestStack: new RequestStack([$request]));
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
        $this->assertSame(0, $snapshot->remaining);
    }

    #[Test]
    public function itStillRefusesWhenThereIsNoRequestToStamp(): void
    {
        $throttle = $this->throttle(limit: 1, requestStack: new RequestStack());
        $throttle->ensureWithinBudget(self::INVITEE_ID);

        $this->expectException(RateLimitExceeded::class);
        $throttle->ensureWithinBudget(self::INVITEE_ID);
    }

    private function throttle(int $limit, ?RequestStack $requestStack = null): InvitationResendThrottle
    {
        $factory = new RateLimiterFactory(
            ['id' => 'per_invitee', 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 day'],
            new InMemoryStorage(),
        );

        return new InvitationResendThrottle($factory, $requestStack ?? new RequestStack());
    }
}
