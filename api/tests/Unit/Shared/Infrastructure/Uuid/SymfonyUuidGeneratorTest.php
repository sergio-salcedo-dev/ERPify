<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Infrastructure\Uuid;

use Erpify\Shared\Infrastructure\Uuid\SymfonyUuidGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * @internal
 */
#[CoversClass(SymfonyUuidGenerator::class)]
final class SymfonyUuidGeneratorTest extends TestCase
{
    public function testReturnsValidRfc4122Uuid(): void
    {
        $value = SymfonyUuidGenerator::generate();

        $this->assertTrue(Uuid::isValid($value), \sprintf('"%s" is not a valid UUID.', $value));
        $this->assertSame(36, \strlen($value));
    }

    public function testTwoConsecutiveCallsYieldDistinctValues(): void
    {
        $first = SymfonyUuidGenerator::generate();
        $second = SymfonyUuidGenerator::generate();

        $this->assertNotSame($first, $second);
    }

    public function testGeneratesTimeOrderedUuidV7(): void
    {
        // App-assigned ids are authoritative and must be UUID v7 so they stay time-ordered and match
        // the persisted PKs (Doctrine no longer overwrites them). See spec-shared-aggregate-id-mismatch.
        $value = SymfonyUuidGenerator::generate();

        $this->assertInstanceOf(UuidV7::class, Uuid::fromString($value));
    }
}
