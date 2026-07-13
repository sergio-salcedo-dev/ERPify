<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\InviteUser;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Iam\Identity\Domain\Enum\Role;
use Erpify\Shared\Validation\Application\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @internal
 */
#[CoversClass(InviteUser::class)]
final class InviteUserTest extends TestCase
{
    #[Test]
    public function itProvisionsAnInvitedCredentiallessIdentityWithRoles(): void
    {
        $users = new InMemoryUserRepository();

        $user = (new InviteUser($users, $this->passingValidator()))->invite('Alice@Erpify.Test', Role::EDITOR);

        $this->assertSame([$user], $users->saved);
        $this->assertSame('alice@erpify.test', $user->email());
        $this->assertSame(IdentityStatus::INVITED, $user->status());
        $this->assertNotInstanceOf(\Erpify\Iam\Identity\Domain\HashedPassword::class, $user->passwordHash());
        $this->assertSame([Role::EDITOR], $user->roles());
    }

    #[Test]
    public function itAllowsARolelessInvite(): void
    {
        $users = new InMemoryUserRepository();

        $user = (new InviteUser($users, $this->passingValidator()))->invite('bob@erpify.test');

        $this->assertSame([], $user->roles());
        $this->assertSame(IdentityStatus::INVITED, $user->status());
    }

    private function passingValidator(): Validator
    {
        $inner = $this->createStub(ValidatorInterface::class);
        $inner->method('validate')->willReturn(new ConstraintViolationList());

        return new Validator($inner);
    }
}
