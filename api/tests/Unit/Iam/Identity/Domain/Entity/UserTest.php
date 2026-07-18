<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Entity;

use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\AuditedEntity;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Validator\Validation;

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
        $passwordHash = $user->passwordHash();
        $this->assertInstanceOf(HashedPassword::class, $passwordHash);
        $this->assertTrue($passwordHash->equals($password));
        $this->assertSame([Role::AUDIT_READER], $user->roles());
    }

    public function testEmailIsStoredCanonicalisedInLowercase(): void
    {
        $user = UserMother::create(email: '  Alice@ERPify.TEST  ');

        $this->assertSame('alice@erpify.test', $user->email());
    }

    public function testMalformedEmailIsRejectedByTheStrictFormatConstraint(): void
    {
        // Validate only the `email` property: the class-level UniqueEntity needs the Doctrine validator
        // service (exercised through the container at the write boundary), out of scope for this unit.
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $malformed = User::register(
            UserMother::DEFAULT_ID,
            'not-an-email',
            HashedPassword::fromHash('h'),
            Role::AUDIT_READER,
        );

        $this->assertGreaterThan(0, $validator->validateProperty($malformed, 'email')->count());
        $this->assertCount(0, $validator->validateProperty(UserMother::create(), 'email'));
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
