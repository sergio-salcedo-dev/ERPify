<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Application;

use Erpify\Documents\Document\Application\ReceiveDocument;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Shared\Persistence\Domain\Exception\ConcurrentUniqueWrite;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid as RandomUuid;

/**
 * @internal
 */
#[CoversClass(ReceiveDocument::class)]
final class ReceiveDocumentTest extends TestCase
{
    private const string DIGEST = 'a3b1c9d7e5f3a1b9c7d5e3f1a9b7c5d3e1f9a7b5c3d1e9f7a5b3c1d9e7f5a3b1';

    #[Test]
    public function aDocumentIsFiledInReceivedWithItsFirstCustodyRowInOneTransaction(): void
    {
        $documents = new InMemoryDocumentRepository();
        $transactions = new CountingTransactionManager();
        $uploader = Uuid::generate();
        $scope = RandomUuid::v4()->toRfc4122();

        $document = (new ReceiveDocument($documents, $transactions))->receive($scope, $uploader, self::DIGEST, 2048);

        $this->assertSame(1, $transactions->entered);
        $this->assertSame(['add'], $documents->calls);
        $this->assertSame(DocumentState::Received, $document->state());
        $this->assertSame($scope, $document->scopeId());
        $this->assertSame($uploader, $document->uploaderId());

        $this->assertCount(1, $documents->firstCustody);
        $custody = $documents->firstCustody[0];
        $this->assertSame($document->getId(), $custody->documentId());
        $this->assertSame(1, $custody->seq());
        $this->assertSame($uploader, $custody->actorId());
    }

    #[Test]
    public function aFirstVersionIsItsOwnSeriesAndHasNoPredecessor(): void
    {
        $document = (new ReceiveDocument(new InMemoryDocumentRepository(), new CountingTransactionManager()))
            ->receive(RandomUuid::v4()->toRfc4122(), Uuid::generate(), self::DIGEST, 1)
        ;

        $this->assertSame($document->getId(), $document->seriesId());
        $this->assertNull($document->predecessorId());
        $this->assertNotSame($document->getId(), $document->scopeId());
    }

    #[Test]
    public function aSecondDocumentUnderTheSameIdempotencyKeyHashIsAConflictAndIsNotRetried(): void
    {
        $documents = new InMemoryDocumentRepository();
        $transactions = new CountingTransactionManager();
        $useCase = new ReceiveDocument($documents, $transactions);
        $hash = \hash('sha256', 'actor-scoped key');
        $uploader = Uuid::generate();

        $useCase->receive(RandomUuid::v4()->toRfc4122(), $uploader, self::DIGEST, 10, $hash);

        $this->expectException(ConcurrentUniqueWrite::class);

        try {
            $useCase->receive(RandomUuid::v4()->toRfc4122(), $uploader, self::DIGEST, 10, $hash);
        } finally {
            $this->assertSame(2, $transactions->entered, 'one attempt per call: a conflict is never retried');
            $this->assertCount(1, $documents->documents);
        }
    }
}
