<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Http;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\RecordRecoverySecretAuditBestEffort;
use Erpify\Iam\Identity\Application\RedeemRecoverySecret;
use Erpify\Iam\Identity\Application\RevokeCurrentSessionBestEffort;
use Erpify\Iam\Identity\Domain\Entity\GeneratedRecoverySecret;
use Erpify\Iam\Identity\Domain\Entity\RecoverySecret;
use Erpify\Iam\Identity\Domain\Exception\InvalidRecoverySecret;
use Erpify\Iam\Identity\Infrastructure\Http\RedeemRecoverySecretController;
use Erpify\Iam\Identity\Infrastructure\Http\RedeemRecoverySecretRequest;
use Erpify\Iam\Identity\Infrastructure\Security\PasswordRecoveryThrottle;
use Erpify\Iam\Identity\Infrastructure\Security\ReauthenticateDevice;
use Erpify\Iam\Session\Application\RevokeSession;
use Erpify\Tests\Unit\Iam\Identity\Application\FixedClock;
use Erpify\Tests\Unit\Iam\Identity\Application\InlineTransactionManager;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryRecoverySecretRepository;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryUserRepository;
use Erpify\Tests\Unit\Iam\Identity\Application\RecordingEventBus;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Iam\Session\Application\InMemorySessionRepository;
use Erpify\Tests\Unit\Iam\Session\Application\RecordingCurrentSessionReference;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * The anonymous redemption edge, asserted at the one place its opacity is decided.
 *
 * **The invariant is that exhaustion is indistinguishable from a dead secret.** Everything else this channel
 * does rests on it: a per-selector 429 would confirm that the selector exists, and a selector is the only
 * thing an unauthenticated caller can present. The controller therefore translates a spent budget into the
 * same `InvalidRecoverySecret` a wrong secret raises, and that translation lives here and nowhere else — no
 * use case, no listener and no exception mapping can restate it, which is why the endpoint is woven rather
 * than mocked.
 *
 * The login seam is deliberately built over an EMPTY user repository, so reaching it at all raises rather
 * than returning. Every case below is about a refusal that must happen before the work; a seam that answered
 * quietly would let "refused" and "refused after signing somebody in" look identical from here.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") — measured at 25 against a threshold of 13, the same
 * price its revoke sibling pays and for the same reason: weaving the real endpoint is the point. The use case,
 * both repositories, the audit projection, the compensating revoke and its session store, the event bus, the
 * transaction seam, the clock, the limiter and its storage, the login seam and the request DTO are each what
 * one of the properties below is stated in terms of. Mocking them would buy the metric and lose the ordering.
 */
#[CoversClass(RedeemRecoverySecretController::class)]
final class RedeemRecoverySecretControllerTest extends TestCase
{
    private const string NOW = '2026-08-28T12:00:00+00:00';

    #[Test]
    public function aSpentSelectorBudgetAnswersTheOpaqueRefusalRatherThanA429(): void
    {
        // The whole channel rests on this line. A `RateLimitExceeded` here would answer an anonymous caller
        // that the selector they guessed is real and under attack — which is the one fact the opaque wall
        // exists to withhold, and the reason no limiter on this path may be keyed by email or identity.
        $secrets = new InMemoryRecoverySecretRepository();
        $generated = $this->mintFor($secrets);
        $controller = $this->endpoint($secrets, budget: 1);

        // Drained by a wrong guess against the real selector, which is the shape the attack actually takes.
        $this->spend($controller, $this->selectorOf($generated) . '.a-wrong-guess');

        $this->expectException(InvalidRecoverySecret::class);

        try {
            $controller(new RedeemRecoverySecretRequest($generated->plaintext()));
        } finally {
            $this->assertSame([], $secrets->removed, 'the row was consumed by a caller that was over budget');
        }
    }

    #[Test]
    public function theBudgetIsSpentBeforeTheUseCaseResolvesAnything(): void
    {
        // A limiter placed after the work would refuse nothing that mattered. The presentation below is the
        // VALID one, so the only thing that can leave the row standing is the budget having been consulted
        // first — and the guess that drained it names the same selector, because the budget is per selector
        // and spending another one's would prove nothing about this row.
        $secrets = new InMemoryRecoverySecretRepository();
        $generated = $this->mintFor($secrets);
        $controller = $this->endpoint($secrets, budget: 1);

        $this->spend($controller, $this->selectorOf($generated) . '.the-guess-that-drains-it');

        $this->expectException(InvalidRecoverySecret::class);

        try {
            $controller(new RedeemRecoverySecretRequest($generated->plaintext()));
        } finally {
            $this->assertSame([], $secrets->removed);
        }
    }

