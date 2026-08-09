<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * The equality contract the firewall asks on every authenticated request: whether the copy of the identity
 * carried in the session still matches the one the provider just reloaded. Kept apart from the adapter's
 * accessors because it answers a different question — not "what does this identity expose to Symfony" but
 * "does this session still stand" — and because each clause it compares is a separate refusal.
 *
 * @internal
 */
#[CoversClass(SecurityUser::class)]
final class SecurityUserEqualityTest extends TestCase
{
    public function testIsEqualToAnIdentityCarryingTheSameIdentifierCredentialAndRoles(): void
    {
        $securityUser = new SecurityUser(UserMother::create(roles: [Role::MANAGER]));

        $this->assertTrue($securityUser->isEqualTo(new SecurityUser(UserMother::create(roles: [Role::MANAGER]))));
    }

    public function testIsNotEqualWhenTheCredentialDiffers(): void
    {
        $securityUser = new SecurityUser(UserMother::create(password: HashedPassword::fromHash('the-old-hash')));
        $reloaded = new SecurityUser(UserMother::create(password: HashedPassword::fromHash('the-new-hash')));

        $this->assertFalse($securityUser->isEqualTo($reloaded));
    }

    public function testIsNotEqualWhenTheRoleSetDiffers(): void
    {
        $securityUser = new SecurityUser(UserMother::create(roles: [Role::MANAGER]));

        $this->assertFalse($securityUser->isEqualTo(new SecurityUser(UserMother::create(roles: [Role::VIEWER]))));
    }

    public function testIsEqualWhenTheSameRolesArriveInADifferentOrder(): void
    {
        // Role order is a property of the JSON column, not of the identity: ejecting live sessions over a
        // reordered column would be a denial of service dressed as a security check.
        $securityUser = new SecurityUser(UserMother::create(roles: [Role::MANAGER, Role::AUDIT_READER]));
        $reloaded = new SecurityUser(UserMother::create(roles: [Role::AUDIT_READER, Role::MANAGER]));

        $this->assertTrue($securityUser->isEqualTo($reloaded));
    }

    public function testIsNotEqualWhenTheIdentifierDiffers(): void
    {
        // Unreachable through the firewall, which resolves the reload BY this identifier — asserted here
        // because equality is a public contract, and one that ignored the identifier would call two different
        // people the same person the moment a caller resolved the reload by anything else.
        $securityUser = new SecurityUser(UserMother::create(email: 'alice@erpify.test'));
        $another = new SecurityUser(UserMother::create(email: 'mallory@erpify.test'));

        $this->assertFalse($securityUser->isEqualTo($another));
    }

    public function testIsEqualWhenBothIdentitiesAreCredentialLess(): void
    {
        $securityUser = new SecurityUser(UserMother::invited());

        $this->assertTrue($securityUser->isEqualTo(new SecurityUser(UserMother::invited())));
    }

    public function testIsNotEqualWhenTheStoredCredentialCannotBeRead(): void
    {
        // A hash the value object refuses is unreachable through the aggregate's own writer, so it is planted
        // the only way persistence can produce it: straight onto the property, the way hydration does. An
        // unreadable credential must refuse here rather than escape as a 500 from inside the firewall, which
        // reads this copy through no provider and so guards it with nothing.
        $securityUser = new SecurityUser($this->withUnreadableCredential());

        $this->assertFalse($securityUser->isEqualTo(new SecurityUser(UserMother::create())));
    }

    public function testIsNotEqualToAUserOfAnotherType(): void
    {
        $securityUser = new SecurityUser(UserMother::create());

        $this->assertFalse($securityUser->isEqualTo(new InMemoryUser('alice@erpify.test', UserMother::DEFAULT_HASH)));
    }

    private function withUnreadableCredential(): User
    {
        $user = UserMother::create();

        $property = new ReflectionProperty(User::class, 'passwordHash');
        $property->setValue($user, '');

        return $user;
    }
}
