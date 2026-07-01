<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Crypto\Domain\Exception;

use Erpify\Shared\Crypto\Domain\Exception\InvalidEncryptionScopeId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(InvalidEncryptionScopeId::class)]
final class InvalidEncryptionScopeIdTest extends TestCase
{
    #[Test]
    public function itDescribesTheExpectedShapeWithoutCarryingTheOffendingValue(): void
    {
        $exception = InvalidEncryptionScopeId::malformed();

        $this->assertSame(InvalidEncryptionScopeId::TYPE, $exception->type());
        $this->assertSame('invalid-encryption-scope-id', $exception->type());
        $this->assertSame('Encryption scope id must be a "<TYPE>:<uuid>" pair.', $exception->title());
        $this->assertSame([], $exception->context());
    }
}
