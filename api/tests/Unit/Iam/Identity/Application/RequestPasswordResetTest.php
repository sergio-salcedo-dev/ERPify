<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\RequestPasswordReset;
use Erpify\Iam\Identity\Application\SendPasswordResetEmailBestEffort;
use Erpify\Iam\Identity\Domain\Entity\PasswordResetToken;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The coupling suppression is measured at 15. The use case orchestrates a token factory, a repository, a
 * clock, a mailer best-effort and a logger, so a test that drives it end to end names all of them; cutting
 * the number means testing less of the orchestration, not testing it more simply.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(RequestPasswordReset::class)]
final class RequestPasswordResetTest extends TestCase
{
    private const string SUPERSEDED_TOKEN_ID = '0190e1f2-a3b4-7c5d-8e6f-1a2b3c4d5e63';

    public function testActiveIdentitySupersedesMintsPersistsAndEmails(): void
    {
        $tokens = new InMemoryPasswordResetTokenRepository();
        $eventBus = new RecordingEventBus();
        $emails = new RecordingPasswordResetEmailSender();
        $this->useCase(new InMemoryUserRepository(UserMother::create()), $tokens, $eventBus, $emails)
            ->request(UserMother::DEFAULT_EMAIL)
        ;

        $this->assertSame([UserMother::DEFAULT_ID], $tokens->deleteAllForUserCalls);
        $this->assertCount(1, $tokens->saved);
        $this->assertSame(UserMother::DEFAULT_ID, $tokens->saved[0]->userId());
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertSame('erpify.iam.identity.password-reset-requested', $eventBus->publishedEvents[0]::eventName());
        $this->assertSame(UserMother::DEFAULT_ID, $eventBus->publishedEvents[0]->aggregateId());

        // The email is dispatched to the identity's address carrying the `<tokenId>.<secret>` composite of the
        // token just saved — the secret rides only in the email, never in the persisted row or the event.
        $this->assertCount(1, $emails->sent);
        $this->assertSame(UserMother::DEFAULT_EMAIL, $emails->sent[0]['email']);
        $this->assertStringStartsWith($tokens->saved[0]->getId() . '.', $emails->sent[0]['token']);
    }

    public function testUnknownEmailTouchesNothing(): void
    {
        $tokens = new InMemoryPasswordResetTokenRepository();
        $eventBus = new RecordingEventBus();
        $emails = new RecordingPasswordResetEmailSender();
        $this->useCase(new InMemoryUserRepository(), $tokens, $eventBus, $emails)->request('nobody@erpify.test');

        $this->assertNoWork($tokens, $eventBus, $emails);
    }

    public function testMalformedEmailTouchesNothing(): void
    {
        $tokens = new InMemoryPasswordResetTokenRepository();
        $eventBus = new RecordingEventBus();
        $emails = new RecordingPasswordResetEmailSender();
        $this->useCase(new InMemoryUserRepository(UserMother::create()), $tokens, $eventBus, $emails)->request('   ');

        $this->assertNoWork($tokens, $eventBus, $emails);
    }

    #[DataProvider('provideNonActiveIdentityTouchesNothingCases')]
    public function testNonActiveIdentityTouchesNothing(User $user): void
    {
        $tokens = new InMemoryPasswordResetTokenRepository();
        $eventBus = new RecordingEventBus();
        $emails = new RecordingPasswordResetEmailSender();
        $this->useCase(new InMemoryUserRepository($user), $tokens, $eventBus, $emails)
            ->request(UserMother::DEFAULT_EMAIL)
        ;

        $this->assertNoWork($tokens, $eventBus, $emails);
    }

    /**
     * @return iterable<string, array{User}>
     */
    public static function provideNonActiveIdentityTouchesNothingCases(): iterable
    {
        $suspended = UserMother::create();
        $suspended->suspend();

        $deactivated = UserMother::create();
        $deactivated->deactivate();

        yield 'invited' => [UserMother::invited()];
        yield 'suspended' => [$suspended];
        yield 'deactivated' => [$deactivated];
    }

    public function testTheSupersedeRunsUnderTheUserRowLock(): void
    {
        // Single-threaded proof of the mutex's SHAPE: the write path re-acquires the user row under a
        // pessimistic lock inside its transaction, which is what serialises two concurrent forgots into
        // "only the latest token lives". The real interleaving is covered by design, not by this test.
        $users = new InMemoryUserRepository(UserMother::create());
        $tokens = new InMemoryPasswordResetTokenRepository();

        $this->useCase($users, $tokens, new RecordingEventBus(), new RecordingPasswordResetEmailSender())
            ->request(UserMother::DEFAULT_EMAIL)
        ;

        $this->assertSame([UserMother::DEFAULT_ID], $users->forUpdateCalls);
        $this->assertCount(1, $tokens->saved);
    }

