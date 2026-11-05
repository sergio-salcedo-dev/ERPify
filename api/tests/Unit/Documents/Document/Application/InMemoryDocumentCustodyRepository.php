<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Application;

use ArrayObject;
use Erpify\Documents\Document\Domain\Entity\Document;
use Erpify\Documents\Document\Domain\Entity\DocumentCustody;
use Erpify\Documents\Document\Domain\Exception\CustodySequenceCollision;
use Erpify\Documents\Document\Domain\Repository\DocumentCustodyRepository;
use Override;

/**
 * In-memory {@see DocumentCustodyRepository}. `collisions` makes the first N appends lose the race the way
 * `UNIQUE (document_id, seq)` would, so the retry loop is exercised with the real exception.
 *
 * @internal
 */
final class InMemoryDocumentCustodyRepository implements DocumentCustodyRepository
{
    /** @var list<DocumentCustody> */
    public array $rows = [];

    /** @var list<int> the sequence numbers computed, one per attempt, collided or not */
    public array $computed = [];

    /** @var list<string> */
    public array $anonymisedSubjects = [];

    /** @var list<string> */
    public array $calls = [];

    /**
     * Shared with the document double when a test asserts the order of the two erasure statements.
     *
     * @var ArrayObject<int, string>|null
     */
    public ?ArrayObject $journal = null;

    public function __construct(
        private int $collisions = 0,
        private readonly int $actorsRewritten = 0,
    ) {
    }

    #[Override]
    public function nextSequenceFor(Document $locked): int
    {
        $count = 0;

        foreach ($this->rows as $row) {
            if ($row->documentId() === $locked->getId()) {
                $count = \max($count, $row->seq());
            }
        }

        return $this->computed[] = $count + 1;
    }

    #[Override]
    public function append(DocumentCustody $custody): void
    {
        $this->calls[] = 'append';

        if ($this->collisions > 0) {
            --$this->collisions;

            throw CustodySequenceCollision::create();
        }

        $this->rows[] = $custody;
    }

    #[Override]
    public function anonymiseActor(string $subjectId): int
    {
        $this->calls[] = 'anonymise-actor';
        $this->anonymisedSubjects[] = $subjectId;
        $this->journal?->append('document_custody');

        return $this->actorsRewritten;
    }
}
