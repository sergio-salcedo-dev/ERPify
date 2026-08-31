<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Controller;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\MintRecoverySecret;
use Erpify\Iam\Identity\Application\ProveCurrentPassword;
use Erpify\Iam\Identity\Application\RecordRecoverySecretAuditBestEffort;
use Erpify\Iam\Identity\Domain\Entity\RecoverySecret;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Exception\AccountSuspended;
use Erpify\Iam\Identity\Domain\Exception\InvalidCurrentPassword;
use Erpify\Iam\Identity\Domain\Exception\RecoverySecretAlreadyExists;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Infrastructure\Controller\MintRecoverySecretController;
use Erpify\Iam\Identity\Infrastructure\Http\MintRecoverySecretRequest;
use Erpify\Iam\Identity\Infrastructure\Security\CurrentPasswordProofThrottle;
use Erpify\Iam\Identity\Infrastructure\Security\PasswordHasher;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\ErrorContract\Domain\Exception\RateLimitExceeded;
use Erpify\Tests\Support\ResourceResponderBuilder;
use Erpify\Tests\Unit\Iam\Identity\Application\FixedClock;
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
 * Minting, asserted where the credential is actually produced.
 *
 * **The 201 body is the only place the plaintext is ever legible** — nothing logs it, nothing persists it
 * and nothing can re-derive it from the stored digest — so the emitted payload is read through the real
 * normalize-then-respond chain rather than a double, and the secret it carries is verified against the row
 * that was saved. A test asserting the DTO would prove the controller built one, not that the owner can
 * ever use what came back.
 *
 * The ORDER of the three refusals is the rest of the contract, and each is asserted by what it leaves
 * behind rather than by the exception alone: the budget before any work, the status wall before the
 * credential proof (so a walled identity pays no KDF), and the proof before the existence of a row is
 * consulted (so a stolen session cannot ask whether a secret exists).
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") — measured at 30 against a threshold of 13, the price
 * of weaving the endpoint rather than mocking it. The use case, both repositories, the credential proof,
 * the audit projection, the event bus, the transaction seam, the clock, the hasher and its factory, the
 * limiter and its storage, the responder chain, the request DTO and the three refusals are each what one of
 * the claims below is stated in terms of; every one of those claims is about ORDER between exactly those
 * objects, which a double would answer for itself.
 */
#[CoversClass(MintRecoverySecretController::class)]
final class MintRecoverySecretControllerTest extends TestCase
{
    private const string PASSWORD = 'the-current-password';

    private const string NOW = '2026-08-28T12:00:00+00:00';

    /** The mint's own TTL away from {@see NOW}: the value the owner plans around. */
    private const string LAPSES = '2036-08-28T12:00:00+00:00';

    #[Test]
    public function theRightPasswordAnswers201WithAUsableSecretAndItsTwoInstants(): void
    {
        $secrets = new InMemoryRecoverySecretRepository();
        $controller = $this->endpoint($secrets, budget: 5);

        $response = $controller($this->signedInOwner(), new MintRecoverySecretRequest(self::PASSWORD));

        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());

        // Narrowed by assertions rather than by a PHPDoc shape: annotating the decoded body would make the
        // key-set check below provably true, and a guard that cannot fail is not a guard. The
        // `assertArrayHasKey` pair under it is the opposite case and is meant to be unfalsifiable here — it
        // is the step that THROWS, so the reads after it are typed; a PHPDoc would have narrowed the key-set
        // check itself, which is the one that has to keep its teeth.
        $body = \json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('data', $body);

        $data = $body['data'];
        $this->assertIsArray($data);
        $this->assertSame(['secret', 'mintedAt', 'expiresAt'], \array_keys($data));
        $this->assertArrayHasKey('secret', $data);
        $this->assertArrayHasKey('mintedAt', $data);
        $this->assertArrayHasKey('expiresAt', $data);

        $presented = $data['secret'];
        $this->assertIsString($presented);
        $halves = \explode('.', $presented, 2);
        $this->assertCount(2, $halves, 'the 201 carried no `<selector>.<secret>` presentation at all');

