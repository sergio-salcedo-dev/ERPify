<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Invitation\Domain\Exception;

use Erpify\Iam\Invitation\Domain\Exception\InvalidToken;
use Erpify\Shared\ErrorContract\Domain\Exception\InvalidInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(InvalidToken::class)]
final class InvalidTokenTest extends TestCase
{
    #[Test]
    public function itIsAnOpaqueInvalidInputWithTheInvalidTokenType(): void
    {
        $exception = new InvalidToken();

        $this->assertTrue((new ReflectionClass(InvalidToken::class))->implementsInterface(InvalidInput::class));
        $this->assertSame('invalid-token', $exception->type());
        $this->assertSame('This link is no longer valid.', $exception->title());
        $this->assertSame([], $exception->context());
    }
}
