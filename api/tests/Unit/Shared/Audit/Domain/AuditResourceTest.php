<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Domain;

use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Audit\Domain\Exception\InvalidAuditLogEntry;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AuditResource::class)]
#[CoversClass(InvalidAuditLogEntry::class)]
final class AuditResourceTest extends TestCase
{
    public function testOfBindsTypeAndId(): void
    {
        $id = Uuid::generate();

        $resource = AuditResource::of('Bank', $id);

        $this->assertSame('Bank', $resource->type);
        $this->assertSame($id, $resource->id);
    }

    #[DataProvider('provideRejectsABlankTypeCases')]
    public function testRejectsABlankType(string $blank): void
    {
        $this->expectException(InvalidAuditLogEntry::class);

        AuditResource::of($blank, Uuid::generate());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRejectsABlankTypeCases(): iterable
    {
        yield 'empty string' => [''];
        yield 'whitespace only' => ['   '];
    }

    public function testRejectsANonUuidResourceId(): void
    {
        $this->expectException(InvalidAuditLogEntry::class);

        AuditResource::of('BankAccount', 'not-a-uuid');
    }
}
