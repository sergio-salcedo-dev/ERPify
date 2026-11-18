<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Domain\Entity;

use Erpify\Documents\Document\Domain\CustodyEvent;
use Erpify\Documents\Document\Domain\Entity\DocumentCustody;
use Erpify\Documents\Document\Domain\ErasedPersonMarker;
use Erpify\Documents\Document\Domain\Erasure\ErasureReason;
use Erpify\Documents\Document\Domain\Erasure\ErasureSystemReason;
use Erpify\Documents\Document\Domain\Scanning\RescanReason;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Shared\Uuid\Domain\Uuid;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Uid\NilUuid;
use Symfony\Component\Uid\Uuid as RandomUuid;

/**
 * @internal
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") one test per invariant of the row
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the row, its events and the two vocabularies of reasons
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

    #[Test]
    #[DataProvider('provideTheSystemsFacts')]
    public function aScanFactIsTheSystemsAndNamesNoActor(CustodyEvent $event): void
    {
        $row = DocumentCustody::record(Uuid::generate(), Uuid::generate(), 2, $event, null);

        $this->assertNull($row->actorId());
        $this->assertNull($row->reason());

        $this->expectException(InvalidArgumentException::class);
        DocumentCustody::record(Uuid::generate(), Uuid::generate(), 2, $event, Uuid::generate());
    }

    #[Test]
    #[DataProvider('provideTheSystemsFacts')]
    public function aScanFactCarriesNoReason(CustodyEvent $event): void
    {
        $this->expectException(InvalidArgumentException::class);

        DocumentCustody::record(Uuid::generate(), Uuid::generate(), 2, $event, null, 'failure_investigated');
    }

    /**
     * @return iterable<string, array{CustodyEvent}>
     */
    public static function provideTheSystemsFacts(): iterable
    {
        foreach (
            [
                CustodyEvent::ScanClaimed,
                CustodyEvent::ScanReady,
                CustodyEvent::ScanQuarantined,
                CustodyEvent::ScanFailed,
            ] as $event
        ) {
            yield $event->value => [$event];
        }
    }

    #[Test]
    public function anOperatorErasureNamesTheOperatorAndCarriesAnHttpReason(): void
    {
        foreach (ErasureReason::cases() as $reason) {
            $operator = Uuid::generate();

            $row = DocumentCustody::record(
                Uuid::generate(),
                Uuid::generate(),
                2,
                CustodyEvent::Erased,
                $operator,
                $reason->value,
            );

            $this->assertSame($operator, $row->actorId());
            $this->assertSame($reason->value, $row->reason());
        }
    }

    #[Test]
    public function aSystemErasureIsTheSystemsWithANullActorAndASystemReason(): void
    {
        foreach (ErasureSystemReason::cases() as $reason) {
            $row = DocumentCustody::record(
                Uuid::generate(),
                Uuid::generate(),
                2,
                CustodyEvent::Erased,
                null,
                $reason->value,
            );

            $this->assertNull($row->actorId(), 'the marker keeps its one meaning, a person who was erased');
            $this->assertSame($reason->value, $row->reason());
        }
    }

    #[Test]
    #[DataProvider('provideAnErasureThatFitsNeitherListIsRefusedCases')]
    public function anErasureThatFitsNeitherListIsRefused(?string $actor, ?string $reason): void
    {
        $this->expectException(InvalidArgumentException::class);

        DocumentCustody::record(Uuid::generate(), Uuid::generate(), 2, CustodyEvent::Erased, $actor, $reason);
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function provideAnErasureThatFitsNeitherListIsRefusedCases(): iterable
    {
        yield 'an operator reason with no operator' => [null, ErasureReason::DataSubjectRequest->value];
        yield 'a system reason with an operator' => [Uuid::generate(), ErasureSystemReason::LedgerReplay->value];
        yield 'the marker standing in for the system' => [
            ErasedPersonMarker::UUID,
            ErasureSystemReason::LedgerCompletion->value,
        ];
        yield 'no reason' => [null, null];
        yield 'an operator and no reason' => [Uuid::generate(), null];
        yield 'a reason outside both lists' => [Uuid::generate(), 'because'];
        yield 'a re-scan reason' => [Uuid::generate(), RescanReason::FailureInvestigated->value];
    }

    #[Test]
    public function theHttpReasonsAndTheSystemReasonsShareNoValue(): void
    {
        $http = \array_map(static fn (ErasureReason $reason): string => $reason->value, ErasureReason::cases());
        $system = \array_map(
            static fn (ErasureSystemReason $reason): string => $reason->value,
            ErasureSystemReason::cases(),
        );

        $this->assertSame([], \array_intersect($http, $system));
    }

    #[Test]
    public function aRescanNamesTheOperatorAndCarriesAReasonFromTheClosedList(): void
    {
        foreach (RescanReason::cases() as $reason) {
            $operator = Uuid::generate();

            $row = DocumentCustody::record(
                Uuid::generate(),
                Uuid::generate(),
                3,
                CustodyEvent::RescanRequested,
                $operator,
                $reason->value,
            );

            $this->assertSame($operator, $row->actorId());
            $this->assertSame($reason->value, $row->reason());
        }
    }

    #[Test]
    #[DataProvider('provideARescanWithoutItsOperatorOrWithoutAValidReasonIsRefusedCases')]
    public function aRescanWithoutItsOperatorOrWithoutAValidReasonIsRefused(?string $actor, ?string $reason): void
    {
        $this->expectException(InvalidArgumentException::class);

        DocumentCustody::record(Uuid::generate(), Uuid::generate(), 3, CustodyEvent::RescanRequested, $actor, $reason);
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function provideARescanWithoutItsOperatorOrWithoutAValidReasonIsRefusedCases(): iterable
    {
        yield 'no operator' => [null, 'failure_investigated'];
        yield 'no reason' => [Uuid::generate(), null];
        yield 'a reason outside the list' => [Uuid::generate(), 'because I said so'];
        yield 'an empty reason' => [Uuid::generate(), ''];
        yield 'the erased marker as the operator' => [ErasedPersonMarker::UUID, 'failure_investigated'];
    }

    #[Test]
    public function anEmptyActorIsRefusedAsNotAUuid(): void
    {
        $this->expectException(InvalidUuidException::class);

        DocumentCustody::record(
            Uuid::generate(),
            Uuid::generate(),
            3,
            CustodyEvent::RescanRequested,
            '',
            'failure_investigated',
        );
    }

    #[Test]
    public function aReceiptCarriesNoReason(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DocumentCustody::record(
            Uuid::generate(),
            Uuid::generate(),
            1,
            CustodyEvent::Received,
            Uuid::generate(),
            'failure_investigated',
        );
    }
}
