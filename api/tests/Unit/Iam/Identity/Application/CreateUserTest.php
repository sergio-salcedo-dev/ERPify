<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\CreateUser;
use Erpify\Iam\Identity\Domain\Enum\Role;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Shared\Validation\Application\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @internal
 */
#[CoversClass(CreateUser::class)]
final class CreateUserTest extends TestCase
{
    public function testRegistersAndPersistsTheUser(): void
    {
        $users = new InMemoryUserRepository();
        $creator = new CreateUser($users, $this->passingValidator());

        $user = $creator->create(
            'Alice@Erpify.Test',
            HashedPassword::fromHash('a-precomputed-hash'),
            Role::AUDIT_READER,
        );

        $this->assertSame([$user], $users->saved);
        $this->assertSame('alice@erpify.test', $user->email());
        $this->assertSame([Role::AUDIT_READER], $user->roles());
    }

    public function testAllowsARolelessUser(): void
    {
        $users = new InMemoryUserRepository();
        $creator = new CreateUser($users, $this->passingValidator());

        $user = $creator->create('bob@erpify.test', HashedPassword::fromHash('another-hash'));

        $this->assertSame([], $user->roles());
        $this->assertCount(1, $users->saved);
    }

    private function passingValidator(): Validator
    {
        $inner = $this->createStub(ValidatorInterface::class);
        $inner->method('validate')->willReturn(new ConstraintViolationList());

        return new Validator($inner);
    }
}