    public function testTheSupersedeDropsThePendingTokenBeforeWritingTheNewOne(): void
    {
        $tokens = new InMemoryPasswordResetTokenRepository($this->pendingToken());

        $deletesSeenAtWriteTime = null;
        $tokens->onSave = static function () use ($tokens, &$deletesSeenAtWriteTime): void {
            $deletesSeenAtWriteTime = $tokens->deleteAllForUserCalls;
        };

        $this->useCase(
            new InMemoryUserRepository(UserMother::create()),
            $tokens,
            new RecordingEventBus(),
            new RecordingPasswordResetEmailSender(),
        )->request(UserMother::DEFAULT_EMAIL);

        // The pending link is GONE from the store, which is the guarantee — "deleteAllForUser was called"
        // is satisfied by a supersede that leaves the superseded row readable, and two live reset links for
        // one identity is exactly the state the supersede exists to prevent.
        $this->assertNotInstanceOf(PasswordResetToken::class, $tokens->findById(self::SUPERSEDED_TOKEN_ID));

        $this->assertCount(1, $tokens->saved);
        $issuedId = $tokens->saved[0]->getId();
        $this->assertIsString($issuedId);

        // The freshly issued link survived, which the reverse order could not manage: the delete is
        // user-wide, so running it after the write would take the new row along with the old one.
        $this->assertInstanceOf(PasswordResetToken::class, $tokens->findById($issuedId));

        // Read at the instant of the write rather than afterwards, so the order is asserted rather than
        // inferred from what the two deletions happen to leave behind.
        $this->assertSame([UserMother::DEFAULT_ID], $deletesSeenAtWriteTime);
    }

    public function testAUserGoneUnderTheLockIssuesAndEmailsNothing(): void
    {
        $users = new InMemoryUserRepository(UserMother::create());
        $users->goneUnderLock = true;

        $tokens = new InMemoryPasswordResetTokenRepository();
        $eventBus = new RecordingEventBus();
        $emails = new RecordingPasswordResetEmailSender();

        $this->useCase($users, $tokens, $eventBus, $emails)->request(UserMother::DEFAULT_EMAIL);

        $this->assertNoWork($tokens, $eventBus, $emails);
    }

    #[DataProvider('provideEveryOutcomePaysTheSameTimingFloorSoLatencyDoesNotLeakExistenceCases')]
    public function testEveryOutcomePaysTheSameTimingFloorSoLatencyDoesNotLeakExistence(
        ?User $user,
        string $requestedEmail,
    ): void {
        $floor = new CountingPreIdentityTimingFloor();
        $users = $user instanceof User ? new InMemoryUserRepository($user) : new InMemoryUserRepository();

        $this->useCase(
            $users,
            new InMemoryPasswordResetTokenRepository(),
            new RecordingEventBus(),
            new RecordingPasswordResetEmailSender(),
            $floor,
        )->request($requestedEmail);

        $this->assertSame(1, $floor->invocations);
    }

    /**
     * @return iterable<string, array{?User, string}>
     */
    public static function provideEveryOutcomePaysTheSameTimingFloorSoLatencyDoesNotLeakExistenceCases(): iterable
    {
        $suspended = UserMother::create();
        $suspended->suspend();

        yield 'active (the write path)' => [UserMother::create(), UserMother::DEFAULT_EMAIL];
        yield 'unknown email' => [null, 'nobody@erpify.test'];
        yield 'malformed email' => [null, '   '];
        yield 'invited' => [UserMother::invited(), UserMother::DEFAULT_EMAIL];
        yield 'suspended' => [$suspended, UserMother::DEFAULT_EMAIL];
    }

    /**
     * A reset link already outstanding for the identity, so the supersede has something real to drop rather
     * than an empty store in which every ordering looks alike.
     */
    private function pendingToken(): PasswordResetToken
    {
        return PasswordResetToken::issue(
            self::SUPERSEDED_TOKEN_ID,
            UserMother::DEFAULT_ID,
            SingleUseToken::mint(new DateTimeImmutable('2026-07-13T12:30:00+00:00'))->token,
        );
    }

    private function useCase(
        InMemoryUserRepository $users,
        InMemoryPasswordResetTokenRepository $tokens,
        RecordingEventBus $eventBus,
        RecordingPasswordResetEmailSender $emails,
        ?CountingPreIdentityTimingFloor $floor = null,
    ): RequestPasswordReset {
        return new RequestPasswordReset(
            $users,
            $tokens,
            $eventBus,
            new InlineTransactionManager(),
            FixedClock::at('2026-07-13T12:00:00+00:00'),
            new SendPasswordResetEmailBestEffort($emails, new NullLogger()),
            $floor ?? new CountingPreIdentityTimingFloor(),
        );
    }

    private function assertNoWork(
        InMemoryPasswordResetTokenRepository $tokens,
        RecordingEventBus $eventBus,
        RecordingPasswordResetEmailSender $emails,
    ): void {
        $this->assertSame([], $tokens->saved);
        $this->assertSame([], $tokens->deleteAllForUserCalls);
        $this->assertSame([], $eventBus->publishedEvents);
        $this->assertSame([], $emails->sent);
    }
}
