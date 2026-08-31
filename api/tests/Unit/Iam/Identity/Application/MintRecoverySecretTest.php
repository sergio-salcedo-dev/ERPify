<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Closure;
use DateTimeImmutable;
use Erpify\Iam\Identity\Application\MintRecoverySecret;
use Erpify\Iam\Identity\Application\ProveCurrentPassword;
use Erpify\Iam\Identity\Application\RecordRecoverySecretAuditBestEffort;
use Erpify\Iam\Identity\Domain\Entity\RecoverySecret;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Event\RecoverySecretMinted;
use Erpify\Iam\Identity\Domain\Exception\AccountDeactivated;
use Erpify\Iam\Identity\Domain\Exception\InvalidCurrentPassword;
use Erpify\Iam\Identity\Domain\Exception\RecoverySecretAlreadyExists;
use Erpify\Iam\Identity\Domain\Exception\UserNotFound;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use Erpify\Tests\Unit\Shared\Persistence\Double\LockOrderJournal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Minting: what it refuses, in what order, and what it leaves behind.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") — the arrange names the two repositories, the credential
 * proof, the audit projection, the event bus, the transaction seam, the clock and the three refusals this
 * endpoint can answer with; each is what one of the cases below is stated in terms of.
 */
#[CoversClass(MintRecoverySecret::class)]
final class MintRecoverySecretTest extends TestCase
{
    private const string NOW = '2026-08-28T12:00:00+00:00';

    #[Test]
    public function itPersistsTheDigestAndReturnsThePlaintextExactlyOnce(): void
    {
        $users = new InMemoryUserRepository(UserMother::create());
        $secrets = new InMemoryRecoverySecretRepository();
        $eventBus = new RecordingEventBus();

        $generated = $this->useCase($users, $secrets, $eventBus)
            ->mint(UserMother::DEFAULT_ID, $this->acceptsTheCurrentPassword())
        ;

        $this->assertCount(1, $secrets->saved);
        $halves = \explode('.', $generated->plaintext(), 2);
        $this->assertCount(2, $halves, 'the plaintext is not a <selector>.<secret> presentation at all');
        [$selector, $secret] = $halves;
        $this->assertSame($generated->secret->getId(), $selector);
        // The returned plaintext is the only copy that ever exists: the row keeps a digest, and the check
        // below is what proves the two halves belong together rather than being separately plausible.
        $this->assertTrue($generated->secret->verify($secret, new DateTimeImmutable(self::NOW)));
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertInstanceOf(RecoverySecretMinted::class, $eventBus->publishedEvents[0]);
    }

    #[Test]
    public function theExpiryIsTenYearsOutAndVisibleToTheCaller(): void
    {
        // The TTL is an accepted risk with an open issue behind it, so it is asserted rather than left to
        // whatever the constant happens to say: a shortened window would silently reintroduce the invisible
        // destruction this design rejected when it decided a password change leaves the secret standing.
        $users = new InMemoryUserRepository(UserMother::create());
        $secrets = new InMemoryRecoverySecretRepository();

        $generated = $this->useCase($users, $secrets)->mint(UserMother::DEFAULT_ID, $this->acceptsTheCurrentPassword());

        $this->assertSame(
            (new DateTimeImmutable(self::NOW))->modify('+10 years')->format(DATE_ATOM),
            $generated->secret->expiresAt()->format(DATE_ATOM),
        );
    }

    #[Test]
    public function aWrongCurrentPasswordIsRefusedBeforeTheExistenceOfASecretIsConsulted(): void
    {
        // The ORDER is the security property. Answering the 409 to somebody who has not re-proved the
        // credential would turn a stolen session into an oracle over whether a recovery secret exists to go
        // looking for — so the refusal must fire even when one does.
        $users = new InMemoryUserRepository(UserMother::create());
        $secrets = new InMemoryRecoverySecretRepository(
            RecoverySecret::mint(UserMother::DEFAULT_ID, new DateTimeImmutable(self::NOW))->secret,
        );

        $this->expectException(InvalidCurrentPassword::class);

        try {
            $this->useCase($users, $secrets)->mint(UserMother::DEFAULT_ID, $this->refusesTheCurrentPassword());
        } finally {
            $this->assertSame([], $secrets->saved);
        }
    }

    #[Test]
    public function aSecondMintIsRefusedRatherThanSupersedingTheFirst(): void
    {
        // Refusing is the point: superseding would destroy, with no notice to anyone, a credential whose
        // holder may have written it down and stored it away from the machine.
        $existing = RecoverySecret::mint(UserMother::DEFAULT_ID, new DateTimeImmutable(self::NOW))->secret;
        $users = new InMemoryUserRepository(UserMother::create());
        $secrets = new InMemoryRecoverySecretRepository($existing);

        $this->expectException(RecoverySecretAlreadyExists::class);

        try {
            $this->useCase($users, $secrets)->mint(UserMother::DEFAULT_ID, $this->acceptsTheCurrentPassword());
        } finally {
            $this->assertSame([], $secrets->saved);
            $this->assertSame([], $secrets->removed, 'the existing secret was destroyed by a refused mint');
        }
    }

