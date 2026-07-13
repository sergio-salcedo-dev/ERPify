<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Exception;

use Erpify\Iam\Identity\Domain\Exception\InvalidResetToken;
use Erpify\Shared\ErrorContract\Domain\Exception\InvalidInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(InvalidResetToken::class)]
final class InvalidResetTokenTest extends TestCase
{
    public function testCarriesTheInvalidInputMarkerSoItMapsToA400(): void
    {
        $this->assertContains(InvalidInput::class, \class_implements(new InvalidResetToken()));
    }

    public function testOverridesTheTypeToTheSharedInvalidTokenWireType(): void
    {
        $exception = new InvalidResetToken();

        $this->assertSame('invalid-token', $exception->type());
        $this->assertSame('This link is no longer valid.', $exception->title());
    }
}
