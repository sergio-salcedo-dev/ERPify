<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Crypto\Domain\Exception;

use Erpify\Shared\Crypto\Domain\Exception\InvalidKek;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(InvalidKek::class)]
final class InvalidKekTest extends TestCase
{
    #[Test]
    public function itNamesTheRequiredKeyLengthWithoutCarryingTheKey(): void
    {
        $exception = InvalidKek::wrongLength(32);

        $this->assertSame(InvalidKek::TYPE, $exception->type());
        $this->assertSame('invalid-kek', $exception->type());
        $this->assertSame('The key-encryption key must be exactly 32 bytes.', $exception->title());
        $this->assertSame([], $exception->context());
    }
}
