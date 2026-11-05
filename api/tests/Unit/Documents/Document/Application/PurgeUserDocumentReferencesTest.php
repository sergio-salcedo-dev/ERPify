<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Application;

use ArrayObject;
use Erpify\Documents\Document\Application\PurgeUserDocumentReferences;
use Erpify\Documents\Document\Domain\ErasedPersonMarker;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PurgeUserDocumentReferences::class)]
final class PurgeUserDocumentReferencesTest extends TestCase
{
    #[Test]
    public function itRewritesTheSubjectsDocumentsAndThenTheirCustodyRowsAndReportsBothCounts(): void
    {
        /** @var ArrayObject<int, string> $journal */
        $journal = new ArrayObject();
        $documentsRepository = new InMemoryDocumentRepository(uploadersRewritten: 3);
        $documentsRepository->journal = $journal;

        $custodyRepository = new InMemoryDocumentCustodyRepository(actorsRewritten: 5);
        $custodyRepository->journal = $journal;

        $subject = Uuid::generate();

        $rewritten = (new PurgeUserDocumentReferences($documentsRepository, $custodyRepository))
            ->eraseReferencesTo($subject)
        ;

        $this->assertSame(8, $rewritten);
        $this->assertSame(
            ['document', 'document_custody'],
            $journal->getArrayCopy(),
            'the documents are locked and rewritten before the custody rows that travel with them',
        );
        $this->assertSame([$subject], $documentsRepository->anonymisedSubjects);
        $this->assertSame([$subject], $custodyRepository->anonymisedSubjects);
    }

    #[Test]
    public function aMalformedIdIsRefusedBeforeAnythingIsRewritten(): void
    {
        $documentsRepository = new InMemoryDocumentRepository();
        $custodyRepository = new InMemoryDocumentCustodyRepository();

        try {
            (new PurgeUserDocumentReferences($documentsRepository, $custodyRepository))
                ->eraseReferencesTo('not-a-uuid')
            ;
            $this->fail('Expected InvalidUuidException.');
        } catch (InvalidUuidException) {
            $this->assertSame([], $documentsRepository->anonymisedSubjects);
            $this->assertSame([], $custodyRepository->anonymisedSubjects);
        }
    }

    #[Test]
    public function theMarkerIsNotASubjectAndRewritesNothing(): void
    {
        $documentsRepository = new InMemoryDocumentRepository(uploadersRewritten: 9);
        $custodyRepository = new InMemoryDocumentCustodyRepository(actorsRewritten: 9);

        $rewritten = (new PurgeUserDocumentReferences($documentsRepository, $custodyRepository))
            ->eraseReferencesTo(ErasedPersonMarker::UUID)
        ;

        $this->assertSame(0, $rewritten, 'the rows already anonymised are not counted again');
        $this->assertSame([], $documentsRepository->anonymisedSubjects);
        $this->assertSame([], $custodyRepository->anonymisedSubjects);
    }
}
