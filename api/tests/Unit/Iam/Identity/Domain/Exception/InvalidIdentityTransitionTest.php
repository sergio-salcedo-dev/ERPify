<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Exception;

use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Iam\Identity\Domain\Exception\InvalidIdentityTransition;
use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(InvalidIdentityTransition::class)]
final class InvalidIdentityTransitionTest extends TestCase
{
    #[Test]
    public function itDescribesTheRejectedTransition(): void
    {
        $exception = InvalidIdentityTransition::from(IdentityStatus::SUSPENDED, IdentityStatus::ACTIVE);

        $this->assertSame('invalid-identity-transition', $exception->type());
        $this->assertSame('Cannot transition an identity from SUSPENDED to ACTIVE.', $exception->title());
    }

    #[Test]
    public function itIsAConflictSoThePipelineMapsItToA409(): void
    {
        // A Conflict marker (like LastActiveAdministratorProtected): an illegal transition is a well-formed,
        // authorized request colliding with the aggregate's state, which the RFC 9457 pipeline maps to 409.
        $this->assertTrue(
            (new ReflectionClass(InvalidIdentityTransition::class))->implementsInterface(Conflict::class),
        );
    }
}
