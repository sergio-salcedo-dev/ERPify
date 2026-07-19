<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Entity;

use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(User::class)]
final class UserChangeRolesTest extends TestCase
{
    public function testReplacesTheWholeSetRatherThanMergingIntoIt(): void
    {
        $user = UserMother::create(roles: [Role::VIEWER, Role::AUDIT_READER]);

        $user->changeRoles(Role::EDITOR);

        $this->assertSame([Role::EDITOR], $user->roles());
    }

    public function testCollapsesDuplicatesInTheIncomingSet(): void
    {
        $user = UserMother::create(roles: [Role::VIEWER]);

        $user->changeRoles(Role::EDITOR, Role::EDITOR, Role::MANAGER);

        $this->assertSame([Role::EDITOR, Role::MANAGER], $user->roles());
    }

    public function testRecordsUserRolesChangedCarryingTheResultingSet(): void
    {
        $user = UserMother::create(roles: [Role::VIEWER]);

        $user->changeRoles(Role::MANAGER, Role::MANAGER, Role::AUDIT_READER);

        $events = $user->pullDomainEvents();
        $this->assertCount(1, $events);
        $this->assertSame('erpify.iam.identity.roles-changed', $events[0]::eventName());
        $this->assertSame($user->getId(), $events[0]->aggregateId());
        // The deduplicated set, so a consumer never has to repeat the aggregate's own normalisation.
        $this->assertSame(['roles' => ['MANAGER', 'AUDIT_READER']], $events[0]->toPrimitives());
    }

    /**
     * Roles are orthogonal to the lifecycle, so unlike suspend/deactivate there is no legal-predecessor guard:
     * every non-erased status accepts a new set.
     */
    #[DataProvider('provideAcceptsANewSetInAnyStatusCases')]
    public function testAcceptsANewSetInAnyStatus(User $user, IdentityStatus $expectedStatus): void
    {
        $user->changeRoles(Role::MANAGER);

        $this->assertSame([Role::MANAGER], $user->roles());
        $this->assertSame($expectedStatus, $user->status());
    }

    /**
     * @return iterable<string, array{User, IdentityStatus}>
     */
    public static function provideAcceptsANewSetInAnyStatusCases(): iterable
    {
        $suspended = UserMother::create();
        $suspended->suspend();

        $deactivated = UserMother::create();
        $deactivated->deactivate();

        yield 'active' => [UserMother::create(), IdentityStatus::ACTIVE];
        yield 'invited' => [UserMother::invited(), IdentityStatus::INVITED];
        yield 'suspended' => [$suspended, IdentityStatus::SUSPENDED];
        yield 'deactivated' => [$deactivated, IdentityStatus::DEACTIVATED];
    }
}
