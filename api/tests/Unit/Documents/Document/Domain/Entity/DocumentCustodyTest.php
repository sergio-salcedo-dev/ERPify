<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Domain\Entity;

use Erpify\Documents\Document\Domain\CustodyEvent;
use Erpify\Documents\Document\Domain\Entity\DocumentCustody;
use Erpify\Documents\Document\Domain\ErasedPersonMarker;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Uuid\Domain\Uuid;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Uid\NilUuid;
use Symfony\Component\Uid\Uuid as RandomUuid;

/**
 * @internal
 */
#[CoversClass(DocumentCustody::class)]
#[CoversClass(ErasedPersonMarker::class)]
final class DocumentCustodyTest extends TestCase
{
    #[Test]
    public function aRowCarriesTheFactItsOrderAndWhoCausedIt(): void
    {
        $id = Uuid::generate();
        $document = Uuid::generate();
        $actor = Uuid::generate();

        $row = DocumentCustody::record($id, $document, 3, CustodyEvent::Received, $actor);

        $this->assertSame($id, $row->id());
        $this->assertSame($document, $row->documentId());
        $this->assertSame(3, $row->seq());
        $this->assertSame($actor, $row->actorId());
        $this->assertSame(SystemClock::now(), $row->createdAt());
    }

    #[Test]
    public function theSequenceStartsAtOne(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DocumentCustody::record(Uuid::generate(), Uuid::generate(), 0, CustodyEvent::Received, Uuid::generate());
    }

    #[Test]
    public function theClassIsImmutableByConstruction(): void
    {
        $class = new ReflectionClass(DocumentCustody::class);

        $this->assertTrue($class->isReadOnly(), 'no property can be reassigned after construction');
        $this->assertTrue($class->isFinal());
    }

    #[Test]
    public function theErasedMarkerIsTheNilUuidAndAValidValueOfTheColumnType(): void
    {
        $this->assertTrue(RandomUuid::fromString(ErasedPersonMarker::UUID)->equals(new NilUuid()));
        $this->assertTrue(Uuid::isValid(ErasedPersonMarker::UUID));
    }

    #[Test]
    public function theMarkerIsNeverRecordedAsAnActorBecauseTheDetectiveSourcesWouldNotSeeIt(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DocumentCustody::record(
            Uuid::generate(),
            Uuid::generate(),
            1,
            CustodyEvent::Received,
            ErasedPersonMarker::UUID,
        );
    }
}
