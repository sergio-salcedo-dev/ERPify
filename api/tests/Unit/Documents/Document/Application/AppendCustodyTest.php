<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Application;

use Erpify\Documents\Document\Application\AppendCustody;
use Erpify\Documents\Document\Application\ReceiveDocument;
use Erpify\Documents\Document\Domain\CustodyEvent;
use Erpify\Documents\Document\Domain\Exception\CustodySequenceCollision;
use Erpify\Documents\Document\Domain\Exception\DocumentNotFound;
use Erpify\Shared\Persistence\Domain\Exception\TransientTransactionFailure;
use Erpify\Shared\Uuid\Domain\Uuid;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid as RandomUuid;
use Throwable;

/**
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the use case, its three ports and the exceptions it maps
 */
#[CoversClass(AppendCustody::class)]
final class AppendCustodyTest extends TestCase
{
    private const string DIGEST = 'a3b1c9d7e5f3a1b9c7d5e3f1a9b7c5d3e1f9a7b5c3d1e9f7a5b3c1d9e7f5a3b1';

    #[Test]
    public function eachAppendLocksTheDocumentFirstAndTakesTheNextSequenceNumber(): void
    {
        [$useCase, $documents, $custody, $documentId] = $this->fixture();
        $actor = Uuid::generate();

        $first = $useCase->append($documentId, CustodyEvent::Received, $actor);
        $second = $useCase->append($documentId, CustodyEvent::Received, $actor);

        $this->assertSame([1, 2], [$first->seq(), $second->seq()]);
        $this->assertSame([$documentId, $documentId], $documents->lockedIds);
        $this->assertSame(['lock', 'lock'], $documents->calls);
        $this->assertSame($actor, $first->actorId());
        $this->assertSame(['append', 'append'], $custody->calls);
    }

    #[Test]
    public function aLostRaceRerunsTheWholeUnitOfWorkAndTheNextAttemptTakesAFreshNumber(): void
    {
        [$useCase, $documents, $custody, $documentId, $transactions] = $this->fixture(collisions: 2);
        $before = $transactions->entered;

        $appended = $useCase->append($documentId, CustodyEvent::Received, Uuid::generate());

        $this->assertSame(3, $transactions->entered - $before, 'two collisions, then the third attempt wins');
        $this->assertSame(
            \array_fill(0, 3, $documentId),
            $documents->lockedIds,
            'the lock is taken again on every attempt: the whole closure re-runs, never only the INSERT',
        );
        $this->assertCount(3, $custody->computed, 'the number is computed afresh on every attempt');
        $this->assertSame(1, $appended->seq());
        $this->assertCount(1, $custody->rows, 'a collided attempt leaves no row');
    }

    #[Test]
    public function aCollisionThatSurvivesEveryAttemptIsATransientFailureNotAServerError(): void
    {
        [$useCase, , $custody, $documentId, $transactions] = $this->fixture(collisions: 99);
        $before = $transactions->entered;

        try {
            $useCase->append($documentId, CustodyEvent::Received, Uuid::generate());
            $this->fail('Expected TransientTransactionFailure.');
        } catch (TransientTransactionFailure $transientTransactionFailure) {
            $this->assertInstanceOf(CustodySequenceCollision::class, $transientTransactionFailure->getPrevious());
        }

        $this->assertSame(3, $transactions->entered - $before, 'bounded at three attempts');
        $this->assertSame([], $custody->rows);
    }

    #[Test]
    public function anAbsentDocumentIsNotFoundAndIsNotRetried(): void
    {
        [$useCase, , , , $transactions] = $this->fixture();
        $before = $transactions->entered;

        try {
            $useCase->append(Uuid::generate(), CustodyEvent::Received, Uuid::generate());
            $this->fail('Expected DocumentNotFound.');
        } catch (DocumentNotFound) {
            $this->assertSame(1, $transactions->entered - $before);
        }
    }

    #[Test]
    public function aMalformedIdIsRefusedBeforeAnyTransactionOpens(): void
    {
        [$useCase, , , , $transactions] = $this->fixture();
        $before = $transactions->entered;

        try {
            $useCase->append('not-a-uuid', CustodyEvent::Received, Uuid::generate());
            $this->fail('Expected a refusal.');
        } catch (Throwable $throwable) {
            $this->assertNotInstanceOf(InvalidArgumentException::class, $throwable);
            $this->assertSame($before, $transactions->entered);
        }
    }

    /**
     * @return array{
     *     AppendCustody,
     *     InMemoryDocumentRepository,
     *     InMemoryDocumentCustodyRepository,
     *     string,
     *     CountingTransactionManager,
     * }
     */
    private function fixture(int $collisions = 0): array
    {
        $documents = new InMemoryDocumentRepository();
        $transactions = new CountingTransactionManager();
        $document = (new ReceiveDocument($documents, $transactions))->receive(
            RandomUuid::v4()->toRfc4122(),
            Uuid::generate(),
            self::DIGEST,
            10,
        );
        // `receive()` filed its first custody row through the document repository's `add()`, which the double
        // keeps apart from the custody log; the append tests number from an empty log of their own.
        $custody = new InMemoryDocumentCustodyRepository($collisions);
        $documents->calls = [];

        return [
            new AppendCustody($documents, $custody, $transactions),
            $documents,
            $custody,
            (string) $document->getId(),
            $transactions,
        ];
    }
}
