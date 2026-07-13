<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\RequestPasswordReset;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(RequestPasswordReset::class)]
final class RequestPasswordResetTest extends TestCase
{
    public function testActiveIdentitySupersedesMintsPersistsAndEmits(): void
    {
        $tokens = new InMemoryPasswordResetTokenRepository();
        $eventBus = new RecordingEventBus();
        $this->useCase(new InMemoryUserRepository(UserMother::create()), $tokens, $eventBus)
            ->request(UserMother::DEFAULT_EMAIL)
        ;

        $this->assertSame([UserMother::DEFAULT_ID], $tokens->deleteAllForUserCalls);
        $this->assertCount(1, $tokens->saved);
        $this->assertSame(UserMother::DEFAULT_ID, $tokens->saved[0]->userId());
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertSame('erpify.iam.identity.password-reset-requested', $eventBus->publishedEvents[0]::eventName());
        $this->assertSame(UserMother::DEFAULT_ID, $eventBus->publishedEvents[0]->aggregateId());
    }

    public function testUnknownEmailTouchesNothing(): void
    {
        $tokens = new InMemoryPasswordResetTokenRepository();
        $eventBus = new RecordingEventBus();
        $this->useCase(new InMemoryUserRepository(), $tokens, $eventBus)->request('nobody@erpify.test');

        $this->assertNoWork($tokens, $eventBus);
    }

    public function testMalformedEmailTouchesNothing(): void
    {
        $tokens = new InMemoryPasswordResetTokenRepository();
        $eventBus = new RecordingEventBus();
        $this->useCase(new InMemoryUserRepository(UserMother::create()), $tokens, $eventBus)->request('   ');

        $this->assertNoWork($tokens, $eventBus);
    }

    #[DataProvider('provideNonActiveIdentityTouchesNothingCases')]
    public function testNonActiveIdentityTouchesNothing(User $user): void
    {
        $tokens = new InMemoryPasswordResetTokenRepository();
        $eventBus = new RecordingEventBus();
        $this->useCase(new InMemoryUserRepository($user), $tokens, $eventBus)->request(UserMother::DEFAULT_EMAIL);

        $this->assertNoWork($tokens, $eventBus);
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
    ): RequestPasswordReset {
        return new RequestPasswordReset(
            $users,
            $tokens,
            $eventBus,
            new InlineTransactionManager(),
            new FixedClock(new DateTimeImmutable('2026-07-13T12:00:00+00:00')),
        );
    }

    private function assertNoWork(
        InMemoryPasswordResetTokenRepository $tokens,
        RecordingEventBus $eventBus,
    ): void {
        $this->assertSame([], $tokens->saved);
        $this->assertSame([], $tokens->deleteAllForUserCalls);
        $this->assertSame([], $eventBus->publishedEvents);
    }
}
