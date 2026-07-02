<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Identity\Domain;

use Erpify\Backoffice\Identity\Domain\Exception\InvalidHashedPassword;
use Erpify\Backoffice\Identity\Domain\HashedPassword;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(HashedPassword::class)]
final class HashedPasswordTest extends TestCase
{
    public function testFromHashRejectsAnEmptyString(): void
    {
        $this->expectException(InvalidHashedPassword::class);

        HashedPassword::fromHash('');
    }

    public function testExposesTheHashItWasBuiltFrom(): void
    {
        $this->assertSame('an-opaque-hash', HashedPassword::fromHash('an-opaque-hash')->toString());
    }

    public function testEqualityIsByValue(): void
    {
        $hash = HashedPassword::fromHash('same-hash');

        $this->assertTrue($hash->equals(HashedPassword::fromHash('same-hash')));
        $this->assertFalse($hash->equals(HashedPassword::fromHash('other-hash')));
    }
}
