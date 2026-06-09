<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Domain\Uuid;

use Erpify\Shared\Domain\Uuid\Uuid as DomainUuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * @internal
 */
#[CoversClass(DomainUuid::class)]
final class UuidTest extends TestCase
{
    public function testReturnsValidRfc4122Uuid(): void
    {
        $value = DomainUuid::generate();

        $this->assertTrue(Uuid::isValid($value), \sprintf('"%s" is not a valid UUID.', $value));
        $this->assertSame(36, \strlen($value));
    }

    public function testTwoConsecutiveCallsYieldDistinctValues(): void
    {
        $first = DomainUuid::generate();
        $second = DomainUuid::generate();

        $this->assertNotSame($first, $second);
    }

    public function testGeneratesTimeOrderedUuidV7(): void
    {
        // App-assigned ids are authoritative and must be UUID v7 so they stay time-ordered and match
        // the persisted PKs (Doctrine no longer overwrites them). See spec-shared-aggregate-id-mismatch.
        $value = DomainUuid::generate();

        $this->assertInstanceOf(UuidV7::class, Uuid::fromString($value));
    }
}
