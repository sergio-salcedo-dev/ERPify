<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Infrastructure\Security\DeactivatedAccountException;
use Erpify\Iam\Identity\Infrastructure\Security\InvitedAccountException;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Iam\Identity\Infrastructure\Security\SuspendedAccountException;
use Erpify\Iam\Identity\Infrastructure\Security\UserChecker;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @internal
 */
#[CoversClass(UserChecker::class)]
final class UserCheckerTest extends TestCase
{
    public function testPreAuthRejectsAnInvitedIdentityBeforeAnyPasswordCheck(): void
    {
        $this->expectException(InvitedAccountException::class);

        (new UserChecker())->checkPreAuth(new SecurityUser(UserMother::invited()));
    }

    public function testPreAuthAdmitsAnActiveIdentity(): void
    {
        $this->expectNotToPerformAssertions();

        (new UserChecker())->checkPreAuth(new SecurityUser(UserMother::create()));
    }

    public function testPostAuthRaisesTheSuspendedWall(): void
    {
        $this->expectException(SuspendedAccountException::class);

        (new UserChecker())->checkPostAuth(new SecurityUser($this->suspended()));
    }

    public function testPostAuthRaisesTheDeactivatedWall(): void
    {
        $this->expectException(DeactivatedAccountException::class);

        (new UserChecker())->checkPostAuth(new SecurityUser($this->deactivated()));
    }

    public function testPostAuthAdmitsAnActiveIdentity(): void
    {
        $this->expectNotToPerformAssertions();

        (new UserChecker())->checkPostAuth(new SecurityUser(UserMother::create()));
    }

    public function testIgnoresAUserItDoesNotOwn(): void
    {
        $this->expectNotToPerformAssertions();

        $checker = new UserChecker();
        $foreign = $this->createStub(UserInterface::class);

        $checker->checkPreAuth($foreign);
        $checker->checkPostAuth($foreign);
    }

    private function suspended(): User
    {
        $user = UserMother::create();
        $user->suspend();

        return $user;
    }

    private function deactivated(): User
    {
        $user = UserMother::create();
        $user->deactivate();

        return $user;
    }
}
