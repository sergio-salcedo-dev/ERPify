<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Closure;
use Erpify\Documents\Document\Domain\Entity\Document;
use Erpify\Documents\Document\Domain\Entity\DocumentCustody;
use Erpify\Documents\Document\Domain\Exception\CustodySequenceCollision;
use Erpify\Documents\Document\Domain\Repository\DocumentCustodyRepository;
use Override;

/**
 * Wraps the production custody repository so a test can reach into the window the real one closes.
 *
 * `beforeAppend` fires inside the unit of work, after the document row is locked and the number computed, which
 * is the instant a second connection has to be refused for the lock to mean anything. `staleReads` makes the
 * first N sequence numbers one too low, the state a missing lock would produce, so the real INSERT meets the
 * real constraint. `afterAppend` fires once the row is flushed, to force a rollback with it pending.
 *
 * @internal
 */
final class ProbingDocumentCustodyRepository implements DocumentCustodyRepository
{
    /** How many times the production repository classified the violation as a lost race. */
    public int $collisionsRaised = 0;

    /**
     * @param (Closure(): void)|null $beforeAppend
     * @param (Closure(): void)|null $afterAppend
     * @param (Closure(): void)|null $beforeAnonymise fires where the actor rewrite is about to run
     */
    public function __construct(
        private readonly DocumentCustodyRepository $inner,
        private readonly ?Closure $beforeAppend = null,
        private int $staleReads = 0,
        private readonly ?Closure $afterAppend = null,
        private readonly ?Closure $beforeAnonymise = null,
    ) {
    }

    #[Override]
    public function nextSequenceFor(Document $locked): int
    {
        $next = $this->inner->nextSequenceFor($locked);

        if ($this->staleReads > 0 && $next > 1) {
            --$this->staleReads;

            return $next - 1;
        }

        return $next;
    }

    #[Override]
    public function append(DocumentCustody $custody): void
    {
        if ($this->beforeAppend instanceof Closure) {
            ($this->beforeAppend)();
        }

        try {
            $this->inner->append($custody);
        } catch (CustodySequenceCollision $custodySequenceCollision) {
            ++$this->collisionsRaised;

            throw $custodySequenceCollision;
        }

        if ($this->afterAppend instanceof Closure) {
            ($this->afterAppend)();
        }
    }

    #[Override]
    public function anonymiseActor(string $subjectId): int
    {
        if ($this->beforeAnonymise instanceof Closure) {
            ($this->beforeAnonymise)();
        }

        return $this->inner->anonymiseActor($subjectId);
    }
}