    #[Test]
    public function theSecretHalfCannotSplitOneSelectorIntoTwoBudgets(): void
    {
        // The key is the selector alone. Keying the whole presentation would hand an attacker a fresh budget
        // per guess, which is the same as having no budget at all.
        $secrets = new InMemoryRecoverySecretRepository();
        $generated = $this->mintFor($secrets);
        $selector = $this->selectorOf($generated);
        $controller = $this->endpoint($secrets, budget: 1);

        $this->spend($controller, $selector . '.first-guess');

        $this->expectException(InvalidRecoverySecret::class);
        $controller(new RedeemRecoverySecretRequest($selector . '.second-guess'));
    }

    #[Test]
    public function aSelectorPresentedInAnotherCaseSpendsTheSAMEBucket(): void
    {
        // The column is a native Postgres `uuid`, which compares case-insensitively, so one ROW answers to
        // every casing of its selector. A verbatim key would give that row thousands of buckets and turn a
        // limit of ten into tens of thousands.
        $secrets = new InMemoryRecoverySecretRepository();
        $generated = $this->mintFor($secrets);
        $selector = $this->selectorOf($generated);
        $controller = $this->endpoint($secrets, budget: 1);

        $this->spend($controller, \strtolower($selector) . '.guess');

        $this->expectException(InvalidRecoverySecret::class);
        $controller(new RedeemRecoverySecretRequest(\strtoupper($selector) . '.guess'));
    }

    #[Test]
    public function aPresentationWithNoSeparatorIsRefusedAndStillCostsItsCaller(): void
    {
        // A malformed presentation has no selector half, so the whole string becomes the key. It matches no
        // row — but it is still paid for, which is what stops an attacker probing the shape for free.
        $secrets = new InMemoryRecoverySecretRepository();
        $this->mintFor($secrets);
        $controller = $this->endpoint($secrets, budget: 1);

        $this->spend($controller, 'no-separator-at-all');

        $this->expectException(InvalidRecoverySecret::class);
        $controller(new RedeemRecoverySecretRequest('no-separator-at-all'));
    }

    /**
     * Drives one attempt that is expected to be refused, so the case that follows meets a drained budget.
     * The refusal is swallowed on purpose: what the caller of this helper asserts is what the NEXT attempt
     * does, and every attempt here is paid for whatever its outcome.
     */
    private function spend(RedeemRecoverySecretController $controller, string $presentation): void
    {
        try {
            $controller(new RedeemRecoverySecretRequest($presentation));
            $this->fail('The attempt was expected to be refused.');
        } catch (InvalidRecoverySecret) {
            // Expected — the point is that the attempt was PAID for, not that it failed.
        }
    }

    /**
     * The aggregate deliberately exposes no `selector()` — it is the row's primary key and therefore a
     * denial capability, so nothing that could put it in a sink is offered. The presentation is the one
     * place it is legible, which is where the controller reads it from too.
     */
    private function selectorOf(GeneratedRecoverySecret $generated): string
    {
        return \explode('.', $generated->plaintext(), 2)[0];
    }

    private function mintFor(InMemoryRecoverySecretRepository $secrets): GeneratedRecoverySecret
    {
        $generated = RecoverySecret::mint(UserMother::DEFAULT_ID, new DateTimeImmutable(self::NOW));
        $generated->secret->pullDomainEvents();

        $secrets->save($generated->secret);

        return $generated;
    }

    private function endpoint(
        InMemoryRecoverySecretRepository $secrets,
        int $budget,
    ): RedeemRecoverySecretController {
        $users = new InMemoryUserRepository(UserMother::create());

        $useCase = new RedeemRecoverySecret(
            $users,
            $secrets,
            new RecordRecoverySecretAuditBestEffort(new RecordingAuditLogger(), new NullLogger()),
            new RevokeCurrentSessionBestEffort(
                new RecordingCurrentSessionReference(),
                new RevokeSession(
                    new InMemorySessionRepository(),
                    new RecordingEventBus(),
                    new InlineTransactionManager(),
                ),
                new NullLogger(),
            ),
            new RecordingEventBus(),
            new InlineTransactionManager(),
            FixedClock::at(self::NOW),
        );

        $perEmail = new RateLimiterFactory(
            ['id' => 'per_email', 'policy' => 'sliding_window', 'limit' => 99, 'interval' => '15 minutes'],
            new InMemoryStorage(),
        );
        $perSelector = new RateLimiterFactory(
            ['id' => 'per_selector', 'policy' => 'sliding_window', 'limit' => $budget, 'interval' => '15 minutes'],
            new InMemoryStorage(),
        );

        return new RedeemRecoverySecretController(
            $useCase,
            // Empty repository: the seam raises if anything reaches it, so a refusal that had already signed
            // somebody in cannot pass for a refusal that never got that far.
            new ReauthenticateDevice(new InMemoryUserRepository(), new Security(new Container())),
            new PasswordRecoveryThrottle($perEmail, $perSelector),
        );
    }
}
