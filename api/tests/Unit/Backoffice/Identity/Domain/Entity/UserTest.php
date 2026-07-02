<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Identity\Domain\Entity;

use Erpify\Backoffice\Identity\Domain\Entity\User;
use Erpify\Backoffice\Identity\Domain\Enum\Role;
use Erpify\Backoffice\Identity\Domain\HashedPassword;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\AuditedEntity;
use Erpify\Tests\Unit\Backoffice\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(User::class)]
final class UserTest extends TestCase
{
    public function testRegisterExposesTheProvidedIdentityCredentialAndRoles(): void
    {
        $password = HashedPassword::fromHash('a-precomputed-hash');

        $user = User::register(UserMother::DEFAULT_ID, 'bob@erpify.test', $password, Role::AUDIT_READER);

        $this->assertSame(UserMother::DEFAULT_ID, $user->getId());
        $this->assertSame('bob@erpify.test', $user->email());
        $this->assertTrue($user->passwordHash()->equals($password));
        $this->assertSame([Role::AUDIT_READER], $user->roles());
    }

    public function testEmailIsStoredCanonicalisedInLowercase(): void
    {
        $user = UserMother::create(email: '  Alice@ERPify.TEST  ');

        $this->assertSame('alice@erpify.test', $user->email());
    }

    public function testRolesRoundTripThroughTheEnum(): void
    {
        $user = UserMother::create(roles: [Role::AUDIT_READER]);

        $this->assertSame([Role::AUDIT_READER], $user->roles());
    }

    public function testDuplicateRolesCollapseToADistinctSet(): void
    {
        $user = UserMother::create(roles: [Role::AUDIT_READER, Role::AUDIT_READER]);

        $this->assertSame([Role::AUDIT_READER], $user->roles());
    }

    public function testAUserWithoutRolesIsLegitimate(): void
    {
        $user = UserMother::create(roles: []);

        $this->assertSame([], $user->roles());
    }

    public function testIdentifierSatisfiesActorContextAttribution(): void
    {
        $id = UserMother::create()->getId();
        $this->assertNotNull($id);

        $actor = ActorContext::forUser($id);

        $this->assertSame($id, $actor->actorId);
    }

    public function testIsNotAuditedSoTheCredentialNeverEntersTheTrail(): void
    {
        $this->assertFalse((new ReflectionClass(User::class))->implementsInterface(AuditedEntity::class));
    }
}
