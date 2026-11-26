<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Domain;

use Erpify\Shared\Audit\Domain\AuditMetadataShape;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The stored-value shape records a cell as itself only when it is shaped like a role name, and otherwise as a
 * sentinel the server chose: a corrupted cell can hold anything and `audit_log.metadata` is rewritten by no erasure.
 *
 * @internal
 */
#[CoversClass(AuditMetadataShape::class)]
final class AuditMetadataShapeTest extends TestCase
{
    #[Test]
    #[DataProvider('provideARoleShapedValueIsRecordedAsItselfCases')]
    public function aRoleShapedValueIsRecordedAsItself(string $raw): void
    {
        $this->assertSame($raw, AuditMetadataShape::storedValue($raw));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideARoleShapedValueIsRecordedAsItselfCases(): iterable
    {
        yield 'a plain role' => ['SUPERVISOR'];
        yield 'with an underscore and digits' => ['GHOST_ROLE_2'];
        yield 'one character' => ['A'];
        yield 'the longest admitted length' => [\str_repeat('A', 64)];
    }

    #[Test]
    #[DataProvider('provideAnythingElseIsRecordedAsTheSentinelCases')]
    public function anythingElseIsRecordedAsTheSentinel(string $raw): void
    {
        $this->assertSame(AuditMetadataShape::NOT_ROLE_SHAPED, AuditMetadataShape::storedValue($raw));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAnythingElseIsRecordedAsTheSentinelCases(): iterable
    {
        yield 'an address' => ['jane.doe@example.test'];
        yield 'free text' => ['Jane Doe'];
        yield 'lower case' => ['supervisor'];
        yield 'a trailing newline' => ["ADMIN\n"];
        yield 'empty' => [''];
        yield 'one character past the longest admitted length' => [\str_repeat('A', 65)];
        yield 'a leading digit' => ['1ROLE'];
    }

    #[Test]
    public function anAbsentCellIsRecordedAsTheNullSentinel(): void
    {
        $this->assertSame(AuditMetadataShape::STORED_NULL, AuditMetadataShape::storedValue(null));
    }

    #[Test]
    public function theStoredValuesShapeAdmitsExactlyWhatStoredValueProduces(): void
    {
        $shape = AuditMetadataShape::StoredValues;

        $this->assertTrue($shape->admits([
            'SUPERVISOR',
            AuditMetadataShape::NOT_ROLE_SHAPED,
            AuditMetadataShape::STORED_NULL,
        ]));
        $this->assertFalse($shape->admits(['jane.doe@example.test']));
        $this->assertFalse($shape->admits([42]));
        $this->assertFalse($shape->admits('SUPERVISOR'));
    }
}
