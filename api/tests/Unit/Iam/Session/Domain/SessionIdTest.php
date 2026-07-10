<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Domain;

use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SessionId::class)]
final class SessionIdTest extends TestCase
{
    public function testGenerateMintsAWellFormedUuid(): void
    {
        $sessionId = SessionId::generate();

        $this->assertTrue(Uuid::isValid($sessionId->toString()));
    }

    public function testFromStringRoundTripsAWellFormedValue(): void
    {
        $value = Uuid::generate();

        $this->assertSame($value, SessionId::fromString($value)->toString());
    }

    public function testFromStringRejectsAMalformedValueAtTheEdge(): void
    {
        $this->expectException(InvalidUuidException::class);

        SessionId::fromString('not-a-uuid');
    }

    public function testTwoIdsWithTheSameValueAreEqual(): void
    {
        $value = Uuid::generate();

        $this->assertTrue(SessionId::fromString($value)->equals(SessionId::fromString($value)));
    }

    public function testTwoDistinctIdsAreNotEqual(): void
    {
        $this->assertFalse(SessionId::generate()->equals(SessionId::generate()));
    }
}
