<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Privacy\Application\Double;

use Closure;
use Erpify\Shared\Privacy\Application\PersonReferenceErasure;
use Erpify\Tests\Unit\Shared\Persistence\Double\LockOrderJournal;
use Override;

/**
 * Spy {@see PersonReferenceErasure}: records the subjects it was asked to erase and, when a test is asserting
 * where the Documents purge falls among the other tables', writes the two tables the real implementation
 * locks — the documents, then their custody rows — to the shared journal.
 *
 * What it can prove is where the USE CASES call the seam. That the real implementation locks those rows in
 * that order is a claim about SQL, and its instrument is the functional lock-order test.
 *
 * @internal
 */
final class RecordingPersonReferenceErasure implements PersonReferenceErasure
{
    /** @var list<string> */
    public array $erasedSubjects = [];

    /** Set when a test is asserting where the Documents purge falls among the other tables'. */
    public ?LockOrderJournal $lockOrderJournal = null;

    /** Set to inject a failure inside the step, as a store that refuses the rewrite would. */
    public ?Closure $onErase = null;

    public function __construct(private readonly int $rewritten = 0)
    {
    }

    #[Override]
    public function eraseReferencesTo(string $subjectId): int
    {
        $this->erasedSubjects[] = $subjectId;
        $this->lockOrderJournal?->locked(LockOrderJournal::DOCUMENT);
        $this->lockOrderJournal?->locked(LockOrderJournal::DOCUMENT_CUSTODY);

        if ($this->onErase instanceof Closure) {
            ($this->onErase)();
        }

        return $this->rewritten;
    }
}
