<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Documents\Document\Domain\Entity\Document;
use Erpify\Documents\Document\Domain\Entity\DocumentCustody;
use Erpify\Documents\Document\Domain\Entity\UploadIntent;
use Erpify\Shared\Audit\Domain\AuditedEntity;
use Erpify\Shared\Audit\Domain\AuditMetadataKey;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The change-capture listener writes a diff of every `#[AuditedEntity]` to `audit_log`, a sink no erasure path
 * rewrites, and the Documents rows carry the sealed original filename and an actor-scoped key hash. None of the
 * three entities may opt in, and the audit metadata vocabulary holds no filename key.
 *
 * @internal
 */
#[CoversNothing]
final class DocumentsNotAuditedGateTest extends TestCase
{
    /**
     * @param class-string $entity
     */
    #[DataProvider('provideTheEntityIsNotAuditedCases')]
    public function testTheEntityIsNotAudited(string $entity): void
    {
        $this->assertFalse((new ReflectionClass($entity))->implementsInterface(AuditedEntity::class));
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function provideTheEntityIsNotAuditedCases(): iterable
    {
        yield 'Document' => [Document::class];
        yield 'UploadIntent' => [UploadIntent::class];
        yield 'DocumentCustody' => [DocumentCustody::class];
    }

    public function testNoAuditMetadataKeyNamesAFilename(): void
    {
        foreach (AuditMetadataKey::cases() as $key) {
            $this->assertStringNotContainsString('filename', \strtolower($key->name . ' ' . $key->value), $key->name);
        }
    }
}
