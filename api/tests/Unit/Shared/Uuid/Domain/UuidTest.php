<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Uuid\Domain;

use Erpify\Shared\ErrorContract\Domain\Exception\InvalidInput;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Shared\Uuid\Domain\Uuid as DomainUuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * @internal
 */
#[CoversClass(DomainUuid::class)]
#[CoversClass(InvalidUuidException::class)]
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

    public function testIsValidAcceptsAWellFormedUuid(): void
    {
        $this->assertTrue(DomainUuid::isValid(DomainUuid::generate()));
        // isValid checks format, not version: a v4 string is a valid identifier.
        $this->assertTrue(DomainUuid::isValid(Uuid::v4()->toRfc4122()));
    }

    public function testIsValidRejectsAMalformedValue(): void
    {
        $this->assertFalse(DomainUuid::isValid('not-a-uuid'));
    }

    public function testEnsureAcceptsAnAppMintedUuid(): void
    {
        $this->expectNotToPerformAssertions();

        DomainUuid::ensure(DomainUuid::generate());
    }

    public function testEnsureAcceptsAnArbitraryValidRfc4122Uuid(): void
    {
        $this->expectNotToPerformAssertions();

        // ensure() validates UUID format, not version: a v4 string is a valid identifier.
        DomainUuid::ensure(Uuid::v4()->toRfc4122());
    }

    public function testEnsureRejectsAMalformedValue(): void
    {
        $this->expectException(InvalidUuidException::class);

        DomainUuid::ensure('not-a-uuid');
    }

    public function testInvalidUuidExceptionCarriesTheInvalidInputMarker(): void
    {
        // The marker drives the RFC 9457 mapping (InvalidInput -> 400 invalid-input).
        $this->assertContains(InvalidInput::class, (array) \class_implements(InvalidUuidException::class));
    }

    public function testInvalidUuidExceptionExposesItsStableProblemType(): void
    {
        $this->assertSame('invalid-uuid', (new InvalidUuidException())->type());
    }
}
