<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Infrastructure\Security\PasswordHashingTimingFloor;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

/**
 * @internal
 */
#[CoversClass(PasswordHashingTimingFloor::class)]
final class PasswordHashingTimingFloorTest extends TestCase
{
    public function testPaysOneVerificationOfTheFirewallsOwnHasherPerEqualisation(): void
    {
        $hasher = $this->createMock(PasswordHasherInterface::class);
        $hasher->expects($this->once())->method('hash')->willReturn('dummy-hash');
        $hasher->expects($this->exactly(2))
            ->method('verify')
            ->with('dummy-hash', $this->anything())
            ->willReturn(false)
        ;

        $factory = $this->createMock(PasswordHasherFactoryInterface::class);
        $factory->expects($this->exactly(2))
            ->method('getPasswordHasher')
            ->with(SecurityUser::class)
            ->willReturn($hasher)
        ;

        $floor = new PasswordHashingTimingFloor($factory);

        // The dummy is hashed once and reused: each equalisation costs exactly one verification — the same
        // work a known email with a wrong password pays — never a fresh hash per probe.
        $floor->equalise();
        $floor->equalise();
    }
}
