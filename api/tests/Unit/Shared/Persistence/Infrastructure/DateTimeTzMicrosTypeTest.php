<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Persistence\Infrastructure;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Erpify\Shared\Persistence\Infrastructure\DateTimeTzMicrosType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(DateTimeTzMicrosType::class)]
final class DateTimeTzMicrosTypeTest extends TestCase
{
    public function testDeclaresMicrosecondTimezoneAwareTimestamp(): void
    {
        $declaration = (new DateTimeTzMicrosType())->getSQLDeclaration([], new PostgreSQLPlatform());

        $this->assertSame('TIMESTAMP(6) WITH TIME ZONE', $declaration);
    }

    public function testParsesTheMicrosecondValuePostgresReturns(): void
    {
        $type = new DateTimeTzMicrosType();

        $value = $type->convertToPHPValue('2026-06-23 12:34:56.789012+00', new PostgreSQLPlatform());

        $this->assertSame('2026-06-23 12:34:56.789012', $value->format('Y-m-d H:i:s.u'));
    }

    public function testKeepsMicrosecondsWhenConvertingToDatabaseValue(): void
    {
        $dateTime = new DateTimeImmutable('2026-06-23 12:34:56.789012+00:00');

        $value = (new DateTimeTzMicrosType())->convertToDatabaseValue($dateTime, new PostgreSQLPlatform());

        $this->assertSame('2026-06-23 12:34:56.789012+00:00', $value);
    }
}
