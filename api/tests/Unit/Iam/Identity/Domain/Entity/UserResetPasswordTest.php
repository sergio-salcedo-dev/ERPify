<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Entity;

use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Iam\Identity\Domain\Exception\InvalidIdentityTransition;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(User::class)]
final class UserResetPasswordTest extends TestCase
{
    public function testReplacesTheCredentialOfAnActiveIdentityWithoutChangingItsStatus(): void
    {
        $user = UserMother::create();
        $newHash = HashedPassword::fromHash('new-argon2id-hash');

        $user->resetPassword($newHash);

        $this->assertTrue($user->passwordHash()?->equals($newHash));
        $this->assertSame(IdentityStatus::ACTIVE, $user->status());
        $this->assertTrue($user->isActive());
    }

    public function testIsActiveIsTrueOnlyForAnActiveIdentity(): void
    {
        $this->assertTrue(UserMother::create()->isActive());
        $this->assertFalse(UserMother::invited()->isActive());
    }

    public function testRecordsPasswordResetCompletedForTheAggregate(): void
    {
        $user = UserMother::create();

        $user->resetPassword(HashedPassword::fromHash('new-argon2id-hash'));

        $events = $user->pullDomainEvents();
        $this->assertCount(1, $events);
        $this->assertSame('erpify.iam.identity.password-reset-completed', $events[0]::eventName());
        $this->assertSame($user->getId(), $events[0]->aggregateId());
    }

    #[DataProvider('provideRejectsResettingANonActiveIdentityCases')]
    public function testRejectsResettingANonActiveIdentity(User $user): void
    {
        $this->expectException(InvalidIdentityTransition::class);

        $user->resetPassword(HashedPassword::fromHash('new-argon2id-hash'));
    }

    /**
     * @return iterable<string, array{User}>
     */
    public static function provideRejectsResettingANonActiveIdentityCases(): iterable
    {
        $suspended = UserMother::create();
        $suspended->suspend();

        $deactivated = UserMother::create();
        $deactivated->deactivate();

        yield 'invited' => [UserMother::invited()];
        yield 'suspended' => [$suspended];
        yield 'deactivated' => [$deactivated];
    }
}
