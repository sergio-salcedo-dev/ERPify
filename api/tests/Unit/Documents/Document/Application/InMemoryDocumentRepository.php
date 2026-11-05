<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Application;

use ArrayObject;
use Erpify\Documents\Document\Domain\Entity\Document;
use Erpify\Documents\Document\Domain\Entity\DocumentCustody;
use Erpify\Documents\Document\Domain\Exception\DocumentNotFound;
use Erpify\Documents\Document\Domain\Repository\DocumentRepository;
use Erpify\Shared\Persistence\Domain\Exception\ConcurrentUniqueWrite;
use Override;

/**
 * In-memory {@see DocumentRepository} that records the order of its calls, so a use-case test can assert what
 * happened inside the unit of work without a database.
 *
 * @internal
 */
final class InMemoryDocumentRepository implements DocumentRepository
{
    /** @var array<string, Document> */
    public array $documents = [];

    /** @var list<DocumentCustody> first custody rows, in the order their documents were added */
    public array $firstCustody = [];

    /** @var list<string> */
    public array $lockedIds = [];

    /** @var list<string> */
    public array $anonymisedSubjects = [];

    /** @var list<string> every call, in order, for assertions on sequencing */
    public array $calls = [];

    /**
     * Shared with the custody double when a test asserts the order of the two erasure statements.
     *
     * @var ArrayObject<int, string>|null
     */
    public ?ArrayObject $journal = null;

    public function __construct(private readonly int $uploadersRewritten = 0)
    {
    }

    #[Override]
    public function add(Document $document, DocumentCustody $firstCustody): void
    {
        $this->calls[] = 'add';
        $hash = $document->idempotencyKeyHash();

        foreach ($this->documents as $existing) {
            if (null !== $hash && $existing->idempotencyKeyHash() === $hash) {
                throw ConcurrentUniqueWrite::onWrite('document');
            }
        }

        $this->documents[(string) $document->getId()] = $document;
        $this->firstCustody[] = $firstCustody;
    }

    #[Override]
    public function lockForUpdate(string $id): Document
    {
        $this->calls[] = 'lock';
        $this->lockedIds[] = $id;

        return $this->documents[$id] ?? throw DocumentNotFound::create();
    }

    #[Override]
    public function anonymiseUploader(string $subjectId): int
    {
        $this->calls[] = 'anonymise-uploader';
        $this->anonymisedSubjects[] = $subjectId;
        $this->journal?->append('document');

        return $this->uploadersRewritten;
    }
}
