<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\RequestPasswordReset;
use Erpify\Iam\Identity\Application\SendPasswordResetEmailBestEffort;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @internal
 */
#[CoversClass(RequestPasswordReset::class)]
final class RequestPasswordResetTest extends TestCase
{
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

    private function useCase(
        InMemoryUserRepository $users,
        InMemoryPasswordResetTokenRepository $tokens,
        RecordingEventBus $eventBus,
        RecordingPasswordResetEmailSender $emails,
    ): RequestPasswordReset {
        return new RequestPasswordReset(
            $users,
            $tokens,
            $eventBus,
            new InlineTransactionManager(),
            new FixedClock(new DateTimeImmutable('2026-07-13T12:00:00+00:00')),
            new SendPasswordResetEmailBestEffort($emails, new NullLogger()),
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
