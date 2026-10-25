<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Application;

use DateTimeImmutable;
use Erpify\Shared\Audit\Application\AuditLogEntry;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\Exception\InvalidAuditLogEntry;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The size bound of `audit_log.metadata`, and where it sits against what the tree legitimately writes.
 *
 * @internal
 */
#[CoversClass(AuditLogEntry::class)]
#[CoversClass(InvalidAuditLogEntry::class)]
final class AuditLogEntryMetadataBoundTest extends TestCase
{
    public function testAdmitsAnEntryWithNoMetadata(): void
    {
        $this->assertSame([], $this->entry(AuditLevel::ACTIVITY, [])->metadata);
    }

    /**
     * The bound must sit above the largest `change` entry a legitimate write produces, because a refused one
     * fails the write itself. This builds that entry: a `Bank` update moving `name` and `nameNormalized` at
     * their 255-character column width and `shortName` at 50, plus the three inherited fields — once with
     * every character four UTF-8 bytes, once with every character a control character JSON escapes as six.
     */
    #[DataProvider('provideAdmitsTheLargestChangeEntryALegitimateWriteProducesCases')]
    public function testAdmitsTheLargestChangeEntryALegitimateWriteProduces(string $character): void
    {
        $wide = static fn (int $length): string => \str_repeat($character, $length);
        $changes = [
            'id' => ['old' => null, 'new' => Uuid::generate()],
            'createdAt' => ['old' => null, 'new' => '2026-10-23T12:59:47+00:00'],
            'updatedAt' => ['old' => null, 'new' => '2026-10-23T12:59:47+00:00'],
            'name' => ['old' => $wide(255), 'new' => $wide(255)],
            'nameNormalized' => ['old' => $wide(255), 'new' => $wide(255)],
            'shortName' => ['old' => $wide(50), 'new' => $wide(50)],
        ];
        $metadata = ['changes' => $changes, 'operation' => 'UPDATED'];

        $entry = $this->entry(AuditLevel::CHANGE, $metadata);

        $this->assertSame($metadata, $entry->metadata);
        $this->assertLessThan(
            AuditLogEntry::MAX_METADATA_BYTES / 2,
            $this->storedBytes($metadata),
            'The largest legitimate change entry has grown past half the metadata bound; revisit the bound.',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAdmitsTheLargestChangeEntryALegitimateWriteProducesCases(): iterable
    {
        yield 'four UTF-8 bytes a character' => ["\u{1F3E6}"];
        yield 'six escaped bytes a character' => ["\u{0001}"];
    }

    public function testRefusesMetadataOverItsBoundAndReportsTheMeasuredSize(): void
    {
        $metadata = ['orphan_role_values' => [\str_repeat('A', AuditLogEntry::MAX_METADATA_BYTES)]];
        $size = $this->storedBytes($metadata);

        try {
            $this->entry(AuditLevel::SECURITY, $metadata);
            $this->fail('Metadata over its bound was admitted.');
        } catch (InvalidAuditLogEntry $invalidAuditLogEntry) {
            $this->assertSame(
                'Audit log entry metadata exceeds its maximum encoded size.',
                $invalidAuditLogEntry->getMessage(),
            );
            $this->assertSame(
                ['field' => 'metadata', 'max' => AuditLogEntry::MAX_METADATA_BYTES, 'actual' => $size],
                $invalidAuditLogEntry->context(),
            );
        }
    }

    /**
     * Measured on what `jsonb` holds, not on the escaped spelling: a name in another script costs its UTF-8
     * bytes, so an escaped measure would refuse it at a sixth of the bound.
     */
    public function testMeasuresTheBoundOnTheUnescapedBytes(): void
    {
        $fourByteCharacters = \intdiv(AuditLogEntry::MAX_METADATA_BYTES, 8);
        $metadata = ['orphan_role_values' => [\str_repeat("\u{1F3E6}", $fourByteCharacters)]];

        $this->assertSame($metadata, $this->entry(AuditLevel::SECURITY, $metadata)->metadata);
    }

    /**
     * @param array<array-key, mixed> $metadata
     */
    private function entry(AuditLevel $level, array $metadata): AuditLogEntry
    {
        return AuditLogEntry::create(
            'AUDIT_METADATA_PROBE',
            $level,
            ActorContext::anonymous(),
            Uuid::generate(),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            metadata: $metadata,
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function storedBytes(array $metadata): int
    {
        return \strlen(\json_encode(
            (object) $metadata,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }
}
