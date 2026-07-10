<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Exception;

use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Iam\Identity\Domain\Exception\InvalidIdentityTransition;
use Erpify\Shared\ErrorContract\Domain\Exception\ClientError;
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
    public function itIsMarkerLessSoThePipelineMapsItToAServerFault(): void
    {
        // No ClientError marker: an illegal transition is an internal precondition fault, not a 4xx client
        // error, so the RFC 9457 pipeline maps it to a generic 500 and keeps it Sentry-visible.
        $this->assertFalse(
            (new ReflectionClass(InvalidIdentityTransition::class))->implementsInterface(ClientError::class),
        );
    }
}
