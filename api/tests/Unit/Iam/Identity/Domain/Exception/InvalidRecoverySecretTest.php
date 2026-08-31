<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Exception;

use Erpify\Iam\Identity\Domain\Exception\InvalidRecoverySecret;
use Erpify\Iam\Identity\Domain\Exception\InvalidResetToken;
use Erpify\Shared\ErrorContract\Domain\Exception\InvalidInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The opaque wall every death case of a redemption answers with: malformed, unknown, lapsed, wrong, already
 * spent and budget exhausted are one type and one title, so the wire says nothing about which.
 *
 * @internal
 */
#[CoversClass(InvalidRecoverySecret::class)]
final class InvalidRecoverySecretTest extends TestCase
{
    #[Test]
    public function itCarriesTheSharedTokenRefusalTypeAndTitle(): void
    {
        $exception = new InvalidRecoverySecret();

        $this->assertSame('invalid-token', $exception->type());
        $this->assertSame('This link is no longer valid.', $exception->title());
    }

    #[Test]
    public function itMapsToA400ThroughTheInvalidInputMarker(): void
    {
        $this->assertTrue(
            (new ReflectionClass(InvalidRecoverySecret::class))->implementsInterface(InvalidInput::class),
        );
    }

    #[Test]
    public function itIsIndistinguishableOnTheWireFromTheResetFlowsRefusal(): void
    {
        // Two classes, one wire shape, and the pair is deliberate: a reader who found them identical might
        // merge them, and the two flows would then be unable to diverge without changing both at once. What
        // may never diverge is what a client SEES, so that is what this pins.
        $recovery = new InvalidRecoverySecret();
        $reset = new InvalidResetToken();

        $this->assertSame($reset->type(), $recovery->type());
        $this->assertSame($reset->title(), $recovery->title());
    }
}