    #[Test]
    public function anIdentityThatResolvedToNoRowIsRefusedRatherThanMintingForNobody(): void
    {
        // A live session whose identity was erased or removed between admission and this transaction. The
        // 404 is the honest answer, and the assertion that matters is the absence: minting here would write a
        // ten-year credential keyed to a `user_id` no row backs, which no erasure path would ever revisit.
        $secrets = new InMemoryRecoverySecretRepository();

        $this->expectException(UserNotFound::class);

        try {
            $this->useCase(new InMemoryUserRepository(), $secrets)
                ->mint(UserMother::DEFAULT_ID, $this->acceptsTheCurrentPassword())
            ;
        } finally {
            $this->assertSame([], $secrets->saved);
        }
    }

    /**
     * @param Closure(): User $identity
     */
    #[Test]
    #[DataProvider('provideAnUnadmittedIdentityCannotMintAndIsWalledBeforeItsCredentialIsReadCases')]
    public function anUnadmittedIdentityCannotMintAndIsWalledBeforeItsCredentialIsRead(Closure $identity): void
    {
        // The state wall fires ahead of the credential proof, so an unadmitted identity is refused without
        // this endpoint becoming a password oracle for one. The refusal type is the one the PWA builds its
        // terminal wall around, and until this case nothing on the API side produced it.
        $secrets = new InMemoryRecoverySecretRepository();

        $this->expectException(AccountDeactivated::class);

        try {
            $this->useCase(new InMemoryUserRepository($identity()), $secrets)
                ->mint(UserMother::DEFAULT_ID, $this->refusesTheCurrentPassword())
            ;
        } finally {
            $this->assertSame([], $secrets->saved);
        }
    }

    /**
     * @return iterable<string, array{Closure(): User}>
     */
    public static function provideAnUnadmittedIdentityCannotMintAndIsWalledBeforeItsCredentialIsReadCases(): iterable
    {
        yield 'deactivated' => [static function (): User {
            $user = UserMother::create();
            $user->deactivate();
            $user->pullDomainEvents();

            return $user;
        }];
        yield 'invited, never activated' => [static fn (): User => UserMother::invited()];
        yield 'invitation revoked' => [static fn (): User => UserMother::revoked()];
    }

    #[Test]
    public function theUserRowIsLockedBeforeTheSecretRow(): void
    {
        $users = new InMemoryUserRepository(UserMother::create());
        $secrets = new InMemoryRecoverySecretRepository();
        $journal = new LockOrderJournal();
        $users->lockOrderJournal = $journal;
        $secrets->lockOrderJournal = $journal;

        $this->useCase($users, $secrets)->mint(UserMother::DEFAULT_ID, $this->acceptsTheCurrentPassword());

        $this->assertSame(
            [LockOrderJournal::IDENTITY_USER, LockOrderJournal::RECOVERY_SECRET],
            $journal->crossTableOrder(),
            'minting must take the same pair in the same order redemption does, or the two can deadlock',
        );
    }

    #[Test]
    public function theAuditRowNamesTheSubjectAndCarriesNothingElse(): void
    {
        $users = new InMemoryUserRepository(UserMother::create());
        $secrets = new InMemoryRecoverySecretRepository();
        $audit = new RecordingAuditLogger();

        $generated = $this->useCase($users, $secrets, audit: $audit)
            ->mint(UserMother::DEFAULT_ID, $this->acceptsTheCurrentPassword())
        ;

        $this->assertCount(1, $audit->records);
        $this->assertSame('RECOVERY_SECRET_MINTED', $audit->records[0]['action']);
        $this->assertSame([], $audit->records[0]['metadata']);
        // The selector may not reach the trail: it is a denial capability, and `audit_log` is readable by
        // anyone holding `auditTrail.read`.
        $this->assertStringNotContainsString(
            (string) $generated->secret->getId(),
            \json_encode($audit->records[0], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Stands in for the hasher comparison the HTTP adapter supplies. It READS the stored credential rather
     * than ignoring it, which is the shape the real closure has: one that took no argument would pass this
     * suite while proving nothing about a use case that stopped handing the credential over.
     *
     * @return Closure(HashedPassword): bool
     */
    private function acceptsTheCurrentPassword(): Closure
    {
        return static fn (HashedPassword $stored): bool => '' !== $stored->toString();
    }

    /**
     * The same seam refusing — handed the same stored credential, answering the other way.
     *
     * @return Closure(HashedPassword): bool
     */
    private function refusesTheCurrentPassword(): Closure
    {
        return static fn (HashedPassword $stored): bool => '' === $stored->toString();
    }

    private function useCase(
        InMemoryUserRepository $users,
        InMemoryRecoverySecretRepository $secrets,
        ?RecordingEventBus $eventBus = null,
        ?RecordingAuditLogger $audit = null,
    ): MintRecoverySecret {
        return new MintRecoverySecret(
            $users,
            $secrets,
            new ProveCurrentPassword(),
            new RecordRecoverySecretAuditBestEffort($audit ?? new RecordingAuditLogger(), new NullLogger()),
            $eventBus ?? new RecordingEventBus(),
            new InlineTransactionManager(),
            FixedClock::at(self::NOW),
        );
    }
}
