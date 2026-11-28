<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application\Evidence;

use DateTimeImmutable;
use Erpify\Documents\Document\Application\Evidence\EvidenceDocument;
use Erpify\Documents\Document\Application\Evidence\EvidenceDocuments;
use Erpify\Documents\Document\Application\Evidence\EvidenceFiling;
use Erpify\Documents\Document\Domain\Exception\DocumentGone;
use Override;

/**
 * The Documents read seam over a map of planted documents, each filed under the given terms and state; `gone` stands
 * for the erasure ledger's override.
 *
 * @internal
 */
final class FakeEvidenceDocuments implements EvidenceDocuments
{
    /** @var array<string, EvidenceFiling> */
    private array $filings = [];

    public bool $gone = false;

    public int $describeCalls = 0;

    public function file(
        string $documentId,
        string $state = 'received',
        ?string $retentionReason = 'contract-execution',
        ?string $legalBasis = 'legitimate-interests',
        ?DateTimeImmutable $reviewAt = null,
    ): void {
        $this->filings[$documentId] = new EvidenceFiling($documentId, $state, $retentionReason, $legalBasis, $reviewAt);
    }

    #[Override]
    public function filingOf(string $documentId): ?EvidenceFiling
    {
        return $this->filings[$documentId] ?? null;
    }

    #[Override]
    public function filingsOf(array $documentIds): array
    {
        return \array_intersect_key($this->filings, \array_flip($documentIds));
    }

    #[Override]
    public function describe(array $documentIds): array
    {
        ++$this->describeCalls;

        if ($this->gone) {
            throw DocumentGone::create();
        }

        $described = [];

        foreach ($this->filingsOf($documentIds) as $id => $filing) {
            $described[$id] = new EvidenceDocument(
                $id,
                'erased' === $filing->state ? null : $id . '.pdf',
                $filing->state,
                \hash('sha256', $id),
                10,
                new DateTimeImmutable('2030-05-05T10:00:00+00:00'),
            );
        }

        return $described;
    }
}
