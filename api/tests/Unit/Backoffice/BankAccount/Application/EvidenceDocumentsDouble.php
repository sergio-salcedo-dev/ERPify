<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application;

use DateTimeImmutable;
use Erpify\Documents\Document\Application\Evidence\DescribeEvidenceDocuments;
use Erpify\Documents\Document\Application\Listing\DocumentNameOpener;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Projection\DocumentListRow;

/**
 * The Documents seam as the BankAccount use cases meet it in a unit test: the real {@see DescribeEvidenceDocuments}
 * over an in-memory listing, and a ledger override the test chooses. A row carries no sealed name unless the test
 * gives one, and the encryptor refuses every call, so a row with a sealed name proves its name is never opened.
 * Building the real seam rather than a stub keeps the use cases tested against what Documents actually answers,
 * erased state included.
 *
 * @internal
 */
final class EvidenceDocumentsDouble
{
    /** @var array<string, DocumentListRow> */
    private array $rows = [];

    private bool $overrideActive = false;

    private ?InMemoryDocumentListing $listing = null;

    public function withDocument(
        string $id,
        DocumentState $state = DocumentState::Ready,
        ?string $retentionReason = 'contract-execution',
        ?string $legalBasis = 'legitimate-interests',
        ?DateTimeImmutable $reviewAt = null,
        ?string $filenameCiphertext = null,
    ): self {
        $this->rows[\strtolower($id)] = new DocumentListRow(
            $id,
            $id,
            null,
            $state,
            \str_repeat('a', 64),
            1024,
            $id,
            $filenameCiphertext,
            $retentionReason,
            $legalBasis,
            $reviewAt,
            new DateTimeImmutable('2030-01-02T03:04:05+00:00'),
        );

        return $this;
    }

    public function withOverrideActive(): self
    {
        $this->overrideActive = true;

        return $this;
    }

    public function seam(): DescribeEvidenceDocuments
    {
        $this->listing = new InMemoryDocumentListing($this->rows);

        return new DescribeEvidenceDocuments(
            $this->listing,
            new DocumentNameOpener(new NameOpeningRefusingEncryptor()),
            new FixedErasureLedgerOverride($this->overrideActive),
        );
    }

    /**
     * How many times the seam asked for rows since {@see seam()} last built it.
     */
    public function findManyCalls(): int
    {
        return $this->listing instanceof InMemoryDocumentListing ? $this->listing->findManyCalls : 0;
    }
}
