<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Domain\Entity;

use Erpify\Documents\Document\Domain\DocumentIdentifiers;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Entity\Document;
use Erpify\Documents\Document\Domain\Exception\PredecessorNotReady;
use Erpify\Documents\Document\Domain\IntakeMetadata;
use Erpify\Documents\Document\Domain\OriginalContent;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Uuid\Domain\Uuid;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Uid\Uuid as RandomUuid;

/**
 * A successor copies its series from the predecessor it is handed and links to it, and only a `Ready` predecessor can
 * be succeeded. The predecessor's state is written by compare-and-set statements in production, so it is set here by
 * reflection rather than through a transition this aggregate does not own.
 *
 * @internal
 */
#[CoversClass(Document::class)]
#[CoversClass(PredecessorNotReady::class)]
#[CoversClass(DocumentState::class)]
final class DocumentSuccessionTest extends TestCase
{
    private const string DIGEST = 'a3b1c9d7e5f3a1b9c7d5e3f1a9b7c5d3e1f9a7b5c3d1e9f7a5b3c1d9e7f5a3b1';

    #[Test]
    public function aSuccessorJoinsThePredecessorsSeriesAndLinksToIt(): void
    {
        $first = $this->documentIn(DocumentState::Ready);
        $second = $this->successorOf($first);
        $this->setState($second, DocumentState::Ready);

        $third = $this->successorOf($second);

        $this->assertSame($first->documentId(), $second->seriesId());
        $this->assertSame($first->documentId(), $second->predecessorId());
        $this->assertSame($first->documentId(), $third->seriesId(), 'the series is the first version, at any depth');
        $this->assertSame($second->documentId(), $third->predecessorId());
        $this->assertSame(DocumentState::Received, $third->state());
    }

    #[Test]
    #[DataProvider('provideOnlyAReadyPredecessorCanBeSucceededCases')]
    public function onlyAReadyPredecessorCanBeSucceeded(DocumentState $state): void
    {
        $this->expectException(PredecessorNotReady::class);

        $this->successorOf($this->documentIn($state));
    }

    /**
     * @return iterable<string, array{DocumentState}>
     */
    public static function provideOnlyAReadyPredecessorCanBeSucceededCases(): iterable
    {
        foreach (DocumentState::cases() as $state) {
            if (DocumentState::Ready !== $state) {
                yield $state->value => [$state];
            }
        }
    }

    #[Test]
    public function aVersionCannotSucceedItself(): void
    {
        $document = $this->documentIn(DocumentState::Ready);

        $this->expectException(InvalidArgumentException::class);

        Document::succeed(
            $document,
            new DocumentIdentifiers($document->documentId(), RandomUuid::v4()->toRfc4122(), Uuid::generate()),
            new OriginalContent(self::DIGEST, 1),
            null,
            new IntakeMetadata(),
            SystemClock::now(),
        );
    }

    #[Test]
    public function theSlotIsHeldByTheStatesOnTheirWayToReadyAndByReady(): void
    {
        $this->assertSame(
            [DocumentState::Received, DocumentState::Scanning, DocumentState::Ready],
            DocumentState::slotHolders(),
        );
    }

    private function documentIn(DocumentState $state): Document
    {
        $document = Document::receive(
            new DocumentIdentifiers(Uuid::generate(), RandomUuid::v4()->toRfc4122(), Uuid::generate()),
            new OriginalContent(self::DIGEST, 1),
        );
        $this->setState($document, $state);

        return $document;
    }

    private function successorOf(Document $predecessor): Document
    {
        return Document::succeed(
            $predecessor,
            new DocumentIdentifiers(Uuid::generate(), RandomUuid::v4()->toRfc4122(), Uuid::generate()),
            new OriginalContent(self::DIGEST, 1),
            null,
            new IntakeMetadata(),
            SystemClock::now(),
        );
    }

    private function setState(Document $document, DocumentState $state): void
    {
        $property = new ReflectionProperty(Document::class, 'state');
        $property->setValue($document, $state);
    }
}
