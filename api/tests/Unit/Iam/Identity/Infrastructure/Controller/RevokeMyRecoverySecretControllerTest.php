<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Controller;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\ProveCurrentPassword;
use Erpify\Iam\Identity\Application\RecordRecoverySecretAuditBestEffort;
use Erpify\Iam\Identity\Application\RevokeRecoverySecret;
use Erpify\Iam\Identity\Domain\Entity\RecoverySecret;
use Erpify\Iam\Identity\Domain\Exception\InvalidCurrentPassword;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Infrastructure\Controller\RevokeMyRecoverySecretController;
use Erpify\Iam\Identity\Infrastructure\Http\RevokeRecoverySecretRequest;
use Erpify\Iam\Identity\Infrastructure\Security\CurrentPasswordProofThrottle;
use Erpify\Iam\Identity\Infrastructure\Security\PasswordHasher;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Shared\ErrorContract\Domain\Exception\RateLimitExceeded;
use Erpify\Tests\Unit\Iam\Identity\Application\InlineTransactionManager;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryRecoverySecretRepository;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryUserRepository;
use Erpify\Tests\Unit\Iam\Identity\Application\RecordingEventBus;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * What it costs to destroy a recovery secret, asserted where the cost is actually assembled.
 *
 * The graph is the real one — the use case, the credential proof and the limiter over in-memory storage —
 * rather than mocks, because both properties under test are about ORDER between collaborators and a double
 * that answered on a schedule of its own would make either of them a property of the double. The hasher is
 * the production adapter over a plaintext algorithm, so "the right password" is a value this test can state
 * while the closure the controller builds stays exactly the one production builds.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") — measured at 25 against a threshold of 13, which is the
 * price of weaving the real endpoint rather than mocking it: the use case, both repositories, the credential
 * proof, the audit projection, the event bus, the transaction seam, the hasher and its factory, the limiter
 * and its storage, the security adapter over the aggregate, the request DTO and the two refusals. Mocking the
 * collaborators would buy the metric back and lose both properties under test, which are about ORDER between
 * exactly those objects.
 */
#[CoversClass(RevokeMyRecoverySecretController::class)]
final class RevokeMyRecoverySecretControllerTest extends TestCase
{
    private const string PASSWORD = 'the-current-password';

    private const string NOW = '2026-08-28T12:00:00+00:00';

    #[Test]
    public function theRightPasswordRetiresTheSecretAndAnswers204(): void
    {
        $secrets = new InMemoryRecoverySecretRepository($this->secret());
        $controller = $this->endpoint($secrets, budget: 5);

        $response = $controller($this->signedInOwner(), new RevokeRecoverySecretRequest(self::PASSWORD));

        $this->assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame('', $response->getContent());
        $this->assertCount(1, $secrets->removed);
    }

    #[Test]
    public function aWrongPasswordRefusesAndLeavesTheSecretStanding(): void
    {
        $secrets = new InMemoryRecoverySecretRepository($this->secret());
        $controller = $this->endpoint($secrets, budget: 5);

        $this->expectException(InvalidCurrentPassword::class);

        try {
            $controller($this->signedInOwner(), new RevokeRecoverySecretRequest('not-the-current-password'));
        } finally {
            $this->assertSame([], $secrets->removed);
        }
    }

    #[Test]
    public function aSpentBudgetRefusesBeforeTheUseCaseRunsAtAll(): void
    {
        // The budget is consumed at this edge and BEFORE the work, which is the half a limiter placed after
        // the use case would not buy: a refusal that still destroyed the row would be no refusal. The
        // password below is the right one, so nothing but the budget can be what stops it.
        $secrets = new InMemoryRecoverySecretRepository($this->secret());
        $controller = $this->endpoint($secrets, budget: 1);

        try {
            $controller($this->signedInOwner(), new RevokeRecoverySecretRequest('spends-the-only-token'));
        } catch (InvalidCurrentPassword) {
            // The budget is spent by the attempt, not by its outcome — which is what leaves none for below.
        }

        $this->expectException(RateLimitExceeded::class);

        try {
            $controller($this->signedInOwner(), new RevokeRecoverySecretRequest(self::PASSWORD));
        } finally {
            $this->assertSame([], $secrets->removed, 'the row went while the caller was over budget');
        }
    }

    #[Test]
    public function aWrongPasswordSpendsTheBudgetSoGuessingIsBounded(): void
    {
        // Nothing here feeds the persisted lockout by design, so this limiter is the only ceiling on guessing
        // the credential from a live session. A refusal that cost nothing would leave the guesses unbounded.
        $secrets = new InMemoryRecoverySecretRepository($this->secret());
        $controller = $this->endpoint($secrets, budget: 2);

        foreach (['first-guess', 'second-guess'] as $guess) {
            try {
                $controller($this->signedInOwner(), new RevokeRecoverySecretRequest($guess));
                $this->fail('A wrong current password must be refused.');
            } catch (InvalidCurrentPassword) {
                // Expected — what the case asserts is that the attempt was PAID for, not that it failed.
            }
        }

        $this->expectException(RateLimitExceeded::class);
        $controller($this->signedInOwner(), new RevokeRecoverySecretRequest(self::PASSWORD));
    }

    private function signedInOwner(): SecurityUser
    {
        return new SecurityUser(UserMother::create(password: HashedPassword::fromHash(self::PASSWORD)));
    }

    private function secret(): RecoverySecret
    {
        $generated = RecoverySecret::mint(UserMother::DEFAULT_ID, new DateTimeImmutable(self::NOW));
        $generated->secret->pullDomainEvents();

        return $generated->secret;
    }

    private function endpoint(
        InMemoryRecoverySecretRepository $secrets,
        int $budget,
    ): RevokeMyRecoverySecretController {
        $users = new InMemoryUserRepository(UserMother::create(
            password: HashedPassword::fromHash(self::PASSWORD),
        ));

        $useCase = new RevokeRecoverySecret(
            $users,
            $secrets,
            new ProveCurrentPassword(),
            new RecordRecoverySecretAuditBestEffort(new RecordingAuditLogger(), new NullLogger()),
            new RecordingEventBus(),
            new InlineTransactionManager(),
        );

        $limiter = new RateLimiterFactory(
            ['id' => 'per_identity', 'policy' => 'sliding_window', 'limit' => $budget, 'interval' => '15 minutes'],
            new InMemoryStorage(),
        );

        return new RevokeMyRecoverySecretController(
            $useCase,
            new PasswordHasher(new PasswordHasherFactory([SecurityUser::class => ['algorithm' => 'plaintext']])),
            new CurrentPasswordProofThrottle($limiter, new RequestStack()),
        );
    }
}
