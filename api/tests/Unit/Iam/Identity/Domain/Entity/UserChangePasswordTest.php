<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Entity;

use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Iam\Identity\Domain\Exception\InvalidIdentityTransition;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Shared\Event\Domain\DomainEvent;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(User::class)]
final class UserChangePasswordTest extends TestCase
{
    public function testReplacesTheCredentialOfAnActiveIdentityWithoutChangingItsStatus(): void
    {
        $user = UserMother::create();
        $newHash = HashedPassword::fromHash('new-argon2id-hash');

        $user->changePassword($newHash);

        $this->assertTrue($user->passwordHash()?->equals($newHash));
        $this->assertSame(IdentityStatus::ACTIVE, $user->status());
    }

    public function testRecordsPasswordChangedForTheAggregate(): void
    {
        $user = UserMother::create();

        $user->changePassword(HashedPassword::fromHash('new-argon2id-hash'));

        $events = $user->pullDomainEvents();
        $this->assertCount(1, $events);
        $this->assertSame('erpify.iam.identity.password-changed', $events[0]::eventName());
        $this->assertSame($user->getId(), $events[0]->aggregateId());
    }

    /**
     * The self-service change and the recovery reset are two different facts about how a credential was
     * replaced, and the durable log is the only place that distinction survives. Asserting the reset name is
     * absent is what would fail if the change were ever implemented by delegating to `resetPassword()`.
     */
    public function testDoesNotRecordTheRecoveryFact(): void
    {
        $user = UserMother::create();

        $user->changePassword(HashedPassword::fromHash('new-argon2id-hash'));

        $names = \array_map(
            static fn (DomainEvent $event): string => $event::eventName(),
            $user->pullDomainEvents(),
        );
        $this->assertNotContains('erpify.iam.identity.password-reset-completed', $names);
    }

    #[DataProvider('provideRejectsChangingANonActiveIdentityCases')]
    public function testRejectsChangingANonActiveIdentity(User $user): void
    {
        $this->expectException(InvalidIdentityTransition::class);

        $user->changePassword(HashedPassword::fromHash('new-argon2id-hash'));
    }

    /**
     * @return iterable<string, array{User}>
     */
    public static function provideRejectsChangingANonActiveIdentityCases(): iterable
    {
        $suspended = UserMother::create();
        $suspended->suspend();

        $deactivated = UserMother::create();
        $deactivated->deactivate();

        yield 'invited' => [UserMother::invited()];
        yield 'suspended' => [$suspended];
        yield 'deactivated' => [$deactivated];
    }

    /**
     * A refused change must leave the aggregate exactly as it was — no credential written, and above all no
     * event queued for a caller that later pulls them on an unrelated write.
     */
    public function testARefusedChangeMutatesNothingAndRecordsNothing(): void
    {
        $user = UserMother::create();
        $user->suspend();
        $user->pullDomainEvents();

        try {
            $user->changePassword(HashedPassword::fromHash('new-argon2id-hash'));
            $this->fail('Expected ' . InvalidIdentityTransition::class);
        } catch (InvalidIdentityTransition) {
            $this->assertSame(UserMother::DEFAULT_HASH, $user->passwordHash()?->toString());
            $this->assertSame([], $user->pullDomainEvents());
        }
    }
}
