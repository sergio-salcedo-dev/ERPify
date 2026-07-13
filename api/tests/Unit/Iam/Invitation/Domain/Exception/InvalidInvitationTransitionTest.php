<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Invitation\Domain\Exception;

use Erpify\Iam\Invitation\Domain\Enum\InvitationStatus;
use Erpify\Iam\Invitation\Domain\Exception\InvalidInvitationTransition;
use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(InvalidInvitationTransition::class)]
final class InvalidInvitationTransitionTest extends TestCase
{
    #[Test]
    public function itIsAConflictNamingBothStates(): void
    {
        $exception = InvalidInvitationTransition::from(InvitationStatus::ACCEPTED, InvitationStatus::SENT);

        $this->assertTrue(
            (new ReflectionClass(InvalidInvitationTransition::class))->implementsInterface(Conflict::class),
        );
        $this->assertSame('invalid-invitation-transition', $exception->type());
        $this->assertStringContainsString('ACCEPTED', $exception->title());
        $this->assertStringContainsString('SENT', $exception->title());
    }
}
