<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Domain\Entity;

use DateTimeImmutable;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Entity\Document;
use Erpify\Documents\Document\Domain\ErasedPersonMarker;
use Erpify\Documents\Document\Domain\IntakeMetadata;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Shared\Uuid\Domain\Uuid;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid as RandomUuid;

/**
 * @internal
 */
#[CoversClass(Document::class)]
final class DocumentTest extends TestCase
{
    private const string DIGEST = 'a3b1c9d7e5f3a1b9c7d5e3f1a9b7c5d3e1f9a7b5c3d1e9f7a5b3c1d9e7f5a3b1';

    #[Test]
    public function aNewDocumentIsReceivedAndIsItsOwnSeriesWithNoPredecessor(): void
    {
        $id = Uuid::generate();
        $scope = RandomUuid::v4()->toRfc4122();
        $uploader = Uuid::generate();

        $document = Document::receive($id, $scope, $uploader, self::DIGEST, 4096);

        $this->assertSame($id, $document->getId());
        $this->assertSame($id, $document->seriesId());
        $this->assertNull($document->predecessorId());
        $this->assertSame($scope, $document->scopeId());
        $this->assertSame($uploader, $document->uploaderId());
        $this->assertSame(DocumentState::Received, $document->state());
        $this->assertSame(self::DIGEST, $document->digest());
        $this->assertSame(4096, $document->sizeBytes());
        $this->assertNull($document->idempotencyKeyHash());
    }

    #[Test]
    public function theScopeIdIsNeverTheDocumentId(): void
    {
        $id = Uuid::generate();

        $this->expectException(InvalidArgumentException::class);

        Document::receive($id, $id, Uuid::generate(), self::DIGEST, 1);
    }

    #[Test]
    public function aMalformedIdentifierIsAnInvalidUuid(): void
    {
        $this->expectException(InvalidUuidException::class);

        Document::receive(Uuid::generate(), 'not-a-uuid', Uuid::generate(), self::DIGEST, 1);
    }

    #[Test]
    public function anUpperCasedIdentifierIsRefusedSoOneRowCannotHaveTwoSpellings(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Document::receive(
            Uuid::generate(),
            \strtoupper(RandomUuid::v4()->toRfc4122()),
            Uuid::generate(),
            self::DIGEST,
            1,
        );
    }

    #[Test]
    #[DataProvider('provideContentThatCouldNeverBeAnOriginalIsRefusedCases')]
    public function contentThatCouldNeverBeAnOriginalIsRefused(string $digest, int $size, ?string $hash): void
    {
        $this->expectException(InvalidArgumentException::class);

        Document::receive(Uuid::generate(), RandomUuid::v4()->toRfc4122(), Uuid::generate(), $digest, $size, $hash);
    }

    /**
     * @return iterable<string, array{string, int, ?string}>
     */
    public static function provideContentThatCouldNeverBeAnOriginalIsRefusedCases(): iterable
    {
        yield 'a short digest' => ['abc', 1, null];
        yield 'an upper-case digest' => [\strtoupper(self::DIGEST), 1, null];
        yield 'a digest with a trailing newline' => [self::DIGEST . "\n", 1, null];
        yield 'a zero size' => [self::DIGEST, 0, null];
        yield 'a negative size' => [self::DIGEST, -1, null];
        yield 'a malformed idempotency key hash' => [self::DIGEST, 1, 'not-a-hash'];
    }

    #[Test]
    public function theIdempotencyKeyHashIsKeptWhenSupplied(): void
    {
        $hash = \hash('sha256', 'key');

        $document = Document::receive(
            Uuid::generate(),
            RandomUuid::v4()->toRfc4122(),
            Uuid::generate(),
            self::DIGEST,
            1,
            $hash,
        );

        $this->assertSame($hash, $document->idempotencyKeyHash());
    }

    #[Test]
    public function theErasedPersonMarkerIsNeverFiledAsAnUploader(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Document::receive(
            Uuid::generate(),
            RandomUuid::v4()->toRfc4122(),
            ErasedPersonMarker::UUID,
            self::DIGEST,
            1,
        );
    }

    #[Test]
    public function theOptionalSealedFilenameAndRetentionFieldsAreKeptWhenSuppliedAndNullOtherwise(): void
    {
        $plain = Document::receive(Uuid::generate(), RandomUuid::v4()->toRfc4122(), Uuid::generate(), self::DIGEST, 1);

        $this->assertNull($plain->filenameCiphertext());
        $this->assertNull($plain->retentionReason());
        $this->assertNull($plain->legalBasis());
        $this->assertNull($plain->reviewAt());

        $review = new DateTimeImmutable('2031-01-01T00:00:00+00:00');
        $full = Document::receive(
            Uuid::generate(),
            RandomUuid::v4()->toRfc4122(),
            Uuid::generate(),
            self::DIGEST,
            1,
            intake: new IntakeMetadata(
                filenameCiphertext: 'sealed',
                retentionReason: 'contract',
                legalBasis: 'legal_obligation',
                reviewAt: $review,
            ),
        );

        $this->assertSame('sealed', $full->filenameCiphertext());
        $this->assertSame('contract', $full->retentionReason());
        $this->assertSame('legal_obligation', $full->legalBasis());
        $this->assertSame($review, $full->reviewAt());
    }
}