        $this->assertCount(1, $secrets->saved);
        // The presentation that came back has to open the row that was stored. Asserting it is a non-empty
        // string would pass over a response carrying a secret nobody can spend — the one failure mode that
        // is unrecoverable here, since the plaintext is never legible again.
        $this->assertTrue(
            $secrets->saved[0]->verify($halves[1], new DateTimeImmutable(self::NOW)),
            'the 201 carried a secret that does not open the row it minted',
        );
        // Both instants carry a VALUE, not a type. `expiresAt` comes from the injected clock, but `mintedAt`
        // is the aggregate's `createdAt` off the ambient {@see SystemClock}, so leaving it unasserted makes
        // the two slots interchangeable: emitting the expiry in the `mintedAt` position passes, and the owner
        // is handed "Created 2036 / Expires 2036" for a credential minted today. Both instants are this
        // case's subject, so both carry a value.
        $this->assertSame(self::NOW, $data['mintedAt']);
        $this->assertSame(self::LAPSES, $data['expiresAt']);
    }

    #[Test]
    public function aSecondMintIsRefusedWithTheConflictThatOffersRevokeThenMint(): void
    {
        // One secret per identity, and the refusal carries its own `type` so the client can offer the
        // remedy instead of rendering a generic conflict.
        $secrets = new InMemoryRecoverySecretRepository($this->existingSecret());
        $controller = $this->endpoint($secrets, budget: 5);

        $this->expectException(RecoverySecretAlreadyExists::class);

        try {
            $controller($this->signedInOwner(), new MintRecoverySecretRequest(self::PASSWORD));
        } finally {
            $this->assertSame([], $secrets->saved, 'the refused mint wrote a second row anyway');
        }
    }

    #[Test]
    public function aWrongPasswordIsRefusedBeforeTheExistenceOfASecretIsConsulted(): void
    {
        // Ordering IS the security property. This account holds one, so grading on existence would answer
        // the 409 here — telling whoever stole the session that a recovery secret exists, without their ever
        // having re-proved the credential.
        $secrets = new InMemoryRecoverySecretRepository($this->existingSecret());
        $controller = $this->endpoint($secrets, budget: 5);

        $this->expectException(InvalidCurrentPassword::class);
        $controller($this->signedInOwner(), new MintRecoverySecretRequest('not-the-current-password'));
    }

    #[Test]
    public function aWalledIdentityIsRefusedBeforeTheCredentialProofRuns(): void
    {
        // The wall precedes the proof, so a suspended identity pays no hashing work — and the refusal is the
        // status one rather than a credential one, even though the password below is deliberately wrong.
        $suspended = UserMother::create(password: HashedPassword::fromHash(self::PASSWORD));
        $suspended->suspend();
        $suspended->pullDomainEvents();

        $secrets = new InMemoryRecoverySecretRepository();
        $controller = $this->endpoint($secrets, budget: 5, user: $suspended);

        $this->expectException(AccountSuspended::class);

        try {
            $controller($this->signedInOwner(), new MintRecoverySecretRequest('not-the-current-password'));
        } finally {
            $this->assertSame([], $secrets->saved);
        }
    }

    #[Test]
    public function aSpentBudgetRefusesBeforeTheUseCaseRunsAtAll(): void
    {
        // The budget is shared with the password change and the revoke on purpose: a bucket of its own would
        // hand a stolen session three times the guesses against one credential. Spent at this edge and
        // BEFORE the work, so a refusal cannot leave a row behind.
        $secrets = new InMemoryRecoverySecretRepository();
        $controller = $this->endpoint($secrets, budget: 1);

        try {
            $controller($this->signedInOwner(), new MintRecoverySecretRequest('spends-the-only-token'));
        } catch (InvalidCurrentPassword) {
            // The attempt is paid for by its being made, not by its outcome — which is what leaves none below.
        }

        $this->expectException(RateLimitExceeded::class);

        try {
            $controller($this->signedInOwner(), new MintRecoverySecretRequest(self::PASSWORD));
        } finally {
            $this->assertSame([], $secrets->saved, 'a caller over budget still minted');
        }
    }

    private function signedInOwner(): SecurityUser
    {
        return new SecurityUser(UserMother::create(password: HashedPassword::fromHash(self::PASSWORD)));
    }

    private function existingSecret(): RecoverySecret
    {
        $generated = RecoverySecret::mint(UserMother::DEFAULT_ID, new DateTimeImmutable(self::NOW));
        $generated->secret->pullDomainEvents();

        return $generated->secret;
    }

    private function endpoint(
        InMemoryRecoverySecretRepository $secrets,
        int $budget,
        ?User $user = null,
    ): MintRecoverySecretController {
        $users = new InMemoryUserRepository(
            $user ?? UserMother::create(password: HashedPassword::fromHash(self::PASSWORD)),
        );

        // The aggregate stamps its own `createdAt` from the ambient clock, which no constructor argument
        // reaches. `ResetSystemClockExtension` unfreezes it after each case.
        SystemClock::set(FixedClock::at(self::NOW));

        $useCase = new MintRecoverySecret(
            $users,
            $secrets,
            new ProveCurrentPassword(),
            new RecordRecoverySecretAuditBestEffort(new RecordingAuditLogger(), new NullLogger()),
            new RecordingEventBus(),
            new InlineTransactionManager(),
            FixedClock::at(self::NOW),
        );

        $limiter = new RateLimiterFactory(
            ['id' => 'per_identity', 'policy' => 'sliding_window', 'limit' => $budget, 'interval' => '15 minutes'],
            new InMemoryStorage(),
        );

        return new MintRecoverySecretController(
            $useCase,
            new PasswordHasher(new PasswordHasherFactory([SecurityUser::class => ['algorithm' => 'plaintext']])),
            new CurrentPasswordProofThrottle($limiter, new RequestStack()),
            ResourceResponderBuilder::wired(),
        );
    }
}
